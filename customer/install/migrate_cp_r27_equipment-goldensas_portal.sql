USE goldensas_portal;
-- GAS-CP-R27-001 · Equipment register: serial numbers (shore converters and any other unit) with their service history,
-- linked to the vessel and fed by the field service reports. Select the portal database first (goldensas_portal).
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS equipment (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  serial_no        VARCHAR(60)  NOT NULL,
  set_code         VARCHAR(80)  NULL,                            -- "5433-001/002/003/004": units delivered together
  maker            VARCHAR(60)  NOT NULL DEFAULT '',             -- ATLAS · MAGNUS · …
  family           VARCHAR(60)  NULL,                            -- SPA II · Classic I · UHF · SP200 · VSP5
  model            VARCHAR(120) NULL,                            -- full model / rating text
  kind             VARCHAR(40)  NOT NULL DEFAULT 'shore_converter',
  vessel_id        INT UNSIGNED NULL,
  site_name        VARCHAR(120) NULL,                            -- when the unit is not on a vessel of the register (a base, a yard)
  sold_by_us       TINYINT(1)   NOT NULL DEFAULT 0,
  date_sold        DATE NULL,
  warranty_until   DATE NULL,
  warranty_ref     VARCHAR(80)  NULL,                            -- "SF7606"
  first_attendance DATE NULL,
  retired          TINYINT(1)   NOT NULL DEFAULT 0,
  retired_at       DATE NULL,
  contacts         TEXT NULL,
  notes            TEXT NULL,
  raw_records      MEDIUMTEXT NULL,                              -- the imported history text, kept whole
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eq (maker, serial_no),
  INDEX idx_eq_vessel (vessel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipment_history (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  equipment_id  INT UNSIGNED NOT NULL,
  hist_date     DATE NULL,
  kind          ENUM('commissioning','maintenance','health_check','repair','warranty','upgrade','troubleshooting','inspection','other') NOT NULL DEFAULT 'other',
  source        ENUM('import','manual','fsr','ticket') NOT NULL DEFAULT 'manual',
  fsr_id        INT UNSIGNED NULL,
  ticket_id     INT UNSIGNED NULL,
  engineer      VARCHAR(160) NULL,
  parts         VARCHAR(500) NULL,
  body          TEXT NOT NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eh_eq (equipment_id, hist_date),
  INDEX idx_eh_fsr (fsr_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fsr_equipment (
  report_id    INT UNSIGNED NOT NULL,
  equipment_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (report_id, equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
