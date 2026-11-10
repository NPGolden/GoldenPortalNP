-- GAS-CP-R95-004 — phase 2 of the parties / invoicing plan: the accounting invoice is a ledger document.
-- Registered invoices become proformas rows of kind = invoice, linked to the proforma(s) they settle through pi_links
-- (one invoice may settle several proformas, with the amount allocated to each). Payments live on the invoice; advances
-- stay on the proforma until it is invoiced. proforma_invoices is kept read-only (the conversion runs in PHP on the first
-- request after this version — pi_ledger_convert()).
CREATE TABLE IF NOT EXISTS pi_links (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  proforma_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  legacy_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_link (invoice_id, proforma_id),
  KEY ix_link_pf (proforma_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE proformas ADD COLUMN legacy_inv_id INT UNSIGNED NULL AFTER kind;
ALTER TABLE acc_lines ADD COLUMN invoice_id INT UNSIGNED NULL AFTER invoice_ref;
ALTER TABLE acc_lines ADD KEY ix_acc_inv (invoice_id);
ALTER TABLE fsr_lines ADD COLUMN nc_reason VARCHAR(40) NULL AFTER time_type;
