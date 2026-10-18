-- 007: two-factor authentication (TOTP) and devices that may skip it for 30 days

ALTER TABLE users
    ADD COLUMN totp_secret     TEXT NULL AFTER blocked,          -- encrypted with the app key
    ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret,
    ADD COLUMN totp_last_step  BIGINT NULL AFTER totp_enabled_at, -- last used time step (no code reuse)
    ADD COLUMN totp_recovery   TEXT NULL AFTER totp_last_step;    -- JSON list of hashed recovery codes

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

INSERT IGNORE INTO schema_migrations (version) VALUES ('007_two_factor');
