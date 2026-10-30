-- GAS-CP-R17-002 · Companies master list, contact channels and subscriptions.
-- Run after migrate_cp_r17_contacts.sql. Re-running reports "already exists" / "duplicate column" — harmless.

CREATE TABLE IF NOT EXISTS companies (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(160) NOT NULL,
  kind         VARCHAR(30) NOT NULL DEFAULT 'other',
  customer_id  INT UNSIGNED NULL,
  country      VARCHAR(80) NULL,
  city         VARCHAR(80) NULL,
  website      VARCHAR(190) NULL,
  email        VARCHAR(190) NULL,
  phone        VARCHAR(60) NULL,
  notes        TEXT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_co_name (name),
  KEY idx_co_kind (kind),
  KEY idx_co_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contacts
  ADD COLUMN company_id    INT UNSIGNED NULL AFTER customer_id,
  ADD COLUMN channels      VARCHAR(190) NULL AFTER mobile,
  ADD COLUMN subscriptions VARCHAR(120) NULL AFTER channels,
  ADD KEY idx_ct_company (company_id);

-- one company per customer of the portal (kind = owner), linked to it
INSERT IGNORE INTO companies (name, kind, customer_id, country, is_active)
  SELECT c.name, 'owner', c.id, c.country, c.is_active FROM customers c;

-- companies typed as free text on existing contacts
INSERT IGNORE INTO companies (name, kind)
  SELECT DISTINCT ct.company, CASE ct.category WHEN 'yard' THEN 'yard' WHEN 'class' THEN 'class' WHEN 'supplier' THEN 'supplier'
                                               WHEN 'management' THEN 'management' WHEN 'consultant' THEN 'consultant' ELSE 'other' END
  FROM contacts ct WHERE ct.company IS NOT NULL AND ct.company <> '';

UPDATE contacts ct JOIN companies co ON LOWER(co.name) = LOWER(ct.company) SET ct.company_id = co.id WHERE ct.company_id IS NULL;
UPDATE contacts ct JOIN companies co ON co.customer_id = ct.customer_id SET ct.company_id = co.id WHERE ct.company_id IS NULL AND ct.customer_id IS NOT NULL;
UPDATE contacts ct JOIN companies co ON co.id = ct.company_id SET ct.customer_id = co.customer_id WHERE ct.customer_id IS NULL AND co.customer_id IS NOT NULL;
UPDATE contacts SET channels = 'email' WHERE channels IS NULL AND email IS NOT NULL AND email <> '';
