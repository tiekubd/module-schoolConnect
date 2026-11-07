# Builds es_DO.py from es_MX.py: Dominican Spanish, following Gibbon's es_DO file.
#   Sentence case (Gibbon's es_DO is mostly "Tomar asistencia", "Perfil del estudiante").
#   Form Group -> sección, Year Group -> grado, report card -> boletín (as MINERD),
#   Late -> retraso; otherwise as es_MX (tareas, período, escuela, agregar, video).
import sys, re, pprint
sys.path.insert(0, '.')
import es_MX as MX

EXACT = {
    'Adjusts arrangement of courses in reports.': 'Ajusta el orden de las asignaturas en los boletines.',
    'All Year Groups': 'Todos los grados',
    'Choose any Form Group': 'Elegir cualquier sección',
    'Choose one of your Form Groups': 'Elegir una de sus secciones',
    'Could not download report': 'No se pudo descargar el boletín',
    'Could not load Form Groups': 'No se pudieron cargar las secciones',
    'Could not load report': 'No se pudo cargar el boletín',
    'Could not load reports': 'No se pudieron cargar los boletines',
    'Enrolable Year Groups': 'Grados con matrícula abierta',
    'Filter by Year Group': 'Filtrar por grado',
    'Form Group *': 'Sección *',
    'My Form Groups': 'Mis secciones',
    'No Form Groups found': 'No se encontraron secciones',
    'No reports available yet': 'Aún no hay boletines disponibles',
    "Non-reportable participants won't display in reports.": 'Los participantes no incluidos en los boletines no aparecerán en ellos.',
    'Please select a Year Group and Form Group': 'Seleccione un grado y una sección',
    'Report-card PDFs, only while you view or share them': 'Los boletines en PDF, solo mientras los ve o los comparte',
    "School Connect shows information from your school's own Gibbon system: timetables, attendance, grades, homework, reports, messages and your family's details. The app connects directly and only to your school's Gibbon server over an encrypted (HTTPS) connection. High Point Education does not receive, store or see this information.": 'School Connect muestra información del propio sistema Gibbon de su escuela: horarios, asistencia, calificaciones, tareas, boletines, mensajes y los datos de su familia. La app se conecta directa y únicamente al servidor Gibbon de su escuela mediante una conexión cifrada (HTTPS). High Point Education no recibe, almacena ni ve esta información.',
    'Signing out removes the sign-in token, your username and any downloaded report cards. Uninstalling the app removes everything else.': 'Al cerrar sesión se eliminan el token de inicio de sesión, su nombre de usuario y los boletines descargados. Al desinstalar la app se elimina todo lo demás.',
    'View and manage Form Groups': 'Ver y administrar secciones',
    'View your report cards': 'Ver sus boletines',
    'Year Group *': 'Grado *',
    "Year Group / Form Group targeting isn't set here — this will apply broadly until finished on the website.": 'La segmentación por grado o sección no se configura aquí — se aplicará a todos hasta que se complete en el sitio web.',
}
EXACT_P = {'{count} late': ('{count} con retraso', '{count} con retraso')}

# Words that keep their capital inside a sentence-case label.
KEEP = {'Gibbon', 'School', 'Connect', 'Google', 'YouTube', 'High', 'Point', 'Education', 'Jitsi', 'Meet',
        'Android', 'Keystore', 'Llavero', 'Apple', 'HTTPS', 'PDF', 'IP', 'ID', 'SMS', 'iOS', 'Gmail', 'Microsoft'}
def sentence(s):
    out, start = [], True
    for w in s.split(' '):
        core = w.lstrip('¿¡("«')
        if (not start and core[:1].isupper() and core.rstrip('.,:;!?)»*') not in KEEP
                and not (len(core) > 1 and core[1:2].isupper())):
            w = w[:len(w) - len(core)] + core[0].lower() + core[1:]
        out.append(w)
        start = bool(re.search(r'[.!?:>—]$', w)) or w in ('-', '+', '·', '|', '/')
    return ' '.join(out)

