/* Writes the two association files the relying party goldensas.com must serve for native passkeys:  node wellknown.js <APPLE_TEAM_ID> <ANDROID_SHA256[,SHA256…]>
 * → well-known/apple-app-site-association  (webcredentials for the three bundle ids)
 * → well-known/assetlinks.json              (get_login_creds for the three package names, every signing certificate listed)
 * Upload both to public_html/.well-known/ (no extension, served as application/json — see README). The same SHA-256 fingerprints go to
 * Settings › Login history › Session policy › "Android app signing SHA-256" so the portal accepts the Android app origin.   GAS-CP-R71-003 */
const fs = require("fs"), path = require("path");
const [team, shas] = process.argv.slice(2); if (!team || !shas) { console.error("usage: node wellknown.js <APPLE_TEAM_ID> <ANDROID_SHA256[,SHA256...]>"); process.exit(1); }
const apps = JSON.parse(fs.readFileSync(path.join(__dirname, "apps.json"), "utf8")); const ids = Object.values(apps).map((a) => a.appId);
const fps = shas.split(",").map((s) => s.trim().toUpperCase().replace(/[^0-9A-F]/g, "").replace(/(..)(?=.)/g, "$1:")).filter((s) => s.length === 95);
if (!fps.length) { console.error("no valid SHA-256 fingerprint (64 hex digits, colons optional)"); process.exit(1); }
const out = path.join(__dirname, "well-known"); fs.mkdirSync(out, { recursive: true });
fs.writeFileSync(path.join(out, "apple-app-site-association"), JSON.stringify({ webcredentials: { apps: ids.map((id) => `${team}.${id}`) } }, null, 2));
fs.writeFileSync(path.join(out, "assetlinks.json"), JSON.stringify(ids.map((id) => ({ relation: ["delegate_permission/common.get_login_creds", "delegate_permission/common.handle_all_urls"], target: { namespace: "android_app", package_name: id, sha256_cert_fingerprints: fps } })), null, 2));
console.log("written:", fs.readdirSync(out).join(", "), "→ upload to https://goldensas.com/.well-known/");
