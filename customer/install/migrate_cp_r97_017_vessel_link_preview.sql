-- R97-017 (v1.128.1): Vessels — preview of the web link (title and description of the linked page, read once when the link is saved).
ALTER TABLE vessels ADD COLUMN info_title VARCHAR(200) NULL AFTER info_url;
ALTER TABLE vessels ADD COLUMN info_desc VARCHAR(600) NULL AFTER info_title;
ALTER TABLE vessels ADD COLUMN info_checked_at DATETIME NULL AFTER info_desc;
