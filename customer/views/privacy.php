<?php /* Public privacy policy for the portal and the mobile apps (Play Store / App Store listing requirement).   GAS-CP-R72-001   ?p=privacy  — no sign-in */
$upd = '1 November 2026'; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0A1B2E">
<title>Privacy Policy — Golden A/S Portal and mobile apps</title>
<link rel="icon" href="../assets/favicon.png" type="image/png">
<link rel="stylesheet" href="../assets/portal.css?v=<?= @filemtime(__DIR__ . '/../../assets/portal.css') ?: 1 ?>">
<style>.legal{max-width:860px;margin:0 auto;padding:28px 18px 60px;color:var(--ink);} .legal h1{font-size:26px;margin:18px 0 6px;} .legal h2{font-size:17px;margin:26px 0 8px;color:var(--navy);} .legal p,.legal li{line-height:1.55;text-align:justify;} .legal .meta{color:var(--muted);font-size:13px;} .legal table{border-collapse:collapse;width:100%;margin:8px 0 14px;font-size:14px;} .legal th,.legal td{border:1px solid var(--line);padding:6px 8px;vertical-align:top;text-align:left;} .legal th{background:var(--navy);color:#fff;} html[data-theme="dark"] .legal th{background:#1F3A5F;}</style>
</head>
<body>
<div class="legal">
  <img src="../assets/golden-logo.png" alt="Golden Automation Systems" style="width:220px;display:block;margin-bottom:10px;">
  <h1>Privacy Policy</h1>
  <p class="meta">Golden A/S Customer Portal (goldensas.com/customer) and the mobile applications <b>Golden A/S Service</b>, <b>Golden PM</b> and <b>Golden A/S Portal</b> (the "Apps"). Last updated <?= e($upd) ?>.</p>

  <h2>1. Who we are</h2>
  <p>Sistimata Ischyos ATEE, trading as Golden Automation Systems (Golden A/S), Keratsini, Piraeus, Greece — e-mail <a href="mailto:np@goldenas.com.gr">np@goldenas.com.gr</a> — is the controller of the personal data processed through the portal and the Apps. The portal and the Apps are business tools for our employees, our customers (vessel owners, managers, yards) and their representatives. They are not intended for the general public or for children under 16; an account is created only on invitation.</p>

  <h2>2. What we collect and why</h2>
  <table>
    <tr><th>Data</th><th>Purpose</th><th>Legal basis</th></tr>
    <tr><td>Name, business e-mail, company, role, language, profile photo (optional)</td><td>Your account, sign-in, showing who did what in the portal</td><td>Performance of the contract with your company / our legitimate interest in running the service</td></tr>
    <tr><td>Password (hashed), authenticator secret, passkey public keys, sign-in history (time, IP address, browser / device)</td><td>Security of your account, fraud and abuse prevention</td><td>Legitimate interest (security), legal obligations</td></tr>
    <tr><td>Device identifier, platform, app version, push token (if you allow notifications)</td><td>Running the Apps, delivering notifications about your projects, revoking a lost device</td><td>Legitimate interest / your consent for notifications</td></tr>
    <tr><td>Project data you enter or receive: tickets, reports, photos, drawings, signatures, comments, offers, approvals</td><td>Delivering our engineering services and documenting them</td><td>Performance of the contract</td></tr>
    <tr><td>Employees only: HR records (leave, timesheets, training, evaluations) and personal data kept by HR</td><td>Employment administration under Greek and Italian labour law</td><td>Employment contract, legal obligations</td></tr>
  </table>
  <p>Biometric data (Face ID, Touch ID, fingerprint) never leaves your device: the Apps and the portal use the device's own unlock and passkeys; we receive only a cryptographic public key and confirmation that you unlocked the device. We do not use advertising, tracking or analytics SDKs, and we do not sell personal data.</p>

  <h2>3. Photos, camera and files</h2>
  <p>The Apps ask for camera or photo access only when you attach a photo to a report, ticket or post, or read an identity document with the assistant; the picture is uploaded to the portal for that purpose and nothing else is read from your library.</p>

  <h2>4. Where the data lives and who sees it</h2>
  <p>Data is stored on our web hosting in the European Union / under EU-standard contractual clauses, behind a web application firewall, encrypted in transit (HTTPS). Access is limited to the people who need it for your projects: our staff with the corresponding role, and, for customer accounts, the representatives of the same company. Personal and payroll data of employees are visible only to the HR manager and the company owner. Optional assistant features send the text you type (never personal or payroll records) to an AI provider under a data-processing agreement, only when you press the assistant button.</p>

  <h2>5. Retention</h2>
  <p>Project records are kept for the duration of the project and the legal retention periods for engineering and commercial documents (up to 10 years). Sign-in history is kept for 12 months, notifications for 90 days after they are read, assistant logs for 180 days. Employee records follow the statutory periods of labour law. An account is deactivated when you leave the company or the project ends; data linked to history is kept for the periods above and then removed.</p>

  <h2>6. Your rights</h2>
  <p>Under the GDPR you may ask for access, correction, deletion, restriction or portability of your data and object to processing based on legitimate interest; employees see their own data on the portal and can correct it through HR. Write to <a href="mailto:np@goldenas.com.gr">np@goldenas.com.gr</a>; we answer within one month. You may also complain to the Hellenic Data Protection Authority (dpa.gr). To delete your account and the device data of the Apps, use "Sign out of every browser" and "Revoke device" in your account, or ask us by e-mail.</p>

  <h2>7. Changes</h2>
  <p>We update this page when the portal or the Apps change what they collect; the date at the top is the current version.</p>
  <p class="meta">v<?= e(CP_VERSION) ?> · <a href="index.php?p=login">Back to the portal</a></p>
</div>
</body>
</html>
