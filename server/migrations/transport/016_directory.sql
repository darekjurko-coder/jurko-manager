CREATE TABLE IF NOT EXISTS jm_transport_directory (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 kind ENUM('factory','carrier') NOT NULL,
 name VARCHAR(150) NOT NULL,
 country VARCHAR(20) NOT NULL DEFAULT '',
 address VARCHAR(300) NOT NULL DEFAULT '',
 email VARCHAR(254) NOT NULL DEFAULT '',
 phone VARCHAR(16) NOT NULL DEFAULT '',
 UNIQUE KEY transport_contact (kind,name,country,address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
