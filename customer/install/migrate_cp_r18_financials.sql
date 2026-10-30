-- GAS-CP-R18-001 · Per Golden A/S role: may this role see financial data (offer amounts, invoicing, timesheet invoicing)?
ALTER TABLE staff_roles ADD COLUMN see_financials TINYINT(1) NOT NULL DEFAULT 1;
