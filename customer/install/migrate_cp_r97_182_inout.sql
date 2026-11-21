-- GAS-CP-R97-182 (v1.214.0): In / Out — attendance punches with position, known sites, overtime decisions. Idempotent.
CREATE TABLE IF NOT EXISTS io_sites (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  lat        DECIMAL(9,6) NOT NULL,
  lng        DECIMAL(9,6) NOT NULL,
  radius_m   INT UNSIGNED NOT NULL DEFAULT 300,
  ips        VARCHAR(400) NULL,                          -- network addresses from which the portal itself may register In / Out (comma separated; a trailing * is a prefix)
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS io_entries (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid        CHAR(36) NULL,                             -- client id of the punch: a repeated upload is recorded once
  user_id     INT UNSIGNED NOT NULL,
  kind        CHAR(3) NOT NULL,                          -- in | out
  at_utc      DATETIME NOT NULL,
  tz_min      SMALLINT NOT NULL DEFAULT 0,               -- minutes east of UTC where the punch was made
  local_day   DATE NOT NULL,
  local_time  TIME NOT NULL,
  lat         DECIMAL(9,6) NULL,
  lng         DECIMAL(9,6) NULL,
  acc_m       INT UNSIGNED NULL,
  site_id     INT UNSIGNED NULL,
  dist_m      INT UNSIGNED NULL,
  source      VARCHAR(12) NOT NULL DEFAULT 'portal',     -- employee | engineer | pm | hr | portal
  ip          VARCHAR(45) NULL,
  late_sync   TINYINT(1) NOT NULL DEFAULT 0,
  flags       VARCHAR(120) NOT NULL DEFAULT '',          -- outside,late,early,weakgps,nogps,noin,double,offday
  status      VARCHAR(10) NOT NULL DEFAULT 'auto',       -- auto | pending | approved | rejected
  decided_by  INT UNSIGNED NULL,
  decided_at  DATETIME NULL,
  decide_note VARCHAR(300) NULL,
  note        VARCHAR(300) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_io_uuid (uuid),
  KEY ix_io_user_day (user_id, local_day),
  KEY ix_io_status (status, local_day),
  KEY ix_io_day (local_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS io_overtime (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  day        DATE NOT NULL,
  minutes    INT NOT NULL DEFAULT 0,
  status     VARCHAR(10) NOT NULL DEFAULT 'pending',     -- approved | rejected
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  note       VARCHAR(300) NULL,
  UNIQUE KEY ux_io_ot (user_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
