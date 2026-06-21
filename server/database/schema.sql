-- Resource Reserve database schema
-- Engine: MySQL 8 / MariaDB 10
-- Style: full words, underscores between words, tabs for indentation.

CREATE DATABASE IF NOT EXISTS resource_reserve
	CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

USE resource_reserve;

-- Drop in dependency order so the script can be re-run cleanly.
DROP TABLE IF EXISTS rate_limit;
DROP TABLE IF EXISTS notification;
DROP TABLE IF EXISTS message;
DROP TABLE IF EXISTS log;
DROP TABLE IF EXISTS administrator_resource;
DROP TABLE IF EXISTS reservation;
DROP TABLE IF EXISTS resource;
DROP TABLE IF EXISTS setting;
DROP TABLE IF EXISTS user;


-- ---------------------------------------------------------------------------
-- user
-- A registered account. The email must be verified through a link before the
-- account is allowed to sign in.
-- ---------------------------------------------------------------------------
CREATE TABLE user (
	user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	email VARCHAR( 254 ) NOT NULL,
	password_hash VARCHAR( 255 ) NOT NULL,
	is_email_verified TINYINT( 1 ) NOT NULL DEFAULT 0,
	verification_token VARCHAR( 64 ) DEFAULT NULL,
	access_token VARCHAR( 64 ) DEFAULT NULL,
	-- There is no administrator flag on the account. A user is treated as an
	-- administrator when ( and only when ) they have at least one row in the
	-- administrator_resource table, which also limits the resources they may
	-- approve.
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY ( user_id ),
	UNIQUE KEY unique_user_email ( email ),
	UNIQUE KEY unique_user_access_token ( access_token )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- resource
-- A bookable resource. Each resource defines the longest single reservation
-- that is allowed, expressed as an amount plus a unit ( day or hour ).
-- ---------------------------------------------------------------------------
CREATE TABLE resource (
	resource_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	resource_name VARCHAR( 120 ) NOT NULL,
	description TEXT DEFAULT NULL,
	maximum_period_value INT UNSIGNED NOT NULL DEFAULT 1,
	maximum_period_unit ENUM( 'day', 'hour' ) NOT NULL DEFAULT 'day',
	is_active TINYINT( 1 ) NOT NULL DEFAULT 1,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY ( resource_id )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- reservation
-- A booking of a resource by a user for a timeframe. A reservation starts in
-- the 'pending' status and stays editable by its owner until an administrator
-- moves it to 'confirmed' ( or 'rejected' ).
-- ---------------------------------------------------------------------------
CREATE TABLE reservation (
	reservation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id INT UNSIGNED NOT NULL,
	resource_id INT UNSIGNED NOT NULL,
	begin_datetime DATETIME NOT NULL,
	end_datetime DATETIME NOT NULL,
	description VARCHAR( 500 ) DEFAULT NULL,
	status ENUM( 'pending', 'confirmed', 'rejected' ) NOT NULL DEFAULT 'pending',
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY ( reservation_id ),
	KEY index_reservation_user ( user_id ),
	KEY index_reservation_resource ( resource_id ),
	KEY index_reservation_begin_datetime ( begin_datetime ),
	CONSTRAINT foreign_key_reservation_user FOREIGN KEY ( user_id ) REFERENCES user ( user_id ) ON DELETE CASCADE,
	CONSTRAINT foreign_key_reservation_resource FOREIGN KEY ( resource_id ) REFERENCES resource ( resource_id ) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- administrator_resource
-- Assigns the resources that a given administrator is allowed to approve or
-- reject reservations for. This table also defines who is an administrator: a
-- user with at least one row here is an administrator, limited to exactly these
-- resources. A user with no rows is a regular user.
-- ---------------------------------------------------------------------------
CREATE TABLE administrator_resource (
	administrator_resource_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id INT UNSIGNED NOT NULL,
	resource_id INT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY ( administrator_resource_id ),
	UNIQUE KEY unique_administrator_resource ( user_id, resource_id ),
	KEY index_administrator_resource_user ( user_id ),
	KEY index_administrator_resource_resource ( resource_id ),
	CONSTRAINT foreign_key_administrator_resource_user FOREIGN KEY ( user_id ) REFERENCES user ( user_id ) ON DELETE CASCADE,
	CONSTRAINT foreign_key_administrator_resource_resource FOREIGN KEY ( resource_id ) REFERENCES resource ( resource_id ) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- setting
-- Application level key / value configuration. The reservation limit per user
-- is stored here so it can be changed without touching the code.
-- ---------------------------------------------------------------------------
CREATE TABLE setting (
	setting_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	setting_key VARCHAR( 100 ) NOT NULL,
	setting_value VARCHAR( 255 ) NOT NULL,
	description VARCHAR( 255 ) DEFAULT NULL,
	PRIMARY KEY ( setting_id ),
	UNIQUE KEY unique_setting_key ( setting_key )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- log
-- Append only record of notable events ( registration, login, reservation
-- changes and administrator decisions ). The user_id is kept nullable so a row
-- survives even if the account is later removed.
-- ---------------------------------------------------------------------------
CREATE TABLE log (
	log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id INT UNSIGNED DEFAULT NULL,
	event_type VARCHAR( 50 ) NOT NULL,
	message VARCHAR( 500 ) DEFAULT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY ( log_id ),
	KEY index_log_event_type ( event_type ),
	KEY index_log_created_at ( created_at ),
	CONSTRAINT foreign_key_log_user FOREIGN KEY ( user_id ) REFERENCES user ( user_id ) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- message
-- A message written by a user about a resource. It is part of the per-resource
-- conversation that the resource's administrators and the participating users
-- can see.
-- ---------------------------------------------------------------------------
CREATE TABLE message (
	message_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	resource_id INT UNSIGNED NOT NULL,
	sender_user_id INT UNSIGNED NOT NULL,
	body VARCHAR( 2000 ) NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY ( message_id ),
	KEY index_message_resource ( resource_id ),
	KEY index_message_sender ( sender_user_id ),
	CONSTRAINT foreign_key_message_resource FOREIGN KEY ( resource_id ) REFERENCES resource ( resource_id ) ON DELETE CASCADE,
	CONSTRAINT foreign_key_message_sender FOREIGN KEY ( sender_user_id ) REFERENCES user ( user_id ) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- notification
-- A queued notification for one recipient. The Android app polls for the rows
-- that are not yet delivered, shows them as system notifications, and the
-- server marks them delivered so they are shown only once.
-- ---------------------------------------------------------------------------
CREATE TABLE notification (
	notification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id INT UNSIGNED NOT NULL,
	notification_type VARCHAR( 30 ) NOT NULL,
	title VARCHAR( 150 ) NOT NULL,
	body VARCHAR( 500 ) NOT NULL,
	-- is_delivered tracks whether the row was already shown as a system
	-- notification; is_read tracks whether the user has opened the inbox since.
	is_delivered TINYINT( 1 ) NOT NULL DEFAULT 0,
	is_read TINYINT( 1 ) NOT NULL DEFAULT 0,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY ( notification_id ),
	KEY index_notification_user ( user_id ),
	KEY index_notification_is_delivered ( is_delivered ),
	KEY index_notification_is_read ( is_read ),
	CONSTRAINT foreign_key_notification_user FOREIGN KEY ( user_id ) REFERENCES user ( user_id ) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- rate_limit
-- One row per throttled attempt ( a sign in, a registration, a message, an
-- account deletion request and so on ). Each row records the action together
-- with the caller's identifier ( IP address, email or user id ). The API counts
-- the rows inside a sliding time window to decide whether to allow the next
-- attempt, and deletes rows that have aged out of the window. It has no foreign
-- keys on purpose: it must keep working for callers that are not signed in.
-- ---------------------------------------------------------------------------
CREATE TABLE rate_limit (
	rate_limit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	rate_limit_key VARCHAR( 190 ) NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY ( rate_limit_id ),
	KEY index_rate_limit_key_created ( rate_limit_key, created_at )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------------

INSERT INTO setting ( setting_key, setting_value, description ) VALUES
	( 'maximum_active_reservations_per_user', '5', 'How many pending or confirmed reservations a single user may hold at the same time.' );

INSERT INTO resource ( resource_name, description, maximum_period_value, maximum_period_unit, is_active ) VALUES
	( 'Meeting Room A', 'Large meeting room with projector.', 7, 'day', 1 ),
	( 'Company Car', 'Shared company car for business trips.', 3, 'day', 1 ),
	( 'Laboratory Microscope', 'High resolution microscope, hourly booking.', 8, 'hour', 1 );