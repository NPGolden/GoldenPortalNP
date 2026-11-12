-- GAS-CP-R97-025 (v1.132.0) — Vessels: classification society and flag; filled by hand or by the particulars import
ALTER TABLE vessels ADD COLUMN class_society VARCHAR(80) NULL AFTER yard;
ALTER TABLE vessels ADD COLUMN flag VARCHAR(80) NULL AFTER class_society;
