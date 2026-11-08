SET NAMES utf8mb4;
-- GAS-CP-R89-001 — HR › Recruiting: vacancies, online selection events, screening sets, applications, CVs, talent pool,
-- public website feed (goldenas.com.gr/careers) and 6-month GDPR retention. All tables prefixed rc_ (recruiting), keyed into hr_departments.
CREATE TABLE IF NOT EXISTS rc_screening_sets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,                       -- e.g. ENG-STD-7 (referenced by vacancies and the website)
  title VARCHAR(120) NOT NULL,
  pass_threshold TINYINT UNSIGNED NOT NULL DEFAULT 6,
  knockout_groups VARCHAR(120) NOT NULL DEFAULT 'loyalty',   -- comma list of question groups that must all score
  questions JSON NOT NULL,                         -- [{id,group,text,text_el,text_it,options:[{id,text,text_el,text_it,score}]}] — score never leaves the server
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rcs_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rc_vacancies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(24) NOT NULL,                       -- VAC-2026-0014
  title VARCHAR(190) NOT NULL,
  department_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,                     -- branches table (HQ / Italia / Nederland)
  location VARCHAR(120) NULL,
  emp_type ENUM('full_time','part_time','contract','internship') NOT NULL DEFAULT 'full_time',
  seniority ENUM('junior','mid','senior','lead') NOT NULL DEFAULT 'mid',
  summary VARCHAR(600) NULL, description_html MEDIUMTEXT NULL, requirements JSON NULL, tags JSON NULL,
  screening_set_id INT UNSIGNED NOT NULL,
  status ENUM('draft','open','closed','filled') NOT NULL DEFAULT 'draft',
  publish_web TINYINT(1) NOT NULL DEFAULT 1,       -- shown on goldenas.com.gr/careers when open
  published_at DATE NULL, closes_at DATE NULL,
  hiring_manager_id INT UNSIGNED NULL,             -- customer_users.id
  created_by INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rcv_code (code), KEY ix_rcv_status (status, publish_web, closes_at), KEY ix_rcv_dept (department_id),
  CONSTRAINT fk_rcv_set FOREIGN KEY (screening_set_id) REFERENCES rc_screening_sets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rc_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(24) NOT NULL,                       -- EVT-2026-0003
  title VARCHAR(190) NOT NULL,
  kind ENUM('assessment','webinar','qna') NOT NULL DEFAULT 'assessment',
  starts_at DATETIME NOT NULL, duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  platform VARCHAR(60) NULL DEFAULT 'Microsoft Teams', join_url VARCHAR(500) NULL,   -- sent to registrants by e-mail only
  capacity SMALLINT UNSIGNED NULL, description_html MEDIUMTEXT NULL, for_vacancies JSON NULL,
  registration_closes_at DATETIME NULL,
  status ENUM('draft','upcoming','closed','done','cancelled') NOT NULL DEFAULT 'draft',
  publish_web TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rce_code (code), KEY ix_rce_status (status, publish_web, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rc_applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(28) NOT NULL,                       -- APP-2026-00042
  vacancy_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL, name VARCHAR(120) NULL, phone VARCHAR(40) NULL,
  answers JSON NULL, score TINYINT UNSIGNED NULL, max_score TINYINT UNSIGNED NULL, passed TINYINT(1) NOT NULL DEFAULT 0, knockout_ok TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('screened','cv_received','shortlisted','interview','offer','hired','rejected','withdrawn') NOT NULL DEFAULT 'screened',
  cv_path VARCHAR(255) NULL, cv_name VARCHAR(190) NULL, cover_note TEXT NULL, department_interest VARCHAR(120) NULL,
  cv_token_hash VARCHAR(255) NULL,                 -- one-time upload token (website step 2)
  rating TINYINT UNSIGNED NULL, hr_notes TEXT NULL, owner_user_id INT UNSIGNED NULL,
  hired_person_id INT UNSIGNED NULL,               -- hr_people.id once hired (onboarding handshake)
  source VARCHAR(20) NOT NULL DEFAULT 'website', lang CHAR(2) NOT NULL DEFAULT 'en', ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL,
  consent_version VARCHAR(20) NULL, consent_at DATETIME NULL,
  status_changed_at DATETIME NULL,                 -- retention clock for the active pipeline
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rca_code (code), UNIQUE KEY uq_rca_vac_email (vacancy_id, email), KEY ix_rca_status (status, created_at),
  CONSTRAINT fk_rca_vac FOREIGN KEY (vacancy_id) REFERENCES rc_vacancies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rc_event_registrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL, email VARCHAR(190) NOT NULL, name VARCHAR(120) NULL, application_id INT UNSIGNED NULL,
  attended TINYINT(1) NULL, result ENUM('strong','good','weak','no_show') NULL, result_note VARCHAR(500) NULL, scored_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rcr_evt_email (event_id, email), KEY ix_rcr_app (application_id),
  CONSTRAINT fk_rcr_evt FOREIGN KEY (event_id) REFERENCES rc_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rc_talent_pool (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, name VARCHAR(120) NULL, area VARCHAR(160) NULL, message TEXT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'website', consent_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rct_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rc_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NULL, event_id INT UNSIGNED NULL, vacancy_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL, detail VARCHAR(500) NULL, user_id INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rcl_app (application_id), KEY ix_rcl_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rc_retention_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, cutoff DATETIME NOT NULL,
  apps_deleted INT NOT NULL DEFAULT 0, apps_anonymised INT NOT NULL DEFAULT 0, cvs_deleted INT NOT NULL DEFAULT 0, events_deleted INT NOT NULL DEFAULT 0, pool_deleted INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- settings used by the public website feed and retention (cp_settings k/v, as everywhere in the portal)
INSERT IGNORE INTO cp_settings (k, v) VALUES
  ('rc_site_key', ''),                              -- shared secret the website sends as X-Site-Key; empty = public feed disabled
  ('rc_site_origin', 'https://goldenas.com.gr'),    -- CORS origin allowed to call the feed
  ('rc_retention_months', '6'),
  ('rc_notify_email', ''),                          -- HR mailbox for new-application notices (blank = HR role users)
  ('rc_cv_max_mb', '8'),
  ('rc_mail_from', ''),
  ('rc_feed_rl_read', '60'),                        -- feed rate limits per IP per minute
  ('rc_feed_rl_write', '10');                              -- From address for candidate e-mails (blank = keep in log, send nothing)

-- default screening set (identical to the website's ENG-STD-7; HR edits it here from now on)
INSERT IGNORE INTO rc_screening_sets (code, title, pass_threshold, knockout_groups, questions) VALUES ('ENG-STD-7', 'Engineers — mindset & commitment (7 questions)', 6, 'loyalty', JSON_ARRAY(
 JSON_OBJECT('id','q1','group','tech','text','A new control protocol appears on a project you have never used before. Your instinct is to…','options',JSON_ARRAY(JSON_OBJECT('id','a','text','Get hands-on, read the spec and learn it — new technology excites me.','score',1),JSON_OBJECT('id','b','text','Stick to what I already know and avoid it where I can.','score',0),JSON_OBJECT('id','c','text','Build a small test setup to understand it properly before deploying.','score',1))),
 JSON_OBJECT('id','q2','group','growth','text','Golden co-funds masters, certifications and per-department training. How does that land with you?','options',JSON_ARRAY(JSON_OBJECT('id','a','text','It is a major draw — I want to keep deepening my expertise for years.','score',1),JSON_OBJECT('id','b','text','Nice to have, but I do not really plan to study or train further.','score',0),JSON_OBJECT('id','c','text','I would use it fully and bring what I learn back to the team.','score',1))),
 JSON_OBJECT('id','q3','group','clever','text','A commissioning fault does not match any documented cause. What do you do?','options',JSON_ARRAY(JSON_OBJECT('id','a','text','Reason from first principles, isolate variables and trace it methodically.','score',1),JSON_OBJECT('id','b','text','Swap parts until something works and move on without finding the cause.','score',0),JSON_OBJECT('id','c','text','Escalate immediately without attempting to diagnose it myself.','score',0))),
 JSON_OBJECT('id','q4','group','loyalty','text','Looking back at your roles, what best describes your typical tenure?','options',JSON_ARRAY(JSON_OBJECT('id','a','text','Several years each — I commit and see things through.','score',1),JSON_OBJECT('id','b','text','Long tenures, with moves driven by genuine growth, not restlessness.','score',1),JSON_OBJECT('id','c','text','Often under a year — I tend to move on quickly and frequently.','score',0))),
 JSON_OBJECT('id','q5','group','loyalty','text','What would make you stay somewhere long-term?','options',JSON_ARRAY(JSON_OBJECT('id','a','text','Interesting work, a team I respect, and room to grow.','score',1),JSON_OBJECT('id','b','text','Whatever pays most at any given moment; I would leave the day a better offer appears.','score',0),JSON_OBJECT('id','c','text','Investment in my development and a clear path forward with the company.','score',1))),
 JSON_OBJECT('id','q6','group','depth','text','How deep is your marine / shipyard knowledge, and where is it heading?','options',JSON_ARRAY(JSON_OBJECT('id','a','text','Solid hands-on depth in refit and newbuilds — and I actively want to go deeper.','score',1),JSON_OBJECT('id','b','text','Strong in my specialism, and I keep up with class rules, makers and new standards.','score',1),JSON_OBJECT('id','c','text','I rely on what I learned years ago; I do not really keep it current.','score',0))),
 JSON_OBJECT('id','q7','group','team','text','A colleague is stuck on something you have solved before. You…','options',JSON_ARRAY(JSON_OBJECT('id','a','text','Sit with them, share what I know, and write it up so the whole team benefits.','score',1),JSON_OBJECT('id','b','text','Keep my methods to myself to stay indispensable.','score',0),JSON_OBJECT('id','c','text','Help right away — a stronger team delivers a better vessel.','score',1)))
));
