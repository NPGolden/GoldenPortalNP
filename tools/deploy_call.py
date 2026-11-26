#!/usr/bin/env python3
"""Deploy helper (GAS-CP-R97-263): asks the release updater on the server to verify (dry) or apply a release zip.

  deploy_call.py dry|apply      env: URL (…/customer), TOKEN, Z (zip name), ORIGIN (optional hosting IP), OUT (file for the JSON report)

1. If ORIGIN is given, the call goes straight to the hosting server with the update key in a header, as before.
2. If the server cannot be reached directly, the call goes through the website firewall. The key is NOT sent: the request carries a
   signature  v1.<time>.<HMAC-SHA256(key, "v1\\n<time>\\n<zip>\\n<mode>")>  that is valid for five minutes and for one use.
   When the firewall cuts a long apply, the helper asks the updater for its status until the run has finished and reads its report.
Nothing secret is printed.
"""
import base64, hashlib, hmac, json, os, re, subprocess, sys, time

mode = sys.argv[1] if len(sys.argv) > 1 else 'dry'
URL = os.environ['URL'].rstrip('/'); TOKEN = os.environ['TOKEN']; Z = os.environ.get('Z', ''); OUT = os.environ.get('OUT', '/tmp/update.json')
ORIGIN = re.sub(r'^[a-z]+://', '', os.environ.get('ORIGIN', '').strip()).split('/')[0].split(':')[0]
host = re.sub(r'^https?://([^/]+).*', r'\1', URL); endpoint = URL + '/install/update.php'
_last_ts = [0]

def curl(args, max_time):
    p = subprocess.run(['curl', '-sS', '-o', OUT, '-w', '%{http_code}', '--max-time', str(max_time), '-X', 'POST', '-A', 'GAS-deploy/1.0'] + args + [endpoint], capture_output=True, text=True)
    code = (p.stdout or '000').strip()[-3:]
    try: body = json.load(open(OUT))
    except Exception: body = None
    return code, body, (p.stderr or '').strip().splitlines()[-1:] or ['']

def fields(m):
    f = ['-d', 'zip=' + Z]
    if m == 'dry': f += ['-d', 'dry=1']
    if m == 'status': f += ['-d', 'status=1']
    return f

def signed(m, max_time):
    ts = max(int(time.time()), _last_ts[0] + 1); wait = ts - time.time()
    if wait > 0: time.sleep(wait)
    _last_ts[0] = ts
    mac = hmac.new(TOKEN.encode(), f"v1\n{ts}\n{Z}\n{m}".encode(), hashlib.sha256).digest()
    sig = 'v1.%d.%s' % (ts, base64.urlsafe_b64encode(mac).decode().rstrip('='))
    return curl(['-e', URL + '/', '-H', 'X-Update-Sig: ' + sig] + fields(m), max_time)

def finish(body, via):
    print('route:', via)
    json.dump(body, open(OUT, 'w'), indent=1); print(json.dumps(body, indent=1)[:6000])
    sys.exit(0 if body.get('ok') else 1)

if mode == 'status':   # which version is on the server, is an update running — always through the website firewall, signed
    code, st, err = signed('status', 40)
    if st is None: print(f'no answer (http {code}; {err[0][:120]})'); sys.exit(1)
    print(json.dumps({k: st.get(k) for k in ('ok', 'status', 'running', 'version', 'error')})); sys.exit(0 if st.get('status') else 1)

if ORIGIN:
    ip = ORIGIN
    if not re.fullmatch(r'[0-9.]+', ip):
        import socket
        try: ip = socket.gethostbyname(ip)
        except Exception: ip = ''
    if ip:
        code, body, err = curl(['--connect-timeout', '8', '--resolve', f'{host}:443:{ip}', '-H', 'X-Update-Token: ' + TOKEN] + fields(mode), 900)
        if body is not None and code != '000': finish(body, 'direct to the hosting server')
        print(f'direct call to the hosting server failed (http {code}; {err[0][:120]}) — going through the website firewall')

code, body, err = signed(mode, 110)
if body is not None and 'steps' in body: finish(body, 'through the website firewall, signed')
if body is not None and not body.get('ok') and code in ('403', '404', '400', '409', '405', '500') and 'error' in body: finish(body, 'through the website firewall, signed')
print(f'no report from the {mode} call (http {code}; {err[0][:120]}) — asking the updater for its status')
deadline = time.time() + (600 if mode == 'apply' else 90); seen_running = False
while time.time() < deadline:
    time.sleep(8)
    code, st, err = signed('status', 40)
    if not st or not st.get('status'): print('  status: no answer (http %s)' % code); continue
    last = st.get('last') or {}; lastzip = next((s.get('zip') for s in last.get('steps', []) if s.get('step') == 'verify'), None)
    print('  status: running=%s version=%s last zip=%s' % (st.get('running'), st.get('version'), lastzip))
    if st.get('running'): seen_running = True; continue
    if mode == 'apply' and lastzip == Z: finish(last, 'through the website firewall, signed (report read after the run)')
    if mode == 'dry' or not seen_running:
        code, body, err = signed(mode, 110)
        if body is not None and ('steps' in body or 'error' in body): finish(body, 'through the website firewall, signed (second call)')
print('the updater gave no report in time'); sys.exit(1)
