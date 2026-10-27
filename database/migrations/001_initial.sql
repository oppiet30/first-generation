CREATE TABLE worlds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 seed BIGINT UNSIGNED NOT NULL,
 rng_state BIGINT UNSIGNED NOT NULL,
 current_day BIGINT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('paused','running','completed') NOT NULL DEFAULT 'paused',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE people (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 world_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(100) NOT NULL,
 sex ENUM('female','male') NOT NULL,
 birth_day BIGINT UNSIGNED NOT NULL DEFAULT 0,
 death_day BIGINT UNSIGNED NULL,
 mother_id BIGINT UNSIGNED NULL,
 father_id BIGINT UNSIGNED NULL,
 health TINYINT UNSIGNED NOT NULL DEFAULT 100,
 hunger TINYINT UNSIGNED NOT NULL DEFAULT 0,
 energy TINYINT UNSIGNED NOT NULL DEFAULT 100,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_people_world_alive (world_id, death_day),
 KEY idx_people_mother (mother_id),
 KEY idx_people_father (father_id),
 CONSTRAINT fk_people_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
 CONSTRAINT fk_people_mother FOREIGN KEY (mother_id) REFERENCES people(id) ON DELETE SET NULL,
 CONSTRAINT fk_people_father FOREIGN KEY (father_id) REFERENCES people(id) ON DELETE SET NULL,
 CONSTRAINT chk_people_health CHECK (health <= 100),
 CONSTRAINT chk_people_hunger CHECK (hunger <= 100),
 CONSTRAINT chk_people_energy CHECK (energy <= 100),
 CONSTRAINT chk_people_life CHECK (death_day IS NULL OR death_day >= birth_day)
) ENGINE=InnoDB;

CREATE TABLE world_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 world_id BIGINT UNSIGNED NOT NULL,
 day BIGINT UNSIGNED NOT NULL,
 person_id BIGINT UNSIGNED NULL,
 event_type VARCHAR(32) NOT NULL,
 details VARCHAR(255) NOT NULL,
 KEY idx_events_world_day (world_id, day, id),
 CONSTRAINT fk_events_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
 CONSTRAINT fk_events_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL
) ENGINE=InnoDB;
