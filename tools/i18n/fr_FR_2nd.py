# One-off: second pass on fr_FR (7 Nov 2026). Writes fr_FR_final.py; rewrites fr_FR.py with trailing per-key lines folded in.
# Tieku chose France / francophone Africa school words over Gibbon's Québec ones: classe (form group, 6e A;
# Gibbon: groupe-repère), niveau (palier), trimestre (étape), professeur principal (mentor), e-mail (courriel),
# matière (course; Gibbon: Cours, which it also uses for classes), cours (class).
import json, pprint, re, sys
sys.path.insert(0, '.')
NB = ' '
cur = json.load(open('frfr_cur.json', encoding='utf-8'))
NEW = {
 'Add Course': 'Ajouter une matière', 'Edit Course': 'Modifier la matière', 'Course': 'Matière', 'Auto-Enrol Courses?': f'Inscription automatique aux matières{NB}?',
 'Course Enrolment by Class': 'Inscription aux matières par cours', 'Course Enrolment by Person': 'Inscription aux matières par personne',
 'Manage Courses & Classes': 'Gérer les matières et les cours', 'Must be unique for this course.': 'Doit être unique pour cette matière.',
 'Should this student be automatically enrolled in courses for their Form Group?': f'Cet élève doit-il être inscrit automatiquement aux matières de sa classe{NB}?',
 'All Terms': 'Tous les trimestres', 'Manage Terms': 'Gérer les trimestres', 'Term': 'Trimestre',
 'By Form Group': 'Par classe', 'Form Group': 'Classe', 'My Form Group': 'Ma classe', 'Year Group': 'Niveau', 'Head of Year': 'Responsable de niveau',
 'Tutor': 'Professeur principal', 'Alternate Email': 'E-mail secondaire', 'Company Emails': 'E-mails de la société', 'Contact By Email': 'Contacter par e-mail',
 'Do not contact by email.': 'Ne pas contacter par e-mail.', 'Email': 'E-mail', 'Email?': f'E-mail{NB}?',
 'Receive non-emergency emails from school?': f'Recevoir les e-mails ordinaires de l’école{NB}?',
 'Should the family be sent a copy of billing emails?': f'Les e-mails de facturation doivent-ils être envoyés en copie à la famille{NB}?',
 'Home': 'Domicile', 'Work': 'Travail', 'Pager': 'Bipeur', 'Website': 'Site web', 'Report': 'Bulletin', 'Reports': 'Bulletins', 'View Reports': 'Voir les bulletins',
 'Teacher': 'Enseignant(e)', 'Full': 'Actif', 'Support': 'Non enseignant', 'Teaching': 'Enseignant', 'Support Staff': 'Personnel non enseignant',
 'Title': 'Civilité', 'Partial': 'Présence partielle', 'Crowd Assessment': 'Évaluation collective', 'Departments': 'Départements',
 'School Admin': 'Administration de l’école', 'System Admin': 'Administration du système', 'Timetable Admin': 'Administration de l’emploi du temps',
 'User Admin': 'Administration des utilisateurs', 'Tracking': 'Suivi', 'Planner': 'Cahier de textes', 'View Planner': 'Voir le cahier de textes',
 'Markbook': 'Carnet de notes', 'Condition': 'Problème de santé', 'Condition Name': 'Nom du problème de santé',
 'Add Medical Condition': 'Ajouter un problème de santé', 'Medical Conditions': 'Problèmes de santé', 'New Condition': 'Nouveau problème de santé',
 'Check the box to add a new medical condition': 'Cochez la case pour ajouter un nouveau problème de santé',
 'User will be prompted on next login.': 'L’utilisateur devra le faire à sa prochaine connexion.',
}
WHY = {
 'Form Group': 'Groupe-repère (Québec)', 'Year Group': 'Palier (Québec)', 'Term': 'Étape (Québec)', 'Tutor': 'Mentor',
 'Course': 'Cours, also used for classes', 'Email': 'Courriel (Québec)', 'Teacher': 'missing (plural entry only)', 'Full': 'Complet (full, as a bus)',
 'School Admin': 'École (school)', 'System Admin': 'Système (system)', 'Timetable Admin': 'Emploi du temps, same as Timetable', 'User Admin': 'Utilisateurs (users)',
 'Tracking': 'Suivi/Graphique', 'Planner': 'Planificateur; French schools keep a cahier de textes', 'Condition': 'Condition (a precondition, not a health problem)',
 'User will be prompted on next login.': 'says users must type their password at next login', 'Home': 'Accueil (home page); phone type',
 'Crowd Assessment': 'Évaluation publique (a public assessment)', 'Support Staff': 'Personnel d’encadrement (supervisory staff)',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('fr_FR_final.py', 'w', encoding='utf-8') as f:
    f.write("# fr_FR: every Gibbon string the app uses, as the app should show it (line-by-line read, 7 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's fr_FR file.\n"
            "# France / francophone Africa: classe, niveau, matière, cours, trimestre, professeur principal, e-mail, bulletin.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
src = open('fr_FR.py', encoding='utf-8').read()
b = {}; exec(src, b)
T, P = dict(b['T']), dict(b['P'])
FIX = {
 'Adjusts arrangement of courses in reports.': 'Ajuste l’ordre des matières dans les bulletins.',
 'All Year Groups': 'Tous les niveaux', 'Choose any Form Group': 'Choisir n’importe quelle classe', 'Choose one of your Form Groups': 'Choisir l’une de vos classes',
 'Comma-separated list of email addresses': 'Liste d’adresses e-mail séparées par des virgules', 'Could not load Form Groups': 'Impossible de charger les classes',
 'Could not load course': 'Impossible de charger la matière', 'Could not load courses': 'Impossible de charger les matières',
 'Could not load terms': 'Impossible de charger les trimestres', 'Delete Course': 'Supprimer la matière', 'Email: Must be unique': f'E-mail{NB}: doit être unique',
 'Enrolable Year Groups': 'Niveaux ouverts à l’inscription', 'Filter by Year Group': 'Filtrer par niveau', 'Form Group *': 'Classe *',
 'Join Virtual Class shows on the timetable and planner': 'Le bouton Rejoindre la classe virtuelle apparaît dans l’emploi du temps et le cahier de textes',
 'My Form Groups': 'Mes classes', 'No Form Groups found': 'Aucune classe trouvée', 'No courses found': 'Aucune matière trouvée',
 'No data for this term': 'Aucune donnée pour ce trimestre', 'No terms found for this school year': 'Aucun trimestre trouvé pour cette année scolaire',
 'Not wired up here yet — enrolling here does not auto-add courses. Finish course enrolment on the website if this student needs it.': 'Pas encore disponible ici — l’inscription ici n’ajoute pas les matières automatiquement. Terminez l’inscription aux matières sur le site web si cet élève en a besoin.',
 'Please select a Year Group and Form Group': 'Veuillez choisir un niveau et une classe', 'Search by course name...': 'Rechercher par nom de matière…',
 'Search by course or class name...': 'Rechercher par nom de matière ou de cours…', 'Search by name, username, email, student ID...': 'Rechercher par nom, nom d’utilisateur, e-mail, numéro d’élève…',
 'Tutor 2': 'Professeur principal 2', 'View and manage Form Groups': 'Voir et gérer les classes', 'Year Group *': 'Niveau *',
 "Year Group / Form Group targeting isn't set here — this will apply broadly until finished on the website.": 'Le ciblage par niveau ou classe ne se règle pas ici — cela s’appliquera à tous jusqu’à ce que ce soit terminé sur le site web.',
 'Class Absences': 'Absences en cours',
}
assert set(FIX) <= set(T), set(FIX) - set(T)
T.update(FIX)
P['{count} school day in term'] = ('{count} jour d’école dans le trimestre', '{count} jours d’école dans le trimestre')
for k, v in T.items():
    T[k] = v.replace('courriel', 'e-mail').replace('Courriel', 'E-mail').replace('planificateur', 'cahier de textes').replace('Planificateur', 'Cahier de textes').replace('site Web', 'site web')
m = re.search(r'^(T|P|GAPS|OVERRIDES|EXTRA)\[', src, re.M)
head = src[:m.start()].rstrip() + '\n'
head = re.sub(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', head, count=1, flags=re.S | re.M)
head = re.sub(r'^P = \{.*?\n(?=GAPS = )', lambda _: 'P = ' + pprint.pformat(P, width=200, sort_dicts=False) + '\n\n', head, count=1, flags=re.S | re.M)
head = re.sub(r'^OVERRIDES = \{.*?\n\}\n', lambda _: 'OVERRIDES = ' + pprint.pformat({k: v for k, v in b['OVERRIDES'].items() if k not in FINAL}, width=200) + '\n', head, count=1, flags=re.S | re.M)
head = re.sub(r'^EXTRA = \{.*?\}\n', lambda _: 'EXTRA = ' + pprint.pformat(b['EXTRA'], width=200) + '\n', head, count=1, flags=re.S | re.M)
head = head.replace("# Vocabulary follows Gibbon's own fr_FR file (Québec-leaning): groupe-repère,\n# palier, étape, courriel, emploi du temps, planificateur, mentor.",
                    "# Second pass 7 Nov 2026 (fr_FR_2nd.py): France / francophone Africa school words, chosen by Tieku:\n# classe (form group), niveau, matière (course), cours (class), trimestre, professeur principal, e-mail, cahier de textes.")
open('fr_FR.py', 'w', encoding='utf-8').write(head)
