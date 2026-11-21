#!/usr/bin/env python3
"""Apple Developer — register the bundle id of one Golden A/S app and its App Store provisioning profile.      GAS-CP-R97-181
Reads apps.json; env ASC_KEY_ID, ASC_ISSUER_ID, ASC_KEY_P8 (path). Idempotent. Capabilities are copied from the bundle id
of a reference app (default hr), so the new app gets the same set (push notifications, associated domains …).
Prints names and ids only — never key material."""
import json, os, sys, time
import jwt, requests

B = "https://api.appstoreconnect.apple.com/v1"
APP = sys.argv[1]; REF = sys.argv[2] if len(sys.argv) > 2 else "hr"
APPS = json.load(open("apps.json")); BID = APPS[APP]["appId"]; NAME = APPS[APP]["appName"]; PROFILE = sys.argv[3] if len(sys.argv) > 3 else "Golden %s AppStore" % APP.capitalize()
def tok(): return jwt.encode({"iss": os.environ["ASC_ISSUER_ID"], "iat": int(time.time()), "exp": int(time.time()) + 900, "aud": "appstoreconnect-v1"}, open(os.environ["ASC_KEY_P8"]).read(), algorithm="ES256", headers={"kid": os.environ["ASC_KEY_ID"]})
def req(m, path, ok=(), **kw):
    r = requests.request(m, B + path, headers={"Authorization": "Bearer " + tok(), "Content-Type": "application/json"}, timeout=60, **kw)
    if r.status_code >= 400 and r.status_code not in ok: raise SystemExit(f"{m} {path} → {r.status_code}: {r.text[:700]}")
    return r.json() if r.text else {}
def log(*a): print("•", *a, flush=True)
def bundle(ident):
    d = req("GET", "/bundleIds", params={"filter[identifier]": ident, "limit": 200})["data"]
    return next((b for b in d if b["attributes"]["identifier"] == ident), None)

b = bundle(BID)
if not b:
    b = req("POST", "/bundleIds", json={"data": {"type": "bundleIds", "attributes": {"identifier": BID, "name": NAME.replace("/", " "), "platform": "IOS"}}})["data"]; log("bundle id registered", BID)
else: log("bundle id exists", BID)
ref = bundle(APPS[REF]["appId"]); added = 0
if ref:
    have = {c["attributes"]["capabilityType"] for c in req("GET", f"/bundleIds/{b['id']}/bundleIdCapabilities")["data"]}
    for c in req("GET", f"/bundleIds/{ref['id']}/bundleIdCapabilities")["data"]:
        t = c["attributes"]["capabilityType"]
        if t in have or t in ("IN_APP_PURCHASE", "GAME_CENTER"): continue
        r = req("POST", "/bundleIdCapabilities", ok=(400, 409), json={"data": {"type": "bundleIdCapabilities", "attributes": {"capabilityType": t}, "relationships": {"bundleId": {"data": {"type": "bundleIds", "id": b["id"]}}}}})   # no settings: the API refuses the ones it returns itself
        if "data" in r: added += 1
        log("capability", t, "added" if "data" in r else "not added: " + json.dumps(r)[:200])
profs = req("GET", "/profiles", params={"filter[name]": PROFILE, "limit": 200})["data"]
p = next((x for x in profs if x["attributes"]["name"] == PROFILE), None)
if p and (added or p["attributes"]["profileState"] != "ACTIVE"): req("DELETE", f"/profiles/{p['id']}"); log("profile removed — it was made before the capabilities changed"); p = None
if p: log("profile exists", PROFILE, "expires", p["attributes"]["expirationDate"][:10])
else:
    certs = [c for c in req("GET", "/certificates", params={"limit": 200})["data"] if c["attributes"]["certificateType"] in ("DISTRIBUTION", "IOS_DISTRIBUTION")]
    if not certs: raise SystemExit("no distribution certificate in the account")
    p = req("POST", "/profiles", json={"data": {"type": "profiles", "attributes": {"name": PROFILE, "profileType": "IOS_APP_STORE"}, "relationships": {"bundleId": {"data": {"type": "bundleIds", "id": b["id"]}}, "certificates": {"data": [{"type": "certificates", "id": c["id"]} for c in certs]}}}})["data"]
    log("profile created", PROFILE, "with", len(certs), "certificate(s), expires", p["attributes"]["expirationDate"][:10])
a = req("GET", "/apps", params={"filter[bundleId]": BID})["data"]
log("app record:", (a[0]["attributes"]["name"] + " " + a[0]["id"]) if a else "none yet — create it in App Store Connect (New App) with bundle id " + BID)
