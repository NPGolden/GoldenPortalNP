-- GAS-CP-R69-002 — performance pass: indexes for the dashboard counters, the activity ranking and the HR balance queries.
-- Every statement is tolerated by the updater when the index already exists.
ALTER TABLE tickets           ADD INDEX ix_tk_created      (created_at);
ALTER TABLE tickets           ADD INDEX ix_tk_creator      (created_by, created_at);
ALTER TABLE tickets           ADD INDEX ix_tk_status_upd   (status, updated_at);
ALTER TABLE ticket_comments   ADD INDEX ix_tkc_created     (created_at, user_id);
ALTER TABLE offers            ADD INDEX ix_of_created      (created_at);
ALTER TABLE offers            ADD INDEX ix_of_status       (status, created_at);
ALTER TABLE fsr_reports       ADD INDEX ix_fsr_date        (report_date, created_by);
ALTER TABLE isr_reports       ADD INDEX ix_isr_created     (created_at, created_by);
ALTER TABLE production_orders ADD INDEX ix_po_created      (created_at);
ALTER TABLE projects          ADD INDEX ix_pr_created      (created_at);
ALTER TABLE projects          ADD INDEX ix_pr_vessel       (vessel_id, status);
ALTER TABLE timesheet_lines   ADD INDEX ix_tsl_date        (work_date);
ALTER TABLE timesheets        ADD INDEX ix_ts_created      (created_at);
ALTER TABLE acc_reports       ADD INDEX ix_ar_created      (created_at);
ALTER TABLE equipment         ADD INDEX ix_eq_vessel       (vessel_id);
ALTER TABLE vessels           ADD INDEX ix_v_customer      (customer_id, is_active);
ALTER TABLE hr_requests       ADD INDEX ix_hrq_type        (type_id, status, date_from);
ALTER TABLE hr_requests       ADD INDEX ix_hrq_created     (created_at);
ALTER TABLE kb_questions      ADD INDEX ix_kbq_author      (author_id, created_at);
ALTER TABLE kb_answers        ADD INDEX ix_kba_author      (author_id, created_at);
ALTER TABLE mob_push_queue    ADD INDEX ix_mpq_sent        (sent_at);
