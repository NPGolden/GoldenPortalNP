<?php
/**
 * Customer Portal deployment self-check.                    GAS-CP-R3-002
 *
 * Upload to  public_html/customer/install/  and open:
 *   https://goldensas.com/customer/install/cp_selfcheck.php
 *
 * Reads the LIVE server and reports, in order, everything a sign-in needs:
 * config, database, tables, migration, accounts, files, storage, sessions.
 * Read-only. Delete when done.
 */
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$root = dirname(__DIR__);
$ok = fn(bool $b) => $b ? '[ OK ]' : '[FAIL]';
@include_once $root . '/app/version.php';
echo "GAS Customer Portal self-check   ", date('c'), "   portal v", defined('CP_VERSION') ? CP_VERSION . ' (' . CP_BUILD . ')' : 'unknown (app/version.php missing)', "\n", str_repeat('=', 64), "\n";
echo "php ", PHP_VERSION, "   extensions: pdo_mysql=", extension_loaded('pdo_mysql') ? 'yes' : 'NO',
     " mbstring=", extension_loaded('mbstring') ? 'yes' : 'NO', " zip=", class_exists('ZipArchive') ? 'yes' : 'NO',
     " simplexml=", extension_loaded('simplexml') ? 'yes' : 'NO', "\n\n";

/* 1. files */
echo "1. Files\n", str_repeat('-', 64), "\n";
foreach (['index.php','app/db.php','app/i18n.php','app/helpers.php','app/auth.php','app/pm.php','app/pm_actions.php','app/xlsx.php','app/docx.php',
          'views/_layout.php','views/login.php','views/projects.php','views/subproject.php','lang/en.php','lang/el.php','lang/it.php','app/config.php'] as $f) {
    $p = $root . '/' . $f; $exists = is_file($p);
    $syntax = '';
    if ($exists && str_ends_with($f, '.php') && function_exists('exec')) {
        @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc); $syntax = $rc === 0 ? '' : '  <- SYNTAX ERROR (corrupted upload?)'; $o = [];
    }
    printf("%s %-24s %s%s\n", $ok($exists), $f, $exists ? filesize($p) . ' B' : 'MISSING', $syntax);
}
echo "   index.php requires pm.php: ", $ok(str_contains((string)@file_get_contents($root . '/index.php'), 'app/pm.php')), "\n";

