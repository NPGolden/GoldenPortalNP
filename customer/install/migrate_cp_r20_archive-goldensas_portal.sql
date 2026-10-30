USE goldensas_portal;
-- GAS-CP-R20-001 · File archive: structured folders and document-numbered file names for every stored file,
-- checksums, document sequences, issued documents and the reorganise/undo log.   (doc GAS-2026-PORTAL-STO-001)
-- Re-running reports "duplicate column" / "already exists" — harmless.
ALTER TABLE sp_files
  ADD COLUMN doc_no   VARCHAR(80) NULL AFTER title,
  ADD COLUMN revision VARCHAR(16) NULL AFTER doc_no,
  ADD COLUMN seq      SMALLINT UNSIGNED NULL AFTER revision,
  ADD COLUMN sha256   CHAR(64) NULL AFTER size_bytes,
  ADD COLUMN archived_at DATETIME NULL AFTER sha256;
ALTER TABLE sp_files MODIFY COLUMN kind ENUM('photo','drawing','owner_doc','class_doc','fat','hat','sat','weekly','monthly','ga','other','fsr_photo','fsr_sign','fsr_doc','offer','issued','file','comment') NOT NULL DEFAULT 'other';
ALTER TABLE ticket_files
  ADD COLUMN seq    SMALLINT UNSIGNED NULL AFTER original_name,
  ADD COLUMN sha256 CHAR(64) NULL AFTER size_bytes,
  ADD COLUMN archived_at DATETIME NULL AFTER sha256;
CREATE TABLE IF NOT EXISTS doc_sequences (
  scope   VARCHAR(80) NOT NULL,          -- e.g. "sub:12" or "project:3"
  family  VARCHAR(12) NOT NULL,          -- DRW · FAT · HAT · SAT · FILE · GA · WN · DOC
  last_no INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (scope, family)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS archive_moves (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id    VARCHAR(20) NOT NULL,
  tbl       VARCHAR(20) NOT NULL,        -- sp_files | ticket_files
  file_id   INT UNSIGNED NOT NULL,
  old_path  VARCHAR(255) NOT NULL,
  new_path  VARCHAR(255) NOT NULL,
  moved_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  undone_at DATETIME NULL,
  KEY idx_am_run (run_id), KEY idx_am_file (tbl, file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
