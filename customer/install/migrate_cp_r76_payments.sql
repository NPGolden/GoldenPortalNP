-- GAS-CP-R76-001 — advance payments on proformas without an invoice (invoiced later); capped at the offer total
CREATE TABLE IF NOT EXISTS proforma_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  proforma_id INT NOT NULL,
  paid_on DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method VARCHAR(12) NOT NULL DEFAULT 'bank',
  reference VARCHAR(80) NULL,
  note VARCHAR(255) NULL,
  invoice_id INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pp_proforma (proforma_id),
  KEY ix_pp_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
