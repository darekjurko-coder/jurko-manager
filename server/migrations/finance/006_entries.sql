CREATE TABLE IF NOT EXISTS jm_financial_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company ENUM('zoo','spk') NOT NULL,
 entry_type ENUM('cost','revenue') NOT NULL,
 entry_date DATE NOT NULL,
 description VARCHAR(200) NOT NULL,
 cost_code VARCHAR(8) NULL,
 amount_net DECIMAL(15,2) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX (company,entry_date),
 FOREIGN KEY (created_by) REFERENCES jm_accounts(id),
 CHECK (amount_net > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
