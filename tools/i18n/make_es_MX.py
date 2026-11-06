# Builds es_MX.py from es_ES.py: Mexican Spanish, following Gibbon's es_MX vocabulary.
#   Homework -> Tareas, Term -> Período, Late -> Atrasado, school -> escuela,
#   Add -> Agregar, Manage -> Administrar, report card -> boleta, video (no accent),
#   rellenar -> llenar, contactar con -> contactar a. Form Group stays Curso,
#   Year Group Nivel, as in Gibbon's es_MX. Formal "usted", Gibbon-style title case.
# Sentences whose grammar changes are rewritten by hand (EXACT); simple swaps by regex.
import sys, re, pprint
sys.path.insert(0, '.')
import es_ES as ES

EXACT = {
    'Add homework?': '¿Agregar tarea?',
    'Adjusts arrangement of courses in reports.': 'Ajusta el orden de las asignaturas en las boletas.',
    'App version, connected school and your data': 'Versión de la app, escuela conectada y sus datos',
    'Connected school': 'Escuela conectada',
    'Could not download report': 'No se pudo descargar la boleta',
    'Could not load homework': 'No se pudieron cargar las tareas',
    'Could not load homework calendar': 'No se pudo cargar el calendario de tareas',
    'Could not load report': 'No se pudo cargar la boleta',
    'Could not load reports': 'No se pudieron cargar las boletas',
    'Could not load terms': 'No se pudieron cargar los períodos',
    'Google Sign-In, only if your school uses it and you choose it': 'Inicio de sesión con Google, solo si su escuela lo usa y usted lo elige',
    'Homework Calendar': 'Calendario de Tareas',
    'Homework due': 'Tareas por entregar',
    'Homework removed.': 'Tarea eliminada.',
    'Homework saved.': 'Tarea guardada.',
    'Impersonate User needs School Connect module 1.16.09 or later on the school server.': 'Suplantar usuario requiere el módulo School Connect 1.16.09 o posterior en el servidor de la escuela.',
    'Manage users and school settings': 'Administrar usuarios y configuración de la escuela',
    'No data for this term': 'No hay datos para este período',
    'No homework recorded.': 'No hay tareas registradas.',
    'No reports available yet': 'Aún no hay boletas disponibles',
    'No terms found for this school year': 'No se encontraron períodos para este año escolar',
    "Non-reportable participants won't display in reports.": 'Los participantes no incluidos en las boletas no aparecerán en ellas.',
    "Once a day, your school's server tells High Point only its own web address, to confirm the school's subscription. No personal information is sent.": 'Una vez al día, el servidor de su escuela le comunica a High Point solo su propia dirección web, para confirmar la suscripción de la escuela. No se envía ninguna información personal.',
    'Report-card PDFs, only while you view or share them': 'Las boletas en PDF, solo mientras las ve o las comparte',
    'School Connect module on the school server': 'Módulo School Connect en el servidor de la escuela',
    "School Connect shows information from your school's own Gibbon system: timetables, attendance, grades, homework, reports, messages and your family's details. The app connects directly and only to your school's Gibbon server over an encrypted (HTTPS) connection. High Point Education does not receive, store or see this information.": 'School Connect muestra información del propio sistema Gibbon de su escuela: horarios, asistencia, calificaciones, tareas, boletas, mensajes y los datos de su familia. La app se conecta directa y únicamente al servidor Gibbon de su escuela mediante una conexión cifrada (HTTPS). High Point Education no recibe, almacena ni ve esta información.',
    'Signing out removes the sign-in token, your username and any downloaded report cards. Uninstalling the app removes everything else.': 'Al cerrar sesión se eliminan el token de inicio de sesión, su nombre de usuario y las boletas descargadas. Al desinstalar la app se elimina todo lo demás.',
    'The order in which the school should contact family members.': 'El orden en que la escuela debe contactar a los miembros de la familia.',
    "Video lessons: virtual classes use your school's chosen video service (by default the public Jitsi Meet service)": 'Clases por video: las clases virtuales usan el servicio de video elegido por su escuela (por defecto, el servicio público Jitsi Meet)',
    'View Homework Calendar': 'Ver Calendario de Tareas',
    'View your report cards': 'Ver sus boletas',
    'You are viewing school year {year}, which is not the current year. Data may not look quite right (for example, students who have left the school will not appear in previous years).': 'Está viendo el año escolar {year}, que no es el año actual. Puede que algunos datos no se vean correctos (por ejemplo, los estudiantes que han dejado la escuela no aparecerán en años anteriores).',
    'You can use this section to record your own homework.': 'Puede usar esta sección para registrar sus propias tareas.',
    'Your data stays with your school': 'Sus datos se quedan en su escuela',
    "Your last username and the last three school addresses you used, so you don't have to retype them": 'Su último nombre de usuario y las tres últimas direcciones de escuela que usó, para no tener que volver a escribirlas',
    "Your school is responsible for your personal data in Gibbon. To see, correct or delete it, contact your school's data protection officer. For questions about the app itself, contact {email}.": 'Su escuela es responsable de sus datos personales en Gibbon. Para consultarlos, corregirlos o eliminarlos, contacte al responsable de protección de datos de su escuela. Para preguntas sobre la propia app, escriba a {email}.',
}
EXACT_P = {
    '{count} school day in term': ('{count} día lectivo en el período', '{count} días lectivos en el período'),
    '{count} late': ('{count} atrasado', '{count} atrasados'),
}

