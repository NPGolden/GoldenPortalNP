-- GAS-CP-R70-004 — world clocks chosen per user (comma-separated catalogue codes; NULL = default set)
ALTER TABLE customer_users ADD COLUMN clocks VARCHAR(120) NULL AFTER ui_theme;
