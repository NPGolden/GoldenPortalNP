-- GAS-CP-R97-176 (v1.212.0): a newsletter issue can also be published as an article on the website (News). Idempotent.
ALTER TABLE nl_issues ADD COLUMN IF NOT EXISTS web_publish TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE nl_issues ADD COLUMN IF NOT EXISTS web_slug VARCHAR(80) NULL;
ALTER TABLE nl_issues ADD COLUMN IF NOT EXISTS web_at DATETIME NULL;
