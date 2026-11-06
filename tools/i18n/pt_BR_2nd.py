# One-off: second pass on pt_BR (6 Nov 2026). Writes pt_BR_final.py and updates T/P in pt_BR.py.
# Terms (agreed with Tieku): Turma = form group (7º ano A), Classe = teaching class (Gibbon: Turma),
# Disciplina = course (Gibbon: Curso, a degree programme in Brazil), Ano = year group (Gibbon: Grupo de
# Ano), Ano Letivo, Área do Conhecimento (BNCC term), Perfil = role (Gibbon: Papel), Espaço = facility,
# Planejamento, Boletim, Professor Conselheiro = tutor. Title Case kept, as Gibbon's pt_BR menus.
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('ptbr_cur.json', encoding='utf-8'))
NEW = {
 'Add Class': 'Adicionar Classe', 'Add Course': 'Adicionar Disciplina', 'All Learning Areas': 'Todas as Áreas do Conhecimento',
 'All Roles': 'Todos os Perfis', 'Auto-Enrol Courses?': 'Matricular Automaticamente nas Disciplinas?',
 'Basic Information': 'Informações Básicas', 'By Form Group': 'Por Turma', 'Choose A Class': 'Escolha uma Classe',
 'Class': 'Classe', 'Class Of': 'Formandos de', 'Class should not run below this number of students.': 'A classe não deve ter menos alunos que este número.',
 'Classes': 'Classes', 'Company All?': 'A Empresa Paga Tudo?', 'Contact Information': 'Informações de Contato',
 'Course': 'Disciplina', 'Course Enrolment by Class': 'Matrícula em Disciplinas por Classe',
 'Course Enrolment by Person': 'Matrícula em Disciplinas por Pessoa', 'Data Updater': 'Atualização de Dados',
 'Due Date': 'Data de Entrega', 'Due at {time} on {date}': 'Entrega em {date} às {time}',
 'Due on {date} at {time}.': 'Entrega em {date} às {time}.', 'Edit Class': 'Editar Classe', 'Edit Course': 'Editar Disciplina',
 'Form Group': 'Turma', 'General Information': 'Informações Gerais', 'Given Name': 'Prenome',
 'Home': 'Residencial', 'Homework + Due Dates': 'Dever de Casa e Prazos',
 'If the specified company is not paying all fees, which categories are they paying?': 'Se a empresa especificada não paga todas as taxas, quais categorias ela paga?',
 'Learning Area': 'Área do Conhecimento', 'Manage Courses & Classes': 'Gerenciar Disciplinas e Classes',
 'Manage School Years': 'Gerenciar Anos Letivos', 'Miscellaneous': 'Diversos',
 'Must be unique for this course.': 'Tem que ser único para esta disciplina.', 'Must be unique for this school year.': 'Tem que ser único para este ano letivo.',
 'My Classes': 'Minhas Classes', 'My Form Group': 'Minha Turma', 'No upcoming due dates!': 'Nenhum prazo próximo!',
 'Partial': 'Presença Parcial', 'Planner': 'Planejamento', 'Receive non-emergency emails from school?': 'Receber e-mails da escola que não sejam de emergência?',
 'Report': 'Boletim', 'Reports': 'Boletins', 'Role': 'Perfil', 'Role Switcher': 'Alternar Perfil',
 'School Information': 'Informações da Escola', 'School Year': 'Ano Letivo', 'Short Name': 'Nome Abreviado',
 'Should the family be sent a copy of billing emails?': 'A família deve receber uma cópia das faturas por e-mail?',
 'Should this student be automatically enrolled in courses for their Form Group?': 'Este aluno deve ser matriculado automaticamente nas disciplinas da sua turma?',
 'Staff Directory': 'Lista de Funcionários', 'Support': 'Apoio', 'Switch': 'Alternar', 'Teaching': 'Docente',
 'There are no lessons for this class.': 'Não há aulas para esta classe.', 'Title': 'Tratamento', 'Tutor': 'Professor Conselheiro',
 'View Planner': 'Ver Planejamento', 'View Reports': 'Ver Boletins', 'Work': 'Comercial', 'Year Group': 'Ano',
 'You have multiple roles within the system. Use the list below to switch role:': 'Você tem vários perfis no sistema. Use a lista abaixo para alternar o perfil:',
 'Teacher': 'Professor', 'Full': 'Ativo', 'Support Staff': 'Equipe de Apoio', 'Planner': 'Planejamento',
 'Individual Needs': 'Necessidades Individuais', 'Track Attendance?': 'Registrar Frequência?', 'Fee Table': 'Tabela de Taxas',
 'Status': 'Situação',
}
WHY = {
 'Form Group': 'Grupo de Inscrição (an "enrolment group"); in Brazil the 7º A group is the turma',
 'Class': 'Turma (now the form group); teaching class = Classe', 'Course': 'Curso (a degree programme in Brazil)',
 'Year Group': 'Grupo de Ano', 'Role': 'Papel (a part in a play); Brazilian systems say perfil',
 'Learning Area': 'Área de Educação; BNCC term', 'Teacher': 'missing (plural entry only)', 'Full': 'Completo (complete)',
 'Home': 'Página Inicial (home page); phone type', 'Work': 'Trabalho; Brazilian phone type is Comercial',
 'Due on {date} at {time}.': 'Vencimento (a bill falling due)', 'Planner': 'Planejador here, Planejamento in Ver Planejamento',
 'Tutor': 'Tutor', 'Report': 'Relatório; Gibbon reports are report cards (boletins)',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('pt_BR_final.py', 'w', encoding='utf-8') as f:
    f.write("# pt_BR: every Gibbon string the app uses, as the app should show it (line-by-line read, 6 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's pt_BR file.\n"
            "# Turma (form group), Classe (class), Disciplina, Ano, Ano Letivo, Período Letivo, Perfil, Área do Conhecimento,\n"
            "# Planejamento, Diário de Notas, Boletim, Professor Conselheiro; Title Case as Gibbon's pt_BR menus.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
import pt_BR as F
T, P = dict(F.T), dict(F.P)
FIX = {
 'Adjusts arrangement of courses in reports.': 'Ajusta a ordem das disciplinas nos boletins.',
 'All Year Groups': 'Todos os Anos', 'Choose any Form Group': 'Escolher qualquer turma', 'Choose one of your Form Groups': 'Escolher uma de suas turmas',
 'Could not load Form Groups': 'Não foi possível carregar as turmas', 'Could not load course': 'Não foi possível carregar a disciplina',
 'Could not load courses': 'Não foi possível carregar as disciplinas', 'Could not load facilities': 'Não foi possível carregar os espaços',
 'Could not load school years': 'Não foi possível carregar os anos letivos', 'Could not switch role': 'Não foi possível alternar o perfil',
 'Could not switch school year': 'Não foi possível trocar o ano letivo', 'Delete Course': 'Excluir Disciplina',
 'Enrolable Year Groups': 'Anos Disponíveis para Matrícula', 'Enroll existing students into the active school year': 'Matricular alunos existentes no ano letivo ativo',
 'Fill in First Name, Surname, Preferred Name, and Primary Role first': 'Preencha primeiro o primeiro nome, o sobrenome, o nome preferido e o perfil principal',
 'Filter by Year Group': 'Filtrar por Ano', 'Form Group *': 'Turma *',
 'Join Virtual Class shows on the timetable and planner': 'O botão Entrar na aula virtual aparece no horário e no planejamento',
 'Link staff-role people to their staff record': 'Vincular pessoas com perfil de funcionário ao seu registro',
 'My Form Groups': 'Minhas Turmas', 'No Form Groups found': 'Nenhuma turma encontrada', 'No Learning Area': 'Sem Área do Conhecimento',
 'No courses found': 'Nenhuma disciplina encontrada', 'No facilities found': 'Nenhum espaço encontrado',
 'No terms found for this school year': 'Nenhum período letivo encontrado para este ano letivo',
 'Not wired up here yet — enrolling here does not auto-add courses. Finish course enrolment on the website if this student needs it.': 'Ainda não disponível aqui — matricular aqui não adiciona disciplinas automaticamente. Conclua a matrícula nas disciplinas pelo site, se este aluno precisar.',
 'Only includes people with a Staff role not already staff.': 'Inclui apenas pessoas com perfil de funcionário que ainda não estão no quadro de funcionários.',
 'Only includes students not enrolled in this school year.': 'Inclui apenas alunos não matriculados neste ano letivo.',
 'Please fill in all required fields (name, official name, gender, role, username)': 'Preencha todos os campos obrigatórios (nome, nome oficial, gênero, perfil, nome de usuário)',
 'Please select a Year Group and Form Group': 'Selecione um ano e uma turma', 'Primary Role *': 'Perfil principal *', 'Role: Can Login': 'Perfil: Pode Entrar',
 'Search by course name...': 'Buscar por nome da disciplina…', 'Search by course or class name...': 'Buscar por nome da disciplina ou da classe…',
 'Switch Role': 'Alternar Perfil', 'Switch School Year': 'Trocar Ano Letivo', 'Timetable by Facility': 'Horário por Espaço',
 'Tutor 2': 'Professor Conselheiro 2', 'Unenroll {name} from this school year?': 'Cancelar a matrícula de {name} neste ano letivo?',
 'View and manage Form Groups': 'Ver e gerenciar turmas', 'Year Group *': 'Ano *',
 "Year Group / Form Group targeting isn't set here — this will apply broadly until finished on the website.": 'O direcionamento por ano ou turma não é definido aqui — isso valerá para todos até ser concluído pelo site.',
 'You are viewing school year {year}, which is not the current year. Data may not look quite right (for example, students who have left the school will not appear in previous years).': 'Você está vendo o ano letivo {year}, que não é o ano atual. Alguns dados podem parecer incorretos (por exemplo, alunos que saíram da escola não aparecerão em anos anteriores).',
 'Could not load roles': 'Não foi possível carregar os perfis',
 'By Class': 'Por Classe', 'Choose a class above': 'Escolha uma classe acima', 'Choose any of your classes': 'Escolher uma de suas classes',
 'Class Absences': 'Faltas nas Aulas', 'Class Enrolments': 'Matrículas em Classes', 'Class Roster': 'Lista da Classe',
 'Could not load classes': 'Não foi possível carregar as classes', 'Delete Class': 'Excluir Classe', 'No class': 'Sem aula',
 'No classes found': 'Nenhuma classe encontrada', 'No classes yet': 'Nenhuma classe ainda', 'Not enrolled in any classes yet': 'Ainda não matriculado em nenhuma classe',
 'Permanently delete "{name}" and all of its classes?': 'Excluir permanentemente "{name}" e todas as suas classes?',
 'Please select a class and a role': 'Selecione uma classe e uma função', 'Remove Class': 'Remover Classe', 'Remove from Class': 'Remover da classe',
 'Remove {name} from this class?': 'Remover {name} desta classe?', 'Search classes...': 'Buscar classes…',
 'Take attendance for this class': 'Fazer a chamada desta classe',
 'This also removes all enrolment and timetable data for those classes. This cannot be undone.': 'Isso também remove todas as matrículas e dados de horário dessas classes. Não é possível desfazer.',
 'This class is not scheduled on {date}. You can still save attendance if needed.': 'Esta classe não tem aula programada em {date}. Você ainda pode salvar a frequência, se precisar.',
 'This removes all enrolment and timetable data for this class. This cannot be undone.': 'Isso remove todas as matrículas e dados de horário desta classe. Não é possível desfazer.',
 'View class grades': 'Ver notas da classe', 'View lesson plans for this class': 'Ver planos de aula desta classe', 'View students in this class': 'Ver alunos desta classe',
 'Dashboard': 'Início',
}
PFIX = {'{count} class': ('{count} classe', '{count} classes'), '{count} facility': ('{count} espaço', '{count} espaços'),
        '{count} school day in the school year': ('{count} dia letivo no ano letivo', '{count} dias letivos no ano letivo')}
assert set(FIX) <= set(T), set(FIX) - set(T)
T.update(FIX); P.update(PFIX)
s = open('pt_BR.py', encoding='utf-8').read()
s = re.sub(r'^T = \{.*?\n(?=P = )', lambda m: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', s, count=1, flags=re.S | re.M)
s = re.sub(r'^P = \{.*?\n(?=GAPS = )', lambda m: 'P = ' + pprint.pformat(P, width=200, sort_dicts=False) + '\n\n', s, count=1, flags=re.S | re.M)
head = s[:s.index('T = ')]
s = ("# Brazilian Portuguese (pt_BR) app-only strings. Second pass 6 Nov 2026 (pt_BR_2nd.py): Turma = form group,\n"
     "# Classe = teaching class, Disciplina = course, Ano = year group, Ano Letivo, Período Letivo, Perfil = role,\n"
     "# Área do Conhecimento, Espaço = facility, Planejamento, Diário de Notas, Boletim, Professor Conselheiro, frequência,\n"
     "# dever de casa, funcionários, responsável. Title Case for labels, as Gibbon's pt_BR menus.\n\n") + s[len(head):]
open('pt_BR.py', 'w', encoding='utf-8').write(s)