/* 2. config + database */
echo "\n2. Config & database\n", str_repeat('-', 64), "\n";
if (!is_file($root . '/app/config.php')) { echo "[FAIL] app/config.php missing — copy config.sample.php and fill it in.\n"; exit; }
$cfg = require $root . '/app/config.php';
printf("   db host=%s name=%s user=%s\n", $cfg['db']['host'] ?? '?', $cfg['db']['name'] ?? '?', $cfg['db']['user'] ?? '?');
try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['db']['host'], (int)($cfg['db']['port'] ?? 3306), $cfg['db']['name']),
                   $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "[ OK ] database connection\n";
} catch (Throwable $e) { echo "[FAIL] database connection: ", $e->getMessage(), "\n"; exit; }

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach (['customers','customer_users','projects','subprojects'] as $t) printf("%s table %s\n", $ok(in_array($t, $tables, true)), $t);
if (!in_array('customer_users', $tables, true)) { echo "   -> schema never imported. Import install/schema.sql (fresh) in phpMyAdmin.\n"; exit; }
$hasRole = (bool)$pdo->query("SHOW COLUMNS FROM customer_users LIKE 'role'")->fetch();
printf("%s R3 migration (customer_users.role)  %s\n", $hasRole ? '[ OK ]' : '[WARN]', $hasRole ? '' : '— login still works; run migrate_cp_r3_pm_tool.sql for the PM tool');
foreach (['vessel_systems','drawing_requests','sp_comments','ga_items'] as $t) printf("%s table %s\n", $ok(in_array($t, $tables, true)), $t);
$vNull = ($c = $pdo->query("SHOW COLUMNS FROM vessels LIKE 'customer_id'")->fetch(PDO::FETCH_ASSOC)) && strtoupper((string)$c['Null']) === 'YES';
printf("%s R4 migration (vessels.customer_id nullable)  %s\n", $vNull ? '[ OK ]' : '[WARN]', $vNull ? '' : '— run install/migrate_cp_r4_vessels.sql for unassigned vessels');
$r5 = in_array('login_log', $tables, true) && in_array('staff_roles', $tables, true) && (bool)$pdo->query("SHOW COLUMNS FROM customer_users LIKE 'staff_role_id'")->fetch();
printf("%s R5 migration (staff_roles, login_log)  %s\n", $r5 ? '[ OK ]' : '[WARN]', $r5 ? '' : '— run install/migrate_cp_r5_roles_log.sql for Golden roles and login history');
$r6 = (bool)$pdo->query("SHOW COLUMNS FROM customer_users LIKE 'totp_enabled'")->fetch();
printf("%s R6 migration (two-factor, presence)  %s\n", $r6 ? '[ OK ]' : '[WARN]', $r6 ? '' : '— run install/migrate_cp_r6_twofa_presence.sql; until then sign-in is password-only');
$r7 = in_array('fsr_reports', $tables, true) && (bool)$pdo->query("SHOW COLUMNS FROM sp_files LIKE 'fsr_id'")->fetch();
printf("%s R7 migration (field service reports)  %s\n", $r7 ? '[ OK ]' : '[WARN]', $r7 ? '' : '— run install/migrate_cp_r7_fsr.sql for the SF-GLD-014 reports');
$r8 = in_array('job_orders', $tables, true) && (bool)$pdo->query("SHOW COLUMNS FROM fsr_lines LIKE 'work_id'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM sp_files LIKE 'fsr_task'")->fetch();
printf("%s R8 migration (hours per task, work orders)  %s\n", $r8 ? '[ OK ]' : '[WARN]', $r8 ? '' : '— run install/migrate_cp_r8_tasks_jo.sql');
$r9 = (bool)$pdo->query("SHOW COLUMNS FROM vessel_systems LIKE 'abbr'")->fetch();
$r9n = $r9 ? (int)$pdo->query("SELECT COUNT(*) FROM vessel_systems WHERE abbr IS NOT NULL AND is_active = 1")->fetchColumn() : 0;
printf("%s R9 migration (vessel systems: drawing code + abbreviation)  %s\n", $r9 ? '[ OK ]' : '[WARN]', $r9 ? $r9n . ' coded system(s) active' : '— run install/migrate_cp_r9_systems.sql (seeds the 52-system master list)');
$r10 = (bool)$pdo->query("SHOW COLUMNS FROM subprojects LIKE 'invoicing'")->fetch() && in_array('oa_comments', $tables, true);
printf("%s R10 migration (owner decisions online, invoicing status)  %s\n", $r10 ? '[ OK ]' : '[WARN]', $r10 ? '' : '— run install/migrate_cp_r10_approvals_invoicing.sql');
$r11 = in_array('timesheets', $tables, true) && in_array('disciplines', $tables, true) && (bool)$pdo->query("SHOW COLUMNS FROM oa_comments LIKE 'parent_id'")->fetch();
printf("%s R11 migration (timesheets, disciplines, comment replies)  %s\n", $r11 ? '[ OK ]' : '[WARN]', $r11 ? '' : '— run install/migrate_cp_r11_timesheets.sql');
$r14 = in_array('tickets', $tables, true) && (bool)$pdo->query("SHOW COLUMNS FROM fsr_reports LIKE 'ticket_id'")->fetch();
printf("%s R14 migration (tickets, vessel history links)  %s\n", $r14 ? '[ OK ]' : '[WARN]', $r14 ? '' : '— run install/migrate_cp_r14_tickets.sql');
$r15 = in_array('offers', $tables, true) && (bool)$pdo->query("SHOW COLUMNS FROM subprojects LIKE 'deadline'")->fetch();
printf("%s R15 migration (offers, deadlines, weekly notes)  %s\n", $r15 ? '[ OK ]' : '[WARN]', $r15 ? '' : '— run install/migrate_cp_r15_offers_weekly.sql');
$r16 = (bool)$pdo->query("SHOW COLUMNS FROM customer_users LIKE 'ui_theme'")->fetch();
printf("%s R16 migration (user theme)  %s\n", $r16 ? '[ OK ]' : '[WARN]', $r16 ? '' : '— run install/migrate_cp_r16_theme.sql');
$r17 = in_array('contacts', $tables, true) && in_array('contact_vessels', $tables, true);
printf("%s R17 migration (contacts)  %s\n", $r17 ? '[ OK ]' : '[WARN]', $r17 ? '' : '— run install/migrate_cp_r17_contacts.sql');
$r17b = in_array('companies', $tables, true) && (bool)$pdo->query("SHOW COLUMNS FROM contacts LIKE 'channels'")->fetch();
printf("%s R17-002 migration (companies, channels)  %s\n", $r17b ? '[ OK ]' : '[WARN]', $r17b ? '' : '— run install/migrate_cp_r17_companies.sql');
$r17c = in_array('offer_statuses', $tables, true);
printf("%s R17-008 migration (offer statuses)  %s\n", $r17c ? '[ OK ]' : '[WARN]', $r17c ? '' : '— run install/migrate_cp_r17_offer_statuses.sql');
$r18 = (bool)$pdo->query("SHOW COLUMNS FROM staff_roles LIKE 'see_financials'")->fetch();
printf("%s R18 migration (financials per role)  %s\n", $r18 ? '[ OK ]' : '[WARN]', $r18 ? '' : '— run install/migrate_cp_r18_financials.sql');
$r18b = (bool)$pdo->query("SHOW TABLES LIKE 'notifications'")->fetch() && (bool)$pdo->query("SHOW TABLES LIKE 'nf_cursor'")->fetch();
printf("%s R18-002 migration (notifications)  %s\n", $r18b ? '[ OK ]' : '[WARN]', $r18b ? '' : '— run install/migrate_cp_r18_notifications.sql');
$r19 = (bool)$pdo->query("SHOW TABLES LIKE 'invoicing_details'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM customers LIKE 'vat_no'")->fetch();
printf("%s R19 migration (customers, invoicing details)  %s\n", $r19 ? '[ OK ]' : '[WARN]', $r19 ? '' : '— run install/migrate_cp_r19_customers_invoicing.sql');
$r20 = (bool)$pdo->query("SHOW COLUMNS FROM sp_files LIKE 'sha256'")->fetch() && (bool)$pdo->query("SHOW TABLES LIKE 'archive_moves'")->fetch();
printf("%s R20 migration (file archive)  %s\n", $r20 ? '[ OK ]' : '[WARN]', $r20 ? '' : '— run install/migrate_cp_r20_archive.sql');
$r21 = (bool)$pdo->query("SHOW TABLES LIKE 'kb_questions'")->fetch();
printf("%s R21 migration (knowledge base)  %s\n", $r21 ? '[ OK ]' : '[WARN]', $r21 ? '' : '— run install/migrate_cp_r21_kb.sql');
$r21b = (bool)$pdo->query("SHOW TABLES LIKE 'kb_files'")->fetch();
printf("%s R21-002 migration (knowledge-base attachments)  %s\n", $r21b ? '[ OK ]' : '[WARN]', $r21b ? '' : '— re-run install/migrate_cp_r21_kb.sql');
$r22 = (bool)$pdo->query("SHOW TABLES LIKE 'tasks'")->fetch() && (bool)$pdo->query("SHOW TABLES LIKE 'cp_settings'")->fetch();
printf("%s R22 migration (licence, tasks)  %s\n", $r22 ? '[ OK ]' : '[WARN]', $r22 ? '' : '— run install/migrate_cp_r22_license_tasks.sql');
$r22b = (bool)$pdo->query("SHOW TABLES LIKE 'task_kinds'")->fetch();
printf("%s R22-006 migration (task types)  %s\n", $r22b ? '[ OK ]' : '[WARN]', $r22b ? '' : '— re-run install/migrate_cp_r22_license_tasks.sql');
$r22c = (bool)$pdo->query("SHOW TABLES LIKE 'file_kinds'")->fetch();
printf("%s R22-007 migration (file categories)  %s\n", $r22c ? '[ OK ]' : '[WARN]', $r22c ? '' : '— run install/migrate_cp_r22c_file_kinds.sql');
$r23 = (bool)$pdo->query("SHOW TABLES LIKE 'work_hours'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM disciplines LIKE 'rate_normal'")->fetch();
printf("%s R23 migration (working hours, holidays)  %s\n", $r23 ? '[ OK ]' : '[WARN]', $r23 ? '' : '— run install/migrate_cp_r23_calendars.sql');
$r23b = (bool)$pdo->query("SHOW TABLES LIKE 'isr_reports'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM sp_files LIKE 'isr_id'")->fetch();
printf("%s R23-003 migration (inspection reports)    %s\n", $r23b ? '[ OK ]' : '[WARN]', $r23b ? '' : '— run install/migrate_cp_r23_isr.sql');
$r24 = (bool)$pdo->query("SHOW TABLES LIKE 'branches'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM projects LIKE 'branch_id'")->fetch();
printf("%s R24-001 migration (branches)              %s\n", $r24 ? '[ OK ]' : '[WARN]', $r24 ? '' : '— run install/migrate_cp_r24_branches.sql');
$r24b = !str_contains(strtolower((string)($pdo->query("SHOW COLUMNS FROM subprojects LIKE 'pct_fat'")->fetch()['Type'] ?? 'unsigned')), 'unsigned');
printf("%s R24-004 migration (stage N/A)             %s\n", $r24b ? '[ OK ]' : '[WARN]', $r24b ? '' : '— run install/migrate_cp_r24_stages_na.sql');
$r25 = (bool)$pdo->query("SHOW TABLES LIKE 'sp_team'")->fetch();
printf("%s R25-001 migration (sub-project teams)     %s\n", $r25 ? '[ OK ]' : '[WARN]', $r25 ? '' : '— run install/migrate_cp_r25_teams.sql');
$r26 = (bool)$pdo->query("SHOW TABLES LIKE 'plan_entries'")->fetch();
printf("%s R26-001 migration (planning board)        %s\n", $r26 ? '[ OK ]' : '[WARN]', $r26 ? '' : '— run install/migrate_cp_r26_planning.sql');
$r27 = (bool)$pdo->query("SHOW TABLES LIKE 'equipment'")->fetch();
printf("%s R27-001 migration (equipment register)     %s\n", $r27 ? '[ OK ]' : '[WARN]', $r27 ? '' : '— run install/migrate_cp_r27_equipment.sql');
$r27b = (bool)$pdo->query("SHOW TABLES LIKE 'equipment_contacts'")->fetch();
printf("%s R27-003 migration (equipment links)        %s\n", $r27b ? '[ OK ]' : '[WARN]', $r27b ? '' : '— run install/migrate_cp_r27_equipment_links.sql');
$r28 = (bool)$pdo->query("SHOW TABLES LIKE 'offer_notes'")->fetch();
printf("%s R28-001 migration (offer notes / tasks)       %s\n", $r28 ? '[ OK ]' : '[WARN]', $r28 ? '' : '— run install/migrate_cp_r28_offer_notes.sql');
$r28b = (bool)$pdo->query("SHOW COLUMNS FROM tickets LIKE 'legacy_id'")->fetch();
printf("%s R28-002 migration (Freshdesk import)         %s\n", $r28b ? '[ OK ]' : '[WARN]', $r28b ? '' : '— run install/migrate_cp_r28_freshdesk.sql');
$r30 = (bool)$pdo->query("SHOW TABLES LIKE 'vessel_aliases'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM vessels LIKE 'merged_into'")->fetch();
printf("%s R30-001 migration (vessel unification)         %s\n", $r30 ? '[ OK ]' : '[WARN]', $r30 ? '' : '— run install/migrate_cp_r30_vessel_unify.sql');
$r31 = (bool)$pdo->query("SHOW TABLES LIKE 'wf_forecasts'")->fetch() && (bool)$pdo->query("SHOW TABLES LIKE 'vessel_areas'")->fetch();
printf("%s R31-001 migration (decks, areas, works forecast)  %s\n", $r31 ? '[ OK ]' : '[WARN]', $r31 ? '' : '— run install/migrate_cp_r31_forecast.sql');
$r32 = (bool)$pdo->query("SHOW TABLES LIKE 'email_templates'")->fetch();
printf("%s R32-001 migration (e-mail templates)  %s\n", $r32 ? '[ OK ]' : '[WARN]', $r32 ? '' : '— run install/migrate_cp_r32_email_templates.sql');
$r33 = (bool)$pdo->query("SHOW TABLES LIKE 'offer_lines'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM offers LIKE 'layout'")->fetch();
printf("%s R33-001 migration (rate cards, offer builder)  %s\n", $r33 ? '[ OK ]' : '[WARN]', $r33 ? '' : '— run install/migrate_cp_r33_offer_builder.sql');
$r34 = (bool)$pdo->query("SHOW TABLES LIKE 'sp_budget_hours'")->fetch() && (bool)$pdo->query("SHOW TABLES LIKE 'sp_costs'")->fetch();
printf("%s R34-001 migration (budget vs actual)  %s\n", $r34 ? '[ OK ]' : '[WARN]', $r34 ? '' : '— run install/migrate_cp_r34_budget.sql');
$r35 = (bool)$pdo->query("SHOW TABLES LIKE 'sp_work_people'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM plan_entries LIKE 'work_id'")->fetch();
printf("%s R35-001 migration (work packages, workload)  %s\n", $r35 ? '[ OK ]' : '[WARN]', $r35 ? '' : '— run install/migrate_cp_r35_work_packages.sql');
$r36 = (bool)$pdo->query("SHOW COLUMNS FROM customer_users LIKE 'can_works'")->fetch();
printf("%s R36-005 migration (work-package permission)  %s\n", $r36 ? '[ OK ]' : '[WARN]', $r36 ? '' : '— run install/migrate_cp_r36_works_permission.sql');
$r38 = (bool)$pdo->query("SHOW TABLES LIKE 'acc_reports'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM timesheets LIKE 'invoicing'")->fetch();
printf("%s R38-001 migration (accounting register)  %s\n", $r38 ? '[ OK ]' : '[WARN]', $r38 ? '' : '— run install/migrate_cp_r38_accounting.sql');
$r38b = (bool)$pdo->query("SHOW COLUMNS FROM fsr_reports LIKE 'kind'")->fetch();
printf("%s R38-003 migration (service calls, external reports)  %s\n", $r38b ? '[ OK ]' : '[WARN]', $r38b ? '' : '— run install/migrate_cp_r38b_service_calls.sql');
$r39 = (bool)$pdo->query("SHOW TABLES LIKE 'kpi_targets'")->fetch() && (bool)$pdo->query("SHOW TABLES LIKE 'sp_risks'")->fetch();
printf("%s R39-001 migration (KPIs, risk log, sales)  %s\n", $r39 ? '[ OK ]' : '[WARN]', $r39 ? '' : '— run install/migrate_cp_r39_kpis.sql');
$r40 = (bool)$pdo->query("SHOW COLUMNS FROM offers LIKE 'origin'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM branches LIKE 'manhour_cost'")->fetch();
printf("%s R40-001 migration (offer numbers, manhour cost)  %s\n", $r40 ? '[ OK ]' : '[WARN]', $r40 ? '' : '— run install/migrate_cp_r40_offer_numbers.sql');
$r41 = (bool)$pdo->query("SHOW TABLES LIKE 'production_orders'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM customer_users LIKE 'is_production'")->fetch();
printf("%s R41-001 migration (production orders)  %s\n", $r41 ? '[ OK ]' : '[WARN]', $r41 ? '' : '— run install/migrate_cp_r41_production.sql');
$r41b = (bool)$pdo->query("SHOW TABLES LIKE 'production_subs'")->fetch();
printf("%s R41-003 migration (subcontracting)  %s\n", $r41b ? '[ OK ]' : '[WARN]', $r41b ? '' : '— run install/migrate_cp_r41b_subcontracting.sql');
$r42 = (bool)$pdo->query("SHOW COLUMNS FROM production_orders LIKE 'st_engineering'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM subprojects LIKE 'stage_manual'")->fetch();
printf("%s R42-001 migration (stage feed)  %s\n", $r42 ? '[ OK ]' : '[WARN]', $r42 ? '' : '— run install/migrate_cp_r42_stage_feed.sql');
$r43 = (bool)$pdo->query("SHOW TABLES LIKE 'sp_contacts'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM offers LIKE 'sq_no'")->fetch();
printf("%s R43 migration (numbering, vessel invoicing, sub-project contacts)  %s\n", $r43 ? '[ OK ]' : '[WARN]', $r43 ? '' : '— run install/migrate_cp_r43_numbering.sql');
$r44 = (bool)$pdo->query("SHOW TABLES LIKE 'proformas'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM offers LIKE 'kind'")->fetch();
printf("%s R44 migration (budgetary offers, payment terms, proformas)  %s\n", $r44 ? '[ OK ]' : '[WARN]', $r44 ? '' : '— run install/migrate_cp_r44_proformas.sql');
$r45 = (bool)$pdo->query("SHOW COLUMNS FROM customer_users LIKE 'can_cashflow'")->fetch();
printf("%s R45 migration (cash flow permission)  %s\n", $r45 ? '[ OK ]' : '[WARN]', $r45 ? '' : '— run install/migrate_cp_r45_cashflow.sql');
$r46c = $pdo->query("SHOW COLUMNS FROM fsr_lines LIKE 'time_type'")->fetch(); $r46 = $r46c && str_contains((string)$r46c['Type'], 'NC');
printf("%s R46-005 migration (NC hours)  %s\n", $r46 ? '[ OK ]' : '[WARN]', $r46 ? '' : '— run install/migrate_cp_r46_nc_hours.sql');
$r47 = (bool)$pdo->query("SHOW TABLES LIKE 'eng_budgets'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM timesheet_lines LIKE 'activity'")->fetch();
printf("%s R47 migration (engineering sections)  %s\n", $r47 ? '[ OK ]' : '[WARN]', $r47 ? '' : '— run install/migrate_cp_r47_engineering.sql');
$r47b = (bool)$pdo->query("SHOW TABLES LIKE 'eng_sections'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM disciplines LIKE 'eng_section_id'")->fetch();
printf("%s R47-002 migration (section mapping)  %s\n", $r47b ? '[ OK ]' : '[WARN]', $r47b ? '' : '— run install/migrate_cp_r47b_eng_sections.sql');
$r48 = (bool)$pdo->query("SHOW TABLES LIKE 'eng_section_disciplines'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM sp_budget_hours LIKE 'section_id'")->fetch();
printf("%s R48 migration (stage sections)  %s\n", $r48 ? '[ OK ]' : '[WARN]', $r48 ? '' : '— run install/migrate_cp_r48_stage_sections.sql');
$r49 = (bool)$pdo->query("SHOW COLUMNS FROM customer_users LIKE 'session_epoch'")->fetch();
printf("%s R49 migration (session hardening)  %s\n", $r49 ? '[ OK ]' : '[WARN]', $r49 ? '' : '— run install/migrate_cp_r49_sessions.sql');
$r50 = (bool)$pdo->query("SHOW TABLES LIKE 'ai_log'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM customer_users LIKE 'can_ai'")->fetch();
printf("%s R50 migration (assistant)  %s\n", $r50 ? '[ OK ]' : '[WARN]', $r50 ? '' : '— run install/migrate_cp_r50_assistant.sql');
$r51 = (bool)$pdo->query("SHOW TABLES LIKE 'hr_leave_types'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM plan_entries LIKE 'leave_type_id'")->fetch();
printf("%s R51 migration (HR leave planning)  %s\n", $r51 ? '[ OK ]' : '[WARN]', $r51 ? '' : '— run install/migrate_cp_r51_hr_role.sql');
$r54 = (bool)$pdo->query("SHOW TABLES LIKE 'hr_requests'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM hr_leave_types LIKE 'unit'")->fetch();
printf("%s R54 migration (HR leave requests)  %s\n", $r54 ? '[ OK ]' : '[WARN]', $r54 ? '' : '— run install/migrate_cp_r54_hr_requests.sql');
$r54b = (bool)$pdo->query("SHOW COLUMNS FROM hr_leave_types LIKE 'name_uk'")->fetch();
printf("%s R54-002 migration (leave-type names per language)  %s\n", $r54b ? '[ OK ]' : '[WARN]', $r54b ? '' : '— run install/migrate_cp_r54_002_hr_names.sql');
$r56 = (bool)$pdo->query("SHOW TABLES LIKE 'hr_employments'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM hr_people LIKE 'left_date'")->fetch();
printf("%s R56 migration (employee card)  %s\n", $r56 ? '[ OK ]' : '[WARN]', $r56 ? '' : '— run install/migrate_cp_r56_employee_card.sql');
$r56b = (bool)$pdo->query("SHOW TABLES LIKE 'hr_messages'")->fetch();
printf("%s R56-002 migration (disciplines + HR messages)  %s\n", $r56b ? '[ OK ]' : '[WARN]', $r56b ? '' : '— run install/migrate_cp_r56_002_hr_unify.sql');
$r56c = (bool)$pdo->query("SHOW COLUMNS FROM hr_requests LIKE 'counted'")->fetch();
printf("%s R56-003 migration (history-only records)  %s\n", $r56c ? '[ OK ]' : '[WARN]', $r56c ? '' : '— run install/migrate_cp_r56_003_history_only.sql');
$r55 = (bool)$pdo->query("SHOW TABLES LIKE 'hr_audit'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM hr_entitlements LIKE 'auto'")->fetch();
printf("%s R55 migration (leave engine, change log)  %s\n", $r55 ? '[ OK ]' : '[WARN]', $r55 ? '' : '— run install/migrate_cp_r55_entitlement_engine.sql');
$r55b = (bool)$pdo->query("SHOW COLUMNS FROM hr_people LIKE 'jurisdiction'")->fetch();
printf("%s R55-002 migration (rule sets, type allowances, contractors)  %s\n", $r55b ? '[ OK ]' : '[WARN]', $r55b ? '' : '— run install/migrate_cp_r55_002_rules_types.sql');
$r60 = (bool)$pdo->query("SHOW TABLES LIKE 'hr_offboarding'")->fetch();
printf("%s R60-001 migration (leave per birth, release of contract)  %s\n", $r60 ? '[ OK ]' : '[WARN]', $r60 ? '' : '— run install/migrate_cp_r60_birth_offboarding.sql');
$r59 = (bool)$pdo->query("SHOW TABLES LIKE 'cp_requests'")->fetch();
printf("%s R59-001 migration (portal requests)  %s\n", $r59 ? '[ OK ]' : '[WARN]', $r59 ? '' : '— run install/migrate_cp_r59_change_requests.sql');
$r57 = (bool)$pdo->query("SHOW COLUMNS FROM projects LIKE 'activated_at'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM subprojects LIKE 'activated_at'")->fetch();
printf("%s R57-001 migration (quoted projects)  %s\n", $r57 ? '[ OK ]' : '[WARN]', $r57 ? '' : '— run install/migrate_cp_r57_project_quoted.sql');
$r55c = (bool)$pdo->query("SHOW TABLES LIKE 'hr_person_types'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM hr_people LIKE 'gender'")->fetch();
printf("%s R55-004 migration (gender, types per person)  %s\n", $r55c ? '[ OK ]' : '[WARN]', $r55c ? '' : '— run install/migrate_cp_r55_004_gender_types.sql');
$updTok = (function () use ($root) { try { $c = require $root . '/app/config.php'; return strlen((string)($c['update']['token'] ?? '')) >= 32; } catch (Throwable $e) { return false; } })();
printf("%s release updater (update.token in config, storage/updates writable)  %s\n", $updTok && is_writable($root . '/storage') ? '[ OK ]' : '[INFO]', $updTok ? '' : '— optional: set update.token for GitHub deploys (DEPLOY.md)');
printf("%s storage/hr writable (leave documents)  %s\n", is_dir(__DIR__ . '/../storage') && is_writable(__DIR__ . '/../storage') ? '[ OK ]' : '[WARN]', '');
$r52 = (bool)$pdo->query("SHOW TABLES LIKE 'mob_devices'")->fetch() && (bool)$pdo->query("SHOW TABLES LIKE 'sync_ops'")->fetch();
printf("%s R52 migration (mobile app API)  %s\n", $r52 ? '[ OK ]' : '[WARN]', $r52 ? '' : '— run install/migrate_cp_r52_mobile_engineer.sql');
printf("%s api/v1 + mobile client present  %s\n", is_file(__DIR__ . '/../api/v1/index.php') && is_file(__DIR__ . '/../mobile/index.html') ? '[ OK ]' : '[WARN]', is_file(__DIR__ . '/../api/v1/index.php') ? '' : '— upload customer/api/v1 and customer/mobile');
printf("%s libsodium (assistant key encryption)  %s\n", function_exists('sodium_crypto_secretbox') ? '[ OK ]' : '[WARN]', function_exists('sodium_crypto_secretbox') ? '' : '— enable the sodium extension in cPanel › Select PHP Version');
printf("%s curl to api.anthropic.com  %s\n", extension_loaded('curl') ? '[ OK ]' : '[WARN]', extension_loaded('curl') ? '' : '— the assistant needs the curl extension');
$r30b = (bool)$pdo->query("SHOW TABLES LIKE 'rs_contracts'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM tickets LIKE 'rs_contract_id'")->fetch();
printf("%s R30-004 migration (remote support contracts)    %s\n", $r30b ? '[ OK ]' : '[WARN]', $r30b ? '' : '— run install/migrate_cp_r30_remote_support.sql');
$r29 = (bool)$pdo->query("SHOW TABLES LIKE 'sp_issues'")->fetch();
printf("%s R29-001 migration (open-issues register)    %s\n", $r29 ? '[ OK ]' : '[WARN]', $r29 ? '' : '— run install/migrate_cp_r29_issues.sql');
$r29b = (bool)$pdo->query("SHOW COLUMNS FROM sp_issues LIKE 'issue_no'")->fetch();
printf("%s R29-003 migration (issue numbers)           %s\n", $r29b ? '[ OK ]' : '[WARN]', $r29b ? '' : '— run install/migrate_cp_r29_issue_no.sql');
$r29c = (bool)$pdo->query("SHOW TABLES LIKE 'issue_categories'")->fetch();
printf("%s R29-004 migration (issue categories)        %s\n", $r29c ? '[ OK ]' : '[WARN]', $r29c ? '' : '— run install/migrate_cp_r29_issue_categories.sql');
printf("%s PHP OpenSSL extension %s\n", function_exists('openssl_verify') ? '[ OK ]' : '[FAIL]', function_exists('openssl_verify') ? '(licence verification)' : '— MISSING: the licence cannot be verified');
if ($r22) { $lk = $pdo->query("SELECT v FROM cp_settings WHERE k = 'license'")->fetchColumn(); printf("%s Licence key %s\n", $lk ? '[ OK ]' : '[WARN]', $lk ? 'installed' : 'not installed — Settings › Licence (14 activation days)'); }
if (function_exists('cp_integrity')) {
    try { $ig = cp_integrity(); $nb = count($ig['modified']) + count($ig['missing']);
          printf("%s File integrity vs manifest v%s  %s\n", $ig['manifest'] ? ($nb ? '[WARN]' : '[ OK ]') : '[WARN]', $ig['manifest']['version'] ?? '?', $ig['manifest'] ? ($nb ? "$nb file(s) differ — see Settings › About" : count($ig['ok']) . ' files match') : 'install/manifest.json missing'); } catch (Throwable $e) {}
}
$tplS = $root . '/install/templates/systems-template.xlsx';
printf("%s %-40s %s\n", $ok(is_file($tplS)), 'install/templates/systems-template.xlsx', is_file($tplS) ? filesize($tplS) . ' B' : 'MISSING — the template download on the import page will 404');
foreach (['app/fonts/LiberationSans-Regular.ttf' => 410820, 'app/fonts/LiberationSans-Bold.ttf' => 414568] as $ff => $sz) {
    $okf = is_file($root . '/' . $ff) && filesize($root . '/' . $ff) === $sz;
    printf("%s %-40s %s\n", $ok($okf), $ff, $okf ? 'OK' : (is_file($root . '/' . $ff) ? 'WRONG SIZE — re-upload in binary mode (PDF text will be garbled)' : 'MISSING — the report PDF cannot be produced'));
}
printf("%s GD extension (photos / signatures in PDF)  %s\n", function_exists('imagecreatefromstring') ? '[ OK ]' : '[WARN]', function_exists('imagecreatefromstring') ? '' : '— PNG photos and signatures will be skipped in the PDF');
printf("%s assets/qrcode.min.js  %s\n", $ok(is_file(dirname($root) . '/assets/qrcode.min.js')), is_file(dirname($root) . '/assets/qrcode.min.js') ? filesize(dirname($root) . '/assets/qrcode.min.js') . ' B (24829 expected)' : 'MISSING — the 2FA QR code cannot be drawn (manual key still works)');

