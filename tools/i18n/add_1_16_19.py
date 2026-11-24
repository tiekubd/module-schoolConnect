# One-off (24 Nov 2026): strings added in app 1.16.19 (register status and Gibbon's
# pre-fill on both attendance registers), for module 1.16.19. App-only strings.
import pprint, re

KEYS = [
    'Set in advance',
    'From a class register',
    'From the form group register',
    'Recorded earlier today',
    'Taken at {time} by {name}',
    'Not taken yet for this date. These are default entries, not saved attendance.',
]

D = {
'af_ZA': ['Vooraf ingestel', 'Uit ’n klasregister', 'Uit die registerklas se register', 'Vroeër vandag aangeteken',
          'Geneem om {time} deur {name}', 'Nog nie vir hierdie datum geneem nie. Dit is standaardinskrywings, nie gestoorde bywoning nie.'],
'ar_SA': ['مسجَّل مسبقًا', 'من سجل حضور شعبة', 'من سجل حضور الصف الدراسي', 'سُجِّل في وقت سابق اليوم',
          'أُخذ الحضور الساعة {time} بواسطة {name}', 'لم يُؤخذ الحضور لهذا التاريخ بعد. هذه قيم افتراضية وليست حضورًا محفوظًا.'],
'ca_ES': ['Establert per endavant', 'D’un registre de classe', 'Del registre del grup classe', 'Registrat abans, avui',
          'Passada a les {time} per {name}', 'Encara no s’ha passat llista en aquesta data. Són valors per defecte, no assistència desada.'],
'de_DE': ['Im Voraus eingetragen', 'Aus einer Lerngruppen-Anwesenheit', 'Aus der Klassen-Anwesenheit', 'Heute schon erfasst',
          'Erfasst um {time} von {name}', 'Für dieses Datum noch nicht erfasst. Das sind Voreinstellungen, keine gespeicherte Anwesenheit.'],
'el_GR': ['Ορίστηκε εκ των προτέρων', 'Από παρουσίες ομάδας μαθήματος', 'Από τις παρουσίες του τμήματος', 'Καταχωρίστηκε νωρίτερα σήμερα',
          'Καταχωρίστηκε στις {time} από {name}', 'Δεν έχουν ληφθεί ακόμη παρουσίες για αυτή την ημερομηνία. Αυτές είναι προεπιλογές, όχι αποθηκευμένες παρουσίες.'],
'es_DO': ['Registrado por adelantado', 'De la asistencia de una clase', 'De la asistencia de la sección', 'Registrado hoy más temprano',
          'Tomada a las {time} por {name}', 'Aún no se ha tomado la asistencia de esta fecha. Son valores predeterminados, no asistencia guardada.'],
'es_ES': ['Registrado con antelación', 'De la asistencia de una clase', 'De la asistencia del curso', 'Registrado hoy más temprano',
          'Tomada a las {time} por {name}', 'Aún no se ha tomado la asistencia de esta fecha. Son valores predeterminados, no asistencia guardada.'],
'es_MX': ['Registrado con anticipación', 'De la asistencia de una clase', 'De la asistencia del grupo', 'Registrado hoy más temprano',
          'Tomada a las {time} por {name}', 'Aún no se ha tomado la asistencia de esta fecha. Son valores predeterminados, no asistencia guardada.'],
'fr_FR': ['Saisi à l’avance', 'D’un appel de cours', 'De l’appel de la classe', 'Saisi plus tôt aujourd’hui',
          'Appel fait à {time} par {name}', 'L’appel n’a pas encore été fait pour cette date. Ce sont des valeurs par défaut, pas des présences enregistrées.'],
'he_IL': ['נקבע מראש', 'מנוכחות של קבוצת לימוד', 'מנוכחות כיתת האם', 'נרשם מוקדם יותר היום',
          'נלקחה ב-{time} על ידי {name}', 'הנוכחות לתאריך זה עדיין לא נלקחה. אלה ערכי ברירת מחדל ולא נוכחות שמורה.'],
'hr_HR': ['Uneseno unaprijed', 'Iz evidencije grupe', 'Iz evidencije razrednog odjela', 'Zabilježeno ranije danas',
          'Evidentirano u {time}, {name}', 'Prisutnost za ovaj datum još nije evidentirana. Ovo su zadane vrijednosti, a ne spremljena prisutnost.'],
'it_IT': ['Inserito in anticipo', 'Da un appello di classe', 'Dall’appello del gruppo classe', 'Registrato oggi in precedenza',
          'Rilevate alle {time} da {name}', 'Le presenze per questa data non sono ancora state rilevate. Sono valori predefiniti, non presenze salvate.'],
'ja_JP': ['事前に登録済み', 'クラスの出欠から', 'ホームルームの出欠から', '今日すでに記録済み',
          '{time} に {name} が記録', 'この日の出欠はまだ取られていません。表示は初期値で、保存された出欠ではありません。'],
'lt_LT': ['Nustatyta iš anksto', 'Iš grupės lankomumo', 'Iš klasės lankomumo', 'Pažymėta anksčiau šiandien',
          'Pažymėta {time}, {name}', 'Šiai datai lankomumas dar nepažymėtas. Tai numatytosios reikšmės, o ne išsaugotas lankomumas.'],
'nl_NL': ['Vooraf ingevoerd', 'Uit een lesgroepregistratie', 'Uit de klasregistratie', 'Eerder vandaag geregistreerd',
          'Geregistreerd om {time} door {name}', 'Voor deze datum nog niet geregistreerd. Dit zijn standaardwaarden, geen opgeslagen aanwezigheid.'],
'pl_PL': ['Wpisane z wyprzedzeniem', 'Z obecności na zajęciach', 'Z obecności klasy', 'Zapisane wcześniej dzisiaj',
          'Sprawdzono o {time}, {name}', 'Obecność na ten dzień nie została jeszcze sprawdzona. To wartości domyślne, a nie zapisana frekwencja.'],
'pt_BR': ['Registrado com antecedência', 'Da chamada de uma classe', 'Da chamada da turma', 'Registrado mais cedo hoje',
          'Chamada feita às {time} por {name}', 'A chamada desta data ainda não foi feita. Estes são valores padrão, não frequência salva.'],
'pt_PT': ['Registado com antecedência', 'Das presenças de uma aula', 'Das presenças da turma', 'Registado hoje mais cedo',
          'Marcadas às {time} por {name}', 'As presenças desta data ainda não foram marcadas. São valores predefinidos, não assiduidade guardada.'],
'ro_RO': ['Introdus în avans', 'Din prezența unei grupe', 'Din prezența clasei', 'Înregistrat mai devreme azi',
          'Luată la {time} de {name}', 'Prezența pentru această dată nu a fost luată încă. Acestea sunt valori implicite, nu prezență salvată.'],
'sq_AL': ['Vendosur paraprakisht', 'Nga frekuentimi i një grupi', 'Nga frekuentimi i klasës', 'Regjistruar më herët sot',
          'Marrë në {time} nga {name}', 'Frekuentimi për këtë datë nuk është marrë ende. Këto janë vlera të paracaktuara, jo frekuentim i ruajtur.'],
'th_TH': ['บันทึกไว้ล่วงหน้า', 'จากการเช็คชื่อของกลุ่มเรียน', 'จากการเช็คชื่อของห้องประจำชั้น', 'บันทึกไว้แล้ววันนี้',
          'เช็คชื่อเวลา {time} โดย {name}', 'ยังไม่ได้เช็คชื่อสำหรับวันที่นี้ ค่าที่แสดงเป็นค่าเริ่มต้น ไม่ใช่การมาเรียนที่บันทึกแล้ว'],
'tr_TR': ['Önceden girildi', 'Bir sınıfın yoklamasından', 'Şube yoklamasından', 'Bugün daha önce girildi',
          'Yoklama {time} saatinde {name} tarafından alındı', 'Bu tarih için henüz yoklama alınmadı. Bunlar varsayılan değerler, kaydedilmiş yoklama değil.'],
'uk_UA': ['Внесено заздалегідь', 'Із відвідуваності заняття', 'Із відвідуваності класу', 'Відмічено раніше сьогодні',
          'Відмічено о {time}, {name}', 'Відвідуваність за цю дату ще не відмічено. Це типові значення, а не збережена відвідуваність.'],
'ur_PK': ['پہلے سے درج', 'کسی کلاس کی حاضری سے', 'سیکشن کی حاضری سے', 'آج پہلے درج کی گئی',
          'حاضری {time} بجے {name} نے لگائی', 'اس تاریخ کی حاضری ابھی نہیں لگی۔ یہ طے شدہ اندراجات ہیں، محفوظ حاضری نہیں۔'],
'vi_VN': ['Đã đặt trước', 'Từ điểm danh lớp học', 'Từ điểm danh lớp chủ nhiệm', 'Đã ghi nhận trước đó hôm nay',
          'Điểm danh lúc {time} bởi {name}', 'Chưa điểm danh cho ngày này. Đây là giá trị mặc định, không phải điểm danh đã lưu.'],
'zh_CN': ['已提前登记', '来自教学班考勤', '来自班级考勤', '今天早些时候已记录',
          '{name} 于 {time} 记录', '该日期尚未记录考勤。以下为默认值，并非已保存的考勤。'],
'zh_HK': ['已預先登記', '來自科目班點名', '來自班別點名', '今天較早時已記錄',
          '{name} 於 {time} 點名', '該日期尚未點名。以下為預設值，並非已儲存的出席紀錄。'],
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
