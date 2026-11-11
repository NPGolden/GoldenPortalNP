<?php /* goldensas.com — portal entry. GAS-APP-PORTAL-001 · R97-010: the Employee Portal door was removed — procurement, warehouse and dispatch are part of the portal
   R97-012: never cached (browser, firewall cache) — a changed entry page must show at once; the stylesheet carries its file time for the same reason. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); header('Expires: 0');
$cssV = (int) @filemtime(__DIR__ . '/assets/portal.css'); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Golden Automation Systems — Portal</title>
<link rel="icon" href="assets/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="assets/favicon.png">
<link rel="stylesheet" href="assets/portal.css?v=<?= $cssV ?>">
</head>
<body>
<div class="land">
  <span class="sea" aria-hidden="true"></span>
  <img class="logo" src="assets/golden-logo-white.png" alt="Golden Automation Systems">
  <div>
    <h1>Marine electrical &amp; automation, in one place</h1>
    <p>Follow project progress, sign documents and reach support — customers and Golden A/S staff sign in to the same portal.</p>
  </div>
  <div class="doors doors--one">
    <a class="door" href="customer/">
      <span class="badge">G</span>
      <b>Golden A/S Portal</b>
      <span>Projects, documents, service &amp; procurement · Πρόοδος έργων · Stato progetti</span>
    </a>
  </div>
  <a class="land-banner" href="https://www.goldenas.com.gr/" target="_blank" rel="noopener">
    <span class="lb-ico" aria-hidden="true">🌐</span>
    <span class="lb-txt"><b>Visit our website</b><span>Company, services and careers · www.goldenas.com.gr</span></span>
    <span class="lb-go" aria-hidden="true">→</span>
  </a>
</div>
</body>
</html>
