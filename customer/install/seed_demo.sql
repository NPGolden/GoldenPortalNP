-- =====================================================================
--  Demo data matching the approved Figma frame. Safe to skip in
--  production; useful for verifying the deployment.
--  Demo sign-in:  captain@demo-fleet.com  /  GoldenDemo2026!
-- =====================================================================
-- erp_code is how the SoftOne sync knows which projects belong to whom:
-- it must equal the CUSTOMER_CODE that getWorkingProjects returns.
INSERT INTO customers (id, name, country, erp_code) VALUES
(1, 'Demo Fleet Management', 'GR', 'DEMO-01');

-- password_hash = bcrypt of GoldenDemo2026!
INSERT INTO customer_users (customer_id, full_name, email, password_hash, locale) VALUES
(1, 'Demo Captain', 'captain@demo-fleet.com',
 '$2b$10$qfterpMO.B08WGHCW0UJxeaCKxtppFeDGGPaGqW9wN9poGl8yV1PO', 'en');

INSERT INTO vessels (id, customer_id, name, imo, yard) VALUES
(1, 1, 'MY ALPHA',  '9812345', 'Palumbo, Ancona'),
(2, 1, 'MY OCEAN',  '9823456', 'TISG, La Spezia'),
(3, 1, 'MY AURORA', '9834567', 'Golden Yachts, Perama');

INSERT INTO projects (id, customer_id, vessel_id, name, status, next_milestone) VALUES
(1, 1, 1, 'Refit-2026',  'active', 'FAT in 3 weeks'),
(2, 1, 2, 'PMS Upgrade', 'active', NULL),
(3, 1, 3, 'IAS Upgrade', 'active', 'Signature: 1 FAT');

INSERT INTO subprojects (project_id, name, pct_engineering, pct_production, pct_fat, pct_sathat) VALUES
(1, 'IAS',        100, 65, 20, 0),
(2, 'Generators', 100, 80,  0, 0),
(3, 'Bridge',      90, 40,  0, 0);

INSERT INTO documents (customer_id, vessel_id, project_id, title, category, status, expires_at) VALUES
(1, 1, 1, 'FAT-ALPHA-2026-v3',   'fat',         'sent_for_signature', NULL),
(1, 2, 2, 'FSR-OCEAN-Feb-2026',  'fsr',         'sent_for_signature', NULL),
(1, 1, 1, 'WorkOrder-ALPHA-04',  'workorder',   'sent_for_signature', NULL),
(1, 3, 3, 'Electrical SLD (MY AURORA)',    'drawing', 'pm_review',    NULL),
(1, 1, 1, 'Fire System Photos (MY ALPHA)', 'other',   'pm_review',    NULL),
(1, 2, 2, 'FSR-2026-02-15',      'fsr',         'pm_review',          NULL),
(1, 1, NULL, 'DNV Class Cert.',  'certificate', 'expiring',           '2026-06-12'),
(1, 3, 3, 'Hardening Report',    'cyber',       'restricted',         NULL);
