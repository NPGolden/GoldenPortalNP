-- GAS-CP-R97-036 (v1.137.0) — Offers: estimated delivery time for parts and for labour (free text), printed in the Delivery time section
ALTER TABLE offers ADD COLUMN delivery_parts VARCHAR(160) NULL;
ALTER TABLE offers ADD COLUMN delivery_labour VARCHAR(160) NULL;
