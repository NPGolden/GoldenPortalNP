-- GAS-CP-R38-004 — Ticket category "Customer complaint" (quality / service complaints, tracked separately in the Reports tab).
ALTER TABLE tickets MODIFY COLUMN category ENUM('fault','service','warranty','spare_parts','question','complaint','other') NOT NULL DEFAULT 'fault';
