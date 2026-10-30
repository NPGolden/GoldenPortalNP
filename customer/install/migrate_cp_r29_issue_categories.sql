-- GAS-CP-R29-004 — configurable issue categories (v1.29.3)
-- Run once in phpMyAdmin (select the portal database first).
SET NAMES utf8mb4;
ALTER TABLE sp_issues ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'other' AFTER source, ADD KEY idx_iss_cat (category);
CREATE TABLE IF NOT EXISTS issue_categories (
  skey       VARCHAR(30) NOT NULL,
  label      VARCHAR(80) NOT NULL,
  tone       VARCHAR(10) NOT NULL DEFAULT 'mute',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  is_builtin TINYINT(1) NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO issue_categories (skey, label, tone, sort_order, is_builtin, is_active) VALUES
 ('mechanical','Mechanical','navy',10,1,1), ('electrical','Electrical','gold',20,1,1), ('automation','Automation','blue',30,1,1), ('navcom','Navigation & communication','blue',40,1,1),
 ('software','Software / configuration','green',50,1,1), ('documentation','Documentation / class','mute',60,1,1), ('logistics','Logistics / spare parts','gold',70,1,1),
 ('commercial','Commercial / offer','mute',80,1,1), ('safety','Safety','bad',90,1,1), ('other','Other','mute',100,1,1);
