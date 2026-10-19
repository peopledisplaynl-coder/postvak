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
- Sessions live in `storage/sessions` (shared hosts clean their common session folder after minutes).
  "Stay logged in" (default on): persistent cookie for `session.lifetime`, renewed daily while in use.
  Off: browser-session cookie and an 8-hour idle limit. `cron.php` removes expired session files.
- Own IMAP/SMTP servers at a first login only when `login_custom_servers` is on (config or admin page):
  otherwise anyone could pick a server that accepts any password for an unused address of an allowed domain.
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

## App updates (PWA)

- The service worker (vite-plugin-pwa, `registerType: 'prompt'`) is registered in `frontend/src/appUpdate.js`. Installed
  apps rarely restart (iOS home screen), so it calls `registration.update()` when the app becomes visible and every
  30 minutes. A waiting version is activated at once (skip waiting + reload) unless a composer is open; then a toast
  offers "Refresh". `index.html` and `sw.js` are served with `Cache-Control: no-cache`.

## Background jobs and push notifications

- `backend/cron.php` (CLI) runs `Cron\CronRunner` every few minutes (host's cron / Plesk scheduled
  task). A lock file in `storage/` prevents overlapping runs; the last run is stored in `app_settings`
  (`cron_last_run`) and shown on the admin and settings pages.
  Log lines go to `storage/logs/cron.log` (rotated at 1 MB), not to the output, because Plesk e-mails
  whatever a cron job writes to stderr; only an uncaught failure is printed.
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

## Calendar and tasks (CalDAV)

- `sabre/dav` serves CalDAV at `<app_url>/dav/` (`public/api/dav.php`, rewritten by `.htaccess`; `/.well-known/caldav`
  redirects there). Phones, Thunderbird and macOS sync calendars (VEVENT) and task lists (VTODO) both ways.
- Storage is sabre's own MySQL schema with a `dav_` prefix (migration 009). The web interface uses the same data
  through `Calendar\CalendarService`, which reads and writes iCalendar via `sabre/vobject`, so anything made in
  Postvak looks native on a phone and the other way round. Recurring events are expanded per requested period;
  the editor changes the whole series, deleting can drop one occurrence (EXDATE).
- Principals are `principals/<login email>`. CalDAV logins use **app passwords** (`app_passwords`, HMAC with
  `app_key`, shown once): the mailbox password is never given to a calendar app, 2FA keeps working, and a lost
  device is revoked on its own. Failed CalDAV logins count towards the normal login rate limit.
- Every user gets a calendar "Agenda" and a task list "Taken" (English names for English users) on first use.
- Reminders (VALARM) become push notifications: `Cron\AlarmNotifier` runs from `cron.php`, looks a few minutes
  ahead and remembers what it sent (`calendar_alarms_sent`). This also works for items made on a phone.
- **Calendars from elsewhere** (`Calendar\FeedService`, table `calendar_feeds`): an iCal address (Google's secret
  address, Outlook, holidays) is fetched by cron every 30 minutes into a calendar instance with access 2 (read):
  read-only for the web UI and for CalDAV clients. One calendar object per UID (`feed-<sha1>.ics`); only changed
  objects are written, so phones sync differences. VALARMs are dropped. Downloads go through `Http\Fetcher`
  (http/https only, public hosts only, redirects checked hop by hop, 10 MB limit, conditional GET with ETag).
  The address is stored encrypted and shown masked.
- **Share links** (`Calendar\ShareService`, table `calendar_shares`): `GET /api/ical/<token>.ics` serves one calendar
  as a single iCalendar file without reminders, for Google "From URL" / Outlook. The token (24 random bytes) is the
  only credential; stored encrypted (so the link can be shown again) and as SHA-256 for lookup.
