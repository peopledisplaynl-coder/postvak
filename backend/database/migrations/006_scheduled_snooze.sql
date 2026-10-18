-- 006: send later (scheduled_sends) and snooze (snoozes). Times are UTC.

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

INSERT IGNORE INTO schema_migrations (version) VALUES ('006_scheduled_snooze');