/* 3. accounts */
echo "\n3. Accounts\n", str_repeat('-', 64), "\n";
$cols = $hasRole ? 'id, email, role, is_active, customer_id, failed_logins, locked_until' : 'id, email, is_active, customer_id, failed_logins, locked_until';
$users = $pdo->query("SELECT {$cols} FROM customer_users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (!$users) {
    echo "[FAIL] NO ACCOUNTS EXIST — nobody can sign in.\n   -> open install/bootstrap_admin.php?key=<sync_key>&email=...&name=...&password=... to create the first admin.\n";
} else {
    foreach ($users as $u) {
        $cust = $u['customer_id'] ? $pdo->query('SELECT name, is_active FROM customers WHERE id = ' . (int)$u['customer_id'])->fetch(PDO::FETCH_ASSOC) : null;
        $why = [];
        if (!$u['is_active']) $why[] = 'account disabled';
        if ($u['locked_until'] && strtotime($u['locked_until']) > time()) $why[] = 'LOCKED until ' . $u['locked_until'] . ' (5 bad passwords)';
        if ($u['customer_id'] && (!$cust || !$cust['is_active'])) $why[] = 'customer missing/inactive';
        printf("%s #%-3d %-32s %-9s %s%s\n", $why ? '[FAIL]' : '[ OK ]', $u['id'], $u['email'], $hasRole ? $u['role'] : 'customer',
               $cust ? '(' . $cust['name'] . ')' : '(staff)', $why ? '  <- ' . implode('; ', $why) : '');
    }
    echo "   Wrong password? bootstrap_admin.php with &password= resets it for an admin; failed_logins resets on success.\n";
}

/* 4. storage + sessions */
echo "\n4. Storage & sessions\n", str_repeat('-', 64), "\n";
foreach (['storage', 'storage/uploads', 'storage/sessions'] as $d) {
    $p = $root . '/' . $d; if (!is_dir($p)) @mkdir($p, $d === 'storage/sessions' ? 0700 : 0755, true);
    printf("%s %-18s %s\n", $ok(is_dir($p) && is_writable($p)), $d, is_dir($p) ? (is_writable($p) ? 'writable' : 'NOT writable (chmod 755 / 700)') : 'missing');
}
$probe = $root . '/storage/sessions/.probe_' . bin2hex(random_bytes(3));
$w = @file_put_contents($probe, 'x') !== false; @unlink($probe);
printf("%s session store write test\n", $ok($w));

echo "\n", str_repeat('=', 64), "\nIf every line above is OK, sign-in works: clear the site's cookies once in the browser and try again.\nDelete this file when done.\n";
