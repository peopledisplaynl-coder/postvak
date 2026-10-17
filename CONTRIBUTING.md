# Contributing

Thanks for helping out. A few guidelines keep the project easy to work on.

## Setup

```bash
npm install && npm --prefix frontend install
npm run dev:mock     # UI with fake data, no PHP or mail server needed
```

For backend work you also need PHP 8.2+, Composer and a test mailbox; see the README.

## Principles

- **Shared hosting first.** Everything must run on plain PHP + MySQL behind Apache/LiteSpeed,
  deployable over FTP. No daemons, no required shell access, no Node.js on the server.
- **The mail server is the source of truth.** Do not copy mail into the database except as a
  cache that can be thrown away.
- **Safe rendering.** Any change that touches how mail HTML is shown needs extra review.
- **All IMAP access goes through `ImapService`**, so the library can be replaced.

## Code style

- PHP: PSR-12, `declare(strict_types=1)`, typed properties and return types.
- JS/Vue: `<script setup>`, Composition API, Pinia for shared state, Tailwind utility classes.
  Colours come from the tokens in `frontend/src/style.css`, not hard-coded hex values.
- UI text goes in `frontend/src/i18n/nl.js` (and other languages), never inline in components.
- Commit messages: short imperative subject ("Add unified inbox"), body explains why.

## Pull requests

1. Open an issue first for larger changes, so we can agree on the approach.
2. Keep PRs focused; one feature or fix per PR.
3. Describe how you tested it (mock mode, real mailbox, which devices).
4. Update `docs/` when behaviour or the API changes.
