-- GAS-CP-R92-005 — HR notifications (leave requests, HR messages, trainings, evaluations, HR assistant, recruiting) get their own group "HR" instead of "Work".
UPDATE notifications SET grp = 'hr' WHERE grp = 'work' AND (kind LIKE 'rc%' OR kind LIKE 'hrq%' OR kind LIKE 'hrm%' OR kind LIKE 'hrt%' OR kind LIKE 'hrev%' OR kind LIKE 'hra%');
