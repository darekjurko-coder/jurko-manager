-- Wygenerowano z server/migrations; nie edytuj ręcznie.
-- Pusty schemat, bez kont i danych firmowych.

-- Moduł: core/001_migrations.sql
CREATE TABLE IF NOT EXISTS jm_migrations (
 version VARCHAR(64) PRIMARY KEY,
 applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Moduł: employees/002_employees.sql
CREATE TABLE IF NOT EXISTS jm_employees (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 display_name VARCHAR(120) NOT NULL,
 department ENUM('sales','warehouse','drivers','administration') NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Moduł: auth/003_accounts.sql
CREATE TABLE IF NOT EXISTS jm_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_id BIGINT UNSIGNED NULL UNIQUE,
 email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NULL,
 role ENUM('owner','manager','employee') NOT NULL DEFAULT 'employee',
 active BOOLEAN NOT NULL DEFAULT FALSE,
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (employee_id) REFERENCES jm_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Moduł: employees/004_results.sql
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

-- Moduł: employees/005_bonuses.sql
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

-- Moduł: finance/006_entries.sql
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

-- Moduł: tasks/007_tasks.sql
CREATE TABLE IF NOT EXISTS jm_tasks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company ENUM('zoo','spk') NOT NULL,
 title VARCHAR(200) NOT NULL,
 assignee_id BIGINT UNSIGNED NULL,
 due_date DATE NOT NULL,
 status ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo',
 priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (assignee_id) REFERENCES jm_employees(id),
 FOREIGN KEY (created_by) REFERENCES jm_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Moduł: audit/008_log.sql
CREATE TABLE IF NOT EXISTS jm_audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NULL,
 action VARCHAR(64) NOT NULL,
 entity_type VARCHAR(64) NOT NULL,
 entity_id VARCHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (actor_id) REFERENCES jm_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Moduł: finance/009_entry_archive.sql
ALTER TABLE jm_financial_entries ADD COLUMN deleted_at DATETIME NULL;

-- Moduł: tasks/010_task_persistence.sql
ALTER TABLE jm_tasks ADD COLUMN owner_label VARCHAR(80) NOT NULL DEFAULT '', ADD COLUMN deleted_at DATETIME NULL, ADD COLUMN version BIGINT UNSIGNED NOT NULL DEFAULT 1;

-- Moduł: employees/011_employee_identity.sql
ALTER TABLE jm_employees ADD COLUMN public_id VARCHAR(64) NULL UNIQUE, ADD COLUMN plan_metric ENUM('turnover','tasks','deliveries') NOT NULL DEFAULT 'turnover';

-- Moduł: core/012_module_revisions.sql
CREATE TABLE IF NOT EXISTS jm_module_revisions (
 module_key VARCHAR(32) PRIMARY KEY,
 version BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Moduł: core/013_write_requests.sql
CREATE TABLE IF NOT EXISTS jm_write_requests (
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) NOT NULL,
 action VARCHAR(32) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (actor_id,request_id),
 FOREIGN KEY (actor_id) REFERENCES jm_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Moduł: sales/014_commissions.sql
CREATE TABLE IF NOT EXISTS jm_sales_imports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_id BIGINT UNSIGNED NOT NULL,
 company ENUM('zoo','spk') NOT NULL,
 report_month CHAR(7) NOT NULL,
 filename VARCHAR(180) NOT NULL,
 file_hash CHAR(64) NOT NULL,
 source_json MEDIUMTEXT NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY(employee_id,company,file_hash),
 FOREIGN KEY(employee_id) REFERENCES jm_employees(id),
 FOREIGN KEY(created_by) REFERENCES jm_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jm_sales_rules (
 employee_id BIGINT UNSIGNED PRIMARY KEY,
 rate_bp SMALLINT UNSIGNED NOT NULL,
 FOREIGN KEY(employee_id) REFERENCES jm_employees(id),
 CHECK(rate_bp<=10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jm_sales_months (
 employee_id BIGINT UNSIGNED NOT NULL,
 month CHAR(7) NOT NULL,
 rate_bp SMALLINT UNSIGNED NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 costs_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
 snapshot_json MEDIUMTEXT NULL,
 snapshot_revision BIGINT UNSIGNED NULL,
 calculated_at DATETIME NULL,
 PRIMARY KEY(employee_id,month),
 FOREIGN KEY(employee_id) REFERENCES jm_employees(id),
 CHECK(rate_bp IS NULL OR rate_bp<=10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jm_sales_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_id BIGINT UNSIGNED NOT NULL,
 import_id BIGINT UNSIGNED NOT NULL,
 source_row INT UNSIGNED NOT NULL,
 company ENUM('zoo','spk') NOT NULL,
 document_no VARCHAR(100) NOT NULL,
 document_type ENUM('invoice','correction','order','other') NOT NULL,
 document_date DATE NOT NULL,
 settlement_month CHAR(7) NOT NULL,
 customer VARCHAR(250) NOT NULL,
 turnover_net DECIMAL(15,2) NOT NULL,
 invoice_net DECIMAL(15,2) NOT NULL,
 source_deduction DECIMAL(15,2) NOT NULL DEFAULT 0,
 source_profit DECIMAL(15,2) NOT NULL,
 profit_override DECIMAL(15,2) NULL,
 share_bp SMALLINT UNSIGNED NOT NULL DEFAULT 10000,
 note TEXT NOT NULL,
 reviewed BOOLEAN NOT NULL DEFAULT FALSE,
 commission_included BOOLEAN NOT NULL DEFAULT TRUE,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 UNIQUE KEY(employee_id,company,document_no,document_date),
 INDEX(employee_id,settlement_month),
 FOREIGN KEY(employee_id) REFERENCES jm_employees(id),
 FOREIGN KEY(import_id) REFERENCES jm_sales_imports(id),
 CHECK(share_bp<=10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jm_sales_costs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_id BIGINT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NULL,
 month CHAR(7) NOT NULL,
 category ENUM('transport','cutting','architect','other') NOT NULL,
 description VARCHAR(250) NOT NULL,
 quantity DECIMAL(12,4) NOT NULL,
 unit_price DECIMAL(15,2) NOT NULL,
 percent_bp SMALLINT UNSIGNED NULL,
 amount_net DECIMAL(15,2) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 deleted_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(employee_id) REFERENCES jm_employees(id),
 FOREIGN KEY(document_id) REFERENCES jm_sales_documents(id),
 FOREIGN KEY(created_by) REFERENCES jm_accounts(id),
 INDEX(employee_id,month),
 CHECK(amount_net>=0),
 CHECK(percent_bp IS NULL OR percent_bp<=10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
