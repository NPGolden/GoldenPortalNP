<?php
/**
 * Build install/manifest.json — sha256 + size of every shipped portal file,
 * relative to public_html. Run from the repo root before packaging:
 *   php tools/build-manifest.php
 * Settings › About compares the live server against this file.     GAS-CP-R13-001
 */
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/customer/app/version.php';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if (preg_match('#^(\.git|\.github|tools|docs|mobile-native|docs-R68|customer/storage|employee/storage|employee/uploads)/|/storage/|(^|/)config\.php$|install/manifest\.json$|^(DEPLOY\.md|\.gitignore)$|\.(log|bak|tmp|DS_Store)$#', $rel)) continue;   // R59-003: repository-only files (docs/, DEPLOY.md, .github) are not shipped
    if (!preg_match('/\.(php|js|css|sql|png|jpg|ico|xlsx|ttf|json|md|txt|htaccess)$/i', $rel) && !str_ends_with($rel, '.htaccess')) continue;
    $files[$rel] = ['sha256' => hash_file('sha256', $f->getPathname()), 'size' => $f->getSize()];
}
ksort($files);
$out = ['name' => CP_NAME, 'version' => CP_VERSION, 'build' => CP_BUILD, 'generated_at' => gmdate('c'), 'count' => count($files), 'files' => $files];
file_put_contents($root . '/customer/install/manifest.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($root . '/VERSION', CP_VERSION . ' (' . CP_BUILD . ")\n");
// CHANGELOG.md from the same patch list
$md = "# " . CP_NAME . " — change log\n\nCurrent version **" . CP_VERSION . "** (build " . CP_BUILD . "). Settings › About / Versions on the live server shows the same table with the database-step status and a file-integrity check.\n\n| Version | Doc ref | Date | What changed | Database step |\n|---|---|---|---|---|\n";
foreach (cp_patches() as $pt) $md .= sprintf("| %s | %s | %s | %s | %s |\n", $pt['version'], $pt['ref'], $pt['date'], $pt['title'], $pt['db'] ? '`' . $pt['db'] . '`' : '—');
file_put_contents($root . '/CHANGELOG.md', $md);
echo "manifest: ", count($files), " files, version ", CP_VERSION, "\n";