- **Invitations** (`Calendar\InvitationService`): a `text/calendar` / `.ics` part with METHOD REQUEST, CANCEL or PUBLISH
  becomes `invitation` in the message response. Answering stores the event (with the user's PARTSTAT) in the calendar,
  or removes it when declined/cancelled, and sends an iMIP REPLY to the organizer (PHPMailer `Ical`, 8-bit).
- "Make a task" in the reader stores a link back to the message (`X-POSTVAK-MAIL`: account, folder, UID, subject,
  sender), so the task can open the mail again as long as it was not moved.

## Labels, appearance, signature images

- **Labels** are IMAP keywords. The defaults are Thunderbird's `$label1` … `$label5` (Important, Work, Personal,
  To do, Later), so they are shared with Thunderbird; own labels get a keyword `pv_<name>`. Definitions (name, colour)
  are a user setting (`labels`). Summaries carry `labels` (keywords without system ones such as `$Forwarded`); the
  reader fetches them with a raw FLAGS (the IMAP library drops keywords). `?label=` filters a folder list with
  `SEARCH KEYWORD`. Rules can set a label.
- **Appearance**: settings `accent` (8 colours, CSS variables per `html[data-accent]`, light and dark) and `text_size`
  (html font size, so everything in rem scales).
- **Signature images** (`signature_images`, migration 012): the browser scales a logo to at most 360 × 180 and uploads
  it; the signature refers to `/api/signature-images/<token>` (only for its owner). `SmtpService` turns those into
  inline `cid:` attachments when a mail is built, so recipients see the logo without loading remote images.

## Rules, out-of-office replies, templates

- Shared hosting offers no delivery hooks and often no ManageSieve, so rules and out-of-office replies run in
  Postvak itself: `Mail\MailProcessor` (from cron, before the new-mail notifier) looks at what arrived in each
  INBOX since the last run (`mail_processing`: uidvalidity + last uid; the first run only sets the starting point).
- `Mail\RuleEngine` is pure logic: conditions on sender, recipient (To/Cc), subject or any of those (contains, does
  not contain, is, starts with, ends with; all or any), actions move / trash / mark read / star. Rules are tried in
  order; flags add up, the first move or trash wins. "Apply now" runs them over the last 500 inbox messages.
- Snoozed mail that returns to the inbox is skipped by rules (`MailProcessor::skipReturned`).
- `Mail\AutoReply` follows RFC 3834: no reply to mailing lists (List-*), bulk/junk precedence, Auto-Submitted mail,
  no-reply/daemon addresses, your own addresses, mail not addressed to the account (To/Cc), or mail older than two
  days; at most one reply per sender per 4 days (`autoreply_log`, sender hashed). Replies carry
  `Auto-Submitted: auto-replied`.
- Templates (`mail_templates`) are HTML snippets per user, inserted at the cursor in the composer.

## Contacts over CardDAV

- `Dav\AddressBookBackend` serves one address book "Postvak" per user (`addressbooks/<login>/contacts/`) straight from
  the `contacts` table: saved contacts are cards, collected addresses are not. Sync tokens come from `contact_changes`
  (one row per change, the token is the highest id); every write in `ContactRepository` logs a change.
- A card a phone sends is kept whole in `contacts.vcard`, so photos, birthdays and addresses survive. Postvak takes
  the name, first e-mail address (others go to `extra_emails`), first phone number and note from it; edits in Postvak
  change only those properties inside the stored card (`Contacts\ContactCards::apply`). A card without an e-mail
  address is allowed (`email` is nullable). Cards made in Postvak are named `pv-<id>.vcf`, a phone's own cards keep
  their name (`card_uri`).
- Deleting a card on a phone keeps an address you send to as a collected one (for autocomplete).

## API

