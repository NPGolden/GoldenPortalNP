-- GAS-CP-R95-005 — phase 3 of the parties / invoicing plan: vessel ownership periods.
-- A resale no longer overwrites the owner: every owner of a vessel is a period (organisation, from, to). Projects and ledger
-- rows keep the billing party they were issued to; the statement of account is addressed to the billing party.
CREATE TABLE IF NOT EXISTS vessel_owners (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  date_from DATE NULL,
  date_to DATE NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_vo_vessel (vessel_id, date_from),
  KEY ix_vo_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- the current owner of every vessel becomes its first (open) period; start date unknown
INSERT INTO vessel_owners (vessel_id, company_id, customer_id)
SELECT v.id, (SELECT MIN(co.id) FROM companies co WHERE co.customer_id = v.customer_id), v.customer_id
FROM vessels v WHERE v.customer_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM vessel_owners o WHERE o.vessel_id = v.id);
-- ledger: an invoice row built by registering it on proformas (its gross = the sum of its allocations) is told apart from a
-- direct invoice typed by accounting (its gross is its own) — unlinking must never shrink or delete a direct invoice.
ALTER TABLE proformas ADD COLUMN from_links TINYINT(1) NOT NULL DEFAULT 0 AFTER legacy_inv_id;
UPDATE proformas p SET p.from_links = 1 WHERE p.kind = 'invoice' AND p.from_links = 0 AND (p.legacy_inv_id IS NOT NULL OR (p.offer_id IS NULL AND (SELECT COUNT(*) FROM pi_links l WHERE l.invoice_id = p.id) > 0 AND ABS(p.gross - (SELECT COALESCE(SUM(l.amount),0) FROM pi_links l WHERE l.invoice_id = p.id)) < 0.01));
