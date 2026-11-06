# split_pl.py: FINAL (pl_PL_final.py) -> GAPS in pl_PL.py and MANUAL in pl_PL_review.py
import re, html, polib, pprint
ns = {}; exec(open('pl_PL_final.py', encoding='utf-8').read(), ns); FINAL = ns['FINAL']
def tidy(t):
    t = re.sub(r'&([a-zA-Z]+)(?![a-zA-Z;])', r'&\1;', t); return re.sub(r'[ \t\r\n]+', ' ', html.unescape(t)).strip()
g = {e.msgid: tidy(e.msgstr) for e in polib.mofile('/home/claude/gibbon-i18n/pl_PL/LC_MESSAGES/gibbon.mo') if e.msgid and e.msgstr}
allp = polib.pofile('All.pot' if False else 'all.pot'); mod = {e.msgid for e in polib.pofile('School Connect.pot')}
need = [e.msgid for e in allp if e.msgid not in mod]
print('not covered by FINAL:', [k for k in need if k not in FINAL])
gaps = {k: v for k, v in FINAL.items() if not g.get(k)}
manual = {k: v for k, v in FINAL.items() if g.get(k) and g[k] != v}
same = [k for k in FINAL if g.get(k) == FINAL[k]]
print(f'gaps {len(gaps)}  manual {len(manual)}  kept as Gibbon {len(same)}')
s = open('pl_PL.py', encoding='utf-8').read()
s = re.sub(r'GAPS = \{.*?\n\}\n|GAPS = \{\}\n', 'GAPS = ' + pprint.pformat(gaps, width=200) + '\n', s, flags=re.S)
open('pl_PL.py', 'w', encoding='utf-8').write(s)
with open('pl_PL_review.py', 'w', encoding='utf-8') as f:
    f.write("# pl_PL: review of Gibbon's own translations as the app shows them (build_po applies both).\n"
            "# Gibbon's Polish file needed most strings corrected (Klasa wychowawcza for year group, Przedmiot for class,\n"
            "# Korepetytor for tutor, Nazwisko rodowe for official name, Terminarz for homework...). Source: pl_PL_final.py.\n\n")
    f.write('AUTO = {}\n\nMANUAL = ' + pprint.pformat(manual, width=200) + '\n')
