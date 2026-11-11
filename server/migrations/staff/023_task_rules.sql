CREATE TABLE jm_staff_task_rules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(200) NOT NULL,
 month_day TINYINT UNSIGNED NOT NULL,
 UNIQUE (employee_id,title,month_day),
 FOREIGN KEY (employee_id) REFERENCES jm_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
