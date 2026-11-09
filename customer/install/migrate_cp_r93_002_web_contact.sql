-- GAS-CP-R93-002 — website contact form lands in the Website inbox too
ALTER TABLE web_inquiries MODIFY kind ENUM('ticket','access','contact') NOT NULL;
