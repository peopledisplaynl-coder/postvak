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
| GET | `/accounts/{id}/messages?folder=&page=&per_page=&q=&sort=` | Message summaries; `sort` = `date_desc` (default), `date_asc`, `from`, `subject`, `size_desc`. Sorted by the server (IMAP SORT), searched in from/to/subject |
| GET | `/accounts/{id}/messages/{uid}?folder=&images=1` | Full message, sanitised |
| GET | `/accounts/{id}/messages/{uid}/attachments/{index}?folder=` | Download |
| POST | `/accounts/{id}/move` | `{folder, uids, target: archive\|trash\|junk\|inbox\|<path>}` |
| POST | `/accounts/{id}/flags` | `{folder, uids, seen?, flagged?, answered?}` |
| POST | `/accounts/{id}/send` | multipart: `payload` (JSON) + `files[]` |
| GET | `/contacts?q=` | Address autocomplete |
| GET | `/health` | Version check |

## Database

See `backend/database/schema.sql`. Schema changes go into `backend/database/migrations/NNN_name.sql`
and are recorded in `schema_migrations`.
