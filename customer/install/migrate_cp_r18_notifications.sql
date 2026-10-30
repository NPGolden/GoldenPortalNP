-- GAS-CP-R18-002 · Notifications: what changed that each user has not seen yet, grouped like the menu.
CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  grp         VARCHAR(20) NOT NULL,
  kind        VARCHAR(30) NOT NULL,
  ref_key     VARCHAR(60) NOT NULL,
  title       VARCHAR(200) NOT NULL,
  body        VARCHAR(400) NULL,
  url         VARCHAR(255) NOT NULL,
  actor_id    INT UNSIGNED NULL,
  actor_name  VARCHAR(120) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at     TIMESTAMP NULL,
  KEY idx_nf_user_read (user_id, read_at, id),
  KEY idx_nf_user_grp (user_id, grp, read_at),
  KEY idx_nf_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nf_cursor (
  source   VARCHAR(40) NOT NULL PRIMARY KEY,
  last_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  run_at   TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- start from "now": only changes after the step was run are notified
INSERT IGNORE INTO nf_cursor (source, last_id) VALUES
 ('ticket_events', (SELECT COALESCE(MAX(id),0) FROM ticket_events)), ('ticket_comments', (SELECT COALESCE(MAX(id),0) FROM ticket_comments)),
 ('jo_events', (SELECT COALESCE(MAX(id),0) FROM jo_events)), ('fsr_events', (SELECT COALESCE(MAX(id),0) FROM fsr_events)),
 ('ts_events', (SELECT COALESCE(MAX(id),0) FROM ts_events)), ('offer_events', (SELECT COALESCE(MAX(id),0) FROM offer_events)),
 ('oa_comments', (SELECT COALESCE(MAX(id),0) FROM oa_comments)), ('owner_approvals', (SELECT COALESCE(MAX(id),0) FROM owner_approvals)),
 ('class_submittals', (SELECT COALESCE(MAX(id),0) FROM class_submittals)), ('drawing_requests', (SELECT COALESCE(MAX(id),0) FROM drawing_requests)),
 ('sp_notes', (SELECT COALESCE(MAX(id),0) FROM sp_notes)), ('sp_comments', (SELECT COALESCE(MAX(id),0) FROM sp_comments)),
 ('documents', (SELECT COALESCE(MAX(id),0) FROM documents)), ('contact_notes', (SELECT COALESCE(MAX(id),0) FROM contact_notes));
