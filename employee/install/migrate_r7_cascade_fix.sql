-- =====================================================================
--  GAS-POMS R6 -> R7: make every child table cascade when an order is
--  deleted. Repairs installations where a foreign key was created without
--  ON DELETE CASCADE, which silently BLOCKS admin order deletion.
--
--  Safe to run repeatedly. Touches only constraint rules, never data.
--  Run via phpMyAdmin -> SQL (database goldensas_poms).
-- =====================================================================

-- Each block: drop whatever FK currently points from <table>.order_id at
-- orders(id) (found by name from information_schema, so it works whatever
-- the constraint was called), then re-add it WITH cascade.

SET @db = DATABASE();

-- ---- order_items ----------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='order_id'
             AND REFERENCED_TABLE_NAME='orders' LIMIT 1);
SET @sql = IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE order_items DROP FOREIGN KEY `', @fk, '`'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE order_items ADD CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE;

-- ---- attachments ----------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='attachments' AND COLUMN_NAME='order_id'
             AND REFERENCED_TABLE_NAME='orders' LIMIT 1);
SET @sql = IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE attachments DROP FOREIGN KEY `', @fk, '`'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE attachments ADD CONSTRAINT fk_att_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE;

-- ---- approvals ------------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='approvals' AND COLUMN_NAME='order_id'
             AND REFERENCED_TABLE_NAME='orders' LIMIT 1);
SET @sql = IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE approvals DROP FOREIGN KEY `', @fk, '`'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE approvals ADD CONSTRAINT fk_appr_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE;

-- ---- status_history -------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='status_history' AND COLUMN_NAME='order_id'
             AND REFERENCED_TABLE_NAME='orders' LIMIT 1);
SET @sql = IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE status_history DROP FOREIGN KEY `', @fk, '`'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE status_history ADD CONSTRAINT fk_hist_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE;

-- ---- notifications --------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='notifications' AND COLUMN_NAME='order_id'
             AND REFERENCED_TABLE_NAME='orders' LIMIT 1);
SET @sql = IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE notifications DROP FOREIGN KEY `', @fk, '`'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE notifications ADD CONSTRAINT fk_notif_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE;

-- ---- order_messages -------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_messages' AND COLUMN_NAME='order_id'
             AND REFERENCED_TABLE_NAME='orders' LIMIT 1);
SET @sql = IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE order_messages DROP FOREIGN KEY `', @fk, '`'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE order_messages ADD CONSTRAINT fk_om_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE;

-- ---- sharepoint_sync ------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sharepoint_sync' AND COLUMN_NAME='order_id'
             AND REFERENCED_TABLE_NAME='orders' LIMIT 1);
SET @sql = IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE sharepoint_sync DROP FOREIGN KEY `', @fk, '`'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE sharepoint_sync ADD CONSTRAINT fk_sp_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE;
