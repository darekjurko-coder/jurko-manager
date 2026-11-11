CREATE TABLE jm_staff_sales (
 employee_id BIGINT UNSIGNED NOT NULL,
 period DATE NOT NULL,
 turnover DECIMAL(15,2) NULL,
 projects INT UNSIGNED NULL,
 PRIMARY KEY (employee_id,period),
 FOREIGN KEY (employee_id) REFERENCES jm_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
