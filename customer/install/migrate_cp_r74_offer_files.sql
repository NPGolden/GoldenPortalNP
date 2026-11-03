-- GAS-CP-R74-002 — several files per offer: supporting documents (approval letter, specifications, drawings…) hang on the offer
ALTER TABLE sp_files ADD COLUMN offer_id INT NULL AFTER fsr_id, ADD INDEX ix_sp_files_offer (offer_id);
