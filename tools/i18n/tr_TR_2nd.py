# One-off: second pass on tr_TR (7 Nov 2026). Writes tr_TR_final.py; updates T/P/OVERRIDES in tr_TR.py.
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('trtr_cur.json', encoding='utf-8'))
NEW = {
 'Teacher': 'Öğretmen', 'Full': 'Aktif', 'Finance': 'Finans', 'Messenger': 'Mesajlaşma', 'Admissions': 'Öğrenci Kabul',
 'Timetable Admin': 'Ders Programı Yönetimi', 'School Admin': 'Okul Yönetimi', 'System Admin': 'Sistem Yönetimi', 'User Admin': 'Kullanıcı Yönetimi',
 'Admin': 'Yönetim Araçları', 'Tracking': 'İlerleme Takibi', 'Home': 'Ev', 'Department': 'Zümre', 'Learning Area': 'Öğrenme Alanı',
 'All Learning Areas': 'Tüm Öğrenme Alanları', 'Due Date': 'Teslim Tarihi', 'Homework + Due Dates': 'Ödevler ve Teslim Tarihleri',
 'Report': 'Karne', 'Reports': 'Karneler', 'View Reports': 'Karneleri Görüntüle', 'Required': 'Zorunlu', 'Selected': 'Seçili',
 'Partial': 'Kısmen Mevcut', 'Vehicle Registration': 'Araç Plakası',
 'In an emergency, please try and contact the adult family members listed below first. If these cannot be reached, then try the emergency contacts below.': 'Acil bir durumda lütfen önce aşağıdaki yetişkin aile üyelerine ulaşmaya çalışın. Onlara ulaşılamazsa aşağıdaki acil durum kişilerini arayın.',
 'These details are used when immediate family members (e.g. parent, spouse) cannot be reached first. Please try to avoid listing immediate family members.': 'Bu bilgiler, birinci derece yakınlara (ör. ebeveyn, eş) ulaşılamadığında kullanılır. Lütfen birinci derece yakınları buraya eklemeyin.',
}
WHY = {
 'Teacher': 'missing (plural entry only)', 'Full': 'Tam (full, complete)', 'Finance': 'Maliye (the Ministry of Finance)', 'Messenger': 'Haberci (a herald)',
 'Admin': 'Sistem Yönetimi in the earlier draft, same as System Admin', 'Tracking': 'Takip, same as Follow Up', 'Home': 'Anasayfa (home page); phone type',
 'Due Date': 'Bitiş tarihi, same as End Date', 'Learning Area': 'Zümre, which Gibbon also uses for Departments',
 'These details are used when immediate family members (e.g. parent, spouse) cannot be reached first. Please try to avoid listing immediate family members.': 'acil aile üyeleri ("urgent family members")',
 'School Admin': 'Okul Yöneticisi (a person)',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('tr_TR_final.py', 'w', encoding='utf-8') as f:
    f.write("# tr_TR: every Gibbon string the app uses, as the app should show it (line-by-line read, 7 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's tr_TR file.\n"
            "# Şube (form group), sınıf, ders, sınıf düzeyi, dönem, rehber öğretmen, zümre (department), öğrenme alanı, karne.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
src = open('tr_TR.py', encoding='utf-8').read()
b = {}; exec(src, b)
assert not re.search(r'^(T|P|GAPS|OVERRIDES|EXTRA)\[', src, re.M)
T, P = dict(b['T']), dict(b['P'])
T['No Learning Area'] = 'Öğrenme Alanı Yok'
P['Trial — {count} day left'] = ('Deneme süresi — {count} gün kaldı', 'Deneme süresi — {count} gün kaldı')
src = re.sub(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
src = re.sub(r'^P = \{.*?\n(?=EXTRA = )', lambda _: 'P = ' + pprint.pformat(P, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
src = re.sub(r'^OVERRIDES = \{.*?\n\}\n', lambda _: 'OVERRIDES = ' + pprint.pformat({k: v for k, v in b['OVERRIDES'].items() if k not in FINAL}, width=200) + '\n', src, count=1, flags=re.S | re.M)
src = src.replace("notlar (grades), personel, öğrenci, veli, çocuklar, mekân (facility), zümre (learning\n# area),", "notlar (grades), personel, öğrenci, veli, çocuklar, mekân (facility), öğrenme alanı (learning\n# area; zümre = department since the 7 Nov 2026 pass),")
open('tr_TR.py', 'w', encoding='utf-8').write(src)
c = {}; exec(src, c); print('ok', c['OVERRIDES'], c['EXTRA'] == b['EXTRA'])
