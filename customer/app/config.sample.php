<?php
/** Customer Portal — copy to app/config.php and fill in. GAS-CFG-CP-001 */
return [
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'goldensas_portal',
        'user'    => 'goldensas_user',
        'pass'    => 'CHANGE-ME',
        'charset' => 'utf8mb4',
    ],
    // Connect Line / SoftOne web services (see GAS-ERP-CP-001).
    // Values below are from the Connect Line document; the credentials
    // arrive in a separate document — replace them there if they differ.
    'softone' => [
        'endpoint'         => 'https://gma.oncloud.gr/s1services',
        'api_endpoint'     => 'https://gma.oncloud.gr/s1services/js/CLWEBServices.S1Lib/ApiServices',
        'username'         => 'GoldenWeb',
        'password'         => 'GoldenWeb',
        'appid'            => '1001',
        'projects_service' => 'getWorkingProjects',
        // Optional: pin the installation instead of using what Login returns.
        'company' => '', 'branch' => '', 'module' => '', 'refid' => '',
        // Secret for triggering the sync over HTTPS (cron by URL). Change it.
        'sync_key'         => 'CHANGE-THIS-LONG-RANDOM-STRING',
    ],

    // Release updater (install/update.php, GitHub Actions deploy). Leave empty to disable the updater entirely.
    // Generate with:  php -r "echo bin2hex(random_bytes(32));"   — the same value goes into the GitHub secret UPDATE_TOKEN.
    'update' => ['token' => ''],

    // Outgoing e-mail (R91-003). goldenas.com.gr is on Microsoft 365 with an SPF that allows only Microsoft, so use the
    // tenant's authenticated SMTP with a licensed mailbox that has "Authenticated SMTP" enabled. Leave 'host' empty to use PHP mail().
    // R94-007 — preferred: Microsoft Graph with OAuth2 client credentials (no mailbox password). Entra app registration with the
    // application permission Mail.Send (admin consent), limited to the sending mailbox by an Exchange application access policy.
    // When all four are set, Graph is used and the smtp block is only a fallback.
    'graph' => [
        'tenant'        => '',                          // tenant ID (GUID) or goldenas.com.gr
        'client_id'     => '',                          // Application (client) ID
        'client_secret' => '',                          // client secret VALUE (not its ID) — rotate before it expires
        'from'          => 'website@goldenas.com.gr',   // the licensed mailbox the app may send as
    ],
    'smtp' => [
        'host' => 'smtp.office365.com',
        'port' => 587,
        'user' => 'website@goldenas.com.gr',
        'pass' => '',
        'from' => 'website@goldenas.com.gr',
    ],
    'app' => [
        'base_url'     => 'https://goldensas.com/customer',
        'company'      => 'Golden Automation Systems',
        'session_name' => 'GASCP',
        'idle_minutes' => 240,   // sign-out after this many minutes of INACTIVITY (activity keeps the session alive)
    ],
];
