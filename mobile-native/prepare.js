/* Prepares one app for Capacitor:  node prepare.js <engineer|pm|customer>
 * - writes capacitor.config.json (appId, appName, server.url = the live client, so screens follow the portal releases)
 * - copies the client folder to www/ as the offline fallback shell (API base absolute, mock/tests/sw removed, native bridge injected)
 * - copies the 1024 px icon to assets/ for @capacitor/assets (icons + splash for both platforms)
 * - prints the version name (the client's own version) for the workflow */
const fs = require("fs"), path = require("path");
const key = process.argv[2]; const apps = JSON.parse(fs.readFileSync(path.join(__dirname, "apps.json"), "utf8")); const a = apps[key];
if (!a) { console.error("usage: node prepare.js <" + Object.keys(apps).join("|") + ">"); process.exit(1); }
const root = path.join(__dirname, ".."), src = path.join(root, a.client), www = path.join(__dirname, "www");
const config = { appId: a.appId, appName: a.appName, webDir: "www",
  server: { url: a.url, cleartext: false, allowNavigation: ["goldensas.com"] },
  ios: { contentInset: "always", limitsNavigationsToAppBoundDomains: true, scheme: a.appName },
  android: { allowMixedContent: false, captureInput: true, webContentsDebuggingEnabled: false },
  plugins: { SplashScreen: { launchShowDuration: 800, backgroundColor: a.splash, showSpinner: false, launchAutoHide: true }, PushNotifications: { presentationOptions: ["badge", "sound", "alert"] },
             CapacitorPasskey: { origin: "https://goldensas.com", domains: ["goldensas.com"], autoShim: true } } };   // R71-003 native passkeys: same relying party as the web portal
fs.writeFileSync(path.join(__dirname, "capacitor.config.json"), JSON.stringify(config, null, 2));
fs.rmSync(www, { recursive: true, force: true }); fs.cpSync(src, www, { recursive: true });
for (const d of ["mock", "tests", "sw.js", ".htaccess"]) fs.rmSync(path.join(www, d), { recursive: true, force: true });
let html = fs.readFileSync(path.join(www, "index.html"), "utf8");
html = html.replace('<meta name="gas-api" content="../api/v1">', '<meta name="gas-api" content="https://goldensas.com/customer/api/v1">').replace(/<script src="mock\/data\.js"><\/script>\s*/g, "");
html = html.replace("</body>", '<script src="native-bridge.js"></script>\n</body>');
fs.writeFileSync(path.join(www, "index.html"), html);
fs.copyFileSync(path.join(__dirname, "native-bridge.js"), path.join(www, "native-bridge.js"));
fs.mkdirSync(path.join(__dirname, "assets"), { recursive: true });
const icon = ["icon-1024.png", "icon-512.png"].map((f) => path.join(src, "icons", f)).find(fs.existsSync);
fs.copyFileSync(icon, path.join(__dirname, "assets", "icon-only.png")); fs.copyFileSync(icon, path.join(__dirname, "assets", "icon-foreground.png"));
// splash = icon centred on the brand colour (2732 × 2732, Capacitor's recommended size)
(async () => { try { const sharp = require("sharp"); const logo = await sharp(icon).resize(760, 760).png().toBuffer();
  for (const name of ["splash.png", "splash-dark.png"]) await sharp({ create: { width: 2732, height: 2732, channels: 4, background: a.splash } }).composite([{ input: logo, gravity: "centre" }]).png().toFile(path.join(__dirname, "assets", name));
  console.log("splash: icon centred on", a.splash); } catch (e) { fs.writeFileSync(path.join(__dirname, "assets", "splash.svg"), `<svg xmlns="http://www.w3.org/2000/svg" width="2732" height="2732"><rect width="2732" height="2732" fill="${a.splash}"/></svg>`); console.log("splash: plain colour (" + e.message + ")"); } })();
const vf = fs.readFileSync(path.join(src, a.pwaVersionFile), "utf8"); const m = vf.match(/VERSION\s*=\s*["']([0-9.]+)["']/) || vf.match(/version["']?\s*[:=]\s*["']([0-9.]+)["']/i);
const version = m ? m[1] : "1.0.0";
fs.writeFileSync(path.join(__dirname, "APP_VERSION"), version);
console.log(`${key}: ${a.appId} "${a.appName}" version ${version} → www/ (${fs.readdirSync(www).length} entries), url ${a.url}`);
