<?php
declare(strict_types=1);

namespace Postvak\Mail;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Postvak\Crypto;
use Postvak\Http\HttpException;

/** Sends mail via the account's SMTP server and returns the raw message (for the Sent folder). */
final class SmtpService
{
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
        $m = new PHPMailer(true);

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
            $m->CharSet = PHPMailer::CHARSET_UTF8;
            $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $m->XMailer = 'Postvak';

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

            $m->isHTML(true);
            $m->Subject = $mail['subject'];
            $m->Body = $mail['html'];
            $m->AltBody = self::htmlToText($mail['html']);

            foreach ($attachments as $a) {
                $m->addStringAttachment($a['content'], $a['name'], PHPMailer::ENCODING_BASE64, $a['mime']);
            }

            $m->send();
            return $m->getSentMIMEMessage();
        } catch (MailerException $e) {
            error_log('[postvak] SMTP error: ' . $m->ErrorInfo);
            throw HttpException::upstream('Sending failed: ' . $m->ErrorInfo);
        }
    }

    private static function htmlToText(string $html): string
    {
        $text = preg_replace(['~<br\s*/?>~i', '~</p>~i', '~</div>~i'], "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
