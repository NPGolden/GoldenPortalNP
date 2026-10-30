USE goldensas_portal;
-- GAS-CP-R22-001 · Licence (serial number + service contract) and GAS-CP-R22-002 · Task scheduler with reminders.
-- Select the portal database first (goldensas_portal). Re-running reports "already exists" — harmless.

-- Small key/value store: licence key, installation id, licence bookkeeping.
CREATE TABLE IF NOT EXISTS cp_settings (
  k            VARCHAR(60) NOT NULL PRIMARY KEY,
  v            TEXT NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks and reminders (Work › Tasks). One row per task; a recurring task keeps one row and moves its due date forward
-- each time it is completed or skipped, like a recurring item in Microsoft To Do. task_log keeps every completion.
CREATE TABLE IF NOT EXISTS tasks (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(200) NOT NULL,
  notes          TEXT NULL,
  kind           VARCHAR(20) NOT NULL DEFAULT 'general',      -- general · weekly · monthly · vessel · timesheet · fsr · progress · offer · ticket · class · drawings
  project_id     INT UNSIGNED NULL,
  subproject_id  INT UNSIGNED NULL,
  vessel_id      INT UNSIGNED NULL,
  owner_id       INT UNSIGNED NULL,                             -- who created it
  assignee_id    INT UNSIGNED NULL,                             -- NULL = the project team (project manager + administrators)
  priority       ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  due_at         DATETIME NOT NULL,                             -- the current (next) occurrence
  recur          ENUM('none','daily','weekly','monthly','yearly') NOT NULL DEFAULT 'none',
  recur_every    TINYINT UNSIGNED NOT NULL DEFAULT 1,           -- every n days / weeks / months / years
  recur_days     VARCHAR(20) NULL,                              -- weekly: ISO weekdays, e.g. '1,3,5'
  recur_dom      TINYINT UNSIGNED NULL,                         -- monthly: day of month (1-31, 31 = last day)
  recur_end      DATE NULL,                                     -- no occurrence after this date
  remind_before  INT UNSIGNED NOT NULL DEFAULT 0,               -- minutes before due_at (0 = at the due time)
  reminded_at    DATETIME NULL,                                 -- last reminder sent for the current occurrence
  status         ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
  done_at        DATETIME NULL,
  done_by        INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_td_due (status, due_at), KEY idx_td_project (project_id, subproject_id), KEY idx_td_assignee (assignee_id, status),
  KEY idx_td_kind (kind, project_id, subproject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  task_id    INT UNSIGNED NOT NULL,
  event      VARCHAR(20) NOT NULL,                              -- created · edited · done · skipped · reopened · reminded · cancelled
  due_at     DATETIME NULL,                                     -- the occurrence the event refers to
  by_id      INT UNSIGNED NULL,
  note       VARCHAR(400) NULL,
  at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tl_task (task_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GAS-CP-R22-006 · Task types configurable by administrators (Settings › Task types). Built-in rows keep translated labels.
CREATE TABLE IF NOT EXISTS task_kinds (
  skey        VARCHAR(30) NOT NULL PRIMARY KEY,
  label       VARCHAR(80) NOT NULL,
  tone        VARCHAR(10) NOT NULL DEFAULT 'mute',          -- gold · green · blue · navy · mute · bad
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  is_builtin  TINYINT(1) NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO task_kinds (skey, label, tone, sort_order, is_builtin) VALUES
 ('general','General','mute',10,1), ('weekly','Weekly report','gold',20,1), ('monthly','Monthly report','navy',30,1), ('progress','Progress update','green',40,1),
 ('timesheet','Timesheets','blue',50,1), ('vessel','Vessel history','navy',60,1), ('fsr','Service report','green',70,1), ('offer','Offer','gold',80,1),
 ('ticket','Ticket','blue',90,1), ('class','Class submittal','mute',100,1), ('drawings','Drawings','mute',110,1);
