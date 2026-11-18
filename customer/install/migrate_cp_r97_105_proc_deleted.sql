-- GAS-CP-R97-105 (v1.178.0) — Procurement: record of deleted requisitions, purchase orders and RFQs (number, reason, who, when, content)
CREATE TABLE IF NOT EXISTS proc_deleted (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_kind      ENUM('po','rfq','pr') NOT NULL,
  doc_no        VARCHAR(40) NOT NULL,
  title         VARCHAR(255) NULL,
  party         VARCHAR(200) NULL,
  project_ref   VARCHAR(120) NULL,
  status_was    VARCHAR(20) NOT NULL,
  total         DECIMAL(14,2) NULL,
  currency      CHAR(3) NULL,
  n_lines       INT UNSIGNED NOT NULL DEFAULT 0,
  reason        VARCHAR(300) NOT NULL,
  snapshot      MEDIUMTEXT NULL,
  deleted_by    INT UNSIGNED NULL,
  deleted_name  VARCHAR(120) NULL,
  ip            VARCHAR(64) NULL,
  deleted_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_proc_deleted_no (doc_no),
  KEY ix_proc_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
