-- GAS-CP-R97-122 (v1.187.0) — the HR year: steps of the annual cycle ticked as done by HR
CREATE TABLE IF NOT EXISTS hr_year_steps (
  year SMALLINT UNSIGNED NOT NULL,
  step_key VARCHAR(24) NOT NULL,
  done_at DATETIME NOT NULL,
  done_by INT UNSIGNED NULL,
  done_name VARCHAR(120) NULL,
  note VARCHAR(300) NULL,
  PRIMARY KEY (year, step_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
