-- GAS-CP-R97-134 (v1.193.1) — payables: documents of an invoice that has no project (the invoice itself, payment proofs), kept with the invoice and read by Accounting only
CREATE TABLE IF NOT EXISTS si_docs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  payment_id INT UNSIGNED NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'proof',
  title VARCHAR(200) NULL,
  stored_name VARCHAR(190) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED NULL,
  uploaded_name VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_si_docs_inv (invoice_id),
  KEY ix_si_docs_pay (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
