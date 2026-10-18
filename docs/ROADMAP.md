# Roadmap

## Phase 1: working base (v0.1)

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
- [x] Unified inbox across all accounts ("Alle inboxen", setting `unified_inbox`)
- [x] Conversation view: IMAP THREAD per folder, own replies from Sent in the reader, actions on whole conversations, setting `conversations`
- [x] Drafts: autosave to the Drafts folder, edit existing drafts
- [x] Rich text editor (Tiptap): headings, colours, lists, links, clean paste from Word; the quoted original stays outside the editor (layout kept, can be left out); pasted images become attachments; Ctrl+Enter sends
- [x] Multi-select in the list (long press on mobile, avatar click on desktop) with bulk actions
- [x] Move to folder, create/rename/delete folders
- [x] Drag & drop (mouse): messages onto a sidebar folder (whole selection; in the unified inbox the sidebar shows the folders of the dragged account), files from the computer into the composer
- [x] Mark as spam / not spam (button, selection, swipe option, `!`); empty trash and spam
- [x] Contacts page: saved / favourites / all collected addresses, edit, vCard import and export, "add sender to contacts" in the reader
- [x] Undo send (setting, default 5 s; the message waits as a draft), mark all as read, search in message text,
      attachment viewer (images, PDF in a new tab), print, keyboard shortcut overview (`?`, `r`/`a`/`f`, `/`)
- [x] Deploy installs new npm/composer packages by itself
- [x] English translation (setting per user: automatic / Nederlands / English; notifications follow the device's language)

## Phase 3: extras

- [x] Web Push notifications for new mail (cron job + VAPID keys, no extra dependencies)
- [x] Snooze and send later (cron job; own IMAP folders "Gesnoozed" and "Gepland")
- [x] Optional 2FA (TOTP) on top of the mailbox login, recovery codes, remembered devices, admin reset
- [x] Automated tests: API unit tests, Vitest + Playwright for the frontend, GitHub Actions

## 1.0: ready for others

- [x] Release zip with a web installer (`install.php`), English installation guide
- [x] Release workflow on GitHub (tag `v*` → zip on the releases page)
- [x] Screenshots generated from the demo mode (`scripts/screenshots.mjs`), changelog

## Ideas after 1.0

- [ ] Offline reading of recently opened messages (IndexedDB)
- [ ] Hardening: per-user data key wrapped with a key derived from the login password
- [ ] More interface languages (German, French; one file each)
- [ ] Filters / rules (Sieve, where the mail server supports ManageSieve)
- [ ] Calendar invitations (.ics) shown in the reader
