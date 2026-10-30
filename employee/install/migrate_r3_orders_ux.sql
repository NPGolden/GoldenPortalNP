-- =====================================================================
--  GAS-POMS R2 -> R3: list filters/pagination preferences, part-number
--  gate before Procurement, per-order Q&A thread.
--  Run once via phpMyAdmin against an existing installation; fresh
--  installs get all of this from schema.sql.
-- =====================================================================

ALTER TABLE users
  ADD COLUMN pref_page_size   SMALLINT UNSIGNED NOT NULL DEFAULT 25 AFTER locale,
  ADD COLUMN pref_period_days SMALLINT UNSIGNED NOT NULL DEFAULT 90 AFTER pref_page_size;

CREATE TABLE order_messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  body       TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_om_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_om_user  FOREIGN KEY (user_id)  REFERENCES users(id),
  INDEX idx_om_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
