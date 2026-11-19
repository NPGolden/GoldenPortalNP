-- GAS-CP-R97-146 (v1.198.0): delivery lots of a purchase order — partial deliveries with their own dates and transport. Idempotent.
CREATE TABLE IF NOT EXISTS po_lots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  seq SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  planned_date DATE NULL,
  shipped_date DATE NULL,
  arrived_date DATE NULL,
  delivered_date DATE NULL,
  carrier VARCHAR(120) NULL,
  tracking VARCHAR(120) NULL,
  note VARCHAR(255) NULL,
  user_name VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_po_lots_order (order_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE po_lines ADD COLUMN IF NOT EXISTS lot_id INT UNSIGNED NULL;
