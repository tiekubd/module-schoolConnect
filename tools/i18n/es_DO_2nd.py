# One-off: second pass on es_DO's Gibbon strings (7 Nov 2026). Writes es_DO_final.py and clears es_DO.py OVERRIDES.
# App-only strings come from es_MX.py through make_es_DO.py (run make_es_MX.py, make_es_DO.py, then split_final.py es_DO).
# Dominican terms (MINERD): sección (form group), grado, boletín, tareas, suplencias; sentence case as Gibbon's es_DO.
import json, sys, re, pprint
sys.path.insert(0, '.')
cur = json.load(open('esdo_cur.json', encoding='utf-8'))
NEW = {
 "Access data on family's children?": '¿Acceder a los datos de los hijos de la familia?', 'Add Child': 'Agregar hijo', 'Admin': 'Administración',
 'Manage': 'Gestión', 'Children': 'Hijos', 'Due Date': 'Fecha de entrega', 'Due Soon': 'Entrega próxima',
 'Due at {time} on {date}': 'Entrega el {date} a las {time}', 'Home': 'Residencial', 'Homework + Due Dates': 'Tareas y fechas de entrega',
 'No upcoming due dates!': '¡No hay entregas próximas!', 'Partial': 'Asistencia parcial', 'Personal': 'Datos personales',
 'Report': 'Boletín', 'Reports': 'Boletines', 'View Reports': 'Ver boletines', 'Siblings': 'Hermanos', 'Student ID': 'ID del estudiante',
 'Support': 'Apoyo', 'Teaching': 'Docente', 'Vehicle Registration': 'Placa del vehículo', 'Parent': 'Padre/madre', 'Full': 'Activo',
 'Higher priority substitutes appear first when booking coverage.': 'Los suplentes con mayor prioridad aparecen primero al asignar una suplencia.',
 'Support Staff': 'Personal de apoyo', 'School Admin': 'Administrar la escuela',
}
WHY = {
 'Parent': 'Padre (father), shown as the role of every parent', 'Full': 'Completo (complete)', 'Home': 'Inicio (home page); phone type',
 'Due Date': 'Vencimiento (a bill falling due)', 'Personal': 'Personal, same as Staff', 'Children': 'Niños (children in general)',
 'Teacher': 'missing (plural entry only)', 'Report': 'Reporte', 'Reports': 'Informes; MINERD says boletín', 'Manage': 'Administrar, same as Admin',
 'My Form Group': 'Mi curso, though its Form Group is Sección',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('es_DO_final.py', 'w', encoding='utf-8') as f:
    f.write("# es_DO: every Gibbon string the app uses, as the app should show it (line-by-line read, 7 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's es_DO file.\n"
            "# Sección (form group), Grado, Clase, Asignatura, Período, Tutor, Tareas, Boletín; usted; sentence case.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
s = open('es_DO.py', encoding='utf-8').read()
s = re.sub(r'^OVERRIDES = \{.*?\}\n', 'OVERRIDES = {}\n', s, count=1, flags=re.S | re.M)
open('es_DO.py', 'w', encoding='utf-8').write(s)
