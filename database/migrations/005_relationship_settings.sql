CREATE TABLE world_relationship_settings (
 world_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 allow_parent_child_partnerships BOOLEAN NOT NULL DEFAULT FALSE,
 CONSTRAINT fk_relationship_settings_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE
) ENGINE=InnoDB;
