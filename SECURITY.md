# Security

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting
("Security" tab → "Report a vulnerability") so the problem can be fixed before it is disclosed.

## Threat model in short

Webmail handles mailbox passwords and untrusted HTML from strangers, so both get special care.

**Credentials**
- Postvak has no passwords of its own; login is checked against the IMAP server.
- Mailbox passwords are stored encrypted with libsodium (`secretbox`, XSalsa20-Poly1305) using
  `app_key` from `config.php`. Someone with both the database **and** `config.php` can decrypt them.
  Keep `config.php` outside the web root and restrict who has FTP access.
- Planned hardening (roadmap): wrap a per-user key with a key derived from the login password, so a
  stolen database plus config is not enough.

**Two-factor authentication (optional, per user)**
- TOTP (RFC 6238, SHA-1/30 s/6 digits); the secret is encrypted with `app_key`, each time step is
  accepted once (no replay), one step of clock drift either way.
- After the mailbox password is verified, a user with 2FA only gets a "pending" session (5 minutes,
  5 tries; wrong codes also count for the login rate limiter). The real session starts after the code.
- 10 single-use recovery codes (stored as HMAC with `app_key`); "remember this device" sets a random
  `HttpOnly`/`SameSite=Strict` cookie for 30 days (stored hashed, revocable per user).
- An admin can switch 2FA off for a user. Locked out yourself as the only admin: run
  `UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_recovery = NULL WHERE login_email = '…';`
  in phpMyAdmin.

**Sessions**
- Cookie: `HttpOnly`, `Secure`, `SameSite=Strict`; session id regenerated at login.
- CSRF token required on every state-changing request (`X-CSRF-Token`).
- Failed logins are rate limited per IP and per address.

**Mail content**
- HTML is sanitised server-side (HTMLPurifier) and client-side (DOMPurify), then rendered in a
  sandboxed iframe without `allow-scripts`.
- Remote images and CSS backgrounds are blocked by default (tracking pixels).
- Attachments are served as downloads, except images and PDF which may be shown inline;
  `X-Content-Type-Options: nosniff` is always set.

**Server**
- User-supplied IMAP/SMTP hosts are checked against private and reserved IP ranges to prevent the
  server being used to probe internal networks.
- `.htaccess` sets HSTS, a Content-Security-Policy, `frame-ancestors 'none'` and denies access to the
  application folder if it has to live inside the web root.
