-- GAS-CP-R97-292 — company tools on projects: the person who receives a tool confirms it in the app or the portal (key r9af)
SET NAMES utf8mb4;
ALTER TABLE inv_tool_daily
  ADD COLUMN ack_at DATETIME NULL,
  ADD COLUMN ack_state VARCHAR(8) NULL,            -- ok · no (not received)
  ADD COLUMN ack_via VARCHAR(8) NULL;              -- portal · app
