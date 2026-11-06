import re, glob, sys, json, polib, collections
APP='/home/claude/gibbon-connect/lib'; I18N='/home/claude/gibbon-i18n'
lit = r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\""
strings = collections.Counter(); files = collections.defaultdict(set)
varcalls = 0
for f in glob.glob(APP+'/**/*.dart', recursive=True):
    if f.endswith('string_store.dart'): continue
    src = open(f, encoding='utf-8').read()
    for m in re.finditer(r'\btr\(', src):
        # grab balanced parens
        i = m.end(); depth = 1; j = i
        while j < len(src) and depth:
            c = src[j]
            if c in "'\"":
                q = c; j += 1
                while j < len(src) and src[j] != q:
                    j += 2 if src[j] == '\\' else 1
            elif c == '(': depth += 1
            elif c == ')': depth -= 1
            j += 1
        arg = src[i:j-1]
        found = [a or b for a, b in re.findall(lit, arg)]
        if not found: varcalls += 1
        for s in found:
            s = s.replace("\\'", "'").replace('\\"', '"').replace('\\n', '\n')
            strings[s] += 1; files[s].add(f.split('/lib/')[1])
interp = {s for s in strings if re.search(r'\$\w|\$\{', s)}
plain = [s for s in strings if s not in interp]
print(f"tr() calls with literal text: {sum(strings.values())}, distinct strings: {len(strings)}")
print(f"  of which contain \$interpolation (can't match gettext as-is): {len(interp)}")
print(f"tr() calls passing a variable (text defined elsewhere): {varcalls}")
langs = sys.argv[1:]
res = {}
for code in langs:
    po = polib.pofile(f'{I18N}/{code}/LC_MESSAGES/gibbon.po')
    tx = {e.msgid: e.msgstr for e in po if e.msgstr and not e.obsolete and 'fuzzy' not in e.flags}
    ids = {e.msgid for e in po if not e.obsolete}
    hit = [s for s in plain if s in tx]
    inpo = [s for s in plain if s in ids]
    res[code] = hit
    print(f"{code}: exact msgid in Gibbon .po: {len(inpo)}/{len(plain)} ({100*len(inpo)/len(plain):.0f}%); translated: {len(hit)}/{len(plain)} ({100*len(hit)/len(plain):.0f}%); .po has {len(tx)} translated of {len(ids)}")
# case-insensitive / trailing-colon near misses (vs fr)
po = polib.pofile(f'{I18N}/fr_FR/LC_MESSAGES/gibbon.po'); ids = {e.msgid for e in po}
low = {i.lower().rstrip(':').strip(): i for i in ids}
miss = [s for s in plain if s not in ids]
near = [s for s in miss if s.lower().rstrip(':').strip() in low]
print(f"near-misses (differ only by case/trailing colon): {len(near)}")
json.dump({'plain': sorted(plain), 'interp': sorted(interp), 'miss': sorted(miss), 'near': sorted(near),
           'files': {s: sorted(v) for s, v in files.items()}}, open(sys.argv[0].rsplit('/',1)[0]+'/coverage.json','w'), indent=1, ensure_ascii=False)
