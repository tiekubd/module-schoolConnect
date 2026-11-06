# casefix.py LANG PERSON: casing for Gibbon's own labels, as the app shows them.
# Reads the endpoint's final strings for that language, finds Gibbon labels (title-case
# English, not the module's own text, not already corrected) whose casing breaks the
# language's convention, and writes them as AUTO in LANG_review.py (MANUAL is kept).
# Then prints every Gibbon string the app uses, as it will appear, for a line-by-line read.
import sys, re, json, subprocess, polib, importlib, pprint, os

S = '/tmp/claude-0/-home-claude/ed44e25e-8ea3-50fe-9e70-472e23db17dc/scratchpad/'
lang, person = sys.argv[1], sys.argv[2]
sys.path.insert(0, S)
F = importlib.import_module(lang)
mine = set(F.GAPS) | set(F.OVERRIDES)

TITLE = {'it_IT', 'es_ES', 'es_MX', 'pt_BR', 'pt_PT', 'tr_TR'}
SENTENCE = {'fr_FR', 'es_DO', 'nl_NL'}
SMALL = {
    'it': 'di del della dei delle degli dello dell e ed a al alla ai alle agli allo in nel nella nei nelle negli per con da dal dalla dai dalle dallo su sul sulla il lo la le i gli un una uno o come tra fra',
    'es': 'de del la las el los y e en a al por para con sin o u un una como sobre su sus mi mis no se',
    'pt': 'de do da dos das e em no na nos nas por para com a o as os um uma ou ao aos à às sem como pelo pela pelos pelas',
    'fr': '',
    'tr': 've ile için veya ya da göre',
}
KEEP = {'Gibbon', 'School', 'Connect', 'Google', 'YouTube', 'Jitsi', 'Meet', 'SMS', 'IP', 'ID', 'PDF', 'URL', 'CC', 'TPC',
        'N/A', 'N/D', 'Email', 'E-mail', 'iOS', 'Android', 'Admin', 'Planner', 'Staff', 'Report'}

small_en = {'a', 'an', 'the', 'of', 'in', 'at', 'by', 'to', 'for', 'and', 'or', 'as', 'on', '&'}
def is_label(en):
    if re.search(r'[.!?:]$', en.strip()) or '{' in en or '...' in en or '%' in en: return False
    w = [x for x in re.findall(r"[A-Za-z][\w']*", en) if x not in small_en]
    return len(w) >= 2 and all(x[0].isupper() for x in w)

def cap(ch):
    return {'i': 'İ', 'ı': 'I'}.get(ch, ch.upper()) if lang.startswith('tr') else ch.upper()

def title(v, small):
    out = []
    for i, w in enumerate(v.split(' ')):
        m = re.match(r"^([(\"'«¿¡]*)(\w+['’])?(.*)$", w)          # dell'Anno, l'Orario
        pre, elid, core = m.group(1), m.group(2) or '', m.group(3)
        if elid and i > 0 and elid.rstrip("'’").lower() in small:
            out.append(pre + elid + cap(core[:1]) + core[1:]); continue
        if i > 0 and (elid + core).lower().strip('.,:;?!)') in small: out.append(w); continue
        if not core[:1].isalpha() or core.isupper(): out.append(w); continue
        out.append(pre + elid + cap(core[:1]) + core[1:])
    return ' '.join(out)

def sentence(v):
    out, start = [], True
    for w in v.split(' '):
        core = w.lstrip('¿¡("«')
        bare = core.rstrip('.,:;!?)»*')
        if (not start and core[:1].isupper() and bare not in KEEP and not (len(core) > 1 and core[1:2].isupper())):
            w = w[:len(w) - len(core)] + core[0].lower() + core[1:]
        out.append(w)
        start = bool(re.search(r'[.!?:>—]$', w)) or w in ('-', '+', '·', '|', '/')
    return ' '.join(out)

d = json.loads(subprocess.run([S + 'call.sh', person], capture_output=True, text=True).stdout)
assert d.get('success'), d
s, pl = d['strings'], d['plurals']
pot = polib.pofile(S + 'all.pot')
module = {e.msgid for e in polib.pofile(S + 'School Connect.pot')}

# Casing is worked out from Gibbon's own file (tidied as the endpoint does), not from the
# endpoint's output, so re-running never sees its own earlier fixes.
import html
def tidy(t):
    t = re.sub(r'&([a-zA-Z]+)(?![a-zA-Z;])', r'&\1;', t)
    return re.sub(r'[ \t\r\n]+', ' ', html.unescape(t)).strip()
gib = {g.msgid: tidy(g.msgstr) for g in polib.mofile(f'/home/claude/gibbon-i18n/{lang}/LC_MESSAGES/gibbon.mo') if g.msgid and g.msgstr}
auto = {}
small = set(SMALL.get(lang[:2], '').split())
for e in pot:
    if e.msgid in module or e.msgid in mine or e.msgid_plural or not is_label(e.msgid): continue
    v = gib.get(e.msgid)
    if not v: continue
    new = title(v, small) if lang in TITLE else sentence(v) if lang in SENTENCE else v
    if new != v: auto[e.msgid] = new

# French: a no-break space before ? ! : ; in every Gibbon string (none, or an ordinary one, lets
# the mark wrap onto its own line).
if lang.startswith('fr'):
    for e in pot:
        if e.msgid in module or e.msgid in mine or e.msgid_plural: continue
        v = auto.get(e.msgid) or gib.get(e.msgid)
        if not v: continue
        new = re.sub(r'(?<=[\w»)’\'])[ \u00a0]?([?!:;])(?=\s|$)', '\u00a0\\1', v)
        if new != v: auto[e.msgid] = new

path = S + f'{lang}_review.py'
manual = {}
if os.path.exists(path):
    ns = {}; exec(open(path, encoding='utf-8').read(), ns); manual = ns.get('MANUAL', {})
with open(path, 'w', encoding='utf-8') as f:
    f.write(f"# {lang}: review of Gibbon's own translations as the app shows them (build_po applies both).\n"
            "# AUTO: casing made consistent with the app's convention (casefix.py, regenerated).\n"
            "# MANUAL: corrections from a line-by-line read (kept across runs; wins over AUTO).\n\n")
    f.write('AUTO = ' + pprint.pformat(auto, width=200, sort_dicts=True) + '\n\n')
    f.write('MANUAL = ' + pprint.pformat(manual, width=200, sort_dicts=True) + '\n')

if '--quiet' not in sys.argv:
    print(f'== {lang}: {len(auto)} casing changes')
    for k, v in auto.items(): print(f'  C {gib[k]!r} -> {v!r}')
    print('== Gibbon strings as shown (after casing):')
    for e in pot:
        if e.msgid in module: continue
        v = manual.get(e.msgid) or auto.get(e.msgid) or (s.get(e.msgid) if not e.msgid_plural else pl.get(e.msgid))
        tag = 'M' if e.msgid in manual else 'A' if e.msgid in auto else 'mine' if e.msgid in mine else ' '
        print(f'{tag:4} {e.msgid!r} => {v!r}')
