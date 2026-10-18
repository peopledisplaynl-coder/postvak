-- 008: language of the notifications per device (the service worker cannot read the user's settings)

ALTER TABLE push_subscriptions
    ADD COLUMN lang VARCHAR(5) NULL AFTER auth;

INSERT IGNORE INTO schema_migrations (version) VALUES ('008_push_language');
