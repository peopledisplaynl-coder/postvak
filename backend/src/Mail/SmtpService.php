<?php
declare(strict_types=1);

namespace Postvak\Mail;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Postvak\Crypto;
use Postvak\Http\HttpException;

/**
 * Builds messages with PHPMailer: sends them via the account's SMTP server,
 * or produces the raw message for a draft (stored in the Drafts folder via IMAP).
 */
final class SmtpService
{
    /** Header that identifies a draft, so its UID can be found after APPEND. */
    public const DRAFT_HEADER = 'X-Postvak-Draft';

    /** Header in a draft: base64(folder) + ":" + uid of the message it answers. */
    public const REPLY_HEADER = 'X-Postvak-Reply';

    /** Stand-in recipient for drafts without one; its header is removed again. */
    private const PLACEHOLDER = 'draft@postvak.invalid';

    /** Remove a header (incl. folded continuation lines) from the header block of a raw message. */
    public static function removeHeader(string $raw, string $name): string
    {
        $pos = strpos($raw, "\r\n\r\n");
        if ($pos === false) {
            return $raw;
        }
        $head = substr($raw, 0, $pos);
        // [^\r\n]* rather than .*: "." also matches "\r", which would leave folded lines behind.
        $head = preg_replace('/^' . preg_quote($name, '/') . ':[^\r\n]*(?:\r?\n[ \t][^\r\n]*)*(?:\r?\n|$)/mi', '', $head) ?? $head;
        return rtrim($head, "\r\n") . substr($raw, $pos);
    }

