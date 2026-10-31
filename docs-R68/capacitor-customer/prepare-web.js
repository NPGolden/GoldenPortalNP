// Copies ../../customer/mobile-customer → ./www for Capacitor: API base fixed to the live module, mock data and service worker removed, native bridge injected.
const fs = require("fs"), path = require("path");
const src = path.join(__dirname, "..", "..", "customer", "mobile-customer"), out = path.join(__dirname, "www");
fs.rmSync(out, { recursive: true, force: true }); fs.cpSync(src, out, { recursive: true });
let html = fs.readFileSync(path.join(out, "index.html"), "utf8");
html = html.replace('<meta name="gas-api" content="../api/v1">', '<meta name="gas-api" content="https://goldensas.com/customer/api/v1">').replace('<script src="mock/data.js"></script>\n', "").replace('<script src="lib/native.js"></script>', '<script src="lib/native.js"></script>\n<script type="module" src="native-bridge.js"></script>');
html = html.replace("connect-src 'self' https://cdnjs.cloudflare.com", "connect-src 'self' https://goldensas.com https://cdnjs.cloudflare.com capacitor://localhost").replace("img-src 'self' data: blob:", "img-src 'self' data: blob: https://goldensas.com");
fs.writeFileSync(path.join(out, "index.html"), html);
fs.rmSync(path.join(out, "mock"), { recursive: true, force: true }); fs.rmSync(path.join(out, "tests"), { recursive: true, force: true }); fs.rmSync(path.join(out, "sw.js"), { force: true });
fs.copyFileSync(path.join(__dirname, "native-bridge.js"), path.join(out, "native-bridge.js"));
console.log("www prepared — bundle: npx esbuild www/native-bridge.js --bundle --format=esm --outfile=www/native-bridge.js --allow-overwrite ; then npx cap sync");
