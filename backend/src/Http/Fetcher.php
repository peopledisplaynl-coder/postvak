<?php
declare(strict_types=1);

namespace Postvak\Http;

use Postvak\Config;
use RuntimeException;

/**
 * Downloads a file the user pointed Postvak to (an iCal address). Only http(s) to public hosts:
 * the server must not be usable to look into its own network. Redirects are followed by hand so
 * every hop is checked, and with curl the checked IP address is the one connected to.
 */
final class Fetcher
{
    public const USER_AGENT = 'Postvak (+https://github.com/peopledisplaynl-coder/postvak)';

    /**
     * @param array<string, string> $headers extra request headers
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    public static function get(string $url, int $maxBytes = 10_000_000, int $timeout = 20, array $headers = []): array
    {
        $url = self::normalize($url);
        for ($hop = 0; $hop < 4; $hop++) {
            [$host, $ip, $port] = self::check($url);
            $res = self::request($url, $host, $ip, $port, $maxBytes, $timeout, $headers);
            if (in_array($res['status'], [301, 302, 303, 307, 308], true) && isset($res['headers']['location'])) {
                $url = self::resolve($url, $res['headers']['location']);
                continue;
            }
            return $res;
        }
        throw new RuntimeException('Too many redirects');
    }

    /** webcal:// is how calendar links are often shared; it means https. */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if (preg_match('#^webcals?://#i', $url)) {
            $url = 'https://' . preg_replace('#^webcals?://#i', '', $url);
        }
        return $url;
    }

    /** @return array{0:string, 1:string, 2:int} host, IP to connect to, port */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || strlen($url) > 2000 || isset($parts['user'])) {
            throw new RuntimeException('Only http and https addresses are supported');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? [trim($host, '[]')] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new RuntimeException("Host not found: $host");
        }
        if (!Config::get('allow_private_hosts', false)) {
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new RuntimeException('This address points to a private network');
                }
            }
        }
        return [$host, $ips[0], $port];
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname($p['path'] ?? '/'), '/');
        return $origin . $dir . '/' . $location;
    }

    private static function request(string $url, string $host, string $ip, int $port, int $maxBytes, int $timeout, array $headers): array
    {
        $lines = ['User-Agent: ' . self::USER_AGENT];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        if (function_exists('curl_init')) {
            $received = '';
            $responseHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => $lines,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_ENCODING       => '',
                CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? "[$ip]" : $ip)],
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$received, $maxBytes): int {
                    $received .= $chunk;
                    return strlen($received) > $maxBytes ? 0 : strlen($chunk); // 0 aborts the transfer
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if (strlen($received) > $maxBytes) {
                throw new RuntimeException('The file is too large');
            }
            if ($ok === false) {
                throw new RuntimeException('Could not download: ' . $error);
            }
            return ['status' => $status, 'body' => $received, 'headers' => $responseHeaders];
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET', 'header' => implode("\r\n", $lines), 'timeout' => $timeout,
            'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('Could not download');
        }
        $body = (string) stream_get_contents($stream, $maxBytes + 1);
        $meta = stream_get_meta_data($stream);
        fclose($stream);
        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('The file is too large');
        }
        $status = 0;
        $responseHeaders = [];
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
                $responseHeaders = [];
            } elseif (str_contains((string) $line, ':')) {
                [$k, $v] = explode(':', (string) $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
    }
}
