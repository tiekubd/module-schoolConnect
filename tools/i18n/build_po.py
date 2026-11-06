# build_po.py LANG  -> builds LANG/LC_MESSAGES/School Connect.po/.mo from School Connect.pot + LANG.py
import sys, re, os, importlib, polib
lang = sys.argv[1]; sys.path.insert(0, '.'); F = importlib.import_module(lang)
# Review of Gibbon's own translations (LANG_review.py): casing (AUTO) and line-by-line corrections (MANUAL).
if os.path.exists(f'{lang}_review.py'):
    _ns = {}; exec(open(f'{lang}_review.py', encoding='utf-8').read(), _ns)
    for _k, _v in {**_ns.get('AUTO', {}), **_ns.get('MANUAL', {})}.items():
        (F.GAPS if _k in F.GAPS else F.OVERRIDES)[_k] = _v
# Default attendance code names (codes.py): GAPS where Gibbon has none, else OVERRIDES.
from codes import CODES
_gmo = f'site/i18n/{lang}/LC_MESSAGES/gibbon.mo'
_g = {e.msgid: e.msgstr for e in polib.mofile(_gmo)} if os.path.exists(_gmo) else {}
for _k, _v in CODES.get(lang, {}).items():
    F.GAPS.pop(_k, None); F.OVERRIDES.pop(_k, None)
    if _g.get(_k) != _v:
        (F.OVERRIDES if _g.get(_k) else F.GAPS)[_k] = _v
names = {'vi_VN': ('Vietnamese', 'nplurals=1; plural=0;'), 'lt_LT': ('Lithuanian', 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && (n%100<10 || n%100>=20) ? 1 : 2);'), 'af_ZA': ('Afrikaans', 'nplurals=2; plural=(n != 1);'), 'ca_ES': ('Catalan', 'nplurals=2; plural=(n != 1);'), 'el_GR': ('Greek', 'nplurals=2; plural=(n != 1);'), 'th_TH': ('Thai', 'nplurals=1; plural=0;'), 'he_IL': ('Hebrew', 'nplurals=2; plural=(n != 1);'), 'uk_UA': ('Ukrainian', 'nplurals=4; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : n%10==0 || (n%10>=5 && n%10<=9) || (n%100>=11 && n%100<=14)? 2 : 3);'), 'pl_PL': ('Polish', 'nplurals=4; plural=(n==1 ? 0 : (n%10>=2 && n%10<=4) && (n%100<12 || n%100>14) ? 1 : n!=1 && (n%10>=0 && n%10<=1) || (n%10>=5 && n%10<=9) || (n%100>=12 && n%100<=14) ? 2 : 3);'), 'ja_JP': ('Japanese', 'nplurals=1; plural=0;'), 'tr_TR': ('Turkish', 'nplurals=2; plural=(n>1);'), 'nl_NL': ('Dutch', 'nplurals=2; plural=(n != 1);'), 'it_IT': ('Italian', 'nplurals=2; plural=(n != 1);'), 'en_US': ('American English', 'nplurals=2; plural=(n != 1);'), 'fr_FR': ('French', 'nplurals=2; plural=(n > 1);'), 'pt_BR': ('Brazilian Portuguese', 'nplurals=2; plural=(n != 1);'), 'pt_PT': ('European Portuguese', 'nplurals=2; plural=(n != 1);'),
         'de_DE': ('German', 'nplurals=2; plural=(n != 1);'),
         'es_ES': ('Spanish', 'nplurals=2; plural=(n != 1);'),
         'es_MX': ('Mexican Spanish', 'nplurals=2; plural=(n != 1);'),
         'es_DO': ('Dominican Spanish', 'nplurals=2; plural=(n != 1);'),
         'zh_CN': ('Simplified Chinese', 'nplurals=1; plural=0;'),
         'zh_HK': ('Traditional Chinese (Hong Kong)', 'nplurals=1; plural=0;'),
         'ar_SA': ('Arabic', 'nplurals=6; plural=n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5;')}
pot = polib.pofile('School Connect.pot'); po = polib.POFile()
po.header = (f"School Connect: {names[lang][0]} ({lang}) translation of the app-only interface text.\n"
             f"AI draft (November 2026), to be checked by a {names[lang][0]}-speaking school before release.\n"
             f"Vocabulary follows Gibbon core's {lang} file. Regenerate the template with\n"
             "tools/extract_strings.py in the app repo.")
po.metadata = {'Project-Id-Version': 'School Connect', 'PO-Revision-Date': '2026-11-04 14:00-0600',
               'Last-Translator': 'AI draft, pending review', 'Language-Team': names[lang][0], 'Language': lang,
               'MIME-Version': '1.0', 'Content-Type': 'text/plain; charset=UTF-8', 'Content-Transfer-Encoding': '8bit',
               'Plural-Forms': names[lang][1]}
ph = lambda s: sorted(re.findall(r'\{\w+\}', s)); missing, bad = [], []
for e in pot:
    if e.msgid_plural:
        if e.msgid not in F.P: missing.append(e.msgid); continue
        forms = F.P[e.msgid]
        nplurals = int(re.search(r'nplurals=(\d+)', names[lang][1]).group(1))
        need = set(ph(e.msgid_plural)) - {'{count}'}   # {count} may be built into the word
        if len(forms) != nplurals or any(set(ph(f)) - {'{count}'} != need or not set(ph(f)) <= set(ph(e.msgid_plural)) for f in forms):
            bad.append(e.msgid)
        po.append(polib.POEntry(msgid=e.msgid, msgid_plural=e.msgid_plural, msgstr_plural=dict(enumerate(forms)), occurrences=e.occurrences, comment=e.comment))
    else:
        if e.msgid not in F.T: missing.append(e.msgid); continue
        bad += [e.msgid] if ph(F.T[e.msgid]) != ph(e.msgid) else []
        po.append(polib.POEntry(msgid=e.msgid, msgstr=F.T[e.msgid], occurrences=e.occurrences, comment=e.comment))
for k, v in getattr(F, 'EXTRA', {}).items():
    po.append(polib.POEntry(msgid=k, msgstr=v, tcomment='Database label the app shows (default attendance code or calendar legend); not in Gibbon\'s files'))
for k, v in {**F.GAPS, **F.OVERRIDES}.items():
    bad += [k] if ph(v) != ph(k) else []
    po.append(polib.POEntry(msgid=k, msgstr=v, tcomment='Gibbon core string: ' + ('missing from its file for this language' if k in F.GAPS else "corrects Gibbon's translation for the app")))
unused = sorted(set(F.T) | set(F.P)) and sorted((set(F.T) | set(F.P)) - {e.msgid for e in pot})
missing = [] if getattr(F, 'SPARSE', False) else missing   # sparse: untranslated strings stay English
print(f'{lang}: missing {missing} | placeholder mismatch {bad} | unused drafts {unused} | entries {len(po)}')
d = f'{lang}/LC_MESSAGES'; os.makedirs(d, exist_ok=True)
po.save(f'{d}/School Connect.po'); po.save_as_mofile(f'{d}/School Connect.mo')
