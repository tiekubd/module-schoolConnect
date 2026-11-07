# Database values the app passes to tr() as variables, so they never appeared in the extracted
# string list: default attendance code names (legend), day names (master timetable), role names,
# class-enrolment roles (roster), user status filters, and module names (notifications).
# The list is in dyn.py. Module names are left as Gibbon has them (they match the website's menu)
# unless clearly wrong.
# build_po.py files each one as a GAP (Gibbon has none) or an OVERRIDE (Gibbon's is wrong or
# breaks the "Present - Late" pattern). Present - Late / Present - Offsite live in each LANG.py EXTRA.
CODES = {
    'ar_SA': {'Left - Early': 'غادر - مبكرًا'},                       # Gibbon: مستأذن - مبكر (excused - early)
    'de_DE': {'Left': 'Gegangen'},                                     # Gibbon: Verlassen (abandoned)
    'fr_FR': {'Left': 'Parti'},                                        # Gibbon: Quitté; matches "Parti - Plus tôt"
    'it_IT': {'Left': 'Uscito', 'Left - Early': 'Uscito - In Anticipo'},   # Gibbon: Manca (missing); untranslated
    'ja_JP': {'Present': '出席', 'Left': '退出', 'Left - Early': '退出 - 早退'},  # Gibbon: プレゼント, 左, 左 - 初期
    'pt_BR': {'Left': 'Saiu'},                                         # Gibbon: Afastado (on leave)
    'pt_PT': {'Present': 'Presente', 'Absent': 'Ausente', 'Left': 'Saiu', 'Left - Early': 'Saiu mais cedo'},  # Gibbon: none
    'tr_TR': {'Left - Early': 'Ayrıldı - Erken'},                      # Gibbon: Erken ayrılmak (to leave early)
    'zh_HK': {'Present': '出席', 'Left': '離校', 'Left - Early': '早退'},  # Gibbon: 到, 離開, 離開-早
}

DB = {
    'fr_FR': {'Expected': 'Attendu',                      # Gibbon: Requis (required)
              'Rubrics': "Grilles d'évaluation"},          # Gibbon: Rubriques (headings)
    'ar_SA': {'Student - Left': 'طالب - مغادر', 'Teacher - Left': 'معلم - مغادر'},   # spacing
    'es_ES': {'Wed': 'Mié', 'User Admin': 'Administrar Usuarios'},
    'es_MX': {'Wed': 'Mié', 'User Admin': 'Administración de Usuarios'},
    'es_DO': {'Wed': 'Mié', 'User Admin': 'Administrar usuarios'},
    'pt_BR': {'Student - Left': 'Aluno - Saiu', 'Teacher - Left': 'Professor - Saiu',   # Gibbon: Afastado (on leave)
              'Expected': 'Previsto',                      # Gibbon: Candidato (applicant)
              'Finance': 'Finanças', 'System Admin': 'Administrador do Sistema'},       # Gibbon: Finança; "Administador"
    'pt_PT': {'Student - Left': 'Estudante - Saiu', 'Teacher - Left': 'Professor - Saiu', 'Full': 'Ativo',
              'Admissions': 'Admissões', 'Finance': 'Finanças', 'Messenger': 'Envio de Mensagens',
              'User Admin': 'Administração de Utilizadores'},                           # Gibbon: "de Users"
    'de_DE': {'Student - Left': 'Schüler/in - Gegangen', 'Teacher - Left': 'Lehrer/in - Gegangen'},   # Gibbon: Verlassen
    'it_IT': {'Parent': 'Genitore',                        # Gibbon: Parente (a relative)
              'Support Staff': 'Personale di Supporto',
              'Student - Left': 'Studente - Uscito', 'Teacher - Left': 'Insegnante - Uscito',
              'Monday': 'Lunedì', 'Tuesday': 'Martedì', 'Wednesday': 'Mercoledì', 'Thursday': 'Giovedì', 'Friday': 'Venerdì',
              'Finance': 'Finanze'},
    'zh_HK': {'Sun': '星期日', 'Student - Left': '學生 - 已離校', 'Teacher - Left': '老師 - 已離校',   # Gibbon: 離開了, 走了
              'Full': '正式', 'School Admin': '學校管理', 'Tracking': '追蹤'},
    'zh_CN': {'Support Staff': '后勤人员'},                # Gibbon: 技术支持人员 (tech support)
    'ja_JP': {'Mon': '月', 'Tue': '火', 'Fri': '金', 'Sun': '日',   # Gibbon: Mon, 火曜日, 金曜日, 太陽 (the sun)
              'Student - Left': '生徒 - 退出', 'Teacher - Left': '先生 - 退出',      # Gibbon: 学生 - 左
              'Full': '在籍', 'Expected': '入学予定',          # Gibbon: フル, 期待 (hope)
              'Technician': '技術職員', 'Finance': '財務', 'Admissions': '入学手続き',
              'Timetable Admin': '時間割管理'},
}
for _l, _d in DB.items():
    CODES.setdefault(_l, {}).update(_d)
