-- GAS-CP-R90-001 — website statistics feed settings (key empty = feed off; the administrator creates it under Settings › System › Website)
SET NAMES utf8mb4;
INSERT IGNORE INTO cp_settings (k, v) VALUES ('web_site_key', ''), ('web_last_pull', '');
