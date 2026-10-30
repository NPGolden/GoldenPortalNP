-- =============================================================== R14
-- Tickets raised by the customer or Golden A/S against a vessel (and
-- optionally a project / sub-project), with a comment thread, attachments
-- and an audit trail. Work orders and service reports can reference the
-- ticket they answer, which feeds the vessel's technical history.
-- Run once, after migrate_cp_r11_timesheets.sql.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_no      VARCHAR(24)  NOT NULL UNIQUE,                -- TK-YYYY-NNNN
  customer_id    INT UNSIGNED NULL,
  vessel_id      INT UNSIGNED NOT NULL,
  project_id     INT UNSIGNED NULL,
  subproject_id  INT UNSIGNED NULL,
  system_id      INT UNSIGNED NULL,                           -- vessel system concerned
  title          VARCHAR(160) NOT NULL,
  description    TEXT NULL,
  category       ENUM('fault','service','warranty','spare_parts','question','other') NOT NULL DEFAULT 'fault',
  priority       ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  status         ENUM('open','in_progress','waiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
  created_side   ENUM('customer','golden') NOT NULL DEFAULT 'customer',
  created_by     INT UNSIGNED NULL,
  created_name   VARCHAR(120) NULL,
  assigned_to    INT UNSIGNED NULL,
  assigned_name  VARCHAR(120) NULL,
  resolution     TEXT NULL,
  resolved_at    DATETIME NULL,
  resolved_name  VARCHAR(120) NULL,
  closed_at      DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tk_vessel (vessel_id, status),
  INDEX idx_tk_project (project_id),
  INDEX idx_tk_customer (customer_id, status),
  INDEX idx_tk_assigned (assigned_to, status),
  CONSTRAINT fk_tk_vessel  FOREIGN KEY (vessel_id)     REFERENCES vessels(id)        ON DELETE CASCADE,
  CONSTRAINT fk_tk_project FOREIGN KEY (project_id)    REFERENCES projects(id)       ON DELETE SET NULL,
  CONSTRAINT fk_tk_sub     FOREIGN KEY (subproject_id) REFERENCES subprojects(id)    ON DELETE SET NULL,
  CONSTRAINT fk_tk_system  FOREIGN KEY (system_id)     REFERENCES vessel_systems(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_comments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id   INT UNSIGNED NOT NULL,
  parent_id   INT UNSIGNED NULL,
  user_id     INT UNSIGNED NULL,
  author      VARCHAR(120) NULL,
  side        ENUM('customer','golden') NOT NULL DEFAULT 'customer',
  visibility  ENUM('internal','external') NOT NULL DEFAULT 'external',
  body        TEXT NULL,
  ip          VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at  DATETIME NULL,
  INDEX idx_tkc_ticket (ticket_id),
  CONSTRAINT fk_tkc_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id)         ON DELETE CASCADE,
  CONSTRAINT fk_tkc_parent FOREIGN KEY (parent_id) REFERENCES ticket_comments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_files (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id     INT UNSIGNED NOT NULL,
  comment_id    INT UNSIGNED NULL,
  stored_name   VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_name VARCHAR(120) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tkf_ticket (ticket_id),
  CONSTRAINT fk_tkf_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_events (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT UNSIGNED NOT NULL,
  event      VARCHAR(30)  NOT NULL,
  user_id    INT UNSIGNED NULL,
  user_name  VARCHAR(120) NULL,
  note       VARCHAR(1000) NULL,
  ip         VARCHAR(45)  NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tke_ticket (ticket_id),
  CONSTRAINT fk_tke_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- work orders and service reports may answer a ticket
ALTER TABLE job_orders  ADD COLUMN ticket_id INT UNSIGNED NULL AFTER project_id, ADD INDEX idx_jo_ticket (ticket_id);
ALTER TABLE fsr_reports ADD COLUMN ticket_id INT UNSIGNED NULL AFTER job_order_id, ADD INDEX idx_fsr_ticket (ticket_id);
