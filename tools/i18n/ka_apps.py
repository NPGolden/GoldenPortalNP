#!/usr/bin/env python3
"""Georgian for the five app shells (GAS-CP-R97-277).
  stat | export <out.tsv> [chars] | merge <in.tsv> | build
English texts of the apps are looked up first in the portal memory (ka_tm.json), then in ka_apps_tm.json.
build writes a `ka` block into each i18n.js (replacing an earlier one) and adds Georgian to LANGS. The blocks are generated, never edited by hand."""
import json, re, sys, subprocess, hashlib, os
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APPS = ['customer/mobile/js/i18n.js', 'customer/mobile-pm/js/i18n.js', 'customer/mobile-hr/js/i18n.js', 'customer/mobile-employee/js/i18n.js', 'customer/mobile-customer/i18n/i18n.js']
TM = os.path.join(ROOT, 'tools/i18n/ka_tm.json'); ATM = os.path.join(ROOT, 'tools/i18n/ka_apps_tm.json')
NODE = r"""
const fs=require('fs'),vm=require('vm');const out={};
for(const f of JSON.parse(process.argv[2])){let s=fs.readFileSync(process.argv[1]+'/'+f,'utf8');
 s=s.replace(/return \{ t, set/,(m)=>'return { __D: (typeof S!=="undefined"?S:D), t, set');
 const w={};const ctx={window:w,localStorage:{getItem(){return null},setItem(){}},navigator:{language:'en'},document:{querySelectorAll(){return[]},documentElement:{}}};
 vm.runInNewContext(s,ctx);out[f]=w.I18N.__D;}
process.stdout.write(JSON.stringify(out));
"""
def dicts():
    r = subprocess.run(['node', '-e', NODE, ROOT, json.dumps(APPS)], capture_output=True, text=True)
    if r.returncode: sys.exit(r.stderr)
    return json.loads(r.stdout)
def mid(s): return hashlib.md5(s.encode()).hexdigest()[:7]
def load(p): return json.load(open(p, encoding='utf-8')) if os.path.exists(p) else {}
def sig(s):
    return (sorted(re.findall(r'\{[a-zA-Z0-9_]+\}', s)), sorted(re.findall(r'%[sd]', s)), sorted(t.lower() for t in re.findall(r'</?([a-zA-Z0-9]+)', s)),
            s.count('\n'), sorted(re.findall(r'&[a-z#0-9]+;', s)), s[:1] == ' ', s[-1:] == ' ')
def needs(s): return bool(re.search(r'[A-Za-z]{2}', s))
def state():
    D = dicts(); tm = load(TM); atm = load(ATM); todo = {}; tot = 0; done = 0
    for f in APPS:
        for k, v in D[f]['en'].items():
            if not isinstance(v, str) or not needs(v): continue
            tot += 1
            if v in atm or v in tm: done += 1
            else: todo[v] = 1
    return D, tm, atm, list(todo), tot, done
def enc(s): return s.replace('\\', '\\\\').replace('\n', '\\n')
def dec(s): return re.sub(r'\\(n|\\)', lambda m: '\n' if m.group(1) == 'n' else '\\', s)
cmd = sys.argv[1] if len(sys.argv) > 1 else 'stat'
if cmd == 'stat':
    D, tm, atm, todo, tot, done = state()
    print(f'app texts {tot}  from memories {done}  unique to translate {len(todo)}  chars left {sum(len(x) for x in todo)}')
elif cmd == 'export':
    D, tm, atm, todo, tot, done = state(); lim = int(sys.argv[3]) if len(sys.argv) > 3 else 20000; n = 0; c = 0
    with open(sys.argv[2], 'w', encoding='utf-8') as o:
        for s in todo:
            if c + len(s) > lim and n: break
            o.write(mid(s) + '\t' + enc(s) + '\n'); n += 1; c += len(s)
    print(f'{n} texts, {c} chars')
elif cmd == 'merge':
    D, tm, atm, todo, tot, done = state(); by = {mid(s): s for s in todo}; ok = 0; bad = []
    for line in open(sys.argv[2], encoding='utf-8'):
        line = line.rstrip('\n')
        if not line.strip(): continue
        i, _, t = line.partition('\t'); t = dec(t)
        if i not in by: bad.append(f'unknown id {i}'); continue
        if sig(by[i]) != sig(t): bad.append(f'{i} placeholders / tags / line breaks / edge space'); continue
        atm[by[i]] = t; ok += 1
    json.dump(atm, open(ATM, 'w', encoding='utf-8'), ensure_ascii=False, indent=0, sort_keys=True)
    print(f'merged {ok}, refused {len(bad)}'); [print(' ', b) for b in bad]
elif cmd == 'build':
    D, tm, atm, todo, tot, done = state()
    for f in APPS:
        p = os.path.join(ROOT, f); s = open(p, encoding='utf-8').read(); ka = {}
        for k, v in D[f]['en'].items():
            if not isinstance(v, str): continue
            t = atm.get(v) or tm.get(v)
            if t is not None and sig(t) == sig(v): ka[k] = t
        body = ', '.join(json.dumps(k, ensure_ascii=False) + ': ' + json.dumps(v, ensure_ascii=False) for k, v in ka.items())
        if 'const D = {};' in s:
            s = re.sub(r'\n  D\.ka = \{.*?\n  \};\n', '\n', s, flags=re.S)
            s = s.replace('\n  let cur = "en";', '\n  D.ka = { ' + body + '\n  };\n  let cur = "en";', 1)
            s = re.sub(r', \["ka", "[^"]*"\]', '', s); s = re.sub(r'(const LANGS = \[.*?)\];', lambda m: m.group(1) + ', ["ka", "ქართული"]];', s, count=1)
        else:
            s = re.sub(r'\n    ka: \{.*?\n    \},\n', '\n', s, flags=re.S)
            i = s.index('\n  };\n  const LANGS'); head = s[:i]
            if not head.rstrip().endswith(','): head = head.rstrip() + ','
            s = head + '\n    ka: { ' + body + '\n    },' + s[i:]
            s = re.sub(r', \["ka", "[^"]*"\]', '', s); s = re.sub(r'(const LANGS = \[.*?)\];', lambda m: m.group(1) + ', ["ka", "KA"]];', s, count=1)
        open(p, 'w', encoding='utf-8').write(s)
        print(f'{f}: en {len(D[f]["en"])} keys, ka {len(ka)}')
