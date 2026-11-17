-- GAS-CP-R97-071 (v1.156.0) — Payables: a supplier invoice line can carry a logistic cost of the purchase order
ALTER TABLE si_lines ADD COLUMN po_cost_id INT UNSIGNED NULL;
ALTER TABLE si_lines ADD KEY k_si_po_cost (po_cost_id);