def is_label(en):
    # Title-case English labels only ('View Homework Calendar'); sentences keep their capitals.
    if re.search(r'[.!?]$', en.strip()) or '{' in en or '...' in en: return False
    small = {'a', 'an', 'the', 'of', 'in', 'at', 'by', 'to', 'for', 'and', 'or', 'as', 'on', '&'}
    words = [w for w in re.findall(r"[A-Za-z][\w']*", en) if w not in small]
    return bool(words) and all(w[0].isupper() for w in words)
lab = lambda en, v: sentence(v) if is_label(en) else v

T = {k: EXACT.get(k, lab(k, v)) for k, v in MX.T.items()}
P = {k: EXACT_P.get(k, tuple(lab(k, f) for f in v)) for k, v in MX.P.items()}
EXTRA = {k: sentence(v) for k, v in MX.EXTRA.items()}
EXTRA['Present - Late'] = 'Presente - Retrasado'

# Gibbon core strings the app uses that Gibbon's es_DO file lacks.
GAPS = {k: lab(k, MX.GAPS[k]) for k in [
    'Cancel Activities?', 'Class should not run below this number of students.', 'Due on {date} at {time}.',
    'Enrolment should not exceed this number of students.', 'Exclude from coverage?', 'Impersonate User',
    'Last Login: IP', 'Last Login: Time', 'Layers', 'Maximum Enrolment', 'Minimum Enrolment', 'Not Reportable',
    'Off Timetable', 'Over Enrolled', 'There are no lessons for this class.', 'There are no lessons on this date.',
    'This student has one or more {level} risk medical conditions.', 'Under Enrolled',
    'Your active session will be switched to the selected user.']}
GAPS['Under Enrolled'] = 'Bajo cupo'

# Corrections to Gibbon's es_DO wording now live in es_DO_final.py (7 Nov 2026 review); es_DO_2nd.py
# clears this block after generation, and split_final.py es_DO must run after this script.
OVERRIDES = {
    'Teacher': 'Profesor/a',                                  # Gibbon has it empty (only 'Profesores')
    'Due at {time} on {date}': 'Vence a las {time} del {date}',  # Gibbon: 'Vencimiento en {time} en {date}'
    'Due Soon': 'Vence pronto',                               # Gibbon: 'Próximamente' (= coming soon)
    'My Form Group': 'Mi sección',                            # Gibbon: 'Mi curso', though Form Group is 'Sección'
    'Select Students': 'Seleccionar estudiantes',             # Gibbon: 'estudantes'
    'First Aid Qualification': 'Certificación de primeros auxilios',  # Gibbon: 'Calificación' (= a mark)
    'Homework + Due Dates': 'Tareas + vencimientos',          # Gibbon: 'Tarea + Plazos'
    'View Planner': 'Ver planificador',                       # Gibbon: 'Visualizar …', where its own 'View' is 'Ver'
    'View Invoices': 'Ver facturas',
    'View Reports': 'Ver informes',
}

leftover = [v for v in list(T.values()) + [f for v in P.values() for f in v]
            if re.search(r'\bcursos?\b|\bnivel(es)?\b|boleta', v, re.I)]
assert not leftover, leftover

with open('es_DO.py', 'w', encoding='utf-8') as f:
    f.write('# Dominican Spanish (es_DO) draft, generated from es_MX.py by make_es_DO.py:\n'
            '# sentence case as in Gibbon es_DO; sección, grado, boletín, retraso. Review before release.\n\n')
    for name, d in (('T', T), ('P', P), ('EXTRA', EXTRA), ('GAPS', GAPS), ('OVERRIDES', OVERRIDES)):
        f.write(f'{name} = ' + pprint.pformat(d, width=200, sort_dicts=True) + '\n\n')
changed = [(k, MX.T[k], T[k]) for k in T if T[k] != MX.T[k] and k not in EXACT]
print('written', len(T), len(P), len(GAPS), '| sentence-cased', len(changed))
for k, a, b in changed: print(f'  {a!r} -> {b!r}')
