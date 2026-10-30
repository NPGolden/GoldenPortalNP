USE goldensas_portal;
-- =============================================================== R15
-- Offers sent to the owner per project / sub-project (status, amount,
-- aging, online approval), deadlines on projects and sub-projects, and
-- the PM's weekly remarks (foreseen delays) behind the weekly vessel report.
-- Run once, after migrate_cp_r14_tickets.sql.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS offers (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id     INT UNSIGNED NOT NULL,
  subproject_id  INT UNSIGNED NULL,
  offer_no       VARCHAR(60)  NOT NULL,                      -- same identity as the sub-project, e.g. GY-85-02 Y.01
  revision       VARCHAR(8)   NOT NULL DEFAULT 'R0',
  title          VARCHAR(200) NOT NULL,
  amount         DECIMAL(14,2) NOT NULL DEFAULT 0,            -- customer amount
  currency       CHAR(3)      NOT NULL DEFAULT 'EUR',
  status         ENUM('sent','rejected','cancelled','approved','in_progress','to_be_invoiced','finished') NOT NULL DEFAULT 'sent',
  sent_at        DATE NULL,
  valid_until    DATE NULL,
  status_at      DATE NULL,                                  -- when the current status was set (aging)
  decided_by     INT UNSIGNED NULL,
  decided_name   VARCHAR(120) NULL,
  decided_at     DATETIME NULL,
  decided_ip     VARCHAR(45)  NULL,
  decision_note  TEXT NULL,
  file_id        INT UNSIGNED NULL,
  notes          TEXT NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_of_project (project_id, status),
  INDEX idx_of_sub (subproject_id),
  CONSTRAINT fk_of_project FOREIGN KEY (project_id)    REFERENCES projects(id)    ON DELETE CASCADE,
  CONSTRAINT fk_of_sub     FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE SET NULL,
  CONSTRAINT fk_of_file    FOREIGN KEY (file_id)       REFERENCES sp_files(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offer_events (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_id   INT UNSIGNED NOT NULL,
  event      VARCHAR(30)  NOT NULL,
  user_id    INT UNSIGNED NULL,
  user_name  VARCHAR(120) NULL,
  note       VARCHAR(1000) NULL,
  ip         VARCHAR(45)  NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ofe_offer (offer_id),
  CONSTRAINT fk_ofe_offer FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sp_files
  MODIFY kind ENUM('photo','drawing','owner_doc','class_doc','fat','hat','sat','weekly','monthly','ga','other','fsr_photo','fsr_sign','fsr_doc','offer') NOT NULL DEFAULT 'other';

-- deadlines: the project as a whole and each sub-project
ALTER TABLE projects    ADD COLUMN deadline DATE NULL AFTER next_milestone;
ALTER TABLE subprojects ADD COLUMN deadline DATE NULL AFTER status;

-- the PM's weekly remarks per project: foreseen delays and general remarks
CREATE TABLE IF NOT EXISTS weekly_notes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  INT UNSIGNED NOT NULL,
  week_start  DATE NOT NULL,                                 -- Monday
  delays      TEXT NULL,
  remarks     TEXT NULL,
  author_id   INT UNSIGNED NULL,
  author      VARCHAR(120) NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wn (project_id, week_start),
  CONSTRAINT fk_wn_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
