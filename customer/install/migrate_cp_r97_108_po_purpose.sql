-- GAS-CP-R97-108 (v1.181.0) — purchase orders and RFQs state their purpose: a project, stock, or own use
ALTER TABLE po_orders ADD COLUMN purpose ENUM('project','stock','use') NOT NULL DEFAULT 'project' AFTER subproject_id;
UPDATE po_orders SET purpose = 'stock' WHERE project_id IS NULL AND subproject_id IS NULL;
