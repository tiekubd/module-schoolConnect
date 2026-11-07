# One-off: second pass on es_ES (6 Nov 2026). Writes es_ES_final.py and updates T in es_ES.py.
# es_ES is also what Latin American schools outside Mexico and the Dominican Republic get (Gibbon has
# no es_CO, es_AR...), so words that work on both sides are preferred: tareas (not deberes or
# "trabajo en casa"), curso (form group, as Gibbon), nivel, asignatura, clase, tutor, hijos, boletín.
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('eses_cur.json', encoding='utf-8'))
NEW = {
 "Access data on family's children?": '¿Acceder a los datos de los hijos de la familia?', 'Add Child': 'Añadir Hijo',
 'Admin': 'Administración', 'Children': 'Hijos', 'Due Date': 'Fecha de Entrega', 'Due Soon': 'Entrega Próxima',
 'Due at {time} on {date}': 'Entrega el {date} a las {time}', 'Due on {date} at {time}.': 'Entrega el {date} a las {time}.',
 'Home': 'Casa', 'Homework': 'Tareas', 'Homework + Due Dates': 'Tareas y Fechas de Entrega', 'Homework Details': 'Detalles de la Tarea',
 'Impersonate User': 'Acceder como Usuario', 'No upcoming due dates!': '¡No hay entregas próximas!', 'Partial': 'Asistencia Parcial',
 'Personal': 'Datos Personales', 'Report': 'Boletín', 'Reports': 'Boletines', 'Siblings': 'Hermanos', 'Student ID': 'N.º de Estudiante',
 'Student Recorded Homework': 'Tareas Registradas por el Estudiante', 'Support': 'Apoyo', 'Teaching': 'Docente', 'Title': 'Tratamiento',
 'Vehicle Registration': 'Matrícula del Vehículo', 'View Reports': 'Ver Boletines',
 'Parent': 'Padre/Madre', 'Full': 'Activo',
}
WHY = {
 'Parent': 'Padre (father), shown as the role of every parent', 'Full': 'Completo (complete)', 'Home': 'Inicio (home page); phone type',
 'Due Date': 'Vencimiento (a bill falling due)', 'Homework': 'Trabajo en casa here, Tarea in Homework Details', 'Personal': 'Personal, same as Staff',
 'Children': 'Niños (children in general)', 'Impersonate User': 'Suplantar (to impersonate fraudulently)', 'Teacher': 'missing (plural entry only)',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('es_ES_final.py', 'w', encoding='utf-8') as f:
    f.write("# es_ES: every Gibbon string the app uses, as the app should show it (line-by-line read, 6 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's es_ES file.\n"
            "# Curso (form group), Nivel, Clase, Asignatura, Período, Tutor, Personal, Tareas, Boletín; usted; Title Case as Gibbon.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
import es_ES as F
T = dict(F.T)
FIX = {
 'Add homework?': '¿Añadir tarea?', 'Could not add child': 'No se pudo añadir al hijo', 'Could not load children': 'No se pudieron cargar los hijos',
 'Could not load homework': 'No se pudieron cargar las tareas', 'Could not load homework calendar': 'No se pudo cargar el calendario de tareas',
 'Could not start impersonating this user': 'No se pudo acceder como este usuario', 'Dashboard': 'Inicio',
 'Due date and details are required.': 'La fecha de entrega y los detalles son obligatorios.',
 "Highlighted in red: marked 'Full' but outside their start/end date range.": "Resaltados en rojo: marcados como 'Activo' pero fuera de su rango de fechas de inicio y fin.",
 'Homework Calendar': 'Calendario de Tareas', 'Homework due': 'Tareas por entregar', 'Homework removed.': 'Tarea eliminada.', 'Homework saved.': 'Tarea guardada.',
 'Impersonate': 'Acceder como Usuario', 'Impersonate User needs School Connect module 1.16.09 or later on the school server.': 'Acceder como otro usuario requiere el módulo School Connect 1.16.09 o posterior en el servidor del centro.',
 'No children found': 'No se encontraron hijos', 'No children linked': 'No hay hijos vinculados', 'No children linked to this account': 'No hay hijos vinculados a esta cuenta',
 'No homework recorded.': 'No hay tareas registradas.', 'Remove Child': 'Quitar Hijo',
 'The impersonation ended after 2 hours. You are back in your own account.': 'El acceso como otro usuario terminó después de 2 horas. Ha vuelto a su propia cuenta.',
 'This option enables Administrators to impersonate another account in the system for troubleshooting and testing purposes. The selected user will still be able to log in and out of their own account.': 'Esta opción permite a los administradores acceder como otra cuenta del sistema para resolver problemas y hacer pruebas. El usuario seleccionado podrá seguir iniciando y cerrando sesión en su propia cuenta.',
 'View Homework Calendar': 'Ver Calendario de Tareas', 'View families, contacts, and children': 'Ver familias, contactos e hijos',
 'You can use this section to record your own homework.': 'Puede usar esta sección para registrar sus propias tareas.',
}
assert set(FIX) <= set(T)
T.update(FIX)
k = "School Connect shows information from your school's own Gibbon system: timetables, attendance, grades, homework, reports, messages and your family's details. The app connects directly and only to your school's Gibbon server over an encrypted (HTTPS) connection. High Point Education does not receive, store or see this information."
T[k] = T[k].replace('trabajo en casa', 'tareas'); assert 'tareas' in T[k]
s = open('es_ES.py', encoding='utf-8').read()
s = re.sub(r'^T = \{.*?\n(?=P = )', lambda m: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', s, count=1, flags=re.S | re.M)
s = s.replace("# curso (form group), nivel (year group), trabajo en casa (homework),", "# curso (form group), nivel (year group), tareas (homework; second pass 6 Nov 2026, es_ES_2nd.py),")
open('es_ES.py', 'w', encoding='utf-8').write(s)
