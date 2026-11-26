# One-off (25 Nov 2026): strings added in app 1.16.22 (coloured register calendar and
# Recent History strips in the Attendance menu), for module 1.16.21. "Recent History"
# (and the In / Out counts) come from Gibbon's own translations, except in the three languages below where
# Gibbon has none yet. App-only strings.
import pprint, re

KEYS = ['Recent History', 'In', 'Out', 'View Daily Attendance']

D = {
'ro_RO': ['Istoric recent', 'Prezenți', 'Absenți', 'Vizualizare prezență zilnică'],
'ur_PK': ['حالیہ تاریخچہ', 'حاضر', 'غیر حاضر', 'روزانہ حاضری دیکھیں'],
'zh_HK': ['最近紀錄', '出席', '缺席', '查看每日出席'],
}

# And, for every language: the Attendance menu's heading over By Class / By
# Form Group (Administrators only).
KEYS2 = ['Other Registers']
D2 = {
'af_ZA': ['Ander registers'], 'ar_SA': ['سجلات أخرى'], 'ca_ES': ['Altres llistes'],
'de_DE': ['Weitere Anwesenheitslisten'], 'el_GR': ['Άλλα παρουσιολόγια'],
'es_DO': ['Otros registros'], 'es_ES': ['Otros registros'], 'es_MX': ['Otros registros'],
'fr_FR': ['Autres appels'], 'he_IL': ['רשימות נוכחות אחרות'], 'hr_HR': ['Ostale evidencije'],
'it_IT': ['Altri registri'], 'ja_JP': ['その他の出欠'], 'lt_LT': ['Kiti žiniaraščiai'],
'nl_NL': ['Andere registers'], 'pl_PL': ['Inne listy obecności'], 'pt_BR': ['Outras chamadas'],
'pt_PT': ['Outros registos'], 'ro_RO': ['Alte cataloage'], 'sq_AL': ['Regjistra të tjerë'],
'th_TH': ['การเช็คชื่ออื่นๆ'], 'tr_TR': ['Diğer yoklamalar'], 'uk_UA': ['Інші журнали'],
'ur_PK': ['دیگر رجسٹر'], 'vi_VN': ['Sổ điểm danh khác'], 'zh_CN': ['其他考勤'], 'zh_HK': ['其他點名'],
}

if __name__ == '__main__':
    for L in sorted(set(D) | set(D2)):
        pairs = list(zip(KEYS, D.get(L, []))) + list(zip(KEYS2, D2.get(L, [])))
        assert L not in D or len(D[L]) == len(KEYS), L
        src = open(f'{L}.py', encoding='utf-8').read()
        b = {}; exec(src, b)
        T = dict(b['T']); T.update(pairs)
        src, n = re.subn(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
        assert n == 1, L
        open(f'{L}.py', 'w', encoding='utf-8').write(src)
        c = {}; exec(src, c)
        assert c['T'] == T and c['P'] == b['P'], L
        print(L, 'ok')
