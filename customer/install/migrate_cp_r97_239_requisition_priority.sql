-- GAS-CP-R97-239 — purchase requisitions carry a priority chosen by the requester (key r99q)
SET NAMES utf8mb4;
ALTER TABLE pr_requests ADD COLUMN IF NOT EXISTS priority VARCHAR(8) NOT NULL DEFAULT 'normal';
