# One-off: second pass on pt_PT (6 Nov 2026). Writes pt_PT_final.py and updates T/P/OVERRIDES in pt_PT.py.
# Portuguese school terms: turma (form group), aula (class, as Gibbon), disciplina (course; Gibbon mixes
# Curso and Disciplina), ano de escolaridade, ano letivo, período, diretor de turma, encarregado de
# educação (Gibbon: Educador, an educator), colaboradores, planificação, boletim, TPC. Title Case as Gibbon.
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('ptpt_cur.json', encoding='utf-8'))
NEW = {
 'Add Course': 'Adicionar Disciplina', 'Address Name': 'Nome da Morada', 'Auto-Enrol Courses?': 'Inscrever Automaticamente nas Disciplinas?',
 'Class Of': 'Ano de Conclusão', 'Course': 'Disciplina', 'Course Enrolment by Class': 'Matrículas nas Disciplinas por Aula',
 'Course Enrolment by Person': 'Matrículas nas Disciplinas por Pessoa', 'Due at {time} on {date}': 'Entrega a {date} às {time}',
 'Edit Course': 'Editar Disciplina', 'Home': 'Casa', 'Homework + Due Dates': 'TPC e Prazos', 'Manage Courses & Classes': 'Gerir Disciplinas e Aulas',
 'Must be unique for this course.': 'Tem de ser único para esta disciplina.', 'Negative': 'Negativo', 'Notes': 'Observações',
 'Partial': 'Presença Parcial', 'Personal': 'Dados Pessoais', 'Planner': 'Planificação', 'Report': 'Boletim', 'Reports': 'Boletins',
 'Should this student be automatically enrolled in courses for their Form Group?': 'Este estudante deve ser inscrito automaticamente nas disciplinas da sua turma?',
 'Summary': 'Resumo', 'Support': 'Não Docente', 'Teaching': 'Docente', 'Track Attendance?': 'Registar Assiduidade?',
 'View Planner': 'Ver Planificação', 'View Reports': 'Ver Boletins',
 'Teacher': 'Professor', 'Teacher - Left': 'Professor - Saiu', 'Parent': 'Encarregado de Educação', 'Support Staff': 'Pessoal Não Docente',
 'Full': 'Ativo', 'Expected': 'Previsto', 'Messenger': 'Envio de Mensagens', 'Tracking': 'Monitorização',
}
WHY = {
 'Parent': 'Educador (an educator); in Portugal the parent is the encarregado de educação', 'Teacher': 'missing (plural entry only)',
 'Course': 'Curso here, Disciplina in Add Course', 'Home': 'Página Inicial (home page); phone type', 'Summary': 'Sumário (in Portugal, the lesson summary)',
 'Messenger': 'Mensagens, same as Messages', 'Tracking': 'Rastreamento (tracking a parcel)', 'Full': 'Completo (complete)',
 'Support Staff': 'Auxiliares', 'Notes': 'Notas (also means marks)', 'Tutor': 'missing',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('pt_PT_final.py', 'w', encoding='utf-8') as f:
    f.write("# pt_PT: every Gibbon string the app uses, as the app should show it (line-by-line read, 6 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's pt_PT file.\n"
            "# Turma, Aula, Disciplina, Ano de Escolaridade, Ano Letivo, Período, Diretor de Turma, Encarregado de Educação,\n"
            "# Colaboradores, Planificação, Boletim, TPC, Assiduidade; Title Case as Gibbon's pt_PT menus.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
import pt_PT as F
T, P = dict(F.T), dict(F.P)
FIX = {
 'Adjusts arrangement of courses in reports.': 'Ajusta a ordem das disciplinas nos boletins.',
 'Could not load course': 'Não foi possível carregar a disciplina', 'Could not load courses': 'Não foi possível carregar as disciplinas',
 'Delete Course': 'Eliminar Disciplina', 'Join Virtual Class shows on the timetable and planner': 'O botão Entrar na Aula Virtual aparece no horário e na planificação',
 'No courses found': 'Nenhuma disciplina encontrada',
 'Not wired up here yet — enrolling here does not auto-add courses. Finish course enrolment on the website if this student needs it.': 'Ainda não disponível aqui — matricular aqui não adiciona disciplinas automaticamente. Conclua a matrícula nas disciplinas no site, se este estudante precisar.',
 'Search by course name...': 'Procurar por nome da disciplina…', 'Search by course or class name...': 'Procurar por nome da disciplina ou da aula…',
 'Dashboard': 'Início',
}
assert set(FIX) <= set(T)
T.update(FIX)
s = open('pt_PT.py', encoding='utf-8').read()
s = re.sub(r'^T = \{.*?\n(?=P = )', lambda m: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', s, count=1, flags=re.S | re.M)
s = re.sub(r'^OVERRIDES = \{.*?\n\}\n', lambda m: 'OVERRIDES = ' + pprint.pformat({k: v for k, v in F.OVERRIDES.items() if k not in FINAL}, width=200) + '\n', s, count=1, flags=re.S | re.M)
s = s.replace("# Title case for labels, as Gibbon's pt_PT. Report cards -> boletins.",
              "# Title case for labels, as Gibbon's pt_PT. Report cards -> boletins.\n# Second pass 6 Nov 2026 (pt_PT_2nd.py): disciplina for courses, planificação, encarregado de educação, Início.")
open('pt_PT.py', 'w', encoding='utf-8').write(s)
