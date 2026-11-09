-- GAS-CP-R94-001 — direct invoices (an invoice without a proforma) live in the proforma module as kind = invoice; sub-project invoicing becomes computed with an optional override.
ALTER TABLE proformas MODIFY offer_id INT UNSIGNED NULL;
ALTER TABLE proformas ADD COLUMN kind ENUM('proforma','invoice') NOT NULL DEFAULT 'proforma' AFTER id;
ALTER TABLE proformas ADD COLUMN customer_id INT UNSIGNED NULL AFTER vessel_id;
ALTER TABLE proformas ADD KEY ix_pi_kind (kind);
ALTER TABLE subprojects ADD COLUMN invoicing_override ENUM('none','partial','full') NULL AFTER invoicing;
