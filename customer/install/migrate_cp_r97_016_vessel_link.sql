-- R97-016 (v1.128.0): Vessels — a web link to information about the vessel that is kept online.
ALTER TABLE vessels ADD COLUMN info_url VARCHAR(500) NULL AFTER yard;
