# Architecture

## Overview

Postvak is a thin, stateless PHP API in front of an IMAP/SMTP server, plus a single-page app.
The mail itself always stays on the mail server; the database only holds what IMAP cannot:
users, linked accounts, preferences and contacts.

```
┌──────────────────────┐   JSON over HTTPS   ┌──────────────────────┐   IMAP (993) / SMTP (465)
│ Vue 3 PWA            │ ──────────────────► │ PHP API (api/index)  │ ─────────────────────────► mail server
│ Pinia stores         │ ◄────────────────── │ Controllers          │
│ swipe, undo, polling │   session cookie    │ ImapService / Smtp   │
└──────────────────────┘   + CSRF header     │ MessageSanitizer     │
                                             └─────────┬────────────┘
                                                       │ PDO
                                                   ┌───▼───┐
                                                   │ MySQL │
                                                   └───────┘
```

## Why these choices

| Choice | Reason |
|---|---|
| PHP API | Runs on every shared host, no long-running process needed. |
| webklex/php-imap (native mode) | Pure-PHP IMAP; `ext-imap` was removed from PHP 8.4. Wrapped in `ImapService` so it can be replaced. |
| Vue 3 + Vite | Built locally into static files; the server only serves them. |
| Tailwind CSS 4 with CSS variables | Light/dark themes swap a handful of tokens (`src/style.css`) instead of `dark:` variants everywhere. |
| PWA | Installable on phone/tablet home screens, own icon, no app store. |
| Polling instead of IMAP IDLE | Shared hosting cannot keep sockets open. Default 60 s, configurable per user. |

## Request flow

1. `public/.htaccess` sends `/api/*` to `public/api/index.php`, everything else to `index.html`.
2. `index.php` loads `bootstrap.php` (autoloader, config, error handler), starts the session,
   checks the CSRF header for state-changing requests and dispatches through `routes.php`.
3. Mail controllers load the account (scoped to the logged-in user), decrypt its password,
   open an IMAP connection for this request only, and close it at the end.
4. Every response is valid JSON: stray output is buffered and discarded, library deprecation
   notices are only logged, and fatal errors (timeouts, memory) are turned into a JSON error by a
   shutdown handler. The frontend calls the API with an absolute path (`/api/...`).
5. `session_write_close()` is called before IMAP work so parallel requests from the same user
   (folder counts + message list) do not block on the session lock.

## Authentication

- Login = IMAP login. The first successful login creates a `users` row and a login account.
- Additional accounts are verified against their IMAP server before they are stored.
- Passwords are stored with `sodium_crypto_secretbox` using `app_key` from `config.php`.
- Session cookie: `HttpOnly`, `Secure`, `SameSite=Strict`. A CSRF token is returned at login
  and sent back as `X-CSRF-Token`.
- Failed logins are rate limited per IP and per address (`login_attempts`, hashed values only).

## Mail rendering

1. Server: `MessageSanitizer` rewrites `cid:` images to the attachment endpoint, runs
   HTMLPurifier (no scripts, forms, event handlers, iframes), and moves remote image URLs to
   `data-blocked-src` unless the user allowed images.
2. Browser: DOMPurify runs again, then the result is shown in an `<iframe srcdoc>` with
   `sandbox="allow-same-origin allow-popups"`, so no script can run even if something slipped through.

## Swipe and undo

- `useSwipe` (frontend/src/composables) handles touch/pen pointer events. It waits until a gesture is
  clearly horizontal, so vertical scrolling is unaffected; `touch-action: pan-y` lets the browser
  keep handling vertical scroll natively.
- Archive and delete are **deferred**: the row disappears immediately, the API call happens when the
  undo window ends (`undo_seconds`, default 5). Undo just puts the row back, so there is no need to
  track the new UID that IMAP assigns after a MOVE. Pending moves are flushed with `keepalive`
  requests when the page is closed.
- Special folders (archive, trash, sent, …) are detected by name (English and Dutch variants) unless
  set explicitly on the account. A missing archive folder is created on first use.

## Composer

- `RichEditor.vue` wraps Tiptap 3 (StarterKit, text colour, inline images). Paste goes through the
  ProseMirror schema, so Word/web markup is reduced to plain structure. Pasted image files become
  attachments instead of inline data URLs.
- The quoted original of a reply/forward is **not** put in the editor (the schema would drop tables and
  layout). It is shown as a read-only preview (sandboxed iframe) and appended on save/send inside
  `<div class="postvak-quote">`. When a draft is reopened, the composer splits on that marker
  (drafts from before 0.5.1: trailing header `<div>` + `<blockquote>`).
- `ComposeSheet` is loaded on demand (`defineAsyncComponent`), so the editor code is not part of the
  initial bundle.

## Background jobs and push notifications

- `backend/cron.php` (CLI) runs `Cron\CronRunner` every few minutes (host's cron / Plesk scheduled
  task). A lock file in `storage/` prevents overlapping runs; the last run is stored in `app_settings`
  (`cron_last_run`) and shown on the admin and settings pages.
