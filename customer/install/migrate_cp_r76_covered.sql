-- GAS-CP-R76-004 — accounting lines covered by an offer (lump sum in the budget), reports closed without accounting
ALTER TABLE acc_lines MODIFY state ENUM('open','draft','reported','invoiced','excluded','covered') NOT NULL DEFAULT 'open', ADD COLUMN offer_id INT UNSIGNED NULL AFTER report_id, ADD INDEX ix_acc_lines_offer (offer_id);
ALTER TABLE acc_reports MODIFY status ENUM('draft','issued','sent','returned','partly_invoiced','invoiced','closed') NOT NULL DEFAULT 'draft', ADD COLUMN offer_id INT UNSIGNED NULL AFTER subproject_id;
ALTER TABLE timesheets MODIFY invoicing ENUM('none','sent','partly','invoiced','covered') NOT NULL DEFAULT 'none';
ALTER TABLE fsr_reports MODIFY invoicing ENUM('none','sent','partly','invoiced','covered') NOT NULL DEFAULT 'none';
