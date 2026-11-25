-- GAS-CP-R97-255 — authenticator obligatory for the General Manager role; devices known per account, for the notice of a sign-in from a new device (key r99x)
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS login_devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  fp CHAR(40) NOT NULL,
  label VARCHAR(80) NOT NULL,
  first_at DATETIME NOT NULL,
  last_at DATETIME NOT NULL,
  UNIQUE KEY ux_login_devices (user_id, fp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO login_devices (user_id, fp, label, first_at, last_at) SELECT user_id, SHA1(CONCAT('web|', device)), LEFT(device, 80), MIN(started_at), MAX(started_at) FROM login_sessions WHERE device IS NOT NULL AND device <> '' GROUP BY user_id, device;
INSERT IGNORE INTO login_devices (user_id, fp, label, first_at, last_at) SELECT user_id, SHA1(CONCAT('app|', app_id, '|', COALESCE(NULLIF(client_device_id, ''), platform))), LEFT(CONCAT(app_id, ' / ', platform), 80), MIN(enrolled_at), MAX(COALESCE(last_seen_at, enrolled_at)) FROM mob_devices WHERE revoked_at IS NULL GROUP BY user_id, app_id, COALESCE(NULLIF(client_device_id, ''), platform), platform;
UPDATE customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id SET cu.twofa_required = 1 WHERE LOWER(TRIM(r.name)) = 'general manager';
