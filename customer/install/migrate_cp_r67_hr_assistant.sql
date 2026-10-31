-- GAS-CP-R67-001 — HR Assistant: guided flows (My card completion · hiring · leaving · dismissal) with a staging area approved by HR.
-- Nothing a flow collects reaches hr_people / hr_employments / the accounts before HR (and, where the template says so, the CEO) approves.
-- Sensitive staged values (AMKA, ΑΦΜ, ID / passport No, IBAN) are stored encrypted (libsodium secretbox; key derived from the instance id,
-- the session name of app/config.php and a random salt kept in cp_settings › hra_salt). Numbers HRA-YYYY-NNNN come from doc_sequences (scope hra).

CREATE TABLE IF NOT EXISTS hr_flows (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20) NOT NULL,                              -- HRA-YYYY-NNNN
  kind          ENUM('card','hire','leave','dismiss') NOT NULL DEFAULT 'card',
  person_id     INT UNSIGNED NULL,                                 -- existing card (card / leave / dismiss); NULL until a hire is applied
  user_id       INT UNSIGNED NULL,                                 -- the account that fills the flow (employee); NULL for an invited candidate
  branch        VARCHAR(4) NOT NULL DEFAULT 'GR',                  -- rule set / document language
  status        ENUM('draft','submitted','manager','hr','ceo','applied','rejected','withdrawn') NOT NULL DEFAULT 'draft',
  step          SMALLINT UNSIGNED NOT NULL DEFAULT 0,              -- current wizard step (0-based) of the filling side
  confidential  TINYINT(1) NOT NULL DEFAULT 0,                     -- dismissal: invisible to the employee until released
  title         VARCHAR(160) NULL,                                 -- candidate name for a hire, else the person's name
  template_id   INT UNSIGNED NULL,
  opened_by     INT UNSIGNED NULL,
  assignee_id   INT UNSIGNED NULL,                                 -- who has to act now (manager / HR / CEO)
  submitted_at  DATETIME NULL,
  decided_at    DATETIME NULL,
  decided_by    INT UNSIGNED NULL,
  decision_note VARCHAR(500) NULL,
  consent_at    DATETIME NULL,                                     -- GDPR Art. 13 notice accepted (filling side)
  consent_ip    VARCHAR(45) NULL,
  purge_after   DATE NULL,                                         -- rejected / withdrawn drafts purged by cron
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hrf_code (code),
  KEY ix_hrf_person (person_id), KEY ix_hrf_user (user_id), KEY ix_hrf_status (status, kind), KEY ix_hrf_assignee (assignee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per proposed field value. old_value = the card at submission time, new_value = what the flow proposes.
-- enc = 1 → both values are secretbox ciphertexts (base64) of a HR_PERSONAL_SECRET field.
CREATE TABLE IF NOT EXISTS hr_flow_fields (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  flow_id     INT UNSIGNED NOT NULL,
  field       VARCHAR(40) NOT NULL,
  old_value   TEXT NULL,
  new_value   TEXT NULL,
  enc         TINYINT(1) NOT NULL DEFAULT 0,
  source      ENUM('user','assistant','document','hr') NOT NULL DEFAULT 'user',
  decision    ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  hr_note     VARCHAR(200) NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hff (flow_id, field),
  CONSTRAINT fk_hff_flow FOREIGN KEY (flow_id) REFERENCES hr_flows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents attached while the flow is open (ID / passport scan, degree, resignation letter …); on approval they are copied to hr_docs.
CREATE TABLE IF NOT EXISTS hr_flow_docs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  flow_id     INT UNSIGNED NOT NULL,
  kind        VARCHAR(16) NOT NULL DEFAULT 'other',               -- hr_docs kind after approval (id, passport, degree, contract, other …)
  name        VARCHAR(160) NOT NULL,
  path        VARCHAR(255) NOT NULL,
  sha         VARCHAR(64) NULL,
  size        INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED NULL,
  extracted   TINYINT(1) NOT NULL DEFAULT 0,                       -- the assistant read it and proposed fields
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_hfd_flow (flow_id),
  CONSTRAINT fk_hfd_flow FOREIGN KEY (flow_id) REFERENCES hr_flows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Checklist items of the HR / management side (hire: accounts, equipment, Ε3 / UNILAV …). owner_role: hr | ceo | manager | external.
CREATE TABLE IF NOT EXISTS hr_flow_tasks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  flow_id     INT UNSIGNED NOT NULL,
  item_key    VARCHAR(40) NOT NULL,
  label       VARCHAR(160) NOT NULL,
  owner_role  VARCHAR(12) NOT NULL DEFAULT 'hr',
  owner_email VARCHAR(190) NULL,                                   -- external owner (accountant, consulente) notified by e-mail
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  done        TINYINT(1) NOT NULL DEFAULT 0,
  done_by     INT UNSIGNED NULL,
  done_at     DATETIME NULL,
  note        VARCHAR(300) NULL,
  UNIQUE KEY uq_hft (flow_id, item_key),
  CONSTRAINT fk_hft_flow FOREIGN KEY (flow_id) REFERENCES hr_flows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_flow_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  flow_id     INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  event       VARCHAR(24) NOT NULL,                                -- opened, step, submitted, returned, approved, rejected, applied, consent, assist, doc, task, invite, denied …
  detail      VARCHAR(300) NULL,
  ip          VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_hfl_flow (flow_id),
  CONSTRAINT fk_hfl_flow FOREIGN KEY (flow_id) REFERENCES hr_flows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Flow templates: steps, fields, documents, approvers and checklist per kind and branch — editable in Settings › HR › HR Assistant.
CREATE TABLE IF NOT EXISTS hr_flow_templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind        ENUM('card','hire','leave','dismiss') NOT NULL,
  branch      VARCHAR(4) NOT NULL DEFAULT '*',                     -- '*' = every branch
  name        VARCHAR(120) NOT NULL,
  definition  MEDIUMTEXT NOT NULL,                                 -- JSON: steps[], approvers[], tasks[], documents[]
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  updated_by  INT UNSIGNED NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hftp (kind, branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time invitation links for candidates (hire flow) — token stored hashed (SHA-256), bound to the e-mail, 7 days, single use.
CREATE TABLE IF NOT EXISTS hr_invitations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  flow_id     INT UNSIGNED NOT NULL,
  email       VARCHAR(190) NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  attempts    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hfi_token (token_hash), KEY ix_hfi_flow (flow_id),
  CONSTRAINT fk_hfi_flow FOREIGN KEY (flow_id) REFERENCES hr_flows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
