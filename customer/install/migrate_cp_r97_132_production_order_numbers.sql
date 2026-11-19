-- GAS-CP-R97-132 (v1.192.0) — production orders are numbered <branch>-PRD-YYYY-NNN, no longer <branch>-PO-YYYY-NNN (the code of the purchase orders)
UPDATE production_orders SET po_no = REPLACE(po_no, '-PO-', '-PRD-') WHERE po_no REGEXP '^[A-Za-z0-9]+-PO-[0-9]{4}-[0-9]+$';
