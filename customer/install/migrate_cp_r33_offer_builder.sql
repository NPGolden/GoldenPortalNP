-- GAS-CP-R33-001 — Rate cards register (price list per branch and revision) · Offer builder (lines from the rate cards,
-- text blocks from templates, portal-generated offer document, links to vessel / ticket / project / sub-project).
CREATE TABLE IF NOT EXISTS rate_lists (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_ref VARCHAR(40) NOT NULL,                    -- GAS-PL-2026
  revision VARCHAR(8) NOT NULL,                    -- R15
  rev_date DATE NULL,
  branch_code VARCHAR(10) NOT NULL DEFAULT 'GAS',  -- column prefix in the master file (GAS, GIT …)
  branch_id INT UNSIGNED NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  status VARCHAR(12) NOT NULL DEFAULT 'active',    -- active | superseded
  source_name VARCHAR(255) NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rl_branch (branch_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rate_list_id INT UNSIGNED NOT NULL,
  section VARCHAR(12) NOT NULL DEFAULT 'hourly',   -- hourly | remote | fixed
  card VARCHAR(12) NOT NULL DEFAULT '',            -- A … N, Tokens
  card_name VARCHAR(80) NOT NULL DEFAULT '',
  erp_code VARCHAR(30) NOT NULL DEFAULT '',
  description VARCHAR(400) NOT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'hour',
  price_local DECIMAL(12,2) NULL,
  price_overseas DECIMAL(12,2) NULL,
  rule VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  KEY ix_ri_list (rate_list_id, section, card, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offer_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  offer_id INT UNSIGNED NOT NULL,
  seq INT NOT NULL DEFAULT 0,
  kind VARCHAR(8) NOT NULL DEFAULT 'rate',         -- rate | free | text (a heading / remark line without price)
  rate_item_id INT UNSIGNED NULL,
  erp_code VARCHAR(30) NOT NULL DEFAULT '',
  description VARCHAR(400) NOT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT '',
  zone VARCHAR(10) NOT NULL DEFAULT 'local',       -- local | overseas
  qty DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  KEY ix_ol_offer (offer_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offer_texts (                 -- templates of the text blocks (Settings › Offer texts)
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  block_key VARCHAR(20) NOT NULL,                  -- intro | scope | exclusions | validity | payment | terms | closing
  layout VARCHAR(12) NOT NULL DEFAULT '',          -- '' = any | service | system | remote
  locale VARCHAR(5) NOT NULL DEFAULT '',
  branch_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  title VARCHAR(120) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_ot_key (block_key, layout, locale, branch_id, customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offer_blocks (                -- the text blocks of one offer (copied from the templates, then edited on the offer)
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  offer_id INT UNSIGNED NOT NULL,
  block_key VARCHAR(20) NOT NULL,
  seq INT NOT NULL DEFAULT 0,
  title VARCHAR(120) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  UNIQUE KEY ux_ob (offer_id, block_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE offers
  ADD COLUMN vessel_id INT UNSIGNED NULL AFTER subproject_id,
  ADD COLUMN ticket_id INT UNSIGNED NULL AFTER vessel_id,
  ADD COLUMN layout VARCHAR(12) NOT NULL DEFAULT 'service' AFTER title,
  ADD COLUMN rate_list_id INT UNSIGNED NULL AFTER layout,
  ADD COLUMN vat_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER currency,
  ADD COLUMN vat_total DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER vat_pct,
  ADD COLUMN gross_total DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER vat_total,
  ADD COLUMN validity_days SMALLINT NOT NULL DEFAULT 30 AFTER valid_until,
  ADD KEY ix_of_vessel (vessel_id), ADD KEY ix_of_ticket (ticket_id);
UPDATE offers o JOIN projects p ON p.id = o.project_id SET o.vessel_id = p.vessel_id WHERE o.vessel_id IS NULL;

ALTER TABLE staff_roles ADD COLUMN can_offer TINYINT(1) NOT NULL DEFAULT 1;
