-- GAS-CP-R40-001 · Portal offer numbers for every branch + ERP origin pill (release C)
-- GAS-CP-R40-002 · Mean manhour cost per branch (company cost of labour; default 35 EUR/h)
-- Run once in phpMyAdmin with the portal database selected.
ALTER TABLE offers ADD COLUMN origin VARCHAR(10) NOT NULL DEFAULT 'portal';
-- Offers that came in through the ERP (SoftOne) import keep their ERP number and are flagged "ERP".
UPDATE offers o SET o.origin = 'erp' WHERE EXISTS (SELECT 1 FROM offer_events ev WHERE ev.offer_id = o.id AND ev.event = 'imported');
ALTER TABLE branches ADD COLUMN manhour_cost DECIMAL(8,2) NOT NULL DEFAULT 35.00;
