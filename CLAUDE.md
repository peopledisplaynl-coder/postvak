# Notes for Claude Code

Postvak: webmail for shared hosting. PHP 8.2 API (`backend/`) + Vue 3 SPA (`frontend/`).
Read `docs/ARCHITECTURE.md` and `docs/ROADMAP.md` before larger changes.

## Constraints

- Production host is shared hosting with **FTP only**: no SSH, no Node on the server, no long-running
  processes, no IMAP IDLE. Deploy with `npm run deploy`.
- `ext-imap` is not available; IMAP goes through webklex/php-imap in native mode, only via
  `backend/src/Mail/ImapService.php`.
- The decoder options in `ImapService::makeClient()` must stay on `mimeheader`
  (`utf-8` calls `imap_utf8()` from ext-imap).
- Interface language is Dutch; all UI strings live in `frontend/src/i18n/nl.js`. Code and docs are English.

## Commands

- `npm run dev:mock`: UI with fake data (frontend/src/api/mock.js). Keep the mock in sync with API changes.
- `npm run dev:api` + `npm run dev`: full stack locally (needs backend/config/config.php).
- `npm run build`: builds into `backend/public`.
- PHP lint: `find backend -name '*.php' -not -path '*/vendor/*' -exec php -l {} \;`

## Conventions

- New API endpoint: route in `backend/routes.php`, method on a controller, document it in the API
  table in `docs/ARCHITECTURE.md`, add a mock handler.
- Schema change: new file in `backend/database/migrations/`, never edit `schema.sql` for existing installs
  (do update it for fresh installs).
- New user setting: default + validation rule in `SettingsRepository`, UI in `SettingsView.vue`, text in `nl.js`.
- Colours only via the CSS tokens in `frontend/src/style.css`.
