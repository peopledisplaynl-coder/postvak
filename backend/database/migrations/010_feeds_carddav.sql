-- 010: calendars from elsewhere (iCal links, read-only), share links for calendars, and contacts over CardDAV.

-- An iCal address from another service (e.g. Google's "secret address in iCal format"), fetched by cron
-- into a read-only calendar of the user. The address itself is a secret, so it is stored encrypted.
CREATE TABLE IF NOT EXISTS calendar_feeds (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    instance_id     INT UNSIGNED NOT NULL,
    url_enc         TEXT NOT NULL,
    url_hash        CHAR(64) NOT NULL,
    etag            VARCHAR(255) NULL,
    last_modified   VARCHAR(100) NULL,
    last_fetch_at   DATETIME NULL,
    last_success_at DATETIME NULL,
    last_error      VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendar_feed_url (user_id, url_hash),
    UNIQUE KEY uq_calendar_feed_instance (instance_id),
    CONSTRAINT fk_calendar_feed_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A secret link that shows one calendar as an .ics file (for Google, Outlook, …). Revocable.
CREATE TABLE IF NOT EXISTS calendar_shares (
    instance_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    token_enc       TEXT NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    DATETIME NULL,
    PRIMARY KEY (instance_id),
    UNIQUE KEY uq_calendar_share_token (token_hash),
    CONSTRAINT fk_calendar_share_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contacts over CardDAV: the full vCard as a phone sent it (so nothing the phone knows gets lost),
-- its name on the server, and other e-mail addresses of the card (for address autocomplete).
-- A card from a phone may have no e-mail address at all.
ALTER TABLE contacts
    MODIFY email VARCHAR(255) NULL,
    ADD COLUMN card_uri     VARCHAR(200) NULL AFTER saved,
    ADD COLUMN vcard        MEDIUMTEXT   NULL AFTER card_uri,
    ADD COLUMN extra_emails VARCHAR(1000) NULL AFTER vcard,
    ADD COLUMN updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY uq_contacts_card (user_id, card_uri);

-- Change log for CardDAV sync (the sync token is the highest id of a user).
CREATE TABLE IF NOT EXISTS contact_changes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    uri             VARCHAR(200) NOT NULL,
    operation       TINYINT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_contact_changes_user (user_id, id),
    CONSTRAINT fk_contact_changes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('010_feeds_carddav');
