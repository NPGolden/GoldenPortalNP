-- =====================================================================
--  GAS-POMS R5 -> R6: admin order deletion.
--  Adds the 'order_deleted' audit event so every deletion is logged.
--  Run once via phpMyAdmin; fresh installs get this from schema.sql.
--  Safe to re-run (re-declaring the same list is a no-op).
-- =====================================================================
ALTER TABLE access_log
  MODIFY event ENUM('login_success','login_failed','twofa_success','twofa_failed',
                    'logout','lockout','password_change','role_change','user_created',
                    'user_disabled','vessel_import','sharepoint_sync','order_deleted') NOT NULL;
