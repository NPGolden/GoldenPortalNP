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

    'app' => [
        'base_url'     => 'https://goldensas.com/customer',
        'company'      => 'Golden Automation Systems',
        'session_name' => 'GASCP',
        'idle_minutes' => 240,   // sign-out after this many minutes of INACTIVITY (activity keeps the session alive)
    ],
];
