ALTER TABLE jm_employees ADD COLUMN public_id VARCHAR(64) NULL UNIQUE, ADD COLUMN plan_metric ENUM('turnover','tasks','deliveries') NOT NULL DEFAULT 'turnover';
