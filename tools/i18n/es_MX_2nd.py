# One-off: second pass on es_MX's Gibbon strings (7 Nov 2026). Writes es_MX_final.py.
# App-only strings come from es_ES.py through make_es_MX.py (run it first, then split_final.py es_MX).
# Mexican terms: grupo (form group, 3° A), grado (year group), clase, asignatura, período, tutor,
# tareas, boleta, padre de familia, celular, casillero, escuela, agregar, administrar.
import json, sys
sys.path.insert(0, '.')
cur = json.load(open('esmx_cur.json', encoding='utf-8'))
NEW = {
 "Access data on family's children?": '¿Acceder a los datos de los hijos de la familia?', 'Add Child': 'Agregar Hijo', 'Admin': 'Administración',
 'Manage': 'Gestión', 'By Form Group': 'Por Grupo', 'Children': 'Hijos', 'Due Date': 'Fecha de Entrega', 'Due Soon': 'Entrega Próxima',
 'Due at {time} on {date}': 'Entrega el {date} a las {time}', 'Due on {date} at {time}.': 'Entrega el {date} a las {time}.',
 'Exclude from coverage?': '¿Excluir de las suplencias?', 'Form Group': 'Grupo', 'Head of Year': 'Coordinador de Grado',
 'Home': 'Particular', 'Homework + Due Dates': 'Tareas y Fechas de Entrega', 'Impersonate User': 'Acceder como Usuario', 'My Form Group': 'Mi Grupo',
 'No upcoming due dates!': '¡No hay entregas próximas!', 'Partial': 'Asistencia Parcial', 'Personal': 'Datos Personales', 'Report': 'Boleta',
 'Reports': 'Boletas', 'Should this student be automatically enrolled in courses for their Form Group?': '¿Se debe inscribir automáticamente a este estudiante en las asignaturas de su grupo?',
 'Siblings': 'Hermanos', 'Student ID': 'ID del Estudiante', 'Support': 'Apoyo', 'Teaching': 'Docente', 'Vehicle Registration': 'Placas del Vehículo',
 'View Reports': 'Ver Boletas', 'Year Group': 'Grado', 'Parent': 'Padre de Familia', 'Full': 'Activo',
 'Higher priority substitutes appear first when booking coverage.': 'Los suplentes de mayor prioridad aparecen primero al asignar una suplencia.',
}
WHY = {
 'Form Group': 'none (Spain\'s Curso in the earlier draft); Mexican schools say grado y grupo', 'Year Group': 'Nivel (Spain\'s word)',
 'Parent': 'Padre (father), shown as the role of every parent', 'Full': 'Completo (complete)', 'Home': 'Inicio (home page); phone type',
 'Due Date': 'Vencimiento (a bill falling due)', 'Personal': 'Personal, same as Staff', 'Children': 'Niños (children in general)',
 'Teacher': 'missing (plural entry only)', 'Report': 'Reporte', 'Reports': 'Informes', 'Manage': 'Administrar, same as Admin',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('es_MX_final.py', 'w', encoding='utf-8') as f:
    f.write("# es_MX: every Gibbon string the app uses, as the app should show it (line-by-line read, 7 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's es_MX file.\n"
            "# Grupo (form group), Grado (year group), Clase, Asignatura, Período, Tutor, Tareas, Boleta, Padre de Familia; usted.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
