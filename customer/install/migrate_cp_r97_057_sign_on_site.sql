-- GAS-CP-R97-057 (v1.151.0) — Customer signs on the engineer's device: how and in which capacity a document was accepted
ALTER TABLE fsr_reports ADD COLUMN accepted_via VARCHAR(20) NULL;
ALTER TABLE fsr_reports ADD COLUMN accepted_role VARCHAR(80) NULL;
ALTER TABLE fc_reports ADD COLUMN accepted_via VARCHAR(20) NULL;
ALTER TABLE fc_reports ADD COLUMN accepted_role VARCHAR(80) NULL;
