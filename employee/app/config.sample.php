<?php
/**
 * GOLDEN A/S — Parts Order Management System
 * Ref: GAS-CFG-POMS-001
 *
 * Copy this file to app/config.php and fill in your hosting details.
 * Never commit app/config.php to version control.
 */

return [

    // ---- Database (MySQL 8.0+ / MariaDB 10.6+) -----------------------
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'gas_poms',
        'user'     => 'gas_poms_user',
        'pass'     => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    // ---- Application -------------------------------------------------
    'app' => [
        // Public base URL, no trailing slash. Used in notification emails.
        'base_url'      => 'https://goldensas.com/employee',
        'company'       => 'Golden A/S — Sistimata Ischyos ATEE',
        'timezone'      => 'Europe/Athens',

        // Absolute or relative path for uploads. Must be writable (0775)
        // and must sit outside the web root, or be protected by .htaccess.
        'storage_path'  => __DIR__ . '/../storage/uploads',

        // Sign-out after this many minutes of INACTIVITY.
        // Activity keeps the session alive indefinitely (rolling window).
        'idle_minutes'  => 240,

        // Account lockout after this many failed password attempts.
        'max_failed_logins' => 5,
        'lockout_minutes'   => 15,

        // Upload limits
        'max_upload_bytes'  => 16 * 1024 * 1024, // 16 MB per file
        'max_items'         => 20,
    ],

    // ---- Outbound mail ----------------------------------------------
    // 'mail' driver sends via PHP mail(). 'smtp' uses the settings below.
    // 'log' writes to storage/mail.log — use this while testing.
    'mail' => [
        'driver'     => 'mail',
        'from_email' => 'noreply@golden-as.gr',
        'from_name'  => 'Golden A/S Parts',
        'smtp' => [
            'host'   => 'smtp.office365.com',
            'port'   => 587,
            'user'   => '',
            'pass'   => '',
            'secure' => 'tls',
        ],
    ],

    // ---- Microsoft Graph / SharePoint --------------------------------
    // Register an app in Entra ID with application permission
    // Sites.ReadWrite.All (admin consent), then fill these in.
    // Values here override the `settings` table when non-empty.
    'sharepoint' => [
        'enabled'       => false,
        'tenant_id'     => '',
        'client_id'     => '',
        'client_secret' => '',
        // Graph identifiers for the "Open Orders" list.
        'site_id'       => '',   // e.g. contoso.sharepoint.com,GUID,GUID
        'list_id'       => '',   // GUID of the list
    ],
];