- `Cron\NewMailNotifier`: for users with push subscriptions, one `SELECT INBOX` per account
  (`notify = 1`) gives UIDNEXT. New UIDs above `accounts.notify_last_uid` that are unread are sent as
  one push (up to 3 messages, otherwise a summary). The first check only stores the starting point;
  a changed UIDVALIDITY resets it.
- Order of a cron run: scheduled sends, returning snoozes, then the new-mail check (so a snoozed
  message that comes back is announced like new mail).
- **Send later** (`Mail\Scheduler`): the message is built when scheduled (`buildDraft`, with a token
  header) and appended to the Scheduled folder; `scheduled_sends` holds the UTC time and the envelope
  (incl. Bcc). Due: fetch the raw message, strip Bcc/X-Postvak headers, set Date, send it unchanged
  with PHPMailer's SMTP class, store in Sent, mark the answered message. Failures retry after 10 min;
  after 3 attempts the message goes back to Drafts and the user gets a push notice.
- **Snooze** (`Mail\Snoozer`): messages move to the Snoozed folder; `snoozes` keeps Message-ID and
  UTC time (a MOVE changes the UID). Due: found by Message-ID, marked unread and moved to INBOX.
  List rows in these two folders carry `scheduled` / `snoozed_until`.
- `Push\WebPush` implements VAPID (RFC 8292, ES256) and `aes128gcm` payload encryption (RFC 8291)
  with ext-openssl only — no Composer dependency. The VAPID key pair is generated on first use and
  stored in `app_settings` (private key encrypted with the app key). Push services answering 404/410
  remove the subscription.
- `public/push-sw.js` is imported into the generated service worker. It shows notifications only when
  no Postvak window is focused, and tells open windows to refresh the list. A click focuses Postvak
  and opens the message.

## API

All endpoints are under `/api`, JSON in and out. Folder paths go in `?folder=` because they can contain
`/` or `.`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/auth/login` | `{email, password, imap_host?, …}` → session, accounts, settings, csrf |
| POST | `/auth/logout` | |
| GET | `/auth/me` | Current session (used at startup) |
| GET / PATCH | `/settings` | User preferences |
| GET / POST | `/accounts` | List / add account (verified first) |
| PATCH / DELETE | `/accounts/{id}` | Edit (name, colour, signature, folders, server, password) / remove |
| GET | `/accounts/{id}/folders` | Folders with unseen/total counts and detected role |
| POST / PATCH / DELETE | `/accounts/{id}/folders` | Create `{name, parent?}`, rename `{path, name}`, delete `?path=` (not INBOX or special folders) |
| GET | `/accounts/{id}/messages?folder=&page=&per_page=&q=&sort=` | Message summaries incl. `snippet` (preview line, cached in `message_snippets`); `sort` = `date_desc` (default), `date_asc`, `from`, `subject`, `size_desc`. `threads=1` (with `date_desc`): one row per conversation via `UID THREAD REFS` (or `REFERENCES`); the row is the newest message plus `thread: {count, uids, unseen, flagged, participants}`. Response has `threaded: false` when the server cannot thread. Dates are UTC. Sorted by the server (IMAP SORT), searched in from/to/subject (`body=1`: also the message text) |
| GET | `/accounts/{id}/messages/{uid}?folder=&images=1` | Full message, sanitised |
| GET | `/accounts/{id}/messages/{uid}/conversation?folder=` | The conversation `uid` belongs to, oldest first: messages in this folder (IMAP `THREAD`) plus own replies found in Sent (by `In-Reply-To`/`References`) |
| GET | `/accounts/{id}/messages/{uid}/attachments/{index}?folder=` | Download |
| POST | `/accounts/{id}/folders/read` | `{path}`: mark everything in the folder as read |
| POST | `/accounts/{id}/folders/empty` | `{path}`: permanently delete everything in the trash or spam folder (other folders are refused) |
| POST | `/accounts/{id}/move` | `{folder, uids, target: archive\|trash\|junk\|inbox\|<path>}` (`junk` = mark as spam, `inbox` from spam = not spam), one `UID MOVE` for all uids |
| POST | `/accounts/{id}/flags` | `{folder, uids, seen?, flagged?, answered?}` |
| POST | `/accounts/{id}/send` | multipart: `payload` (JSON, incl. `draft_uid?`, `include_attachments?: {folder, uid, indexes}`) + `files[]`; deletes the draft after sending |
| POST | `/accounts/{id}/drafts` | multipart like `send` (recipients optional), `draft_uid?` = previous version: APPENDs the draft to the Drafts folder and removes the old copy; returns `{uid, folder, saved_at, attachments}` |
| DELETE | `/accounts/{id}/drafts/{uid}` | Discard a draft permanently |
| GET | `/unified/messages?page=&per_page=&q=&sort=&threads=` | Inboxes of all accounts merged; each summary carries `account_id`; failing accounts listed in `errors` |
| GET | `/push` | VAPID public key, number of registered devices, cron status (`last_run`, `ok` = ran in the last 30 min) |
| POST | `/push/subscriptions` | The browser's `PushSubscription.toJSON()` (`{endpoint, keys: {p256dh, auth}}`); HTTPS endpoints on public hosts only |
| POST | `/push/unsubscribe` | `{endpoint}` |
| POST | `/push/test` | `{endpoint}`: send a test notification to this device now |
| GET | `/cron?key=` | Run the background jobs (only when `cron_key` is set in config.php; normally use `cron.php`) |
| POST | `/accounts/{id}/scheduled` | Send later: multipart like `send` plus `payload.send_at` (ISO 8601). Built now, stored in the Scheduled folder ("Gepland") |
| POST | `/scheduled/{id}/cancel` | Back to Drafts; returns `{account_id, folder, uid}` of the draft |
| POST | `/scheduled/{id}/send` | Send now |
| POST | `/accounts/{id}/snooze` | `{folder, uids, until}`: move to the Snoozed folder ("Gesnoozed"); from that folder only the time changes |
| POST | `/accounts/{id}/unsnooze` | `{uids}` in the Snoozed folder: back to the inbox now |
| POST | `/auth/2fa` | Second login step `{code, remember?}` after `/auth/login` answered `{two_factor: true}` (code from the app or a recovery code) |
| GET | `/security` | 2FA status, recovery codes left, remembered devices |
| POST | `/security/2fa/setup` | New secret + `otpauth://` URI (kept in the session until confirmed) |
| POST | `/security/2fa/enable` | `{code}`: switch on; returns 10 recovery codes once and remembers this device |
| POST | `/security/2fa/disable` / `/security/2fa/recovery` | `{code}`: switch off / new recovery codes |
| POST | `/security/devices/forget` | Forget all remembered devices |
| POST | `/admin/users/{id}/reset-2fa` | Admin: switch 2FA off for a user |
| GET | `/contacts?q=` | Address autocomplete (favourites and saved contacts first) |
| GET | `/contacts/list?q=&saved=1` | Contacts page; without `saved=1` also the addresses collected automatically when sending |
| POST | `/contacts` | `{email, name?, phone?, notes?, favorite?}`; an automatically collected address becomes a saved contact, a saved one gives 409 |
| PATCH / DELETE | `/contacts/{id}` | Edit (same fields) / remove |
| POST | `/contacts/import` | multipart `file` (.vcf, vCard 2.1/3.0/4.0, max 5 MB): one contact per e-mail address; existing data is never overwritten. Returns `{cards, added, updated, skipped}` |
| GET | `/contacts/export?all=1` | vCard 3.0 download of the saved contacts (`all=1`: also the collected ones) |
| GET / POST | `/trusted-senders` | Safe senders: `{kind: email\|domain, value}`; remote images always shown |
| DELETE | `/trusted-senders/{id}` | Remove a safe sender |
| GET | `/admin/overview` | Admin: version, PHP, extensions, limits, migrations, mail server reachability, stats |
| POST | `/admin/migrate` | Admin: apply pending database updates |
| GET | `/admin/users` · PATCH / DELETE `/admin/users/{id}` | Admin: list, block/unblock (`{blocked}`), remove users |
| GET | `/admin/security` · POST `/admin/security/release` | Admin: login attempts, current blocks, release (`{ip_key}` / `{email_key}`) |
| GET / PATCH | `/admin/settings` | Admin: allowed login domains, default user preferences |
| GET | `/health` | Version check |

