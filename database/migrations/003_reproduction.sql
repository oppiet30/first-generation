CREATE TABLE pregnancies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 world_id BIGINT UNSIGNED NOT NULL,
 mother_id BIGINT UNSIGNED NOT NULL,
 father_id BIGINT UNSIGNED NOT NULL,
 conceived_day BIGINT UNSIGNED NOT NULL,
 due_day BIGINT UNSIGNED NOT NULL,
 delivered_day BIGINT UNSIGNED NULL,
 child_id BIGINT UNSIGNED NULL,
 KEY idx_pregnancies_due (world_id,delivered_day,due_day),
 CONSTRAINT fk_preg_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
 CONSTRAINT fk_preg_mother FOREIGN KEY (mother_id) REFERENCES people(id),
 CONSTRAINT fk_preg_father FOREIGN KEY (father_id) REFERENCES people(id),
 CONSTRAINT fk_preg_child FOREIGN KEY (child_id) REFERENCES people(id),
 CONSTRAINT chk_preg_due CHECK (due_day > conceived_day)
) ENGINE=InnoDB;
