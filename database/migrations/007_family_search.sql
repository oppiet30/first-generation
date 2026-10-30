-- Prefix name search for the family tree, scoped to one world.
CREATE INDEX idx_people_world_name ON people (world_id,name,id);
-- Selecting the first founder must not sort every person in a large world.
CREATE INDEX idx_people_world_id ON people (world_id,id);
