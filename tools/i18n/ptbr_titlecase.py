import re, sys, polib
sys.path.insert(0, '.'); import pt_BR as F
gib = {e.msgid: e.msgstr for e in polib.pofile('/home/claude/gibbon-i18n/pt_BR/LC_MESSAGES/gibbon.po') if e.msgstr}
SMALL = {'de','da','do','das','dos','e','em','no','na','nos','nas','para','por','com','a','o','as','os','um','uma','ao','à','às','ou','sem','é','como'}
def is_label(en):
    if re.search(r'[.!?:]$', en.strip()) or '{' in en or '...' in en: return False
    small_en = {'a','an','the','of','in','at','by','to','for','and','or','as','on','&'}
    words = [w for w in re.findall(r"[A-Za-z][\w']*", en) if w not in small_en]
    return bool(words) and all(w[0].isupper() for w in words)
def title(pt):
    out = []
    for i, w in enumerate(pt.split(' ')):
        core = w.strip('()"\'«»')
        if i > 0 and core.lower() in SMALL: out.append(w); continue
        out.append(w[:1].upper() + w[1:] if w[:1].isalpha() else w)
    return ' '.join(out)
changes = {}
for en, pt in F.T.items():
    if en.endswith(' *') and en[:-2] in gib:
        new = gib[en[:-2]] + ' *'
    elif is_label(en):
        new = title(pt)
    else:
        continue
    if new != pt: changes[en] = (pt, new)
pl = {}
for en, (a, b) in F.P.items():
    if is_label(en):
        na, nb = title(a), title(b)
        if (na, nb) != (a, b): pl[en] = ((a, b), (na, nb))
if __name__ == '__main__':
    for en, (a, b) in changes.items(): print(f'{en!r}: {a!r} -> {b!r}')
    for en, (a, b) in pl.items(): print(f'{en!r}: {a} -> {b}')
    print(len(changes) + len(pl), 'changes')
