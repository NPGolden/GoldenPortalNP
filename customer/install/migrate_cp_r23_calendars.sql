-- GAS-CP-R23-001 · Working hours and national holidays per country (Greece, Italy); hour classification on timesheets;
-- manhour cost rates per discipline. Select the portal database first (goldensas_portal). Re-running is harmless.
CREATE TABLE IF NOT EXISTS work_calendars (
  code       VARCHAR(8)  NOT NULL PRIMARY KEY,                 -- GR · IT · …
  name       VARCHAR(80) NOT NULL,
  country    VARCHAR(2)  NOT NULL,                             -- ISO country (standard holiday generator)
  is_active  TINYINT(1)  NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO work_calendars (code, name, country, sort_order) VALUES ('GR', 'Greece', 'GR', 10), ('IT', 'Italy', 'IT', 20);

CREATE TABLE IF NOT EXISTS work_hours (
  calendar_code VARCHAR(8) NOT NULL,
  dow           TINYINT UNSIGNED NOT NULL,                     -- 1 = Monday … 7 = Sunday
  is_workday    TINYINT(1) NOT NULL DEFAULT 1,
  start_time    TIME NULL,
  end_time      TIME NULL,
  break_min     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  off_type      ENUM('overtime','holiday') NOT NULL DEFAULT 'overtime',   -- how hours on a non-working day are classed
  PRIMARY KEY (calendar_code, dow)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO work_hours (calendar_code, dow, is_workday, start_time, end_time, break_min, off_type) VALUES
 ('GR',1,1,'08:30','17:00',30,'overtime'), ('GR',2,1,'08:30','17:00',30,'overtime'), ('GR',3,1,'08:30','17:00',30,'overtime'), ('GR',4,1,'08:30','17:00',30,'overtime'), ('GR',5,1,'08:30','17:00',30,'overtime'), ('GR',6,0,NULL,NULL,0,'overtime'), ('GR',7,0,NULL,NULL,0,'holiday'),
 ('IT',1,1,'08:30','17:30',60,'overtime'), ('IT',2,1,'08:30','17:30',60,'overtime'), ('IT',3,1,'08:30','17:30',60,'overtime'), ('IT',4,1,'08:30','17:30',60,'overtime'), ('IT',5,1,'08:30','17:30',60,'overtime'), ('IT',6,0,NULL,NULL,0,'overtime'), ('IT',7,0,NULL,NULL,0,'holiday');

CREATE TABLE IF NOT EXISTS work_holidays (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  calendar_code VARCHAR(8) NOT NULL,
  hdate         DATE NOT NULL,
  name          VARCHAR(120) NOT NULL,
  source        VARCHAR(12) NOT NULL DEFAULT 'manual',          -- manual · standard · import
  UNIQUE KEY uq_wh (calendar_code, hdate), KEY idx_wh_date (hdate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customer_users ADD COLUMN work_calendar VARCHAR(8) NULL AFTER manager_user_id;
ALTER TABLE disciplines ADD COLUMN rate_normal DECIMAL(8,2) NULL, ADD COLUMN rate_overtime DECIMAL(8,2) NULL, ADD COLUMN rate_holiday DECIMAL(8,2) NULL;
ALTER TABLE timesheet_lines ADD COLUMN auto_type TINYINT(1) NOT NULL DEFAULT 0;
