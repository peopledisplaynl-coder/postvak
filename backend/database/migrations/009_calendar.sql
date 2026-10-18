-- 009: calendars and tasks (CalDAV via sabre/dav), app passwords for phones, sent reminders.
-- The dav_* tables follow sabre/dav's own MySQL schema (examples/sql/mysql.calendars.sql) with a prefix.

CREATE TABLE IF NOT EXISTS dav_calendarobjects (
    id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    calendardata MEDIUMBLOB,
    uri VARBINARY(200),
    calendarid INTEGER UNSIGNED NOT NULL,
    lastmodified INT(11) UNSIGNED,
    etag VARBINARY(32),
    size INT(11) UNSIGNED NOT NULL,
    componenttype VARBINARY(8),
    firstoccurence INT(11) UNSIGNED,
    lastoccurence INT(11) UNSIGNED,
    uid VARBINARY(200),
    UNIQUE KEY uq_dav_object (calendarid, uri),
    INDEX idx_dav_object_time (calendarid, firstoccurence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dav_calendars (
    id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    synctoken INTEGER UNSIGNED NOT NULL DEFAULT '1',
    components VARBINARY(21)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dav_calendarinstances (
    id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    calendarid INTEGER UNSIGNED NOT NULL,
    principaluri VARBINARY(100),
    access TINYINT(1) NOT NULL DEFAULT '1',
    displayname VARCHAR(100),
    uri VARBINARY(200),
    description TEXT,
    calendarorder INT(11) UNSIGNED NOT NULL DEFAULT '0',
    calendarcolor VARBINARY(10),
    timezone TEXT,
    transparent TINYINT(1) NOT NULL DEFAULT '0',
    share_href VARBINARY(100),
    share_displayname VARCHAR(100),
    share_invitestatus TINYINT(1) NOT NULL DEFAULT '2',
    UNIQUE KEY uq_dav_instance_uri (principaluri, uri),
    UNIQUE KEY uq_dav_instance_cal (calendarid, principaluri),
    UNIQUE KEY uq_dav_instance_share (calendarid, share_href)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dav_calendarchanges (
    id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    uri VARBINARY(200) NOT NULL,
    synctoken INT(11) UNSIGNED NOT NULL,
    calendarid INT(11) UNSIGNED NOT NULL,
    operation TINYINT(1) NOT NULL,
    INDEX idx_dav_changes (calendarid, synctoken)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dav_calendarsubscriptions (
    id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    uri VARBINARY(200) NOT NULL,
    principaluri VARBINARY(100) NOT NULL,
    source TEXT,
    displayname VARCHAR(100),
    refreshrate VARCHAR(10),
    calendarorder INT(11) UNSIGNED NOT NULL DEFAULT '0',
    calendarcolor VARBINARY(10),
    striptodos TINYINT(1) NULL,
    stripalarms TINYINT(1) NULL,
    stripattachments TINYINT(1) NULL,
    lastmodified INT(11) UNSIGNED,
    UNIQUE KEY uq_dav_subscription (principaluri, uri)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dav_schedulingobjects (
    id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    principaluri VARBINARY(255),
    calendardata MEDIUMBLOB,
    uri VARBINARY(200),
    lastmodified INT(11) UNSIGNED,
    etag VARBINARY(32),
    size INT(11) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Passwords for calendar apps on phones and computers (CalDAV). Shown once, stored as an HMAC.
CREATE TABLE IF NOT EXISTS app_passwords (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_password_hash (token_hash),
    KEY idx_app_password_user (user_id),
    CONSTRAINT fk_app_password_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reminders already pushed (one row per object, occurrence and alarm time).
CREATE TABLE IF NOT EXISTS calendar_alarms_sent (
    object_id       INT UNSIGNED NOT NULL,
    occurrence      INT UNSIGNED NOT NULL,
    trigger_at      INT UNSIGNED NOT NULL,
    sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (object_id, occurrence, trigger_at),
    KEY idx_alarm_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('009_calendar');
