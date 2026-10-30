-- =====================================================================
--  GOLDEN A/S — Parts Order Management System
--  Document ref: GAS-DB-POMS-001  R0
--  Engine: MySQL 8.0+ / MariaDB 10.6+
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------- roles
DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(32)  NOT NULL,
  name        VARCHAR(64)  NOT NULL,
  description VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- users
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name      VARCHAR(120) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  phone          VARCHAR(40)  NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role_id        TINYINT UNSIGNED NOT NULL,
  locale         CHAR(2)      NOT NULL DEFAULT 'en',   -- en | el | bg | ru | it
  pref_page_size   SMALLINT UNSIGNED NOT NULL DEFAULT 25,  -- list rows per page: 25|50|100
  pref_period_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,  -- list window in days; 0 = all time
  totp_secret    VARCHAR(64)  NULL,
  totp_enabled   TINYINT(1)   NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  failed_logins  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME     NULL,
  last_login_at  DATETIME     NULL,
  last_seen_at   DATETIME     NULL,   -- presence: online = seen in last 5 min
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- vessels
-- Populated from CSV (install/import_vessels.php or Admin > Vessels).
DROP TABLE IF EXISTS vessels;
CREATE TABLE vessels (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  imo        VARCHAR(20)  NULL,
  hull_no    VARCHAR(40)  NULL,
  yard       VARCHAR(120) NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vessels_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- projects
-- Approver routing lives here: every project has a PM and a TM.
DROP TABLE IF EXISTS projects;
CREATE TABLE projects (
  project_number  VARCHAR(40) NOT NULL,
  name            VARCHAR(160) NOT NULL,
  vessel_id       INT UNSIGNED NULL,
  pm_user_id      INT UNSIGNED NOT NULL,
  tm_user_id      INT UNSIGNED NOT NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (project_number),
  KEY ix_projects_pm (pm_user_id),
  KEY ix_projects_tm (tm_user_id),
  CONSTRAINT fk_projects_vessel FOREIGN KEY (vessel_id) REFERENCES vessels (id) ON DELETE SET NULL,
  CONSTRAINT fk_projects_pm FOREIGN KEY (pm_user_id) REFERENCES users (id),
  CONSTRAINT fk_projects_tm FOREIGN KEY (tm_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- orders
DROP TABLE IF EXISTS orders;
CREATE TABLE orders (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_ref             VARCHAR(32) NOT NULL,
  project_number        VARCHAR(40) NOT NULL,
  vessel_id             INT UNSIGNED NULL,
  vessel_name           VARCHAR(120) NULL,           -- snapshot at time of order
  requested_by          INT UNSIGNED NOT NULL,       -- field engineer
  pm_user_id            INT UNSIGNED NOT NULL,
  tm_user_id            INT UNSIGNED NOT NULL,
  procurement_user_id   INT UNSIGNED NULL,
  driver_user_id        INT UNSIGNED NULL,
  priority              ENUM('routine','urgent','vessel_down') NOT NULL DEFAULT 'routine',
  needed_by             DATE NULL,
  approx_delivery_date  DATE NULL,
  actual_delivery_date  DATE NULL,
  delivery_address      VARCHAR(255) NULL,
  status                ENUM(
                          'draft',
                          'awaiting_pm',
                          'awaiting_tm',
                          'rejected_pm',
                          'rejected_tm',
                          'with_procurement',
                          'ordered',
                          'backordered',
                          'in_warehouse',
                          'ready_for_dispatch',
                          'in_transit',
                          'delivered',
                          'partially_fulfilled',
                          'closed',
                          'cancelled'
                        ) NOT NULL DEFAULT 'draft',
  notes                 TEXT NULL,
  sp_item_id            VARCHAR(64) NULL,            -- SharePoint list item id
  sp_synced_at          DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  submitted_at          DATETIME NULL,
  closed_at             DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_ref (order_ref),
  KEY ix_orders_status (status),
  KEY ix_orders_requester (requested_by),
  KEY ix_orders_pm (pm_user_id),
  KEY ix_orders_tm (tm_user_id),
  KEY ix_orders_driver (driver_user_id),
  KEY ix_orders_project (project_number),
  CONSTRAINT fk_orders_project FOREIGN KEY (project_number) REFERENCES projects (project_number),
  CONSTRAINT fk_orders_vessel  FOREIGN KEY (vessel_id) REFERENCES vessels (id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_req     FOREIGN KEY (requested_by) REFERENCES users (id),
  CONSTRAINT fk_orders_pm      FOREIGN KEY (pm_user_id) REFERENCES users (id),
  CONSTRAINT fk_orders_tm      FOREIGN KEY (tm_user_id) REFERENCES users (id),
  CONSTRAINT fk_orders_proc    FOREIGN KEY (procurement_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_driver  FOREIGN KEY (driver_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- order_items
-- Hard cap of 20 lines per order is enforced in the application layer
-- (workflow.php) and by the trigger below.
DROP TABLE IF EXISTS order_items;
CREATE TABLE order_items (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id             INT UNSIGNED NOT NULL,
  line_no              TINYINT UNSIGNED NOT NULL,
  description          VARCHAR(500) NOT NULL,
  part_number          VARCHAR(80) NULL,
  manufacturer         VARCHAR(120) NULL,
  quantity             DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit                 VARCHAR(16) NOT NULL DEFAULT 'pcs',
  supplier             VARCHAR(160) NULL,
  unit_price           DECIMAL(12,2) NULL,
  currency             CHAR(3) NOT NULL DEFAULT 'EUR',
  approx_delivery_date DATE NULL,
  actual_delivery_date DATE NULL,
  item_status          ENUM('requested','approved','ordered','backordered','received','dispatched','delivered','cancelled')
                       NOT NULL DEFAULT 'requested',
  PRIMARY KEY (id),
  UNIQUE KEY uq_items_line (order_id, line_no),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$
CREATE TRIGGER trg_items_max20 BEFORE INSERT ON order_items
FOR EACH ROW
BEGIN
  DECLARE n INT;
  SELECT COUNT(*) INTO n FROM order_items WHERE order_id = NEW.order_id;
  IF n >= 20 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An order cannot hold more than 20 line items.';
  END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------- attachments
DROP TABLE IF EXISTS attachments;
CREATE TABLE attachments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id      INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NULL,
  kind          ENUM('photo','audio','document') NOT NULL,
  stored_name   VARCHAR(160) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  uploaded_by   INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_att_order (order_id),
  KEY ix_att_item (item_id),
  CONSTRAINT fk_att_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_item  FOREIGN KEY (item_id) REFERENCES order_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_user  FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- approvals
-- Two gates per order: level 1 = Project Manager, level 2 = Technical Manager.
DROP TABLE IF EXISTS approvals;
CREATE TABLE approvals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id        INT UNSIGNED NOT NULL,
  level           ENUM('pm','tm') NOT NULL,
  approver_user_id INT UNSIGNED NOT NULL,
  decision        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  comment         VARCHAR(500) NULL,
  decided_at      DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_appr_order_level (order_id, level),
  CONSTRAINT fk_appr_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_appr_user  FOREIGN KEY (approver_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- status_history
DROP TABLE IF EXISTS status_history;
CREATE TABLE status_history (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(32) NULL,
  to_status   VARCHAR(32) NOT NULL,
  changed_by  INT UNSIGNED NOT NULL,
  comment     VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_hist_order (order_id),
  CONSTRAINT fk_hist_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_hist_user  FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- notifications
DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  order_id   INT UNSIGNED NULL,
  title      VARCHAR(160) NOT NULL,
  body       VARCHAR(500) NOT NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  emailed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_notif_user (user_id, is_read),
  CONSTRAINT fk_notif_user  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- access_log
DROP TABLE IF EXISTS access_log;
CREATE TABLE access_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  email_tried VARCHAR(190) NULL,
  event       ENUM('login_success','login_failed','twofa_success','twofa_failed',
                   'logout','lockout','password_change','role_change','user_created',
                   'user_disabled','vessel_import','sharepoint_sync','order_deleted') NOT NULL,
  detail      VARCHAR(255) NULL,
  ip_address  VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_log_user (user_id),
  KEY ix_log_event (event),
  KEY ix_log_time (created_at),
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- sharepoint_sync
DROP TABLE IF EXISTS sharepoint_sync;
CREATE TABLE sharepoint_sync (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    INT UNSIGNED NULL,
  direction   ENUM('push','pull') NOT NULL,
  http_status SMALLINT NULL,
  ok          TINYINT(1) NOT NULL DEFAULT 0,
  message     VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_sp_order (order_id),
  CONSTRAINT fk_sp_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- settings
DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
  k VARCHAR(64) NOT NULL,
  v TEXT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Per-order Q&A between the requester and everyone else involved.
-- Rendered inside the order's history timeline.
CREATE TABLE order_messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  reply_to   INT UNSIGNED NULL,                 -- threaded reply reference
  body       TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_om_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_om_user  FOREIGN KEY (user_id)  REFERENCES users(id),
  CONSTRAINT fk_om_reply FOREIGN KEY (reply_to) REFERENCES order_messages(id) ON DELETE SET NULL,
  INDEX idx_om_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time form tokens: idempotency guard against double-submitted forms.
CREATE TABLE form_tokens (
  token      CHAR(32) NOT NULL PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  purpose    VARCHAR(40) NOT NULL,
  result_id  INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ft_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
