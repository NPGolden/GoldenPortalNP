-- GAS-CP-R97-254 — login history: sign-ins from the mobile apps carry the app, its version and the platform (key r99w)
SET NAMES utf8mb4;
ALTER TABLE login_log ADD COLUMN source VARCHAR(16) NULL, ADD COLUMN app_version VARCHAR(16) NULL, ADD COLUMN platform VARCHAR(8) NULL, ADD KEY idx_ll_source (source);
