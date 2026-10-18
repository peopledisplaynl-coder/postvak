-- 005: push notifications for new mail (Web Push), run by the cron job

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    endpoint        TEXT NOT NULL,
    endpoint_hash   CHAR(64) NOT NULL,
    p256dh          VARCHAR(128) NOT NULL,
    auth            VARCHAR(64) NOT NULL,
    user_agent      VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_success_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    KEY idx_push_user (user_id),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE accounts
    ADD COLUMN notify             TINYINT(1)      NOT NULL DEFAULT 1 AFTER signature_html,
    ADD COLUMN notify_uidvalidity BIGINT UNSIGNED NULL AFTER notify,
    ADD COLUMN notify_last_uid    INT UNSIGNED    NULL AFTER notify_uidvalidity;

INSERT IGNORE INTO schema_migrations (version) VALUES ('005_push_notifications');
