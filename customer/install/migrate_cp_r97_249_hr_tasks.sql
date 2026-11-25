-- GAS-CP-R97-249 — HR tasks: tasks seen by HR only, optionally about one employee (key r99u)
SET NAMES utf8mb4;
ALTER TABLE tasks ADD COLUMN hr_only TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN hr_person_id INT UNSIGNED NULL, ADD KEY ix_tasks_hr (hr_only, status);
