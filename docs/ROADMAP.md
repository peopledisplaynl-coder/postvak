# Roadmap

## Phase 1: working base (v0.1, this release)

- [x] Login with mailbox credentials, sessions, CSRF, login rate limiting
- [x] Multiple accounts per user, encrypted credentials
- [x] Folder list with unread counts, special-folder detection (EN/NL names)
- [x] Message list with infinite scroll, pull-to-refresh, search, polling
- [x] Swipe left/right with configurable actions and undo
- [x] Reader with sanitised HTML in a sandboxed iframe, blocked remote images, attachments
- [x] Compose, reply, reply all, forward, attachments, signatures, Sent copy via IMAP APPEND
- [x] Settings: theme, density, images, polling, swipe actions, undo time, accounts
- [x] Responsive layout (phone / tablet / desktop), dark mode, PWA, keyboard shortcuts
- [x] FTP deploy script, mock backend for UI development
- [x] First test run against a real Cloud86 mailbox

## Phase 1.5 (v0.2)

- [x] Safe senders: always show remote images from an address or a whole domain
- [x] Admin page: overview (system, database updates, mail server check), users (block/remove),
      security (login attempts, release blocks), settings (allowed domains, default preferences)
- [x] Database updates run automatically at login
- [x] Tested on a real mailbox at Cloud86 (Dovecot): folder namespace, special folders, sorting

## Phase 2: comfort

- [x] Preview snippets in the list (partial `BODY.PEEK[TEXT]<0.4096>` fetch, cached in MySQL)
- [ ] Unified inbox across all accounts (setting `unified_inbox` already exists)
- [ ] Conversation view (group by `References` / `In-Reply-To`)
- [ ] Drafts: autosave to the Drafts folder, edit existing drafts
- [ ] Rich text editor (e.g. Tiptap) instead of `execCommand`
- [x] Multi-select in the list (long press on mobile, avatar click on desktop) with bulk actions
- [x] Move to folder, create/rename/delete folders
- [ ] Contacts page (import vCard, edit)
- [ ] English translation

## Phase 3: extras

- [ ] Web Push notifications for new mail (cron job + VAPID keys)
- [ ] Snooze and send later (cron job)
- [ ] Offline reading of recently opened messages (IndexedDB)
- [ ] Hardening: per-user data key wrapped with a key derived from the login password
- [ ] Optional 2FA (TOTP) on top of the mailbox login
- [ ] Automated tests: PHPUnit for the API, Vitest + Playwright for the frontend, GitHub Actions
