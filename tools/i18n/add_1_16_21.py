# One-off (25 Nov 2026): strings added in app 1.16.22 (coloured register calendar and
# Recent History strips in the Attendance menu), for module 1.16.21. "Recent History"
# (and the In / Out counts) come from Gibbon's own translations, except in the three languages below where
# Gibbon has none yet. App-only strings.
import pprint, re

KEYS = ['Recent History', 'In', 'Out']

D = {
'ro_RO': ['Istoric recent', 'Prezenți', 'Absenți'],
'ur_PK': ['حالیہ تاریخچہ', 'حاضر', 'غیر حاضر'],
'zh_HK': ['最近紀錄', '出席', '缺席'],
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
