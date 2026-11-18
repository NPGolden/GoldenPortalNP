-- GAS-CP-R97-126 (v1.189.1) — the code written in an imported file stays on a free-text line of an order / requisition (proposed for the new inventory item)
ALTER TABLE po_lines ADD COLUMN IF NOT EXISTS typed_code VARCHAR(60) NULL AFTER part_no;
ALTER TABLE pr_lines ADD COLUMN IF NOT EXISTS typed_code VARCHAR(60) NULL AFTER part_no;
