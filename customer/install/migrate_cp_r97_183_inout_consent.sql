-- GAS-CP-R97-183 (v1.215.0): In / Out — the employee's acceptance of the notice on the recording of time and position. Idempotent.
CREATE TABLE IF NOT EXISTS io_consents (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  version     VARCHAR(8) NOT NULL,                       -- version of the notice that was accepted
  locale      VARCHAR(5) NULL,                           -- language in which it was shown
  source      VARCHAR(12) NOT NULL DEFAULT 'portal',     -- employee | engineer | pm | hr | portal
  ip          VARCHAR(45) NULL,
  device      VARCHAR(160) NULL,
  accepted_at DATETIME NOT NULL,
  UNIQUE KEY ux_io_consent (user_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
