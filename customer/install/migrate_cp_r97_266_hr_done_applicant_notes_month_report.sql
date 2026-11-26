-- GAS-CP-R97-266 (v1.264.0): applicant notes, monthly attendance report notes, HR conversations stay open until Done.
CREATE TABLE IF NOT EXISTS rc_notes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL, body TEXT NOT NULL, user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rcn_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS io_month_notes (
  user_id INT UNSIGNED NOT NULL, ym CHAR(7) NOT NULL, note VARCHAR(1000) NULL,
  by_user_id INT UNSIGNED NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- conversations HR has already answered are closed once, so the open list starts with the ones still to answer
UPDATE hr_messages m JOIN (SELECT person_id, MAX(id) AS mx FROM hr_messages WHERE from_hr = 1 GROUP BY person_id) h ON h.person_id = m.person_id
   SET m.done_at = NOW() WHERE m.from_hr = 0 AND m.done_at IS NULL AND m.id < h.mx;
