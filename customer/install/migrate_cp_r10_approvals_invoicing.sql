-- =============================================================== R10
-- 1. Owner approvals decided online: who / when / from where, a decision
--    note, and a comment thread per document (owner <-> Golden A/S).
-- 2. Invoicing status per sub-project (not invoiced / partial / full).
-- Run once, after migrate_cp_r9_systems.sql.
-- ---------------------------------------------------------------------
ALTER TABLE owner_approvals
  ADD COLUMN decided_by    INT UNSIGNED NULL AFTER decided_at,
  ADD COLUMN decided_name  VARCHAR(120) NULL AFTER decided_by,
  ADD COLUMN decided_ip    VARCHAR(45)  NULL AFTER decided_name,
  ADD COLUMN decided_time  DATETIME     NULL AFTER decided_ip,
  ADD COLUMN decision_note TEXT         NULL AFTER decided_time,
  ADD COLUMN on_behalf     TINYINT(1)   NOT NULL DEFAULT 0 AFTER decision_note;

CREATE TABLE IF NOT EXISTS oa_comments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  approval_id INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  author      VARCHAR(120) NULL,
  side        ENUM('customer','golden') NOT NULL DEFAULT 'customer',
  kind        ENUM('comment','approved','approved_comments','rejected','resubmitted') NOT NULL DEFAULT 'comment',
  body        TEXT NULL,
  ip          VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_oac_approval (approval_id),
  CONSTRAINT fk_oac_approval FOREIGN KEY (approval_id) REFERENCES owner_approvals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE subprojects
  ADD COLUMN invoicing    ENUM('none','partial','full') NOT NULL DEFAULT 'none' AFTER status,
  ADD COLUMN invoiced_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER invoicing;
