USE goldensas_portal;
-- GAS-CP-R19-001 · Customers master data (editable in Settings) and invoicing details:
-- several billing identities per customer, company or contact.
-- Re-running reports "duplicate column" / "already exists" — harmless.
ALTER TABLE customers
  ADD COLUMN address     VARCHAR(200) NULL AFTER country,
  ADD COLUMN city        VARCHAR(80)  NULL AFTER address,
  ADD COLUMN postal_code VARCHAR(20)  NULL AFTER city,
  ADD COLUMN vat_no      VARCHAR(40)  NULL AFTER postal_code,
  ADD COLUMN email       VARCHAR(190) NULL AFTER vat_no,
  ADD COLUMN phone       VARCHAR(60)  NULL AFTER email,
  ADD COLUMN website     VARCHAR(190) NULL AFTER phone,
  ADD COLUMN notes       TEXT         NULL AFTER website;

CREATE TABLE IF NOT EXISTS invoicing_details (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_type    ENUM('customer','company','contact') NOT NULL,
  owner_id      INT UNSIGNED NOT NULL,
  label         VARCHAR(80)  NOT NULL,                 -- e.g. "Head office", "Management company", "Owning SPV"
  legal_name    VARCHAR(200) NOT NULL,
  vat_no        VARCHAR(40)  NULL,
  tax_office    VARCHAR(120) NULL,
  reg_no        VARCHAR(60)  NULL,                     -- company registration / GEMI / chamber number
  address       VARCHAR(200) NULL,
  city          VARCHAR(80)  NULL,
  postal_code   VARCHAR(20)  NULL,
  country       VARCHAR(80)  NULL,
  email         VARCHAR(190) NULL,                     -- where invoices are sent
  phone         VARCHAR(60)  NULL,
  attn          VARCHAR(120) NULL,                     -- for the attention of
  bank          VARCHAR(120) NULL,
  iban          VARCHAR(48)  NULL,
  swift         VARCHAR(16)  NULL,
  currency      CHAR(3)      NOT NULL DEFAULT 'EUR',
  payment_terms VARCHAR(120) NULL,
  notes         TEXT         NULL,
  is_default    TINYINT(1)   NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_iv_owner (owner_type, owner_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
