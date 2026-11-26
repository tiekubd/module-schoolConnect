# One-off (26 Nov 2026): for app 1.16.23 / module 1.16.23. First: the View Daily
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

# And, for every language: the app Settings switch for View Daily Attendance's
# Other Registers (module setting otherRegistersAdminOnly). The terms inside
# follow each language's own "By Class", "By Form Group" and
# "View Daily Attendance".
KEYS2 = [
    'Other Registers for Administrators only',
    'Only Administrators see By Class and By Form Group in View Daily Attendance. Permissions on the website are not changed.',
    "By Class and By Form Group in View Daily Attendance follow each role's Attendance permissions, as on the website.",
]
D2 = {
'af_ZA': ['Ander registers slegs vir administrateurs',
          'Slegs administrateurs sien "Per klas" en "Per registerklas" in "Kyk na daaglikse bywoning". Toestemmings op die webwerf verander nie.',
          '"Per klas" en "Per registerklas" in "Kyk na daaglikse bywoning" volg elke rol se bywoningstoestemmings, soos op die webwerf.'],
'ar_SA': ['السجلات الأخرى للمسؤولين فقط',
          'يرى مسؤولو النظام فقط «حسب الشعبة» و«حسب الصف الدراسي» في «عرض الحضور اليومي». لا تتغير الأذونات على الموقع.',
          'يتبع «حسب الشعبة» و«حسب الصف الدراسي» في «عرض الحضور اليومي» أذونات الحضور لكل دور، كما على الموقع.'],
'ca_ES': ['Altres llistes només per a administradors',
          "Només els administradors veuen «Per classe» i «Per grup classe» a «Mostra l'assistència diària». Els permisos del web no canvien.",
          "«Per classe» i «Per grup classe» a «Mostra l'assistència diària» segueixen els permisos d'assistència de cada rol, com al web."],
'de_DE': ['Weitere Anwesenheitslisten nur für Administrator/innen',
          'Nur Administrator/innen sehen „Nach Lerngruppe“ und „Nach Klasse“ unter „Tägliche Anwesenheit anzeigen“. Die Berechtigungen auf der Website ändern sich nicht.',
          '„Nach Lerngruppe“ und „Nach Klasse“ unter „Tägliche Anwesenheit anzeigen“ richten sich nach den Anwesenheitsberechtigungen jeder Rolle, wie auf der Website.'],
'el_GR': ['Άλλα παρουσιολόγια μόνο για διαχειριστές',
          'Μόνο οι διαχειριστές βλέπουν τα «Ανά ομάδα μαθήματος» και «Ανά τμήμα» στην «Προβολή καθημερινών παρουσιών». Τα δικαιώματα στον ιστότοπο δεν αλλάζουν.',
          'Τα «Ανά ομάδα μαθήματος» και «Ανά τμήμα» στην «Προβολή καθημερινών παρουσιών» ακολουθούν τα δικαιώματα παρουσιών κάθε ρόλου, όπως στον ιστότοπο.'],
'es_DO': ['Otros registros solo para administradores',
          'Solo los administradores ven «Por clase» y «Por sección» en «Visualizar asistencia diaria». Los permisos del sitio web no cambian.',
          '«Por clase» y «Por sección» en «Visualizar asistencia diaria» siguen los permisos de asistencia de cada rol, como en el sitio web.'],
'es_ES': ['Otros registros solo para administradores',
          'Solo los administradores ven «Por Clase» y «Por curso» en «Ver Asistencia Diaria». Los permisos de la web no cambian.',
          '«Por Clase» y «Por curso» en «Ver Asistencia Diaria» siguen los permisos de asistencia de cada rol, como en la web.'],
'es_MX': ['Otros registros solo para administradores',
          'Solo los administradores ven «Por Clase» y «Por Grupo» en «Ver Asistencia Diaria». Los permisos del sitio web no cambian.',
          '«Por Clase» y «Por Grupo» en «Ver Asistencia Diaria» siguen los permisos de asistencia de cada rol, como en el sitio web.'],
'fr_FR': ['Autres appels réservés aux administrateurs',
          'Seuls les administrateurs voient « Par cours » et « Par classe » dans « Voir la fiche de présences journalières ». Les autorisations du site ne changent pas.',
          '« Par cours » et « Par classe » dans « Voir la fiche de présences journalières » suivent les autorisations de présence de chaque rôle, comme sur le site.'],
'he_IL': ['רשימות נוכחות אחרות למנהלי מערכת בלבד',
          'רק מנהלי מערכת רואים את "לפי קבוצת לימוד" ו"לפי כיתת אם" ב"הצג נוכחות יומית". ההרשאות באתר אינן משתנות.',
          '"לפי קבוצת לימוד" ו"לפי כיתת אם" ב"הצג נוכחות יומית" פועלים לפי הרשאות הנוכחות של כל תפקיד, כמו באתר.'],
'hr_HR': ['Ostale evidencije samo za administratore',
          'Samo administratori vide „Po grupi” i „Po razrednom odjelu” u „Pregled dnevnih prisutnosti”. Dopuštenja na web-stranici se ne mijenjaju.',
          '„Po grupi” i „Po razrednom odjelu” u „Pregled dnevnih prisutnosti” prate dopuštenja za prisutnost svake uloge, kao na web-stranici.'],
'it_IT': ['Altri registri solo per gli amministratori',
          'Solo gli amministratori vedono «Per Classe» e «Per Gruppo Classe» in «Visualizza la Frequenza Giornaliera». Le autorizzazioni sul sito non cambiano.',
          '«Per Classe» e «Per Gruppo Classe» in «Visualizza la Frequenza Giornaliera» seguono le autorizzazioni sulla frequenza di ogni ruolo, come sul sito.'],
'ja_JP': ['その他の出欠を管理者のみに表示',
          '「デイリーアテンダンスを見る」の「クラス別」と「ホームルーム別」は管理者だけに表示されます。ウェブサイトの権限は変わりません。',
          '「デイリーアテンダンスを見る」の「クラス別」と「ホームルーム別」は、ウェブサイトと同じく各ロールの出欠の権限に従います。'],
'lt_LT': ['Kiti žiniaraščiai tik administratoriams',
          'Tik administratoriai mato „Pagal grupę“ ir „Pagal klasę“ skiltyje „Peržiūrėti dienos lankomumą“. Leidimai svetainėje nesikeičia.',
          '„Pagal grupę“ ir „Pagal klasę“ skiltyje „Peržiūrėti dienos lankomumą“ atitinka kiekvieno vaidmens lankomumo leidimus, kaip svetainėje.'],
'nl_NL': ['Andere registers alleen voor beheerders',
          'Alleen beheerders zien "Per lesgroep" en "Per klas" in "Bekijk dagelijkse aanwezigheid". De rechten op de website veranderen niet.',
          '"Per lesgroep" en "Per klas" in "Bekijk dagelijkse aanwezigheid" volgen de aanwezigheidsrechten van elke rol, zoals op de website.'],
'pl_PL': ['Inne listy obecności tylko dla administratorów',
          'Tylko administratorzy widzą „Według zajęć” i „Według klasy” w „Zobacz codzienne uczestnictwo”. Uprawnienia w serwisie się nie zmieniają.',
          '„Według zajęć” i „Według klasy” w „Zobacz codzienne uczestnictwo” są zgodne z uprawnieniami do obecności każdej roli, tak jak w serwisie.'],
'pt_BR': ['Outras chamadas somente para administradores',
          'Somente administradores veem "Por Classe" e "Por Turma" em "Visualizar Frequência Diária". As permissões no site não mudam.',
          '"Por Classe" e "Por Turma" em "Visualizar Frequência Diária" seguem as permissões de frequência de cada função, como no site.'],
'pt_PT': ['Outros registos apenas para administradores',
          'Apenas os administradores veem "Por Aula" e "Por Turma" em "Visualizar Assiduidade Diária". As permissões no site não mudam.',
          '"Por Aula" e "Por Turma" em "Visualizar Assiduidade Diária" seguem as permissões de assiduidade de cada função, como no site.'],
'ro_RO': ['Alte cataloage doar pentru administratori',
          'Doar administratorii văd „După grupă” și „După clasă” în „Vizualizare prezență zilnică”. Permisiunile de pe site nu se schimbă.',
          '„După grupă” și „După clasă” în „Vizualizare prezență zilnică” urmează permisiunile de prezență ale fiecărui rol, ca pe site.'],
'sq_AL': ['Regjistra të tjerë vetëm për administratorët',
          'Vetëm administratorët shohin "Sipas grupit" dhe "Sipas klasës" te "Shiko frekuentimin ditor". Lejet në faqen e internetit nuk ndryshojnë.',
          '"Sipas grupit" dhe "Sipas klasës" te "Shiko frekuentimin ditor" ndjekin lejet e frekuentimit të çdo roli, si në faqen e internetit.'],
'th_TH': ['การเช็คชื่ออื่นๆ เฉพาะผู้ดูแลระบบ',
          'เฉพาะผู้ดูแลระบบเท่านั้นที่เห็น "ตามกลุ่มเรียน" และ "ตามห้องประจำชั้น" ใน "ดูการเข้าร่วมรายวัน" สิทธิ์บนเว็บไซต์ไม่เปลี่ยนแปลง',
          '"ตามกลุ่มเรียน" และ "ตามห้องประจำชั้น" ใน "ดูการเข้าร่วมรายวัน" เป็นไปตามสิทธิ์การเช็คชื่อของแต่ละบทบาท เหมือนบนเว็บไซต์'],
'tr_TR': ['Diğer yoklamalar yalnızca yöneticiler için',
          '"Günlük Yoklama Görüntüleme" içindeki "Sınıfa Göre" ve "Şubeye Göre" seçeneklerini yalnızca yöneticiler görür. Web sitesindeki izinler değişmez.',
          '"Günlük Yoklama Görüntüleme" içindeki "Sınıfa Göre" ve "Şubeye Göre", web sitesinde olduğu gibi her rolün yoklama izinlerine uyar.'],
'uk_UA': ['Інші журнали лише для адміністраторів',
          'Лише адміністратори бачать «За заняттями» та «За класом» у «Переглянути щоденне відвідування». Дозволи на сайті не змінюються.',
          '«За заняттями» та «За класом» у «Переглянути щоденне відвідування» відповідають дозволам на відвідування кожної ролі, як на сайті.'],
'ur_PK': ['دیگر رجسٹر صرف منتظمین کے لیے',
          '"روزانہ حاضری دیکھیں" میں "کلاس کے لحاظ سے" اور "سیکشن کے لحاظ سے" صرف منتظمین کو نظر آتے ہیں۔ ویب سائٹ پر اجازتیں تبدیل نہیں ہوتیں۔',
          '"روزانہ حاضری دیکھیں" میں "کلاس کے لحاظ سے" اور "سیکشن کے لحاظ سے" ویب سائٹ کی طرح ہر کردار کی حاضری کی اجازتوں کے مطابق ہوتے ہیں۔'],
'vi_VN': ['Sổ điểm danh khác chỉ dành cho quản trị viên',
          'Chỉ quản trị viên mới thấy "Theo lớp học" và "Theo lớp chủ nhiệm" trong "Xem Người Tham Gia Hàng Ngày". Quyền trên trang web không thay đổi.',
          '"Theo lớp học" và "Theo lớp chủ nhiệm" trong "Xem Người Tham Gia Hàng Ngày" tuân theo quyền điểm danh của từng vai trò, như trên trang web.'],
'zh_CN': ['其他考勤仅限管理员',
          '只有管理员能在“查看每日考勤”中看到“按教学班”和“按班级”。网站上的权限不变。',
          '“查看每日考勤”中的“按教学班”和“按班级”与网站一样，按各角色的考勤权限显示。'],
'zh_HK': ['其他點名只限管理員',
          '只有管理員能在「查看每日出席」中看到「按科目班」和「按班別」。網站上的權限不變。',
          '「查看每日出席」中的「按科目班」和「按班別」與網站一樣，按各角色的出席權限顯示。'],
}
# "Attendance By Class" (the By Class screen's title): Gibbon has none in these.
KEYS3 = ['Attendance By Class']
D3 = {'ro_RO': ['Prezență după grupă'], 'ur_PK': ['کلاس کے لحاظ سے حاضری'], 'zh_HK': ['按科目班點名']}

if __name__ == '__main__':
    for L in sorted(set(D) | set(D2) | set(D3)):
        pairs = list(zip(KEYS, D.get(L, []))) + list(zip(KEYS2, D2.get(L, []))) + list(zip(KEYS3, D3.get(L, [])))
        assert L not in D or len(D[L]) == len(KEYS), L
        assert L not in D2 or len(D2[L]) == len(KEYS2), L
        src = open(f'{L}.py', encoding='utf-8').read()
        b = {}; exec(src, b)
        T = dict(b['T']); T.update(pairs)
        src, n = re.subn(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
        assert n == 1, L
        open(f'{L}.py', 'w', encoding='utf-8').write(src)
        c = {}; exec(src, c)
        assert c['T'] == T and c['P'] == b['P'], L
        print(L, 'ok')
