-- 012: images in signatures (a logo). Stored in the database, sent as inline (cid:) images,
-- so they show in the recipient's mail program without loading anything from the internet.

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

INSERT IGNORE INTO schema_migrations (version) VALUES ('012_signature_images');