    /**
     * @param array{
     *   to: array<int, array{email:string, name?:string}>,
     *   cc?: array<int, array{email:string, name?:string}>,
     *   bcc?: array<int, array{email:string, name?:string}>,
     *   subject: string,
     *   html: string,
     *   in_reply_to?: ?string,
     *   references?: ?string,
     * } $mail
     * @param array<int, array{name:string, mime:string, content:string}> $attachments
     * @return string raw RFC 822 message as sent
     */
    public static function send(array $account, array $mail, array $attachments = []): string
    {
        $s = ServerSettings::fromAccount($account);
        $m = self::build($account, $mail, $attachments);

        try {
            $m->isSMTP();
            $m->Host = $s->smtpHost;
            $m->Port = $s->smtpPort;
            $m->SMTPAuth = true;
            $m->Username = $s->username;
            $m->Password = Crypto::decrypt($account['password_enc']);
            $m->SMTPSecure = match ($s->smtpEncryption) {
                'ssl'  => PHPMailer::ENCRYPTION_SMTPS,
                'tls'  => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $m->SMTPAutoTLS = $s->smtpEncryption !== 'none';
            $m->Timeout = 20;
            if (!$s->validateCert) {
                $m->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            }
            $m->send();
            return $m->getSentMIMEMessage();
        } catch (MailerException $e) {
            error_log('[postvak] SMTP error: ' . $m->ErrorInfo);
            throw HttpException::upstream('Sending failed: ' . $m->ErrorInfo);
        }
    }

    /**
     * Send an already built raw message (e.g. one that was scheduled) as it is.
     * Bcc and Postvak's own X- headers are removed and the Date is set to now.
     *
     * @param string[] $recipients envelope recipients (To, Cc and Bcc addresses)
     * @return string the raw message as sent (for the Sent folder)
     */
    public static function sendRaw(array $account, string $raw, array $recipients): string
    {
        foreach (['Bcc', self::DRAFT_HEADER, self::REPLY_HEADER, 'X-Postvak-Send-At'] as $h) {
            $raw = self::removeHeader($raw, $h);
        }
        $raw = self::removeHeader($raw, 'Date');
        $raw = 'Date: ' . date('r') . "\r\n" . $raw;
        $raw = preg_replace("/\r?\n/", "\r\n", $raw) ?? $raw;

        $s = ServerSettings::fromAccount($account);
        $smtp = new SMTP();
        $smtp->Timeout = 20;
        $smtp->do_verp = false;
        $options = $s->validateCert ? [] : ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $host = ($s->smtpEncryption === 'ssl' ? 'ssl://' : '') . $s->smtpHost;
        $fail = static function (string $step) use ($smtp): never {
            $err = $smtp->getError();
            $smtp->close();
            throw HttpException::upstream("Sending failed ($step): " . trim(($err['error'] ?? '') . ' ' . ($err['smtp_code'] ?? '') . ' ' . ($err['detail'] ?? '')));
        };
        if (!$smtp->connect($host, $s->smtpPort, 20, $options)) {
            $fail('connect');
        }
        $helo = parse_url((string) \Postvak\Config::get('app_url', ''), PHP_URL_HOST) ?: 'localhost';
        if (!$smtp->hello($helo)) {
            $fail('hello');
        }
        if ($s->smtpEncryption === 'tls') {
            if (!$smtp->startTLS() || !$smtp->hello($helo)) {
                $fail('starttls');
            }
        }
        if (!$smtp->authenticate($s->username, Crypto::decrypt($account['password_enc']))) {
            $fail('login');
        }
        if (!$smtp->mail($account['email'])) {
            $fail('mail from');
        }
        foreach (array_unique($recipients) as $to) {
            if (!$smtp->recipient($to)) {
                $fail('recipient ' . $to);
            }
        }
        if (!$smtp->data($raw)) {
            $fail('data');
        }
        $smtp->quit();
        $smtp->close();
        return $raw;
    }

    /**
     * Raw message for the Drafts folder (not sent). Keeps Bcc, which a sent message hides.
     *
     * @return string raw RFC 822 message
     */
    public static function buildDraft(array $account, array $mail, array $attachments, string $draftId): string
    {
        // PHPMailer refuses a message without recipients; a draft may not have any yet.
        $noRecipients = ($mail['to'] ?? []) === [] && ($mail['cc'] ?? []) === [] && ($mail['bcc'] ?? []) === [];
        if ($noRecipients) {
            $mail['to'] = [['email' => self::PLACEHOLDER, 'name' => '']];
        }
        $m = self::build($account, $mail, $attachments, allowEmpty: true);
        try {
            $m->addCustomHeader(self::DRAFT_HEADER, $draftId);
            $m->Mailer = 'mail'; // only to make PHPMailer include the Bcc header; nothing is sent
            $m->preSend();
            $raw = $m->getSentMIMEMessage();
            return $noRecipients ? self::removeHeader($raw, 'To') : $raw;
        } catch (MailerException $e) {
            throw HttpException::badRequest('Could not save draft: ' . $m->ErrorInfo);
        }
    }

    private static function build(array $account, array $mail, array $attachments, bool $allowEmpty = false): PHPMailer
    {
        $m = new PHPMailer(true);
        try {
            $m->CharSet = PHPMailer::CHARSET_UTF8;
            $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $m->XMailer = 'Postvak';
            $m->AllowEmpty = $allowEmpty;

            $m->setFrom($account['email'], (string) ($account['display_name'] ?? ''));
            foreach ($mail['to'] as $r)          { $m->addAddress($r['email'], $r['name'] ?? ''); }
            foreach ($mail['cc'] ?? [] as $r)    { $m->addCC($r['email'], $r['name'] ?? ''); }
            foreach ($mail['bcc'] ?? [] as $r)   { $m->addBCC($r['email'], $r['name'] ?? ''); }

            if (!empty($mail['in_reply_to'])) {
                $m->addCustomHeader('In-Reply-To', $mail['in_reply_to']);
            }
            if (!empty($mail['references'])) {
                $m->addCustomHeader('References', $mail['references']);
            }
            // Drafts remember which message they answer, so "answered" can be set when sent later.
            if (!empty($mail['reply_ref'])) {
                $m->addCustomHeader(self::REPLY_HEADER, $mail['reply_ref']);
            }

            $m->isHTML(true);
            $m->Subject = $mail['subject'];
            $m->Body = $mail['html'] !== '' ? $mail['html'] : ($allowEmpty ? ' ' : '');
            $m->AltBody = self::htmlToText($mail['html']);

            foreach ($attachments as $a) {
                $m->addStringAttachment($a['content'], $a['name'], PHPMailer::ENCODING_BASE64, $a['mime']);
            }
        } catch (MailerException $e) {
            throw HttpException::badRequest($m->ErrorInfo ?: $e->getMessage());
        }
        return $m;
    }

    private static function htmlToText(string $html): string
    {
        $text = preg_replace(['~<br\s*/?>~i', '~</p>~i', '~</div>~i'], "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
