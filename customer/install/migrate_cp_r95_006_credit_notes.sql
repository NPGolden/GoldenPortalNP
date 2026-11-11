-- GAS-CP-R95-006 — customer credit notes in the one ledger.
-- A credit note is a ledger row of kind 'credit' with NEGATIVE amounts. It can be standalone or raised for an invoice, is applied
-- to one or more invoices (pi_credits) and what is not applied is either refunded to the customer (refunds = negative payments on
-- the credit note) or carried forward as a credit balance — the accountant chooses (credit_mode).
ALTER TABLE proformas MODIFY COLUMN kind ENUM('proforma','invoice','credit') NOT NULL DEFAULT 'proforma';
ALTER TABLE proformas ADD COLUMN credit_mode ENUM('refund','carry') NULL AFTER kind;
ALTER TABLE proformas ADD COLUMN credit_of_id INT UNSIGNED NULL AFTER credit_mode;
CREATE TABLE IF NOT EXISTS pi_credits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credit_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cr (credit_id, invoice_id),
  KEY ix_cr_inv (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
