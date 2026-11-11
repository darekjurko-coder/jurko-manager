CREATE TABLE jm_staff_receivables (
 employee_id BIGINT UNSIGNED NOT NULL,
 document VARCHAR(100) NOT NULL,
 customer VARCHAR(150) NOT NULL,
 balance DECIMAL(15,2) NOT NULL,
 PRIMARY KEY (employee_id,document),
 FOREIGN KEY (employee_id) REFERENCES jm_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
