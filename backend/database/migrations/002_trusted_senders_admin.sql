-- 002: safe senders, admin features (blocking users, app-wide settings, readable login log)

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

CREATE TABLE IF NOT EXISTS app_settings (
    name            VARCHAR(64) NOT NULL,
    value_json      TEXT NOT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN blocked TINYINT(1) NOT NULL DEFAULT 0;

-- Plain IP/address so an admin can see and release blocks (rows are deleted after 24 hours).
ALTER TABLE login_attempts ADD COLUMN ip VARCHAR(45) NULL, ADD COLUMN email VARCHAR(255) NULL;

INSERT IGNORE INTO schema_migrations (version) VALUES ('002_trusted_senders_admin');
