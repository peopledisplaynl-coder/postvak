# Installing Postvak

This guide uses the release zip and the web installer. You need a hosting account with PHP 8.2+,
MySQL/MariaDB, HTTPS and FTP (or a file manager). Nothing has to be built on your own computer.

## 1. Prepare

1. Download `postvak-x.y.z.zip` from the latest GitHub release and unpack it. You get:
   - `public/`: the files for the web root
   - `app/`: the program code, database scripts and libraries
2. In your hosting control panel:
   - create the (sub)domain, for example `mail.example.com`, and turn on HTTPS (Let's Encrypt);
   - check that PHP is 8.2 or newer with the extensions `sodium`, `pdo_mysql`, `mbstring`, `openssl`, `iconv` and `dom`;
   - create an empty MySQL database with a user. Write down the host (usually `localhost`), database name, user and password.

## 2. Upload

Upload with your FTP program or the control panel's file manager:

- the **contents** of `public/` into the document root of the (sub)domain (for example `httpdocs/` or `public_html/mail/`).
  Make sure hidden files such as `.htaccess` are uploaded as well.
- the `app/` folder **next to** the document root, so it can't be opened from a browser. For example:

```
/home/you/
  httpdocs/        <- contents of public/ (index.html, api/, assets/, install.php, .htaccess)
  app/             <- the app folder
```

If `app/` ends up somewhere else, change the path in `public/api/app-path.php`
(it is relative to that file; the default is `__DIR__ . '/../../app'`).

The installer needs to write to `app/config/` and `app/storage/`. Most hosts allow that already;
otherwise set those folders to 755 or 775.

## 3. Run the installer

Open `https://mail.example.com/install.php`.

1. The server check must be green (orange items are recommended, not required).
2. Fill in the database details.
3. Check the address of Postvak and the mail server. `{domain}` is replaced by the part of the login address
   after the @, so `mail.{domain}` works for most hosts with several domains. Ports: IMAP 993, SMTP 465 (or 587).
4. Optional: the domains that may log in (recommended), and the admin address.
5. Click **Install**. The installer creates the tables, writes `app/config/config.php` and deletes itself.
   If it can't delete itself, delete `install.php` by hand.

**Back up `app/config/config.php`.** It contains `app_key`; without it the stored mailbox passwords can't be decrypted
and every user has to log in again.

## 4. Cron job

Notifications for new mail, calendar reminders, send later and snooze need a background task every 5 minutes:

- **Plesk:** Scheduled tasks → Add task → *Run a PHP script* → `app/cron.php`, same PHP version as the site, cron style `*/5 * * * *`.
- **cPanel / DirectAdmin:** `php /home/you/app/cron.php` every 5 minutes.
- **Only a URL possible?** Set a long random `cron_key` in `config.php` and call `https://mail.example.com/api/cron?key=THAT-KEY` every 5 minutes.

The admin page (Admin → Overview) shows when the job last ran.

## 5. Use it

Open `https://mail.example.com` and log in with a mailbox address and its password. On a phone, choose
"Add to home screen" (iPhone) or "Install app" (Android). Notifications are turned on per device under
Settings → Notifications.

### Calendar and contacts on your phone

Under **Settings → Calendar & tasks** you find the server address and your user name, and you create an app password
per device. Instructions for iPhone, Android (DAVx5), macOS and Thunderbird are on that page; contacts sync over the same server (CardDAV). If a phone can't find
the server, check that `.htaccess` from `public/` was uploaded: it passes the password on to PHP and points
`/.well-known/caldav` to `/dav/`.

## Updating

1. Download the new release.
2. Upload `public/` and `app/` over the existing files. **Don't** upload `install.php` (or delete it afterwards), and keep `app/config/config.php`.
3. Open Postvak: database changes are applied automatically on the first visit.

## Problems

| Message | Fix |
|---|---|
| `Postvak is not installed yet` | Open `/install.php`, or check that `app/config/config.php` exists. |
| Installer: "Application folder found" is red | `app/` is not where `public/api/app-path.php` points to. Move it or change that file. |
| `Could not connect to the mail server` | Check the IMAP/SMTP host on the admin page. Try the host's server name instead of `mail.<domain>`. |
| 404 on `/api/...` | `.htaccess` is missing or `mod_rewrite` is off. |
| Lost phone and recovery codes (2FA) | An admin can turn 2FA off under Admin → Users. Only admin yourself? In phpMyAdmin: `UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_recovery = NULL WHERE login_email = 'you@example.com';` |
