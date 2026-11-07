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
