-- GAS-CP-R27-003 — equipment linked to inspection reports, tasks and contacts (v1.27.2)
-- Run once in phpMyAdmin (select the portal database first). Safe to re-run only if the tables do not exist yet.
SET NAMES utf8mb4;
ALTER TABLE equipment_history
  MODIFY source ENUM('import','manual','fsr','ticket','isr','task') NOT NULL DEFAULT 'manual',
  ADD COLUMN isr_id  INT UNSIGNED NULL AFTER ticket_id,
  ADD COLUMN task_id INT UNSIGNED NULL AFTER isr_id,
  ADD KEY idx_eqh_isr (isr_id), ADD KEY idx_eqh_task (task_id);
CREATE TABLE IF NOT EXISTS isr_equipment (
  report_id INT UNSIGNED NOT NULL, equipment_id INT UNSIGNED NOT NULL, PRIMARY KEY (report_id, equipment_id), KEY idx_isreq_eq (equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS task_equipment (
  task_id INT UNSIGNED NOT NULL, equipment_id INT UNSIGNED NOT NULL, PRIMARY KEY (task_id, equipment_id), KEY idx_tdeq_eq (equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS equipment_contacts (
  equipment_id INT UNSIGNED NOT NULL, contact_id INT UNSIGNED NOT NULL, role VARCHAR(80) NULL, added_by INT UNSIGNED NULL, added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (equipment_id, contact_id), KEY idx_eqct_ct (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
