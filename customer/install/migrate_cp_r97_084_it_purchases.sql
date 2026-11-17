-- GAS-CP-R97-084 (v1.162.0) — Procurement: sub-projects opened for purchases of the IT department
ALTER TABLE subprojects ADD COLUMN it_purchase TINYINT(1) NOT NULL DEFAULT 0;
