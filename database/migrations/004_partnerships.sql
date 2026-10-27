CREATE TABLE partnerships (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 world_id BIGINT UNSIGNED NOT NULL,
 mother_id BIGINT UNSIGNED NOT NULL,
 father_id BIGINT UNSIGNED NOT NULL,
 started_day BIGINT UNSIGNED NOT NULL,
 ended_day BIGINT UNSIGNED NULL,
 KEY idx_partner_world (world_id,ended_day),
 CONSTRAINT fk_partner_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
 CONSTRAINT fk_partner_mother FOREIGN KEY (mother_id) REFERENCES people(id),
 CONSTRAINT fk_partner_father FOREIGN KEY (father_id) REFERENCES people(id),
 CONSTRAINT chk_partner_distinct CHECK (mother_id <> father_id)
) ENGINE=InnoDB;
