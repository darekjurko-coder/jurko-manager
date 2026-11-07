CREATE TABLE IF NOT EXISTS jm_monthly_results (
 employee_id BIGINT UNSIGNED NOT NULL,
 year SMALLINT UNSIGNED NOT NULL,
 month TINYINT UNSIGNED NOT NULL,
 metric ENUM('turnover','tasks','deliveries') NOT NULL,
 target DECIMAL(15,2) NULL,
 actual DECIMAL(15,2) NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (employee_id,year,month,metric),
 FOREIGN KEY (employee_id) REFERENCES jm_employees(id),
 CHECK (month BETWEEN 1 AND 12),
 CHECK (year BETWEEN 2000 AND 2100),
 CHECK (target IS NULL OR target >= 0),
 CHECK (actual IS NULL OR actual >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
