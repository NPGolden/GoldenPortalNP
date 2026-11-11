-- R97-005 (v1.126.1): System Budgets — audit trail: the IP address on every event; events are kept when a budget or a template is deleted.
ALTER TABLE sb_events ADD COLUMN ip VARCHAR(45) NULL AFTER note;
ALTER TABLE sb_events ADD KEY ix_sbv_time (created_at);
ALTER TABLE sb_events ADD KEY ix_sbv_user (user_id, kind, created_at);
