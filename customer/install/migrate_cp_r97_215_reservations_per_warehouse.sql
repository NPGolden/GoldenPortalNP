-- GAS-CP-R97-215 — reservations per warehouse: which warehouse holds how much for a requisition line (key r99l)
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS pr_reservations (
  pr_line_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (pr_line_id, warehouse_id), KEY ix_prr_item (item_id, warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- what is reserved today is booked in the warehouse of its line
INSERT IGNORE INTO pr_reservations (pr_line_id, item_id, warehouse_id, qty)
  SELECT l.id, l.item_id, l.warehouse_id, l.qty_reserved FROM pr_lines l WHERE l.qty_reserved > 0 AND l.item_id IS NOT NULL AND l.warehouse_id IS NOT NULL;