All endpoints are under `/api`, JSON in and out. Folder paths go in `?folder=` because they can contain
`/` or `.`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/auth/login` | `{email, password, imap_host?, …}` → session, accounts, settings, csrf |
| POST | `/auth/logout` | |
| GET | `/auth/me` | Current session (used at startup) |
| GET | `/auth/options` | What the login page shows (`custom_servers`); no session needed |
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
| GET / POST | `/calendars` | Calendars and task lists of the user (with `feed` and `share_url`) / create `{name, color, type: events\|tasks}` |
| PATCH / DELETE | `/calendars/{id}` | Rename or recolour `{name?, color?}` / delete with all items (not the last one) |
| GET | `/events?start=&end=&tz=&calendars=` | Occurrences in the period (recurring events expanded), times in `tz` |
| GET / PUT / DELETE | `/events/{id}` | One event (series) with editor fields / save / delete (`?occurrence=<timestamp>`: only that one) |
| POST | `/events` | `{calendar_id, title, start, end, all_day, location?, notes?, reminder?, repeat?, until?}` |
| GET / POST | `/tasks?completed=0` | Tasks of all lists / create `{list_id, title, due?, due_time?, remind?, priority?, notes?, mail?}` |
| PATCH / DELETE | `/tasks/{id}` | Change only the fields sent (e.g. `{completed: true}`) / delete |
| GET | `/dav` | CalDAV server address, user name and the user's app passwords |
| POST / DELETE | `/dav/passwords`, `/dav/passwords/{id}` | Create an app password `{name}` (returned once) / revoke |
| POST | `/calendar-feeds` | Add a calendar from elsewhere `{url, name?, color?}`: downloads it at once (400 with the reason when it does not work) |
| POST | `/calendar-feeds/{id}/refresh` | Fetch now |
| DELETE | `/calendar-feeds/{id}` | Stop and remove (also `DELETE /calendars/{id}` of its calendar) |
| POST / DELETE | `/calendars/{id}/share` | Make (or replace) / revoke the secret .ics link of a calendar; `GET /calendars` returns it as `share_url` |
| GET | `/ical/{token}.ics` | Public: the shared calendar as iCalendar |
| POST | `/accounts/{id}/messages/{uid}/invitation` | `{folder, response: accepted\|tentative\|declined\|remove, reply?, calendar_id?}`; the message itself returns `invitation` (method, title, times, organizer, `in_calendar`, `calendar_status`) |
| GET / POST | `/rules` | Rules of the user / create `{name, account_id?, enabled, match_all, conditions: [{field, op, value}], actions: {move?, trash?, read?, flag?}}` |
| PUT / PATCH / DELETE | `/rules/{id}` | Replace / `{enabled}` only / remove |
| POST | `/rules/order` | `{ids}` in the new order |
| POST | `/rules/apply` | `{rule_id?}`: run on the last 500 inbox messages now → `{messages, matched}` |
| GET | `/autoreplies` | Out-of-office settings per account (with `active`) |
| PUT | `/autoreplies/{accountId}` | `{enabled, starts_on?, ends_on?, subject, body, contacts_only}` |
| GET / POST | `/templates` | Reply templates / create `{name, subject?, html}` |
| PUT / DELETE | `/templates/{id}` | Change / remove |
| POST | `/accounts/{id}/labels` | `{folder, uids, add?: [keyword], remove?: [keyword]}`; list endpoints take `label=` |
| POST | `/signature-images` | multipart `file` (PNG/JPEG/GIF/WebP, max 300 kB) → `{url}` |
| GET | `/signature-images/{token}` | The image (owner only) |
| GET | `/health` | Version check |

## Admin

Admins are listed in `config.php` (`'admins' => ['you@example.com']`); when the list is empty the first
user (id 1) is the admin. Being admin is never stored in the database, so nobody can promote themselves
through the app. Blocked users are logged out on their next request.

## Database

See `backend/database/schema.sql`. Schema changes go into `backend/database/migrations/NNN_name.sql`
and are recorded in `schema_migrations`. There is no command line on shared hosting, so pending
migrations run automatically on the first request after a new version (`Migrator::ensureCurrent`, a marker file `storage/.schema-<version>`), at login and from cron, and can be started from the admin page. A failure is shown on the admin page.

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
  `frontend/package.json`, `POSTVAK_VERSION` in `bootstrap.php` and the tag must match.
- The installer only works while `app/config/config.php` does not exist. It imports `schema.sql` (which registers all
  migrations as applied) and writes the config with a new `app_key`; later versions update the schema through the
  migrations.
