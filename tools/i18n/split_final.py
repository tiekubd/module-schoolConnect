# split_final.py LANG: FINAL (LANG_final.py) -> GAPS in LANG.py and MANUAL in LANG_review.py
import re, html, polib, pprint, sys
L = sys.argv[1]
sys.path.insert(0, '.'); from dyn import DYN
ns = {}; exec(open(f'{L}_final.py', encoding='utf-8').read(), ns); FINAL = ns['FINAL']
def tidy(t):
    t = re.sub(r'&([a-zA-Z]+)(?![a-zA-Z;])', r'&\1;', t); return re.sub(r'[ \t\r\n]+', ' ', html.unescape(t)).strip()
g = {e.msgid: tidy(e.msgstr) for e in polib.mofile(f'/home/claude/gibbon-i18n/{L}/LC_MESSAGES/gibbon.mo') if e.msgid and e.msgstr}
allp = polib.pofile('All.pot' if False else 'all.pot'); mod = {e.msgid for e in polib.pofile('School Connect.pot')}
need = [e.msgid for e in allp if e.msgid not in mod] + DYN
print('not covered by FINAL:', [k for k in need if k not in FINAL])
gaps = {k: v for k, v in FINAL.items() if not g.get(k)}
manual = {k: v for k, v in FINAL.items() if g.get(k) and g[k] != v}
same = [k for k in FINAL if g.get(k) == FINAL[k]]
print(f'gaps {len(gaps)}  manual {len(manual)}  kept as Gibbon {len(same)}')
s = open(f'{L}.py', encoding='utf-8').read()
s, n = re.subn(r'GAPS = \{.*?\}\n(?=(?:\n|#[^\n]*\n)*OVERRIDES = )', lambda m: 'GAPS = ' + pprint.pformat(gaps, width=200) + '\n', s, count=1, flags=re.S)
assert n == 1, 'GAPS block not found'
open(f'{L}.py', 'w', encoding='utf-8').write(s)
with open(f'{L}_review.py', 'w', encoding='utf-8') as f:
    f.write(f"# {L}: review of Gibbon's own translations as the app shows them (build_po applies both).\n"
            f"# Source: {L}_final.py (comments there say what Gibbon had where it matters).\n\n")
    f.write('AUTO = {}\n\nMANUAL = ' + pprint.pformat(manual, width=200) + '\n')
