ALTER TABLE people ADD COLUMN initial_age_days INT UNSIGNED NOT NULL DEFAULT 0 AFTER birth_day;
UPDATE people SET initial_age_days=7300 WHERE mother_id IS NULL AND father_id IS NULL AND birth_day=0 AND name IN ('Adam','Eve');
