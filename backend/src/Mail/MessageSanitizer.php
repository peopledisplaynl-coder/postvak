<?php
declare(strict_types=1);

namespace Postvak\Mail;

use DOMDocument;
use DOMElement;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Makes untrusted HTML mail safe to display:
 *  - strips scripts, forms, event handlers, iframes etc. (HTMLPurifier)
 *  - rewrites cid: images to the attachment endpoint
 *  - blocks remote images (tracking pixels) unless the user allows them
 *  - opens links in a new tab without leaking the referrer
 *
 * The frontend additionally renders the result in a sandboxed iframe.
 */
final class MessageSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    /**
     * @param array<string, string> $cidMap content-id => URL of the inline attachment
     * @return array{html:string, blocked_images:int}
     */
    public static function html(string $html, array $cidMap, bool $allowRemoteImages): array
    {
        // Replace cid: references before purifying (HTMLPurifier does not know the cid scheme).
        $html = preg_replace_callback('/(["\'(])cid:([^"\')\s]+)/i', static function ($m) use ($cidMap) {
            $cid = trim(rawurldecode($m[2]), '<>');
            return $m[1] . ($cidMap[$cid] ?? 'about:blank');
        }, $html) ?? $html;

        $clean = self::purifier()->purify($html);

        return self::postProcess($clean, $allowRemoteImages);
    }

    /** Plain text body to safe HTML with clickable links. */
    public static function text(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = preg_replace(
            '~\b(https?://[^\s<>"\']+)~i',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $escaped
        ) ?? $escaped;
        return '<div style="white-space:pre-wrap;font-family:inherit">' . $linked . '</div>';
    }

    private static function postProcess(string $html, bool $allowRemote): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'blocked_images' => 0];
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="pv-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $blocked = 0;

        /** @var DOMElement $img */
        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            $src = trim($img->getAttribute('src'));
            if (str_starts_with($src, '//')) {
                $src = 'https:' . $src; // protocol-relative: would resolve wrongly in the reader
                $img->setAttribute('src', $src);
            }
            $isRemote = (bool) preg_match('~^https?://~i', $src);
            if ($isRemote && !$allowRemote) {
                $img->setAttribute('data-blocked-src', $src);
                $img->removeAttribute('src');
                $img->removeAttribute('srcset');
                $blocked++;
            } elseif ($src === '' || $src === 'about:blank') {
                $img->parentNode?->removeChild($img); // missing inline image: drop instead of a broken icon
            }
        }

        // Legacy background="..." attributes on tables and cells.
        $xpathBg = new \DOMXPath($doc);
        foreach ($xpathBg->query('//*[@background]') ?: [] as $el) {
            /** @var DOMElement $el */
            if (!$allowRemote) {
                $el->removeAttribute('background');
                $blocked++;
            }
        }

        // Remote backgrounds in inline styles are also tracking vectors.
        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*[@style]') ?: [] as $el) {
            /** @var DOMElement $el */
            $style = $el->getAttribute('style');
            if (stripos($style, 'url(') !== false) {
                if (!$allowRemote) {
                    $blocked++;
                }
                $el->setAttribute('style', $allowRemote ? $style : preg_replace('/url\([^)]*\)/i', 'none', $style));
            }
        }

        $root = $doc->getElementById('pv-root');
        $out = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $doc->saveHTML($child);
        }

        return ['html' => $out, 'blocked_images' => $blocked];
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('Attr.EnableID', false);
        $config->set('CSS.AllowTricky', true);
        $config->set('CSS.Proprietary', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('HTML.ForbiddenElements', ['form', 'input', 'button', 'select', 'textarea']);

        $cacheDir = POSTVAK_ROOT . '/storage/cache/htmlpurifier';
        if ((is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) && is_writable($cacheDir)) {
            $config->set('Cache.SerializerPath', $cacheDir);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        return self::$purifier = new HTMLPurifier($config);
    }
}
