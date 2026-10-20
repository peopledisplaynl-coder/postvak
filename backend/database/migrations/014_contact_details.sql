-- 014: richer contacts (as on Google Contacts): first and last name, company, job title, several e-mail
-- addresses and phone numbers with a type, addresses, birthday, websites, groups (JSON in `details`) and a photo.

ALTER TABLE contacts
    ADD COLUMN details    MEDIUMTEXT NULL AFTER notes,
    ADD COLUMN photo      MEDIUMBLOB NULL AFTER details,
    ADD COLUMN photo_mime VARCHAR(40) NULL AFTER photo;

INSERT IGNORE INTO schema_migrations (version) VALUES ('014_contact_details');
