-- 011: rules (filters) and out-of-office replies, run by cron on new inbox mail; reply templates.

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

INSERT IGNORE INTO schema_migrations (version) VALUES ('011_rules_autoreply_templates');
