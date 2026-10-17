<?php
declare(strict_types=1);

namespace Postvak\Mail;

use Postvak\Config;
use Postvak\Http\HttpException;

/** IMAP + SMTP connection details for one mailbox. */
final class ServerSettings
{
    private const ENCRYPTIONS = ['ssl', 'tls', 'none'];

    public function __construct(
        public string $imapHost,
        public int $imapPort,
        public string $imapEncryption,
        public string $smtpHost,
        public int $smtpPort,
        public string $smtpEncryption,
        public string $username,
        public bool $validateCert = true,
    ) {}

    /** Defaults from config, with optional overrides from user input. */
    public static function forEmail(string $email, array $overrides = []): self
    {
        $domain = substr(strrchr($email, '@') ?: '@', 1);
        $d = (array) Config::get('default_servers', []);
        $fill = static fn (string $v) => str_replace('{domain}', $domain, $v);

        $s = new self(
            imapHost: $fill((string) ($d['imap_host'] ?? 'mail.{domain}')),
            imapPort: (int) ($d['imap_port'] ?? 993),
            imapEncryption: (string) ($d['imap_encryption'] ?? 'ssl'),
            smtpHost: $fill((string) ($d['smtp_host'] ?? 'mail.{domain}')),
            smtpPort: (int) ($d['smtp_port'] ?? 465),
            smtpEncryption: (string) ($d['smtp_encryption'] ?? 'ssl'),
            username: $email,
            validateCert: (bool) ($d['validate_cert'] ?? true),
        );

        foreach (['imap_host' => 'imapHost', 'smtp_host' => 'smtpHost', 'username' => 'username'] as $in => $prop) {
            if (!empty($overrides[$in]) && is_string($overrides[$in])) {
                $s->$prop = trim($overrides[$in]);
            }
        }
        foreach (['imap_port' => 'imapPort', 'smtp_port' => 'smtpPort'] as $in => $prop) {
            if (!empty($overrides[$in])) {
                $port = (int) $overrides[$in];
                if ($port < 1 || $port > 65535) {
                    throw HttpException::badRequest('Invalid port');
                }
                $s->$prop = $port;
            }
        }
        foreach (['imap_encryption' => 'imapEncryption', 'smtp_encryption' => 'smtpEncryption'] as $in => $prop) {
            if (!empty($overrides[$in])) {
                if (!in_array($overrides[$in], self::ENCRYPTIONS, true)) {
                    throw HttpException::badRequest('Invalid encryption');
                }
                $s->$prop = $overrides[$in];
            }
        }
        $s->assertSafeHosts();
        return $s;
    }

    public static function fromAccount(array $row): self
    {
        return new self(
            imapHost: $row['imap_host'],
            imapPort: (int) $row['imap_port'],
            imapEncryption: $row['imap_encryption'],
            smtpHost: $row['smtp_host'],
            smtpPort: (int) $row['smtp_port'],
            smtpEncryption: $row['smtp_encryption'],
            username: $row['username'],
            validateCert: (bool) Config::get('default_servers.validate_cert', true),
        );
    }

    /**
     * Prevent the server from being used to probe internal hosts
     * (e.g. imap_host = 127.0.0.1 or a private network address).
     */
    public function assertSafeHosts(): void
    {
        foreach ([$this->imapHost, $this->smtpHost] as $host) {
            if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
                throw HttpException::badRequest('Invalid server name');
            }
            $ips = gethostbynamel($host) ?: [];
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw HttpException::badRequest('Server address not allowed');
                }
            }
        }
    }
}
