CREATE TABLE IF NOT EXISTS jm_bonus_settings (
 employee_id BIGINT UNSIGNED NOT NULL,
 year SMALLINT UNSIGNED NOT NULL,
 base_gross DECIMAL(12,2) NULL,
 monthly_bonus DECIMAL(12,2) NULL,
 quarterly_bonus DECIMAL(12,2) NULL,
 team_bonus DECIMAL(12,2) NULL,
 threshold_percent DECIMAL(7,2) NOT NULL DEFAULT 100,
 PRIMARY KEY (employee_id,year),
 FOREIGN KEY (employee_id) REFERENCES jm_employees(id),
 CHECK (base_gross IS NULL OR base_gross >= 0),
 CHECK (monthly_bonus IS NULL OR monthly_bonus >= 0),
 CHECK (quarterly_bonus IS NULL OR quarterly_bonus >= 0),
 CHECK (team_bonus IS NULL OR team_bonus >= 0),
 CHECK (threshold_percent > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
