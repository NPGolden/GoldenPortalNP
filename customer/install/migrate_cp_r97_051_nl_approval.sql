-- GAS-CP-R97-051 (v1.146.0) — Newsletters: approval by the CEO / General Manager before an issue is sent
ALTER TABLE nl_issues ADD COLUMN appr_state VARCHAR(10) NULL;
ALTER TABLE nl_issues ADD COLUMN appr_req_by INT UNSIGNED NULL;
ALTER TABLE nl_issues ADD COLUMN appr_req_name VARCHAR(120) NULL;
ALTER TABLE nl_issues ADD COLUMN appr_req_at DATETIME NULL;
ALTER TABLE nl_issues ADD COLUMN appr_note VARCHAR(300) NULL;
ALTER TABLE nl_issues ADD COLUMN appr_by INT UNSIGNED NULL;
ALTER TABLE nl_issues ADD COLUMN appr_name VARCHAR(120) NULL;
ALTER TABLE nl_issues ADD COLUMN appr_at DATETIME NULL;
ALTER TABLE nl_issues ADD COLUMN appr_reply VARCHAR(500) NULL;
ALTER TABLE nl_issues ADD COLUMN appr_hash CHAR(40) NULL;
ALTER TABLE nl_issues ADD COLUMN appr_n INT NULL;
