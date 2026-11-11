CREATE TABLE jm_staff_cash (
 employee_id BIGINT UNSIGNED NOT NULL,
 cash_date DATE NOT NULL,
 amount DECIMAL(15,2) NOT NULL,
 note VARCHAR(300) NOT NULL,
 PRIMARY KEY (employee_id,cash_date),
 FOREIGN KEY (employee_id) REFERENCES jm_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
