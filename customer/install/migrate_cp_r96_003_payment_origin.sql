-- R96-003 (v1.122.2): a payment keeps the proforma it was received for.
-- for_pi = the proforma a payment logged on an invoice counts for (NULL = shared pro rata by all proformas the invoice settles).
ALTER TABLE proforma_payments ADD COLUMN for_pi INT UNSIGNED NULL AFTER proforma_id;
ALTER TABLE proforma_payments ADD KEY ix_pp_for (for_pi);
-- backfill 1: an invoice settling one proforma only — its payments are that proforma's
UPDATE proforma_payments pp JOIN (SELECT invoice_id, MIN(proforma_id) AS pid FROM pi_links GROUP BY invoice_id HAVING COUNT(*) = 1) m ON m.invoice_id = pp.proforma_id SET pp.for_pi = m.pid WHERE pp.for_pi IS NULL;
-- backfill 2: an invoice settling several proformas — a payment whose amount equals exactly one allocation (and is the only payment of that amount) belongs to that proforma
UPDATE proforma_payments pp JOIN (SELECT invoice_id, amount, MIN(proforma_id) AS pid FROM pi_links GROUP BY invoice_id, amount HAVING COUNT(*) = 1) m ON m.invoice_id = pp.proforma_id AND m.amount = pp.amount JOIN (SELECT proforma_id, amount FROM proforma_payments GROUP BY proforma_id, amount HAVING COUNT(*) = 1) u ON u.proforma_id = pp.proforma_id AND u.amount = pp.amount SET pp.for_pi = m.pid WHERE pp.for_pi IS NULL;
