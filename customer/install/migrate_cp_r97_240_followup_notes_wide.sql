-- GAS-CP-R97-240 — offer follow-up: room for the outcome and the comment (key r99r)
SET NAMES utf8mb4;
ALTER TABLE offer_events MODIFY note TEXT NULL;
ALTER TABLE offers MODIFY next_action_note VARCHAR(2000) NULL;
