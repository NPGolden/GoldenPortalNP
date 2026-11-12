-- GAS-CP-R97-020 (v1.129.0) — System Budgets: discount per labour line and per part line (percent of the line's sale; empty = none);
-- percentage on cost per other-cost line (travel expenses at cost + x %)
ALTER TABLE sb_labour ADD COLUMN discount_pct DECIMAL(5,2) NULL AFTER rate_ot;
ALTER TABLE sb_parts ADD COLUMN discount_pct DECIMAL(5,2) NULL AFTER margin_pct;
ALTER TABLE sb_expenses ADD COLUMN markup_pct DECIMAL(6,2) NULL AFTER cost;
