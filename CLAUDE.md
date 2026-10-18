# Notes for Claude Code

Postvak: webmail for shared hosting. PHP 8.2 API (`backend/`) + Vue 3 SPA (`frontend/`).
Read `docs/ARCHITECTURE.md` and `docs/ROADMAP.md` before larger changes.

## Constraints

- Production host is shared hosting with **FTP only**: no SSH, no Node on the server, no long-running
  processes, no IMAP IDLE. Deploy with `npm run deploy`.
- Background work (push notifications, rules, out-of-office replies, scheduled sending, snooze, calendar feeds) runs only from `backend/cron.php`
  via the host's cron (`Cron\CronRunner`); never assume a daemon or queue worker.
- Calendar and tasks live in sabre/dav's tables (`dav_*`); the web UI goes through `Calendar\CalendarService`
  (iCalendar via sabre/vobject) so phones syncing over CalDAV (`/dav/`, app passwords) see the same data.
  Contacts sync over CardDAV from the `contacts` table (`Dav\AddressBookBackend`); every contact write must log a
  change via `Contacts\ContactCards` so phones pick it up.
- Anything fetched from a user-given URL goes through `Http\Fetcher` (no private hosts).
- `ext-imap` is not available; IMAP goes through webklex/php-imap in native mode, only via
  `backend/src/Mail/ImapService.php`.
- The decoder options in `ImapService::makeClient()` must stay on `mimeheader`
  (`utf-8` calls `imap_utf8()` from ext-imap).
- Interface languages: Dutch (`frontend/src/i18n/nl.js`, the reference) and English (`en.js`). Every UI string
  goes through `t()`; add new keys to both files and run `node scripts/check-i18n.mjs`. Dates use `locale()`.
  The only other UI strings are in `frontend/public/push-sw.js` (per language). Code and docs are English.

## Commands

- `npm run dev:mock`: UI with fake data (frontend/src/api/mock.js). Keep the mock in sync with API changes.
- `npm run dev:api` + `npm run dev`: full stack locally (needs backend/config/config.php).
- `npm run build`: builds into `backend/public`.
- Tests: `php backend/tests/run.php`, `npm --prefix frontend test`, `npm --prefix frontend run test:e2e` (demo mode).
- Release: bump `frontend/package.json` + `POSTVAK_VERSION` (backend/bootstrap.php), add a CHANGELOG entry, tag `vX.Y.Z` (CI builds the zip via `scripts/release.mjs`).
- Screenshots: start the demo on :5174, then `node scripts/screenshots.mjs`.
- PHP lint: `find backend -name '*.php' -not -path '*/vendor/*' -exec php -l {} \;`

## Conventions

- New API endpoint: route in `backend/routes.php`, method on a controller, document it in the API
  table in `docs/ARCHITECTURE.md`, add a mock handler.
- Schema change: new file in `backend/database/migrations/`, never edit `schema.sql` for existing installs
  (do update it for fresh installs; the installer imports it).
- New user setting: default + validation rule in `SettingsRepository`, UI in `SettingsView.vue`, text in `nl.js`.
- Colours only via the CSS tokens in `frontend/src/style.css`.
