-- 004: address book — contacts can be saved/edited, not only collected automatically

ALTER TABLE contacts
    ADD COLUMN phone      VARCHAR(64)  NULL AFTER name,
    ADD COLUMN notes      TEXT         NULL AFTER phone,
    ADD COLUMN favorite   TINYINT(1)   NOT NULL DEFAULT 0 AFTER notes,
    ADD COLUMN saved      TINYINT(1)   NOT NULL DEFAULT 0 AFTER favorite,
    ADD COLUMN created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER last_used_at;

INSERT IGNORE INTO schema_migrations (version) VALUES ('004_contacts');
