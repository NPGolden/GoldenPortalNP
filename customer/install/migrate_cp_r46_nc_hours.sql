-- GAS-CP-R46-005 · Service reports: NC (not chargeable) hours next to WT / TT
-- Run once in phpMyAdmin with the portal database selected.
ALTER TABLE fsr_lines MODIFY time_type ENUM('TT','WT','NC') NOT NULL DEFAULT 'WT';
