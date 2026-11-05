-- GAS-CP-R82-001 — Offer lines from the inventory with margin; FSR parts from the inventory (stock issues); production kits
ALTER TABLE offer_lines ADD COLUMN item_id INT UNSIGNED NULL AFTER rate_item_id;
ALTER TABLE offer_lines ADD COLUMN cost_price DECIMAL(12,4) NULL AFTER unit_price;
ALTER TABLE offer_lines ADD COLUMN margin_pct DECIMAL(6,2) NULL AFTER cost_price;
ALTER TABLE offer_lines ADD KEY ix_ol_item (item_id);
