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
    times_used      INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contacts_user_email (user_id, email),
    CONSTRAINT fk_contacts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-force protection for the login form.
CREATE TABLE IF NOT EXISTS login_attempts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash         CHAR(64) NOT NULL,
    email_hash      CHAR(64) NOT NULL,
    success         TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_ip (ip_hash, attempted_at),
    KEY idx_attempts_email (email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks applied migrations.
CREATE TABLE IF NOT EXISTS schema_migrations (
    version         VARCHAR(32) NOT NULL,
    applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_initial');
