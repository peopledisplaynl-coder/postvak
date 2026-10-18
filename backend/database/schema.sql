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
    email           VARCHAR(255) NOT NULL,
    name            VARCHAR(255) NULL,
    phone           VARCHAR(64) NULL,
    notes           TEXT NULL,
    favorite        TINYINT(1) NOT NULL DEFAULT 0,
    saved           TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = added/edited by the user, 0 = collected when sending
    times_used      INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contacts_user_email (user_id, email),
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

-- Tracks applied migrations.
CREATE TABLE IF NOT EXISTS schema_migrations (
    version         VARCHAR(32) NOT NULL,
    applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_initial'), ('002_trusted_senders_admin'), ('003_message_snippets'), ('004_contacts'), ('005_push_notifications'), ('006_scheduled_snooze'), ('007_two_factor'), ('008_push_language');
