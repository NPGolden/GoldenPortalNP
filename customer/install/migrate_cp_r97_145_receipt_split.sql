-- GAS-CP-R97-145 (v1.197.0): goods receipt — part of a received line to the project (delivery note), the rest stays in stock. Idempotent.
ALTER TABLE po_receipt_lines ADD COLUMN IF NOT EXISTS dest_qty DECIMAL(12,3) NULL AFTER dest;
