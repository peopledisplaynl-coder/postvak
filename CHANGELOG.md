# Changelog

All notable changes to Postvak. The format follows [Keep a Changelog](https://keepachangelog.com/),
versions follow [Semantic Versioning](https://semver.org/).

## [1.6.2] - 2026-10-19

### Added
- User manual in Dutch: [docs/HANDLEIDING.md](docs/HANDLEIDING.md), with screenshots
  (`node scripts/manual-screenshots.mjs` makes them again). Linked from the README and Settings → About Postvak.

## [1.6.1] - 2026-10-19

### Fixed
- Installed app on Android (and other devices) now always notices a new version. Besides the service worker,
  Postvak asks the server which version it runs (at start, when you return to the app, every 30 minutes). A new
  version loads by itself when you come back to the app and are not writing; otherwise a bar at the bottom offers
  "Update". If the phone keeps the old version waiting, "Update" empties the app cache and loads the new version
  from the server (push notifications keep working).

## [1.6.0] - 2026-10-19

### Added
- Quick reply: a box under the open message (or the newest one of a conversation) to answer straight away, with
  "Reply all" when there are more people on it and "Full editor" to continue in the composer. Ctrl+Enter sends.
  It goes through the normal composer (signature, quote, undo send).
- Search filters: Unread, Starred, Has attachment and a period (past week / month / year), plus "More from this
  sender" in the reader.
- Large files as a download link: files over 10 MB, or more than the server accepts as attachments, are uploaded
  in pieces to your own Postvak server; the message gets a link (works for 30 days). Overview, copy link and
  remove under Settings → Privacy. Database update 013 runs by itself.
- Undo for marking several messages read/unread or starred, and for adding or removing a label.
- Admin → default settings: messages to load at a time (phone/tablet and computer).

### Changed
- The plain-text version of a sent message keeps the address of every link.

## [1.5.1] - 2026-10-19

### Added
- Settings → About Postvak: version, maker (PeopleDisplay), licence, website, what's new, source code, reporting a
  problem, and the open-source building blocks. Links live in `frontend/src/about.js`.
- "Messages to load at a time" can now be set to 5.

### Changed
- In All inboxes the accounts are listed once (with their unread count) instead of twice.

## [1.5.0] - 2026-10-19

### Added
- Settings → General → "Messages to load at a time", separately for phone/tablet (default 25) and computer
  (default 50). The rest loads as you scroll; a "Load more messages (x of y)" button sits at the end of the list.

### Fixed
- Checking for new mail no longer shrinks the list back to the first page after you scrolled further.

## [1.4.1] - 2026-10-18

### Fixed
- The installed app (iPhone/iPad home screen, Android, desktop) now looks for a new version every time it comes to
  the foreground and every 30 minutes, and switches to it right away (or offers "Refresh" while you are writing).
  Before, iOS kept showing the old version for a long time.

### Added
- Settings → General shows the version and has "Check for updates".

## [1.4.0] - 2026-10-18

### Added
- Signatures with a logo or other image: a rich text editor for the signature, images are scaled down and sent as
  embedded images, so recipients see them without loading remote images.
- Labels: Important, Work, Personal, To do and Later (the same as in Thunderbird) plus your own, with colours.
  Label messages from the reader or a selection, see them in the list, filter a folder on a label in the sidebar,
  and let a rule add a label.
- Appearance tab: light or dark, 8 accent colours, text size (small, normal, large) and list density.

## [1.3.0] - 2026-10-18

### Added
- Rules for new mail (Settings → Rules & away): move to a folder, mark as read, star or move to trash when the
  sender, recipient or subject matches. Make one straight from a message; apply to the inbox now.
- Out-of-office reply per account, with optional start and end date and "only to my contacts". One reply per
  sender per 4 days; mailing lists, automatic mail and no-reply addresses get none.
- Templates in the composer: insert a saved text, or save the current one.

### Changed
- The cron job runs rules before the new-mail notification, so mail that a rule moves away is not announced.

## [1.2.1] - 2026-10-18

### Fixed
- The tabs of Settings and Admin could not all be reached with a mouse (the scrollbar was hidden): they now wrap
  onto a second line. The same for the filters on the contacts and tasks pages.

## [1.2.0] - 2026-10-18

### Added
- Contacts sync with the contacts app on your phone and with macOS and Thunderbird (CardDAV, address book "Postvak").
  Whatever the phone stores (photo, birthday, address, more numbers) is kept; contacts without an e-mail address are allowed.
- Meeting invitations in a message show as a card: accept, maybe or decline; the event goes into your calendar and
  the answer to the organizer. Cancellations can remove the event again.
- Calendars from elsewhere: paste an iCal address (e.g. Google's secret address) and see that calendar read-only
  in Postvak and on your phone; updated every half hour.
- Share link per calendar: a secret .ics address to show a Postvak calendar in Google Calendar or Outlook; revocable.

### Changed
- vCard export contains the full cards, including what a phone added.
- An address you send to no longer renames a saved contact.

## [1.1.2] - 2026-10-18

### Added
- The folder column and the message list can be made wider or narrower by dragging their edge
  (double-click resets; remembered per device).

### Fixed
- The heading and text colour menus in the editor were cut off; the toolbar now wraps instead of scrolling.
- Thin, quiet scrollbars instead of thick grey bars on Windows; rows of tabs and account chips no longer show a scrollbar.
- The cron job writes its log to `storage/logs/cron.log`, so Plesk no longer e-mails routine messages.

## [1.1.1] - 2026-10-18

### Fixed
- Database updates now run right after an update is deployed, instead of waiting for the next login.
  Before, the calendar gave "Internal server error" for anyone still logged in from the previous version.

### Added
- Admins see the real cause of a server error on screen, and the admin overview shows a failed database
  update and missing PHP libraries.

## [1.1.0] - 2026-10-18

### Added
- Calendar: month, week, day and list view, repeating events, several calendars with their own colour.
- Tasks: lists, due dates with or without time, priorities, completed tasks; "Make a task" from a message.
- Reminders for events and tasks as push notifications (also for items made on a phone).
- CalDAV server: sync calendars and task lists both ways with iPhone/iPad, Android (DAVx5), macOS and Thunderbird.
  Each device gets its own app password (Settings → Calendar & tasks), with set-up instructions.

### Changed
- New dependency: `sabre/dav` (installed automatically by `npm run deploy`).

## [1.0.1] - 2026-10-18

### Added
- "Stay logged in" on the login page (on by default). Without it the session ends when the browser closes
  or after 8 hours without activity. With it, active users stay logged in (30 days, renewed while in use).

### Changed
- Sessions are stored in Postvak's own `storage/sessions` folder, so the host's cleanup no longer logs people
  out early. Everyone has to log in once after this update.
- The server settings on the login page are hidden unless the admin allows them (Admin → Settings).

### Security
- Own server settings at a first login are off by default. Before, someone could log in with an unused address
  of an allowed domain by pointing Postvak to a mail server that accepts any password.

## [1.0.0] - 2026-10-18

First public release.

### Added
- Release zip with a web installer (`install.php`): checks the server, creates the tables, writes
  `config.php` with a new encryption key and removes itself.
- Automated tests: API unit tests (`backend/tests`), Vitest and Playwright (demo mode), all run in GitHub Actions.
- Release workflow: pushing a `v*` tag builds the zip and publishes a GitHub release.
- English installation guide, new screenshots, this changelog.

### Changed
- Demo mode shows English sample data in an English browser.
- A missing `config.php` now points to the installer.

## [0.11.0] - 2026-10-18
### Added
- English interface. Language per user (automatic / Nederlands / English); notifications follow the device's language.

## [0.10.0] - 2026-10-18
### Added
- Undo send, mark all as read, search in the message text, attachment viewer, print view, keyboard shortcut overview.
- Deploy installs new npm and Composer packages by itself.

## [0.9.0] - 2026-10-18
### Added
- Optional two-step verification (TOTP) with recovery codes, remembered devices and admin reset.

## [0.8.0] - 2026-10-18
### Added
- Send later and snooze, handled by the cron job.

## [0.7.0] - 2026-10-18
### Added
- Web Push notifications for new mail (VAPID, no external dependencies) and `cron.php`.

## [0.6.0] - 2026-10-17
### Added
- Conversation view.

## [0.5.1] - 2026-10-17
### Added
- Rich text editor (Tiptap).

## [0.5.0] - 2026-10-17
### Added
- Contacts page with vCard import and export.

## [0.4.2] - 2026-10-17
### Added
- Mark as spam / not spam, empty trash and spam.

## [0.4.1] - 2026-10-17
### Added
- Drag & drop of messages onto folders and of files into the composer.

## [0.4.0] - 2026-10-17
### Added
- Drafts saved automatically; unified inbox across accounts.

## [0.3.0] - 2026-10-17
### Added
- Preview lines in the list, multi-select, move to folder, folder management.

## [0.2.0] - 2026-10-17
### Added
- Safe senders for remote images, admin page, automatic database updates.

## [0.1.0] - 2026-10-17
### Added
- First version: login with mailbox credentials, multiple accounts, swipe actions with undo,
  sanitised reader, composer, settings, responsive layout, dark mode, PWA, FTP deploy.

[1.6.2]: ../../releases/tag/v1.6.2
[1.6.1]: ../../releases/tag/v1.6.1
[1.6.0]: ../../releases/tag/v1.6.0
[1.5.1]: ../../releases/tag/v1.5.1
[1.5.0]: ../../releases/tag/v1.5.0
[1.4.1]: ../../releases/tag/v1.4.1
[1.4.0]: ../../releases/tag/v1.4.0
[1.3.0]: ../../releases/tag/v1.3.0
[1.2.1]: ../../releases/tag/v1.2.1
[1.2.0]: ../../releases/tag/v1.2.0
[1.1.2]: ../../releases/tag/v1.1.2
[1.1.1]: ../../releases/tag/v1.1.1
[1.1.0]: ../../releases/tag/v1.1.0
[1.0.1]: ../../releases/tag/v1.0.1
[1.0.0]: ../../releases/tag/v1.0.0
