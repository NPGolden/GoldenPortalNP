-- GAS-CP-R55-001 · Statutory annual-leave engine (entitlements computed from each person's status: hiring date, service at Golden A/S +
-- prior service, 5/6-day week), HR override with lock, per-person change log shown on the employee card, import batches.
-- Run once in phpMyAdmin with the portal database selected, after migrate_cp_r56_003_history_only.sql.
ALTER TABLE hr_entitlements ADD COLUMN auto TINYINT(1) NOT NULL DEFAULT 0;      -- 1 = days follow the engine; 0 = fixed by HR (manual / imported)
CREATE TABLE IF NOT EXISTS hr_audit (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,                      -- who made the change (NULL = engine / import)
  kind VARCHAR(16) NOT NULL,                      -- card | entitlement | employment | document | request | message | import
  field VARCHAR(40) NULL,
  old_value VARCHAR(300) NULL, new_value VARCHAR(300) NULL,
  note VARCHAR(300) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ha_person (person_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- statutory rules, editable by HR (Settings › HR › Rules) — the accountant validates them at go-live
INSERT IGNORE INTO cp_settings (k, v) VALUES ('hr_rules', '{"y1_5":20,"y2_5":21,"y3_5":22,"y1_6":24,"y2_6":25,"y3_6":26,"senior_same":10,"senior_total":12,"senior_5":25,"senior_6":30,"long_total":25,"long_5":26,"long_6":31,"carry_max":5,"round":0.5}');
