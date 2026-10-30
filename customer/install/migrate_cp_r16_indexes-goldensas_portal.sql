USE goldensas_portal;
-- GAS-CP-R16-003 · optional indexes for the accounts filters and presence at 2 000+ users.
-- Safe to run twice (errors "Duplicate key name" can be ignored).
ALTER TABLE customer_users ADD INDEX idx_cu_role_active (role, is_active);
ALTER TABLE customer_users ADD INDEX idx_cu_last_login (last_login_at);
ALTER TABLE customer_users ADD INDEX idx_cu_locale (locale);
