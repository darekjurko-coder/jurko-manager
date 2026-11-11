CREATE TABLE jm_staff_login_attempts (
 bucket CHAR(64) CHARACTER SET ascii PRIMARY KEY,
 hits INT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL,
 INDEX (expires_at)
) ENGINE=InnoDB;
