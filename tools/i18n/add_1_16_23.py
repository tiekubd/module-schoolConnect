# One-off (26 Nov 2026): for app 1.16.23 / module 1.16.23. The View Daily
# Attendance "N/A" explanation is Gibbon core's own text; these five languages
# have no translation of it in GibbonEdu/i18n yet. App-only strings.
import pprint, re

KEYS = ['This class is not timetabled to run on the specified date. Attendance may still be taken for this group however it currently falls outside the regular schedule for this class.']

D = {
'ar_SA': ['هذا الفصل غير مُدرج في الجدول في التاريخ المحدد. لا يزال بالإمكان تسجيل الحضور لهذه المجموعة، لكنه يقع حاليًا خارج الجدول المعتاد لهذا الفصل.'],
'hr_HR': ['Ovaj razred nije u rasporedu za odabrani datum. Prisutnost se za ovu skupinu i dalje može evidentirati, ali je trenutno izvan redovitog rasporeda za ovaj razred.'],
'ro_RO': ['Această clasă nu este programată în orar la data specificată. Prezența poate fi totuși înregistrată pentru acest grup, însă în prezent se află în afara programului obișnuit al acestei clase.'],
'sq_AL': ['Kjo klasë nuk është në orar për datën e zgjedhur. Frekuentimi mund të merret ende për këtë grup, por aktualisht bie jashtë orarit të rregullt të kësaj klase.'],
'zh_HK': ['此班別在指定日期沒有編入時間表。仍可為此班別點名，但目前不屬於此班別的正常上課時間。'],
}

if __name__ == '__main__':
    for L, vals in D.items():
        assert len(vals) == len(KEYS), L
        src = open(f'{L}.py', encoding='utf-8').read()
        b = {}; exec(src, b)
        T = dict(b['T']); T.update(zip(KEYS, vals))
        src, n = re.subn(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
        assert n == 1, L
        open(f'{L}.py', 'w', encoding='utf-8').write(src)
        c = {}; exec(src, c)
        assert c['T'] == T and c['P'] == b['P'], L
        print(L, 'ok')
