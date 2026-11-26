#!/usr/bin/env python3
"""Georgian (ka) language file of the portal — export of untranslated texts, merge of translations, build of customer/lang/ka.php.
   GAS-CP-R97-268.  Usage:  ka.py stat | export <out.tsv> [chars] | merge <in.tsv> | build
   The translation memory tools/i18n/ka_tm.json maps the English text to the Georgian one; ka.php holds every key of en.php
   (English where a text is not translated yet), so the five + one language files keep the same number of keys."""
import sys, json, re, subprocess, hashlib, os
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TM = os.path.join(ROOT, 'tools/i18n/ka_tm.json')
PRIO = ['btn', 'th', 'nav', 'sys', 'err', 'login', 'common', 'app', 'twofa', 'sec', 'pk', 'dash', 'nf', 'io', 'pl', 'wl', 'hr', 'soc', 'hre', 'jd', 'fsr', 'isr', 'jo', 'ts', 'tk', 'td', 'eq', 'hra', 'hrs', 'kb', 'cr', 'pf', 'wc']
def en(): return json.loads(subprocess.check_output(['php', '-r', 'echo json_encode(require "%s/customer/lang/en.php", JSON_UNESCAPED_UNICODE);' % ROOT]))
def tm(): return json.load(open(TM, encoding='utf-8')) if os.path.exists(TM) else {}
def hid(s): return hashlib.md5(s.encode('utf-8')).hexdigest()[:7]
def needs(s): return bool(re.search(r'[A-Za-z]{2}', re.sub(r'\{[^}]*\}|<[^>]*>|&[a-z]+;|https?://\S+', '', s)))
def todo(E, T):
    seen = {}; 
    for k, v in E.items():
        if v not in seen and v not in T and needs(v): seen[v] = k.split('.')[0]
    rank = {p: i for i, p in enumerate(PRIO)}
    return sorted(seen.items(), key=lambda kv: rank.get(kv[1], 999))   # stable: file order inside a rank
def sig(s): return (sorted(re.findall(r'\{[a-zA-Z0-9_]+\}', s)), sorted(re.findall(r'%[sd]', s)), re.findall(r'</?([a-zA-Z0-9]+)', s), s.count('\n'), sorted(re.findall(r'&[a-z]+;', s)))
cmd = sys.argv[1] if len(sys.argv) > 1 else 'stat'
E = en(); T = tm()
if cmd == 'stat':
    u = set(E.values()); n = [v for v in u if needs(v)]; d = [v for v in n if v in T]
    print('keys %d  unique %d  to translate %d  done %d (%.1f %%)  chars left %d' % (len(E), len(u), len(n), len(d), 100 * len(d) / max(1, len(n)), sum(len(v) for v in n if v not in T)))
elif cmd == 'export':
    lim = int(sys.argv[3]) if len(sys.argv) > 3 else 14000; out = []; c = 0
    for v, p in todo(E, T):
        if c + len(v) > lim and out: break
        out.append(hid(v) + '\t' + v.replace('\\', '\\\\').replace('\n', '\\n')); c += len(v)
    open(sys.argv[2], 'w', encoding='utf-8').write('\n'.join(out) + '\n'); print('%d texts, %d chars' % (len(out), c))
elif cmd == 'merge':
    by = {}
    for v in set(E.values()):
        h = hid(v); assert h not in by or by[h] == v, 'hash clash'; by[h] = v
    ok = 0; bad = []
    for ln in open(sys.argv[2], encoding='utf-8').read().split('\n'):
        if not ln.strip(): continue
        if '\t' not in ln: bad.append('no tab: ' + ln[:60]); continue
        h, g = ln.split('\t', 1); h = h.strip(); g = re.sub(r'\\n', '\n', g.replace('\\\\', '\x00')).replace('\x00', '\\')
        if h not in by: bad.append('unknown id ' + h); continue
        s = by[h]
        if sig(s) != sig(g): bad.append('%s placeholders/tags differ: %s | %s' % (h, s[:70], g[:70])); continue
        if s[:1].isspace() != g[:1].isspace() or s[-1:].isspace() != g[-1:].isspace(): bad.append(h + ' edge space'); continue
        T[s] = g; ok += 1
    json.dump(T, open(TM, 'w', encoding='utf-8'), ensure_ascii=False, indent=0, sort_keys=True); print('merged %d, refused %d' % (ok, len(bad)))
    for b in bad[:40]: print('  ' + b)
elif cmd == 'build':
    q = lambda s: s.replace('\\', '\\\\').replace("'", "\\'")
    L = ['<?php', '/** Georgian (ქართული) — built by tools/i18n/ka.py from tools/i18n/ka_tm.json; a text not translated yet is kept in English. Do not edit by hand: change the translation memory and build again.   GAS-CP-R97-268 */', 'return [']
    for k, v in E.items(): L.append("'%s' => '%s'," % (q(k), q(T.get(v, v))))
    L.append('];'); open(os.path.join(ROOT, 'customer/lang/ka.php'), 'w', encoding='utf-8').write('\n'.join(L) + '\n'); print('ka.php: %d keys' % len(E))
