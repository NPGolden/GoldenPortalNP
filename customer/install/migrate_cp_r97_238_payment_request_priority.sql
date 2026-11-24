-- GAS-CP-R97-238 — supplier payment requests carry a priority chosen by the requester (key r99p)
SET NAMES utf8mb4;
ALTER TABLE si_requests ADD COLUMN IF NOT EXISTS priority VARCHAR(8) NOT NULL DEFAULT 'normal';
