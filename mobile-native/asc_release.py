#!/usr/bin/env python3
"""App Store Connect — prepare (and optionally submit) a release for one of the Golden A/S apps.
Reads store/<app>/metadata.json and store/<app>/screenshots/*.png; env ASC_KEY_ID, ASC_ISSUER_ID, ASC_KEY_P8 (path),
DEMO_USER / DEMO_PASS (reviewer account, never printed). Idempotent: re-running updates what is there.
Steps: app → appInfo localisation (name, subtitle, privacy URL) + categories + age rating → version (create or reuse the
editable one, releaseType MANUAL) → version localisation (description, keywords, promo, URLs, what's new when allowed)
→ screenshots (APP_IPHONE_67 set replaced) → latest VALID build attached → review details → [submit].
Not covered by the public API: App Privacy questionnaire, pricing/availability, content rights — set once in the UI."""
import hashlib, json, os, sys, time, glob
import jwt, requests

B = "https://api.appstoreconnect.apple.com/v1"
APP = sys.argv[1]; SUBMIT = "--submit" in sys.argv
META = json.load(open(f"store/{APP}/metadata.json")); LOC = META["locale"]
_tok = None; _tok_at = 0
def tok():
    global _tok, _tok_at
    if not _tok or time.time() - _tok_at > 900:
        _tok = jwt.encode({"iss": os.environ["ASC_ISSUER_ID"], "iat": int(time.time()), "exp": int(time.time()) + 1100, "aud": "appstoreconnect-v1"},
                          open(os.environ["ASC_KEY_P8"]).read(), algorithm="ES256", headers={"kid": os.environ["ASC_KEY_ID"]}); _tok_at = time.time()
    return _tok
def req(m, path, **kw):
    r = requests.request(m, path if path.startswith("http") else B + path, headers={"Authorization": "Bearer " + tok(), "Content-Type": "application/json"}, timeout=60, **kw)
    if r.status_code >= 400:
        body = r.text
        for s in [os.environ.get("DEMO_PASS", ""), os.environ.get("DEMO_USER", "")]:
            if s: body = body.replace(s, "***")
        raise SystemExit(f"{m} {path} → {r.status_code}: {body[:900]}")
    return r.json() if r.text else {}
def rel(t, i): return {"data": {"type": t, "id": i}}
def log(*a): print("•", *a, flush=True)

# ---- app ----
apps = req("GET", "/apps", params={"filter[bundleId]": META["bundleId"]})["data"]
if not apps: raise SystemExit("no app record for " + META["bundleId"])
app = apps[0]; aid = app["id"]; log("app", app["attributes"]["name"], aid)

# ---- appInfo: localisation, categories, age rating ----
infos = req("GET", f"/apps/{aid}/appInfos", params={"include": "primaryCategory"})["data"]
info = next((i for i in infos if i["attributes"].get("appStoreState") not in ("READY_FOR_SALE", "REPLACED_WITH_NEW_INFO")), infos[0])
locs = req("GET", f"/appInfos/{info['id']}/appInfoLocalizations")["data"]
loc = next((l for l in locs if l["attributes"]["locale"] == LOC), None)
attrs = {"name": META["name"], "subtitle": META["subtitle"], "privacyPolicyUrl": META["privacyPolicyUrl"]}
if loc: req("PATCH", f"/appInfoLocalizations/{loc['id']}", json={"data": {"type": "appInfoLocalizations", "id": loc["id"], "attributes": attrs}})
else: req("POST", "/appInfoLocalizations", json={"data": {"type": "appInfoLocalizations", "attributes": {**attrs, "locale": LOC}, "relationships": {"appInfo": rel("appInfos", info["id"])}}})
log("app info localisation", LOC, "ok")
try:
    req("PATCH", f"/appInfos/{info['id']}", json={"data": {"type": "appInfos", "id": info["id"], "relationships": {"primaryCategory": rel("appCategories", META["primaryCategory"]), "secondaryCategory": rel("appCategories", META["secondaryCategory"])}}})
    log("categories", META["primaryCategory"], META["secondaryCategory"])
