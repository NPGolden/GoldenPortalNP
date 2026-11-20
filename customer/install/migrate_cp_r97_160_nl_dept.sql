-- GAS-CP-R97-160 (v1.203.0): a newsletter issue belongs to a department — 'sales' (Commercial › Web Sales) or 'hr' (HR › Newsletters). Idempotent.
ALTER TABLE nl_issues ADD COLUMN IF NOT EXISTS dept VARCHAR(8) NOT NULL DEFAULT 'sales';
