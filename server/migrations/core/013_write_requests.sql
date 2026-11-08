CREATE TABLE IF NOT EXISTS jm_write_requests (
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) NOT NULL,
 action VARCHAR(32) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (actor_id,request_id),
 FOREIGN KEY (actor_id) REFERENCES jm_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
