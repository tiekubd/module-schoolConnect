# One-off: second pass on ar_SA (7 Nov 2026). Writes ar_SA_final.py; rewrites ar_SA.py with trailing per-key lines folded in.
# Structure kept from the first pass (الصف الدراسي form group, المرحلة الدراسية year group, الشعبة class,
# الفصل الدراسي term). Fixes: رائد الفصل (tutor; Gibbon: المدرس, "the teacher"), اسم العائلة (surname; Gibbon:
# اللقب, which Saudi readers take as a title), الأبناء (children), موعد التسليم (due), hamzat al-wasl spelling.
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('arsa_cur.json', encoding='utf-8'))
NEW = {
 "Access data on family's children?": 'الوصول إلى بيانات أبناء الأسرة؟', 'Add Child': 'إضافة ابن/ابنة', 'Children': 'الأبناء',
 'Tutor': 'رائد الفصل', 'Surname': 'اسم العائلة', 'Family name as shown in ID documents.': 'اسم العائلة كما هو مبين في وثائق الهوية.',
 'Title': 'اللقب', 'Home': 'المنزل', 'House': 'الفريق', 'Full': 'نشط', 'Messenger': 'المراسلة', 'Rubrics': 'سلالم التقدير',
 'Timetable Admin': 'إدارة الجدول المدرسي', 'User Admin': 'إدارة المستخدمين', 'Support Staff': 'الكادر المساند', 'Support': 'مساند',
 'Teaching': 'تعليمي', 'All Staff': 'جميع الموظفين', 'Wednesday': 'الأربعاء', 'Assistant': 'مساعد', 'View': 'عرض',
 'Vehicle Registration': 'رقم لوحة المركبة', 'Pager': 'جهاز النداء', 'Legend': 'مفتاح الرموز', 'Due Date': 'موعد التسليم',
 'Due Soon': 'موعد التسليم قريب', 'Data Updater': 'تحديث البيانات', 'Tracking': 'تتبع التقدم', 'Teacher': 'معلم/معلمة',
 'Teacher - Left': 'معلم - مغادر', 'Emergency Contacts': 'جهات اتصال الطوارئ', 'Edit Staff': 'تعديل بيانات الموظف',
 'Course Enrolment by Person': 'الالتحاق بالمنهج حسب الشخص', 'Edit Enrolment': 'تعديل الالتحاق', 'Student Enrolment': 'التحاق الطالب',
 'Add Student Enrolment': 'إضافة التحاق طالب', 'Select Students': 'اختيار الطلبة', 'Student Agreements': 'اتفاقيات الطلاب',
 'Response': 'الاستجابة', 'First Aid Expiry': 'انتهاء صلاحية شهادة الإسعافات الأولية', 'Ethnicity': 'الانتماء العرقي',
 'Partial': 'حاضر جزئيًا', 'Edit Course': 'تعديل المنهج', 'Include all students, regardless of status and current enrolment. Some data may not display.': 'تضمين جميع الطلبة، بغض النظر عن الحالة والالتحاق الحالي. قد لا تُعرض بعض البيانات.',
}
WHY = {
 'Tutor': 'المدرس (the teacher); a Saudi homeroom teacher is رائد الفصل', 'Surname': 'اللقب (read as a title or nickname)',
 'Title': 'العنوان (an address or heading)', 'Home': 'الرئيسية (home page); phone type', 'Teacher': 'missing (plural entry only)',
 'Full': 'كامل (complete)', 'Support Staff': 'فريق الدعم (a helpdesk team)', 'View': 'معاينة (preview)', 'Due Date': 'تاريخ الاستحقاق (a payment due date)',
 'Tracking': 'المتابعة, same as Follow Up', 'Children': 'الأطفال (young children in general)',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
for k, v in FINAL.items():   # hamzat al-wasl: these words never take a hamza on the alif
    FINAL[k] = (v.replace('إلتحاق', 'التحاق').replace('الإلتحاق', 'الالتحاق').replace('إختر', 'اختر').replace('إختيار', 'اختيار')
                 .replace('إتصال', 'اتصال').replace('الإتصال', 'الاتصال').replace('الإستجابة', 'الاستجابة').replace('إنتهاء', 'انتهاء'))
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('ar_SA_final.py', 'w', encoding='utf-8') as f:
    f.write("# ar_SA: every Gibbon string the app uses, as the app should show it (line-by-line read, 7 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's ar_SA file.\n"
            "# الصف الدراسي (form group), المرحلة الدراسية (year group), الشعبة (class), الفصل الدراسي (term), رائد الفصل (tutor).\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
src = open('ar_SA.py', encoding='utf-8').read()
b = {}; exec(src, b)
T, P = dict(b['T']), dict(b['P'])
FIX = {
 'Could not add child': 'تعذّرت إضافة الابن/الابنة', 'Could not load children': 'تعذّر تحميل الأبناء', 'Could not load your children': 'تعذّر تحميل أبنائك',
 'Due date and details are required.': 'موعد التسليم والتفاصيل مطلوبة.', 'Fill in First Name, Surname, Preferred Name, and Primary Role first': 'املأ الاسم الأول واسم العائلة والاسم المفضل والدور الأساسي أولًا',
 'No children found': 'لم يُعثر على أبناء', 'No children linked': 'لا يوجد أبناء مرتبطون', 'No children linked to this account': 'لا يوجد أبناء مرتبطون بهذا الحساب',
 'Remove Child': 'إزالة الابن/الابنة', 'Surname *': 'اسم العائلة *', 'Tutor 2': 'رائد الفصل 2', 'View families, contacts, and children': 'عرض الأسر وجهات الاتصال والأبناء',
}
assert set(FIX) <= set(T)
T.update(FIX)
m = re.search(r'^(T|P|GAPS|OVERRIDES|EXTRA)\[', src, re.M)
head = src[:m.start()].rstrip() + '\n'
head = re.sub(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', head, count=1, flags=re.S | re.M)
head = re.sub(r'^P = \{.*?\n(?=GAPS = )', lambda _: 'P = ' + pprint.pformat(P, width=200, sort_dicts=False) + '\n\n', head, count=1, flags=re.S | re.M)
head = re.sub(r'^OVERRIDES = \{.*?\}\n', lambda _: 'OVERRIDES = ' + pprint.pformat({k: v for k, v in b['OVERRIDES'].items() if k not in FINAL}, width=200) + '\n', head, count=1, flags=re.S | re.M)
if 'EXTRA = ' not in head:
    head = head.rstrip() + '\n\nEXTRA = ' + pprint.pformat(b['EXTRA'], width=200) + '\n'
else:
    head = re.sub(r'^EXTRA = \{.*?\}\n', lambda _: 'EXTRA = ' + pprint.pformat(b['EXTRA'], width=200) + '\n', head, count=1, flags=re.S | re.M)
head = head.replace("# Plurals: 6 forms", "# Second pass 7 Nov 2026 (ar_SA_2nd.py): رائد الفصل, اسم العائلة, الأبناء, موعد التسليم.\n# Plurals: 6 forms")
open('ar_SA.py', 'w', encoding='utf-8').write(head)
c = {}; exec(head, c)
print('T same as intended', all(c['T'][k] == T[k] for k in T), 'P', c['P'] == P, 'EXTRA', c['EXTRA'] == b['EXTRA'], 'names', [n for n in ['T','P','GAPS','OVERRIDES','EXTRA'] if n not in c])
