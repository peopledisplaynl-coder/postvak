# Changelog

All notable changes to Postvak. The format follows [Keep a Changelog](https://keepachangelog.com/),
versions follow [Semantic Versioning](https://semver.org/).

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

[1.0.0]: ../../releases/tag/v1.0.0
