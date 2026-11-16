-- GAS-CP-R97-059 (v1.152.1) — Acceptance by e-mail: delivery problems are kept on the link and shown to staff
ALTER TABLE doc_links ADD COLUMN mail_error VARCHAR(255) NULL;
ALTER TABLE doc_links ADD COLUMN mail_error_at DATETIME NULL;
ALTER TABLE doc_links ADD COLUMN bounced_at DATETIME NULL;
ALTER TABLE doc_links ADD COLUMN bounce_reason VARCHAR(255) NULL;
ALTER TABLE doc_links ADD COLUMN expiry_told TINYINT UNSIGNED NOT NULL DEFAULT 0;
