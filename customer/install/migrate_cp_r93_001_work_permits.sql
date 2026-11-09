-- GAS-CP-R93-001 — work permits per main project (holder, type, number, authority, issue / expiry, document), watched for expiry.
CREATE TABLE IF NOT EXISTS work_permits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NULL,                      -- hr_people (optional)
  holder_name VARCHAR(160) NOT NULL,
  permit_type VARCHAR(40) NOT NULL DEFAULT 'work_permit',   -- work_permit | residence | visa | a1 | yard_pass | port_pass | other
  permit_no VARCHAR(80) NULL,
  authority VARCHAR(160) NULL,
  country CHAR(2) NULL,
  issue_date DATE NULL,
  expiry_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',     -- active | renewal | cancelled
  notes VARCHAR(500) NULL,
  stored_name VARCHAR(200) NULL,
  original_name VARCHAR(255) NULL,
  mime_type VARCHAR(120) NULL,
  size_bytes INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_wkp_project (project_id), KEY ix_wkp_expiry (expiry_date), KEY ix_wkp_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