except SystemExit as e: log("categories not changed:", " ".join(str(e).split())[:400])
try:
    ar = req("GET", f"/appInfos/{info['id']}/ageRatingDeclaration")["data"]
    cur = ar["attributes"]; bools = {"gambling", "unrestrictedWebAccess", "lootBox", "advertising", "parentalControls", "ageAssurance", "healthOrWellnessTopics", "messagingAndChat", "userGeneratedContent"}
    fill = {}
    for k, v in cur.items():
        if k in ("kidsAgeBand", "ageRatingOverride", "koreaAgeRatingOverride") or k.endswith("Url") or k.endswith("Uri"): continue   # optional / URL-typed
        if v is None: fill[k] = False if k in bools else "NONE"
    def patch(f): req("PATCH", f"/ageRatingDeclarations/{ar['id']}", json={"data": {"type": "ageRatingDeclarations", "id": ar["id"], "attributes": f}})
    if fill:
        try: patch(fill)
        except SystemExit as e:          # Apple adds questions over time — learn their type from the error and retry once
            import re
            for k in re.findall(r"attribute '([A-Za-z]+)'\. Expected a BOOLEAN", str(e)): fill[k] = False
            for k in re.findall(r"attribute '([A-Za-z]+)'\. Expected a STRING|Expected an ENUM", str(e)): fill[k] = "NONE"
            patch(fill)
        log("age rating: answered", len(fill), "open questions with none/no →", sorted(fill))
    else: log("age rating already complete")
except SystemExit as e: log("age rating not changed (answer the questionnaire in App Information):", " ".join(str(e).split())[:500])

# ---- version ----
version_string = open("APP_VERSION").read().strip() if os.path.exists("APP_VERSION") else None
vers = req("GET", f"/apps/{aid}/appStoreVersions", params={"filter[platform]": "IOS", "limit": 10})["data"]
editable = [v for v in vers if v["attributes"]["appStoreState"] in ("PREPARE_FOR_SUBMISSION", "DEVELOPER_REJECTED", "REJECTED", "METADATA_REJECTED", "INVALID_BINARY", "WAITING_FOR_REVIEW")]
live = [v for v in vers if v["attributes"]["appStoreState"] in ("READY_FOR_SALE", "READY_FOR_DISTRIBUTION", "PENDING_DEVELOPER_RELEASE", "PROCESSING_FOR_DISTRIBUTION")]
# latest valid build decides the version string when APP_VERSION is absent
builds = req("GET", "/builds", params={"filter[app]": aid, "filter[processingState]": "VALID", "sort": "-uploadedDate", "limit": 1})
build = builds["data"][0] if builds["data"] else None
pre = req("GET", f"/builds/{build['id']}/preReleaseVersion")["data"] if build else None
if pre: version_string = pre["attributes"]["version"]          # the store version must carry the build's marketing version
if not version_string: version_string = editable[0]["attributes"]["versionString"] if editable else "1.0.0"
if editable:
    ver = editable[0]
    if ver["attributes"]["versionString"] != version_string and ver["attributes"]["appStoreState"] in ("PREPARE_FOR_SUBMISSION", "DEVELOPER_REJECTED", "REJECTED", "METADATA_REJECTED", "INVALID_BINARY"):
        req("PATCH", f"/appStoreVersions/{ver['id']}", json={"data": {"type": "appStoreVersions", "id": ver["id"], "attributes": {"versionString": version_string}}})
    req("PATCH", f"/appStoreVersions/{ver['id']}", json={"data": {"type": "appStoreVersions", "id": ver["id"], "attributes": {"releaseType": META.get("releaseType", "MANUAL")}}})
else:
    ver = req("POST", "/appStoreVersions", json={"data": {"type": "appStoreVersions", "attributes": {"platform": "IOS", "versionString": version_string, "releaseType": META.get("releaseType", "MANUAL")}, "relationships": {"app": rel("apps", aid)}}})["data"]
vid = ver["id"]; log("version", version_string, ver["attributes"]["appStoreState"], vid)

# ---- version localisation ----
vlocs = req("GET", f"/appStoreVersions/{vid}/appStoreVersionLocalizations")["data"]
vloc = next((l for l in vlocs if l["attributes"]["locale"] == LOC), None)
vattrs = {"description": META["description"], "keywords": META["keywords"], "promotionalText": META["promotionalText"], "supportUrl": META["supportUrl"], "marketingUrl": META.get("marketingUrl")}
if live: vattrs["whatsNew"] = META["whatsNew"]          # Apple refuses "What's New" on the very first version
if vloc: req("PATCH", f"/appStoreVersionLocalizations/{vloc['id']}", json={"data": {"type": "appStoreVersionLocalizations", "id": vloc["id"], "attributes": vattrs}})
else: vloc = req("POST", "/appStoreVersionLocalizations", json={"data": {"type": "appStoreVersionLocalizations", "attributes": {**vattrs, "locale": LOC}, "relationships": {"appStoreVersion": rel("appStoreVersions", vid)}}})["data"]
log("version localisation", LOC, "ok", "(what's new skipped: first release)" if not live else "")

