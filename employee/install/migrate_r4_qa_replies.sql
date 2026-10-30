-- =====================================================================
--  GAS-POMS R3 -> R4: threaded replies in the per-order Q&A.
--  Run once via phpMyAdmin against an existing installation; fresh
--  installs get this from schema.sql.
--  Until this runs, the thread keeps working — replies simply post as
--  ordinary messages without the reference line.
-- =====================================================================

ALTER TABLE order_messages
  ADD COLUMN reply_to INT UNSIGNED NULL AFTER user_id,
  ADD CONSTRAINT fk_om_reply FOREIGN KEY (reply_to)
      REFERENCES order_messages(id) ON DELETE SET NULL;
