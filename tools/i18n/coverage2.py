import re, glob, sys, json, polib, subprocess
APP, I18N, REF = sys.argv[1], '/home/claude/gibbon-i18n', None
LIT = re.compile(r"""\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")""")
def unesc(s): return s.replace("\\'", "'").replace('\\"', '"').replace('\\n', '\n')
def strings_in(src):
    out = set(); var = 0
    for m in re.finditer(r'\b(trN?)\(', src):
        i = m.end(); found = []
        # walk the argument: literal runs (adjacent literals joined) that are whole arguments
        depth, j, start = 1, i, i
        args, cur = [], ''
        while j < len(src) and depth:
            c = src[j]
            if c in "'\"":
                q = c; k = j + 1
                while src[k] != q: k += 2 if src[k] == '\\' else 1
                cur += src[j:k+1]; j = k + 1; continue
            if c in '([{': depth += 1
            elif c in ')]}': depth -= 1
            if depth == 1 and c == ',' or depth == 0: args.append(cur); cur = ''
            else: cur += c
            j += 1
        n = 2 if m.group(1) == 'trN' else 1
        for a in args[:n]:
            a = a.strip()
            parts = []; pos = 0
            # whole argument is adjacent literals → one string
            while True:
                mm = LIT.match(a, pos)
                if not mm: break
                parts.append(unesc(mm.group(1) if mm.group(1) is not None else mm.group(2))); pos = mm.end()
            if parts and a[pos:].strip() == '':
                out.add(''.join(parts)); continue
            # ternary: cond ? 'a' : 'b'  → take literal branches after ? and :
            br = re.findall(r"[?:]\s*'((?:[^'\\]|\\.)*)'", a)
            if br: out.update(unesc(b) for b in br); continue
            var += 1
    return out, var
allS, var = set(), 0
for f in glob.glob(APP + '/**/*.dart', recursive=True):
    if f.endswith('string_store.dart'): continue
    s, v = strings_in(open(f, encoding='utf-8').read()); allS |= s; var += v
interp = {s for s in allS if re.search(r'\$\w|\$\{', s)}
plain = sorted(allS - interp)
print(f"  distinct strings: {len(plain)} (+{len(interp)} built from code, {var} tr(variable) calls)")
for code in ['fr_FR', 'es_ES', 'pt_BR', 'ar_SA', 'pt_PT']:
    po = polib.pofile(f'{I18N}/{code}/LC_MESSAGES/gibbon.po')
    ids = {e.msgid for e in po if not e.obsolete}
    tx = {e.msgid for e in po if e.msgstr and not e.obsolete and 'fuzzy' not in e.flags}
    a = sum(s in ids for s in plain); b = sum(s in tx for s in plain)
    print(f"  {code}: in Gibbon {a}/{len(plain)} ({100*a/len(plain):.0f}%), translated {b} ({100*b/len(plain):.0f}%)")
if len(sys.argv) > 2:
    po = polib.pofile(f'{I18N}/fr_FR/LC_MESSAGES/gibbon.po'); ids = {e.msgid for e in po}
    json.dump(sorted(s for s in plain if s not in ids), open(sys.argv[2], 'w'), indent=1, ensure_ascii=False)
