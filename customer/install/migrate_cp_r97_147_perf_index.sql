-- GAS-CP-R97-147 (v1.198.1): index for "last movement" of an inventory item (the inventory list asked it per item without one). Idempotent.
ALTER TABLE inv_moves ADD INDEX IF NOT EXISTS ix_invmv_item_kind_at (item_id, kind, created_at);