## Admin

Admins are listed in `config.php` (`'admins' => ['you@example.com']`); when the list is empty the first
user (id 1) is the admin. Being admin is never stored in the database, so nobody can promote themselves
through the app. Blocked users are logged out on their next request.

## Database

See `backend/database/schema.sql`. Schema changes go into `backend/database/migrations/NNN_name.sql`
and are recorded in `schema_migrations`. There is no command line on shared hosting, so pending
migrations run automatically at login and can be started from the admin page (`Migrator`).

## Tests and releases

- `backend/tests/run.php`: a small test runner without PHPUnit (`test()` + assert helpers); covers TOTP, vCard,
  snippets, Web Push encryption, header handling and core helpers. No database or mail server needed.
- `frontend/src/**/*.test.js`: Vitest unit tests (formatting, translations, mail store).
- `frontend/e2e/`: Playwright tests against the demo mode (`vite --mode mock`), desktop and phone.
- `scripts/check-i18n.mjs`: every key and placeholder exists in every language.
- CI (`.github/workflows/ci.yml`) runs all of the above on every push and pull request.
- `scripts/release.mjs` builds `dist/postvak-<version>.zip` with `public/` (web root, including `backend/installer/install.php`)
  and `app/` (code, `vendor` without dev packages, database scripts). `public/api/app-path.php` points to `../../app`.
  `.github/workflows/release.yml` runs it for every `v*` tag and attaches the zip to a GitHub release; the version in
  `frontend/package.json`, `POSTVAK_VERSION` in `public/api/index.php` and the tag must match.
- The installer only works while `app/config/config.php` does not exist. It imports `schema.sql` (which registers all
  migrations as applied) and writes the config with a new `app_key`; later versions update the schema through the
  migrations at login.
