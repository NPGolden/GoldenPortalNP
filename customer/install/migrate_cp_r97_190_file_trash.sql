-- GAS-CP-R97-190 — recycle bin for uploaded files (30 days) and supporting documents of system budgets.
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS file_trash (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tbl          VARCHAR(40)  NOT NULL,               -- the table the record came from (sp_files, ticket_files, …)
  row_id       INT UNSIGNED NOT NULL,               -- its id there; a restore puts it back under the same id
  row_json     MEDIUMTEXT   NOT NULL,               -- the whole record
  refs_json    TEXT         NULL,                   -- records that pointed to it and were unlinked: [[table, column, [ids]], …]
  path         VARCHAR(500) NOT NULL,               -- where the bytes stay until the purge (absolute)
  label        VARCHAR(255) NOT NULL,
  context      VARCHAR(255) NULL,                   -- project, offer, ticket … in words
  size_bytes   BIGINT UNSIGNED NULL,
  protected    TINYINT(1)   NOT NULL DEFAULT 0,     -- issued / sent / signed: removed by an administrator with a reason
  reason       VARCHAR(300) NULL,
  deleted_by   INT UNSIGNED NULL,
  deleted_name VARCHAR(160) NULL,
  deleted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  purge_after  DATE         NOT NULL,
  restored_at  DATETIME     NULL,
  restored_by  INT UNSIGNED NULL,
  purged_at    DATETIME     NULL,
  INDEX idx_ft_open (purged_at, restored_at, purge_after),
  INDEX idx_ft_row (tbl, row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sb_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id     INT UNSIGNED NOT NULL,
  title         VARCHAR(200) NULL,
  stored_name   VARCHAR(190) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
  sha256        CHAR(64)     NULL,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_name VARCHAR(160) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sbf_budget (budget_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
