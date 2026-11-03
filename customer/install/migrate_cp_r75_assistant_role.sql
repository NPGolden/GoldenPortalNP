-- GAS-CP-R75-005 — Personal Assistant role: contacts, companies, customers and vessels only (scope "assistant", no financials)
INSERT INTO staff_roles (name, sort_order, is_active, see_financials, can_offer, scope)
SELECT 'Personal Assistant', 13, 1, 0, 0, 'assistant' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM staff_roles WHERE scope = 'assistant');
