CREATE TABLE jm_staff_task_checks (
 rule_id BIGINT UNSIGNED NOT NULL,
 period DATE NOT NULL,
 PRIMARY KEY (rule_id,period),
 FOREIGN KEY (rule_id) REFERENCES jm_staff_task_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB;
