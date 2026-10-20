-- Postvak database schema (MySQL 5.7+ / MariaDB 10.3+)
-- Run once on a fresh database. Later changes go into database/migrations/.

SET NAMES utf8mb4;

-- A Postvak user. Logs in with the address + password of one of their mailboxes
-- (the "login account"). Postvak never stores its own password for a user.
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    login_email     VARCHAR(255) NOT NULL,
    display_name    VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME NULL,
    blocked         TINYINT(1) NOT NULL DEFAULT 0,
    -- Two-factor authentication (TOTP). Secret encrypted with the app key; recovery codes hashed.
    totp_secret     TEXT NULL,
    totp_enabled_at DATETIME NULL,
    totp_last_step  BIGINT NULL,
    totp_recovery   TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_login_email (login_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mail accounts linked to a user. A user can have several.
-- Passwords are encrypted with libsodium (see src/Crypto.php), never stored in plain text.
CREATE TABLE IF NOT EXISTS accounts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    email           VARCHAR(255) NOT NULL,
    display_name    VARCHAR(255) NULL,
    color           VARCHAR(7)   NULL,          -- accent colour in the UI, e.g. #3b82f6
    is_login        TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order      SMALLINT     NOT NULL DEFAULT 0,

    imap_host       VARCHAR(255) NOT NULL,
    imap_port       SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    imap_encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
    smtp_host       VARCHAR(255) NOT NULL,
    smtp_port       SMALLINT UNSIGNED NOT NULL DEFAULT 465,
    smtp_encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
    username        VARCHAR(255) NOT NULL,
    password_enc    TEXT NOT NULL,

    -- Special folders. NULL = detect automatically.
    archive_folder  VARCHAR(255) NULL,
    trash_folder    VARCHAR(255) NULL,
    sent_folder     VARCHAR(255) NULL,
    drafts_folder   VARCHAR(255) NULL,
    junk_folder     VARCHAR(255) NULL,

    signature_html  TEXT NULL,
    signatures      MEDIUMTEXT NULL,                   -- several signatures as JSON (015)

    -- Push notifications: on/off, and the last INBOX uid the cron job has seen.
    notify             TINYINT(1)      NOT NULL DEFAULT 1,
    notify_uidvalidity BIGINT UNSIGNED NULL,
    notify_last_uid    INT UNSIGNED    NULL,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_accounts_user_email (user_id, email),
    CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user preferences as JSON (swipe actions, theme, density, ...).
-- Defaults live in src/Repositories/SettingsRepository.php.
CREATE TABLE IF NOT EXISTS user_settings (
    user_id         INT UNSIGNED NOT NULL,
    settings_json   TEXT NOT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contacts for address autocomplete (phase 2).
CREATE TABLE IF NOT EXISTS contacts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    email           VARCHAR(255) NULL,                 -- a card from a phone may have no address
    name            VARCHAR(255) NULL,
    phone           VARCHAR(64) NULL,
    notes           TEXT NULL,
    details         MEDIUMTEXT NULL,                   -- JSON: names, company, e-mails, phones, addresses, birthday, … (014)
    photo           MEDIUMBLOB NULL,                   -- photo added in Postvak (a phone's photo stays in vcard)
    photo_mime      VARCHAR(40) NULL,
    favorite        TINYINT(1) NOT NULL DEFAULT 0,
    saved           TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = added/edited by the user, 0 = collected when sending
    card_uri        VARCHAR(200) NULL,                 -- CardDAV name when a phone created the card
    vcard           MEDIUMTEXT NULL,                   -- the full card as a phone sent it
    extra_emails    VARCHAR(1000) NULL,
    times_used      INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contacts_user_email (user_id, email),
    UNIQUE KEY uq_contacts_card (user_id, card_uri),
    CONSTRAINT fk_contacts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Browsers/devices that receive push notifications (Web Push).
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    endpoint        TEXT NOT NULL,
    endpoint_hash   CHAR(64) NOT NULL,
    p256dh          VARCHAR(128) NOT NULL,
    auth            VARCHAR(64) NOT NULL,
    lang            VARCHAR(5) NULL,                   -- language of the notification texts
    user_agent      VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_success_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    KEY idx_push_user (user_id),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Send later and snooze (times are UTC).
CREATE TABLE IF NOT EXISTS scheduled_sends (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    token           CHAR(24) NOT NULL,              -- X-Postvak-Draft header of the message in the Scheduled folder
    message_id      VARCHAR(255) NULL,
    send_at         DATETIME NOT NULL,
    recipients      TEXT NOT NULL,                  -- JSON list of envelope addresses (incl. Bcc)
    subject         VARCHAR(255) NULL,
    reply_ref       VARCHAR(512) NULL,              -- {folder, uid} JSON of the message answered
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error      VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_scheduled_due (send_at),
    KEY idx_scheduled_account (account_id),
    CONSTRAINT fk_scheduled_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_scheduled_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS snoozes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    message_id      VARCHAR(255) NOT NULL,
    subject         VARCHAR(255) NULL,
    until_at        DATETIME NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_snooze_message (account_id, message_id),
    KEY idx_snooze_due (until_at),
    CONSTRAINT fk_snooze_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_snooze_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Devices that may skip the two-factor code for 30 days (cookie token, stored hashed).
CREATE TABLE IF NOT EXISTS trusted_devices (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    user_agent      VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    DATETIME NULL,
    expires_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trusted_token (token_hash),
    KEY idx_trusted_user (user_id),
    CONSTRAINT fk_trusted_device_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-force protection for the login form.
CREATE TABLE IF NOT EXISTS login_attempts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash         CHAR(64) NOT NULL,
    email_hash      CHAR(64) NOT NULL,
    ip              VARCHAR(45) NULL,
    email           VARCHAR(255) NULL,
    success         TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_ip (ip_hash, attempted_at),
    KEY idx_attempts_email (email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Senders (or whole domains) whose remote images are always shown.
CREATE TABLE IF NOT EXISTS trusted_senders (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    kind            ENUM('email','domain') NOT NULL,
    value           VARCHAR(255) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trusted (user_id, kind, value),
    CONSTRAINT fk_trusted_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- App-wide settings changed on the admin page (override config.php).
CREATE TABLE IF NOT EXISTS app_settings (
    name            VARCHAR(64) NOT NULL,
    value_json      TEXT NOT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache of preview lines for the message list (rebuilt automatically when missing).
CREATE TABLE IF NOT EXISTS message_snippets (
    account_id      INT UNSIGNED NOT NULL,
    folder_hash     CHAR(40) NOT NULL,
    uidvalidity     BIGINT UNSIGNED NOT NULL,
    uid             INT UNSIGNED NOT NULL,
    snippet         VARCHAR(400) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id, folder_hash, uid),
    CONSTRAINT fk_snippets_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendars and tasks (CalDAV, sabre/dav schema with a dav_ prefix), app passwords, sent reminders.
CREATE TABLE IF NOT EXISTS dav_calendarobjects (
    id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    calendardata MEDIUMBLOB,
    uri VARBINARY(200),
    calendarid INTEGER UNSIGNED NOT NULL,
    lastmodified INT(11) UNSIGNED,
    etag VARBINARY(32),
    size INT(11) UNSIGNED NOT NULL,
    componenttype VARBINARY(8),
    firstoccurence INT(11) UNSIGNED,
    lastoccurence INT(11) UNSIGNED,
    uid VARBINARY(200),
    UNIQUE KEY uq_dav_object (calendarid, uri),
    INDEX idx_dav_object_time (calendarid, firstoccurence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dav_calendars (
    id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    synctoken INTEGER UNSIGNED NOT NULL DEFAULT '1',
    components VARBINARY(21)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dav_calendarinstances (
    id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    calendarid INTEGER UNSIGNED NOT NULL,
    principaluri VARBINARY(100),
    access TINYINT(1) NOT NULL DEFAULT '1',
    displayname VARCHAR(100),
    uri VARBINARY(200),
    description TEXT,
    calendarorder INT(11) UNSIGNED NOT NULL DEFAULT '0',
    calendarcolor VARBINARY(10),
    timezone TEXT,
    transparent TINYINT(1) NOT NULL DEFAULT '0',
    share_href VARBINARY(100),
    share_displayname VARCHAR(100),
    share_invitestatus TINYINT(1) NOT NULL DEFAULT '2',
    UNIQUE KEY uq_dav_instance_uri (principaluri, uri),
    UNIQUE KEY uq_dav_instance_cal (calendarid, principaluri),
    UNIQUE KEY uq_dav_instance_share (calendarid, share_href)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dav_calendarchanges (
    id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    uri VARBINARY(200) NOT NULL,
    synctoken INT(11) UNSIGNED NOT NULL,
    calendarid INT(11) UNSIGNED NOT NULL,
    operation TINYINT(1) NOT NULL,
    INDEX idx_dav_changes (calendarid, synctoken)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dav_calendarsubscriptions (
    id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    uri VARBINARY(200) NOT NULL,
    principaluri VARBINARY(100) NOT NULL,
    source TEXT,
    displayname VARCHAR(100),
    refreshrate VARCHAR(10),
    calendarorder INT(11) UNSIGNED NOT NULL DEFAULT '0',
    calendarcolor VARBINARY(10),
    striptodos TINYINT(1) NULL,
    stripalarms TINYINT(1) NULL,
    stripattachments TINYINT(1) NULL,
    lastmodified INT(11) UNSIGNED,
    UNIQUE KEY uq_dav_subscription (principaluri, uri)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dav_schedulingobjects (
    id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    principaluri VARBINARY(255),
    calendardata MEDIUMBLOB,
    uri VARBINARY(200),
    lastmodified INT(11) UNSIGNED,
    etag VARBINARY(32),
    size INT(11) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Passwords for calendar apps on phones and computers (CalDAV). Shown once, stored as an HMAC.
CREATE TABLE IF NOT EXISTS app_passwords (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_password_hash (token_hash),
    KEY idx_app_password_user (user_id),
    CONSTRAINT fk_app_password_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reminders already pushed (one row per object, occurrence and alarm time).
CREATE TABLE IF NOT EXISTS calendar_alarms_sent (
    object_id       INT UNSIGNED NOT NULL,
    occurrence      INT UNSIGNED NOT NULL,
    trigger_at      INT UNSIGNED NOT NULL,
    sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (object_id, occurrence, trigger_at),
    KEY idx_alarm_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks applied migrations.
CREATE TABLE IF NOT EXISTS schema_migrations (
    version         VARCHAR(32) NOT NULL,
    applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendars from elsewhere, share links, CardDAV change log (see migration 010).
CREATE TABLE IF NOT EXISTS calendar_feeds (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    instance_id     INT UNSIGNED NOT NULL,
    url_enc         TEXT NOT NULL,
    url_hash        CHAR(64) NOT NULL,
    etag            VARCHAR(255) NULL,
    last_modified   VARCHAR(100) NULL,
    last_fetch_at   DATETIME NULL,
    last_success_at DATETIME NULL,
    last_error      VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendar_feed_url (user_id, url_hash),
    UNIQUE KEY uq_calendar_feed_instance (instance_id),
    CONSTRAINT fk_calendar_feed_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_shares (
    instance_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    token_enc       TEXT NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    DATETIME NULL,
    PRIMARY KEY (instance_id),
    UNIQUE KEY uq_calendar_share_token (token_hash),
    CONSTRAINT fk_calendar_share_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_changes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    uri             VARCHAR(200) NOT NULL,
    operation       TINYINT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_contact_changes_user (user_id, id),
    CONSTRAINT fk_contact_changes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rules: conditions and actions as JSON (see Mail\RuleEngine). account_id NULL = every account of the user.
CREATE TABLE IF NOT EXISTS mail_rules (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NULL,
    name            VARCHAR(100) NOT NULL,
    enabled         TINYINT(1) NOT NULL DEFAULT 1,
    match_all       TINYINT(1) NOT NULL DEFAULT 1,
    conditions_json TEXT NOT NULL,
    actions_json    TEXT NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    times_applied   INT UNSIGNED NOT NULL DEFAULT 0,
    last_applied_at DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mail_rules_user (user_id, sort_order),
    CONSTRAINT fk_mail_rules_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_rules_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Out-of-office reply per account.
CREATE TABLE IF NOT EXISTS autoreplies (
    account_id      INT UNSIGNED NOT NULL,
    enabled         TINYINT(1) NOT NULL DEFAULT 0,
    starts_on       DATE NULL,
    ends_on         DATE NULL,
    subject         VARCHAR(255) NOT NULL DEFAULT '',
    body            TEXT NOT NULL,
    contacts_only   TINYINT(1) NOT NULL DEFAULT 0,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id),
    CONSTRAINT fk_autoreplies_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who already got an out-of-office reply (one per sender per few days).
CREATE TABLE IF NOT EXISTS autoreply_log (
    account_id      INT UNSIGNED NOT NULL,
    sender_hash     CHAR(64) NOT NULL,
    sent_at         DATETIME NOT NULL,
    PRIMARY KEY (account_id, sender_hash),
    CONSTRAINT fk_autoreply_log_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How far cron got in each inbox for rules and replies (separate from the push notifier's position).
CREATE TABLE IF NOT EXISTS mail_processing (
    account_id      INT UNSIGNED NOT NULL,
    uidvalidity     INT UNSIGNED NOT NULL,
    last_uid        INT UNSIGNED NOT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id),
    CONSTRAINT fk_mail_processing_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reusable texts for the composer.
CREATE TABLE IF NOT EXISTS mail_templates (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    subject         VARCHAR(255) NULL,
    html            MEDIUMTEXT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mail_templates_user (user_id),
    CONSTRAINT fk_mail_templates_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Images in signatures (migration 012).
CREATE TABLE IF NOT EXISTS signature_images (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    token           CHAR(32) NOT NULL,
    mime            VARCHAR(40) NOT NULL,
    data            MEDIUMBLOB NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_signature_image_token (token),
    KEY idx_signature_images_user (user_id),
    CONSTRAINT fk_signature_images_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Large attachments as a download link (013)
CREATE TABLE IF NOT EXISTS shared_files (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    token           CHAR(32) NOT NULL,
    name            VARCHAR(255) NOT NULL,
    mime            VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    size            BIGINT UNSIGNED NOT NULL,
    received        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    complete        TINYINT(1) NOT NULL DEFAULT 0,
    downloads       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL,
    expires_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shared_file_token (token),
    KEY idx_shared_files_user (user_id),
    KEY idx_shared_files_expires (expires_at),
    CONSTRAINT fk_shared_files_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Muted conversations and follow-up reminders (015)
CREATE TABLE IF NOT EXISTS muted_threads (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id      INT UNSIGNED NOT NULL,
    message_id      VARCHAR(255) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_muted_thread (account_id, message_id),
    CONSTRAINT fk_muted_threads_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS followups (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id      INT UNSIGNED NOT NULL,
    message_id      VARCHAR(255) NOT NULL,
    subject         VARCHAR(500) NOT NULL DEFAULT '',
    remind_at       DATETIME NOT NULL,
    status          VARCHAR(10) NOT NULL DEFAULT 'waiting',   -- waiting | answered | reminded
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_followups_due (status, remind_at),
    CONSTRAINT fk_followups_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_initial'), ('002_trusted_senders_admin'), ('003_message_snippets'), ('004_contacts'), ('005_push_notifications'), ('006_scheduled_snooze'), ('007_two_factor'), ('008_push_language'), ('009_calendar'), ('010_feeds_carddav'), ('011_rules_autoreply_templates'), ('012_signature_images'), ('013_shared_files'), ('014_contact_details'), ('015_mute_followup_signatures');
