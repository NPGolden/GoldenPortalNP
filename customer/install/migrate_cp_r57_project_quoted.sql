-- GAS-CP-R57-001 — Projects become active through their offers.
-- New status "quoted" (offer stage) for projects and sub-projects: a project / sub-project is created quoted and
-- becomes active automatically when one of its offers is approved (approved → in progress → to be invoiced).
-- Projects with no portal offer at all keep the status they have (set by hand). Vessel "general" projects (GEN-) stay active.
ALTER TABLE projects    MODIFY status ENUM('quoted','active','onhold','completed') NOT NULL DEFAULT 'quoted';
ALTER TABLE subprojects MODIFY status ENUM('quoted','active','onhold','completed') NOT NULL DEFAULT 'quoted';
ALTER TABLE projects    ADD COLUMN activated_at DATE NULL AFTER status, ADD COLUMN activated_offer_id INT UNSIGNED NULL AFTER activated_at;
ALTER TABLE subprojects ADD COLUMN activated_at DATE NULL AFTER status, ADD COLUMN activated_offer_id INT UNSIGNED NULL AFTER activated_at;

-- One-off alignment: active projects whose portal offers are all still open / lost go back to the offer stage.
UPDATE projects p SET p.status = 'quoted'
 WHERE p.status = 'active' AND p.project_code NOT LIKE 'GEN-%'
   AND EXISTS (SELECT 1 FROM offers o WHERE o.project_id = p.id)
   AND NOT EXISTS (SELECT 1 FROM offers o LEFT JOIN offer_statuses st ON st.skey = o.status WHERE o.project_id = p.id
                   AND COALESCE(st.behaves, CASE o.status WHEN 'approved' THEN 'approved' WHEN 'in_progress' THEN 'approved' WHEN 'to_be_invoiced' THEN 'approved' WHEN 'finished' THEN 'closed_won' ELSE 'open' END) IN ('approved','closed_won'));
UPDATE subprojects s JOIN projects p ON p.id = s.project_id SET s.status = 'quoted'
 WHERE s.status = 'active' AND p.project_code NOT LIKE 'GEN-%'
   AND EXISTS (SELECT 1 FROM offers o WHERE o.project_id = p.id)
   AND NOT EXISTS (SELECT 1 FROM offers o LEFT JOIN offer_statuses st ON st.skey = o.status WHERE o.project_id = p.id AND (o.subproject_id = s.id OR o.subproject_id IS NULL)
                   AND COALESCE(st.behaves, CASE o.status WHEN 'approved' THEN 'approved' WHEN 'in_progress' THEN 'approved' WHEN 'to_be_invoiced' THEN 'approved' WHEN 'finished' THEN 'closed_won' ELSE 'open' END) IN ('approved','closed_won'));
-- Record when the remaining active ones were activated, from the first approval event of their offers.
UPDATE projects p SET p.activated_at = (SELECT MIN(DATE(e.created_at)) FROM offers o JOIN offer_events e ON e.offer_id = o.id WHERE o.project_id = p.id AND e.event = 'approved'),
                      p.activated_offer_id = (SELECT o.id FROM offers o JOIN offer_events e ON e.offer_id = o.id WHERE o.project_id = p.id AND e.event = 'approved' ORDER BY e.id LIMIT 1)
 WHERE p.status = 'active' AND p.activated_at IS NULL;