# ---- screenshots: replace the 6.7"/6.9" iPhone set ----
files = sorted(glob.glob(f"store/{APP}/screenshots/*.png"))
if files:
    sets = req("GET", f"/appStoreVersionLocalizations/{vloc['id']}/appScreenshotSets", params={"include": "appScreenshots"})
    sset = next((s for s in sets["data"] if s["attributes"]["screenshotDisplayType"] == "APP_IPHONE_67"), None)
    if sset:
        for sc in sets.get("included", []):
            if sc["type"] == "appScreenshots": req("DELETE", f"/appScreenshots/{sc['id']}")
    else:
        sset = req("POST", "/appScreenshotSets", json={"data": {"type": "appScreenshotSets", "attributes": {"screenshotDisplayType": "APP_IPHONE_67"}, "relationships": {"appStoreVersionLocalization": rel("appStoreVersionLocalizations", vloc["id"])}}})["data"]
    ids = []
    for f in files:
        data = open(f, "rb").read()
        sc = req("POST", "/appScreenshots", json={"data": {"type": "appScreenshots", "attributes": {"fileName": os.path.basename(f), "fileSize": len(data)}, "relationships": {"appScreenshotSet": rel("appScreenshotSets", sset["id"])}}})["data"]
        for op in sc["attributes"]["uploadOperations"]:
            h = {x["name"]: x["value"] for x in op["requestHeaders"]}
            r = requests.request(op["method"], op["url"], headers=h, data=data[op["offset"]:op["offset"] + op["length"]], timeout=120); r.raise_for_status()
        req("PATCH", f"/appScreenshots/{sc['id']}", json={"data": {"type": "appScreenshots", "id": sc["id"], "attributes": {"uploaded": True, "sourceFileChecksum": hashlib.md5(data).hexdigest()}}})
        ids.append(sc["id"]); log("screenshot", os.path.basename(f), f"{len(data)//1024} KB")
    req("PATCH", f"/appScreenshotSets/{sset['id']}/relationships/appScreenshots", json={"data": [{"type": "appScreenshots", "id": i} for i in ids]})

# ---- build ----
if build:
    req("PATCH", f"/appStoreVersions/{vid}/relationships/build", json=rel("builds", build["id"]))
    log("build attached", build["attributes"]["version"], build["attributes"]["uploadedDate"])
else: log("WARNING: no VALID build — upload one with Build iOS app first")

# ---- review details ----
rd = None
try: rd = req("GET", f"/appStoreVersions/{vid}/appStoreReviewDetail")["data"]
except SystemExit: pass
c = META["contact"]
rattrs = {"contactFirstName": c["firstName"], "contactLastName": c["lastName"], "contactPhone": c["phone"], "contactEmail": c["email"], "notes": META["reviewNotes"],
          "demoAccountRequired": bool(os.environ.get("DEMO_USER")), "demoAccountName": os.environ.get("DEMO_USER", ""), "demoAccountPassword": os.environ.get("DEMO_PASS", "")}
if rd: req("PATCH", f"/appStoreReviewDetails/{rd['id']}", json={"data": {"type": "appStoreReviewDetails", "id": rd["id"], "attributes": rattrs}})
else: req("POST", "/appStoreReviewDetails", json={"data": {"type": "appStoreReviewDetails", "attributes": rattrs, "relationships": {"appStoreVersion": rel("appStoreVersions", vid)}}})
log("review details ok; demo account", "set" if os.environ.get("DEMO_USER") else "MISSING — set secrets ASC_DEMO_USER_*/ASC_DEMO_PASS_*")

# ---- submit ----
if SUBMIT:
    if not build: raise SystemExit("cannot submit without a build")
    if not os.environ.get("DEMO_USER"): raise SystemExit("cannot submit without the demo account")
    rs = req("POST", "/reviewSubmissions", json={"data": {"type": "reviewSubmissions", "attributes": {"platform": "IOS"}, "relationships": {"app": rel("apps", aid)}}})["data"]
    req("POST", "/reviewSubmissionItems", json={"data": {"type": "reviewSubmissionItems", "relationships": {"reviewSubmission": rel("reviewSubmissions", rs["id"]), "appStoreVersion": rel("appStoreVersions", vid)}}})
    req("PATCH", f"/reviewSubmissions/{rs['id']}", json={"data": {"type": "reviewSubmissions", "id": rs["id"], "attributes": {"submitted": True}}})
    log("SUBMITTED for review", rs["id"])
else:
    log("prepared — not submitted. Remaining in the UI: App Privacy questionnaire, Pricing (Free), Content rights; then run again with submit=true or press Submit in App Store Connect.")
