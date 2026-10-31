-- Resets the sandbox records used by the tests (after install/seed_demo.sql + the customer seed).
UPDATE fsr_reports SET status='submitted', accepted_at=NULL, accepted_name=NULL, accepted_by=NULL, accepted_ip=NULL, customer_remark=NULL, customer_sign_id=NULL;
DELETE o1 FROM offers o1 JOIN offers o2 ON o2.offer_no = o1.offer_no AND o2.id < o1.id;
UPDATE owner_approvals SET status='submitted', decided_at=NULL, decided_by=NULL, decided_name=NULL, decided_ip=NULL, decided_time=NULL, decision_note=NULL, on_behalf=0, comments=NULL; DELETE FROM oa_comments;
UPDATE offers SET status='sent', decided_at=NULL, decided_by=NULL, decided_name=NULL, decided_ip=NULL, decision_note=NULL; DELETE FROM offer_events;
DELETE FROM tickets WHERE ticket_no NOT IN ('TK-2026-0088','TK-2026-0081'); DELETE FROM ticket_comments WHERE side='customer';
UPDATE tickets SET status='waiting_customer' WHERE ticket_no='TK-2026-0088'; UPDATE tickets SET status='resolved', closed_at=NULL WHERE ticket_no='TK-2026-0081';
-- D-8 / D-9 cases: the demo captain has 2FA on (test secret), a second customer user without 2FA, a second open offer
UPDATE customer_users SET totp_enabled = 1, totp_secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' WHERE email = 'captain@demo-fleet.com';
INSERT IGNORE INTO customer_users (id, customer_id, full_name, email, password_hash, role, locale) SELECT 91, 1, 'Demo Owner Rep', 'owner@demo-fleet.com', password_hash, 'customer', 'en' FROM customer_users WHERE email = 'captain@demo-fleet.com';
UPDATE customer_users SET totp_enabled = 0, totp_secret = NULL WHERE id = 91;
INSERT INTO offers (project_id, subproject_id, offer_no, revision, title, amount, currency, status, sent_at, valid_until, status_at, created_by) SELECT project_id, subproject_id, 'GAS-OF-2026-072', 'R0', 'Spare DI card', 980.00, 'EUR', 'sent', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), CURDATE(), 90 FROM offers WHERE offer_no = 'GAS-OF-2026-071' AND NOT EXISTS (SELECT 1 FROM offers x WHERE x.offer_no = 'GAS-OF-2026-072') LIMIT 1;
UPDATE offers SET status = 'sent', decided_at = NULL, decided_name = NULL, decision_note = NULL WHERE offer_no = 'GAS-OF-2026-072';
UPDATE tickets SET status='waiting_customer' WHERE ticket_no='TK-2026-0088';