SWAPS = [
    (r'\bAñadir\b', 'Agregar'), (r'\bañadir\b', 'agregar'), (r'\bañade\b', 'agrega'),
    (r'\bGestionar\b', 'Administrar'), (r'\bgestionar\b', 'administrar'),
    (r'\bvídeo', 'video'), (r'\bVídeo', 'Video'),
    (r'\bRellene\b', 'Llene'), (r'\brellenarse\b', 'llenarse'),
    (r'\bcontactar con\b', 'contactar a'), (r'\bcontacte con\b', 'contacte a'),
]
def swap(s):
    for a, b in SWAPS:
        s = re.sub(a, b, s)
    return s

T = {k: EXACT.get(k, swap(v)) for k, v in ES.T.items()}
P = {k: EXACT_P.get(k, tuple(swap(f) for f in v)) for k, v in ES.P.items()}
EXTRA = {'Present - Late': 'Presente - Atrasado', 'Present - Offsite': 'Presente - Fuera de la escuela'}
for k, v in ES.EXTRA.items():
    EXTRA.setdefault(k, swap(v))

# Gibbon core strings the app uses that Gibbon's es_MX file lacks (or has only as fuzzy).
GAPS = {
    'Apply': 'Aplicar',
    'By Form Group': 'Por Curso',
    'Cancel Activities?': '¿Cancelar actividades?',
    'Class should not run below this number of students.': 'La clase no debe impartirse con menos de este número de estudiantes.',
    'Course': 'Asignatura',
    'Due Soon': 'Vence pronto',
    'Due at {time} on {date}': 'Vence a las {time} del {date}',
    'Due on {date} at {time}.': 'Vence el {date} a las {time}.',
    'Enrolment should not exceed this number of students.': 'La matrícula no debe superar este número de estudiantes.',
    'Exclude from coverage?': '¿Excluir de la cobertura?',
    'First Aid Qualification': 'Certificación de Primeros Auxilios',
    'Homework + Due Dates': 'Tareas + Vencimientos',
    'Impersonate User': 'Suplantar Usuario',
    'Last Login: IP': 'Último inicio de sesión: IP',
    'Last Login: Time': 'Último inicio de sesión: Hora',
    'Layers': 'Capas',
    'Maximum Enrolment': 'Matrícula Máxima',
    'Minimum Enrolment': 'Matrícula Mínima',
    'My Form Group': 'Mi Curso',
    'No upcoming due dates!': '¡No hay vencimientos próximos!',
    'Not Reportable': 'No Reportable',
    'Off Timetable': 'Fuera de Horario',
    'Over Enrolled': 'Sobrecupo',
    'Select Students': 'Seleccionar Estudiantes',
    'Should this student be automatically enrolled in courses for their Form Group?': '¿Se debe inscribir automáticamente a este estudiante en las asignaturas de su curso?',
    'There are no lessons for this class.': 'No hay lecciones para esta clase.',
    'There are no lessons on this date.': 'No hay lecciones en esta fecha.',
    'This student has one or more {level} risk medical conditions.': 'Este estudiante tiene una o más condiciones médicas de riesgo {level}.',
    'Under Enrolled': 'Bajo Cupo',
    'Update Staff Data': 'Actualizar Datos del Personal',
    'Your active session will be switched to the selected user.': 'Su sesión activa se cambiará al usuario seleccionado.',
}
# Gibbon's es_MX file has 'Teacher' empty (only the plural 'Profesores').
OVERRIDES = {'Teacher': 'Profesor/a'}

leftover = [v for v in list(T.values()) + [f for v in P.values() for f in v] + list(EXTRA.values())
            if re.search(r'trabajo en casa|\bcentro\b|bolet[ií]n|t[ée]rminos?\b|retraso|[Aa]ñad|[Gg]estion|vídeo|[Rr]ellen', v)]
assert not leftover, leftover

with open('es_MX.py', 'w', encoding='utf-8') as f:
    f.write('# Mexican Spanish (es_MX) draft, generated from es_ES.py by make_es_MX.py:\n'
            '# Gibbon es_MX vocabulary (Tareas, Período, Atrasado, escuela, Agregar, Administrar,\n'
            '# boleta for report cards, video). Review before release.\n\n')
    for name, d in (('T', T), ('P', P), ('EXTRA', EXTRA), ('GAPS', GAPS), ('OVERRIDES', OVERRIDES)):
        f.write(f'{name} = ' + pprint.pformat(d, width=200, sort_dicts=True) + '\n\n')
print('written', len(T), len(P), len(GAPS))
