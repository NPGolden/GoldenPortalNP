#!/usr/bin/env python3
"""App Store — release the version of one Golden A/S app that Apple approved and that waits for us ("Pending Developer Release").   GAS-CP-R97-208
Reads apps.json; env ASC_KEY_ID, ASC_ISSUER_ID, ASC_KEY_P8 (path). Does nothing when no version is in that state. Prints names and states only."""
import json, os, sys, time
import jwt, requests

B = "https://api.appstoreconnect.apple.com/v1"
APP = sys.argv[1]; APPS = json.load(open("apps.json")); BID = APPS[APP]["appId"]
def tok(): return jwt.encode({"iss": os.environ["ASC_ISSUER_ID"], "iat": int(time.time()), "exp": int(time.time()) + 900, "aud": "appstoreconnect-v1"}, open(os.environ["ASC_KEY_P8"]).read(), algorithm="ES256", headers={"kid": os.environ["ASC_KEY_ID"]})
def req(m, path, **kw):
    r = requests.request(m, B + path, headers={"Authorization": "Bearer " + tok(), "Content-Type": "application/json"}, timeout=60, **kw)
    if r.status_code >= 400: raise SystemExit(f"{m} {path} → {r.status_code}: {r.text[:700]}")
    return r.json() if r.text else {}
def log(*a): print("•", *a, flush=True)

apps = [a for a in req("GET", "/apps", params={"filter[bundleId]": BID, "limit": 10})["data"] if a["attributes"]["bundleId"] == BID]
if not apps: raise SystemExit("no app record for " + BID)
a = apps[0]; log("app", a["attributes"]["name"], a["id"])
vers = req("GET", f"/apps/{a['id']}/appStoreVersions", params={"filter[platform]": "IOS", "limit": 10})["data"]
for v in vers: log("version", v["attributes"]["versionString"], v["attributes"]["appStoreState"])
wait = [v for v in vers if v["attributes"]["appStoreState"] == "PENDING_DEVELOPER_RELEASE"]
if not wait: log("nothing waits for release"); sys.exit(0)
v = wait[0]
req("POST", "/appStoreVersionReleaseRequests", json={"data": {"type": "appStoreVersionReleaseRequests", "relationships": {"appStoreVersion": {"data": {"type": "appStoreVersions", "id": v["id"]}}}}})
log("release requested for", v["attributes"]["versionString"])
time.sleep(8); log("state now", req("GET", f"/appStoreVersions/{v['id']}")["data"]["attributes"]["appStoreState"])
