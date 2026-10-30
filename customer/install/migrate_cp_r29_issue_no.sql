-- GAS-CP-R29-003 — permanent number on open issues (v1.29.2). Existing entries are numbered automatically the first time the register is opened.
SET NAMES utf8mb4;
ALTER TABLE sp_issues ADD COLUMN issue_no VARCHAR(40) NULL AFTER id, ADD UNIQUE KEY uq_iss_no (issue_no);
