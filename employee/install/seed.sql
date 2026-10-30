-- =====================================================================
--  GOLDEN A/S — Parts Order Management System
--  Reference data. Run after schema.sql.
--  Users are created by install/setup.php (passwords are hashed there).
-- =====================================================================

-- These names are a fallback. The interface shows the translated
-- name from lang/<code>.php, keyed on roles.code.
INSERT INTO roles (code, name, description) VALUES
  ('system_admin',      'System & User Admin', 'Full access. Manages users, roles, vessels, settings and audit logs.'),
  ('field_engineer',    'Field Engineer',      'Raises parts orders from the yard and tracks them to delivery.'),
  ('project_manager',   'Project Manager',     'First approval gate for orders on their projects.'),
  ('technical_manager', 'Technical Manager',   'Second approval gate. Confirms technical fit before purchase.'),
  ('procurement',       'Procurement',         'Sources parts, places purchase orders, sets expected delivery dates.'),
  ('warehouse',         'Warehouse',           'Receives goods, picks and packs, releases orders for dispatch.'),
  ('driver',            'Driver',              'Collects dispatches and records delivery on site.');

INSERT INTO settings (k, v) VALUES
  ('order_ref_prefix',      'GAS-PO'),
  ('default_locale',        'en'),
  ('company_name',          'Golden A/S — Sistimata Ischyos ATEE'),
  ('notify_email_enabled',  '1'),
  ('sharepoint_enabled',    '0'),
  ('sharepoint_site_id',    ''),
  ('sharepoint_list_id',    ''),
  ('graph_tenant_id',       ''),
  ('graph_client_id',       ''),
  ('graph_client_secret',   '');

-- Example vessel list. Replace via Admin > Vessels > Import CSV.
INSERT INTO vessels (name, imo, hull_no, yard) VALUES
  ('AMBROSIA',   NULL,  'NB-1042', 'Palumbo, Ancona'),
  ('GALILEO',    NULL,  'C620',    'TISG, La Spezia'),
  ('SEAHAWK',    NULL,  'PN-50',   'Perini Navi, Viareggio'),
  ('BELLA VITA', NULL,  'L-60',    'Lürssen, Bremen'),
  ('HODA',       NULL,  'AD-42',   'Adriatic 42, Tivat'),
  ('CAPRI I',    NULL,  NULL,      'Piraeus'),
  ('NB531',      NULL,  '531',     'Split Shipyard, Croatia');
