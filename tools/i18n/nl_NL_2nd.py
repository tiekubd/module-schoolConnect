# One-off: second pass on nl_NL (7 Nov 2026). Writes nl_NL_final.py; updates T/P/OVERRIDES in nl_NL.py.
# Dutch school words, by the same logic Tieku chose for German: vak (course; Gibbon: cursus), lesgroep (class;
# Gibbon: vak), klas (form group), leerjaar (year group; Gibbon: jaargang), leerling (student; Gibbon: student,
# which in the Netherlands means higher education), huiswerk (Gibbon: opdrachten), mentor, periode, rapport.
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('nlnl_cur.json', encoding='utf-8'))
def dutch(s):
    s = re.sub(r'\bvakken\b', 'lesgroepen', s); s = re.sub(r'\bVakken\b', 'Lesgroepen', s)
    s = re.sub(r'\bvak\b', 'lesgroep', s); s = re.sub(r'\bVak\b', 'Lesgroep', s)
    s = s.replace('cursussen', 'vakken').replace('Cursussen', 'Vakken').replace('cursus', 'vak').replace('Cursus', 'Vak')
    s = s.replace('Student', 'Leerling').replace('student', 'leerling')
    s = s.replace('jaargangen', 'leerjaren').replace('Jaargangen', 'Leerjaren').replace('jaargang', 'leerjaar').replace('Jaargang', 'Leerjaar')
    s = s.replace('Opdrachten', 'Huiswerk').replace('opdrachten', 'huiswerk')
    return s
NEW = {
 'Teacher': 'Docent', 'Admin': 'Administratie', 'Home': 'Thuis', 'Full': 'Actief', 'Rubrics': 'Rubrics', 'Tracking': 'Voortgang volgen',
 'Support Staff': 'Onderwijsondersteunend personeel', 'Support': 'Onderwijsondersteunend', 'Teaching': 'Onderwijzend',
 'School Closure': 'Schoolsluiting', 'Report': 'Rapport', 'Reports': 'Rapporten', 'View Reports': 'Rapporten bekijken',
 'Homework Details': 'Huiswerkdetails', 'Homework + Due Dates': 'Huiswerk & deadlines', 'Student Recorded Homework': 'Door leerlingen bijgehouden huiswerk',
 'Name In Characters': 'Naam in ander schrift', 'Choose A Class': 'Kies een lesgroep',
}
WHY = {
 'Teacher': 'missing (plural entry only)', 'Class': 'Vak (now the course); a teaching group is a lesgroep', 'Course': 'Cursus (a course, as in evening classes)',
 'Student': 'Student (higher education in the Netherlands); schools say leerling', 'Homework': 'Opdrachten (assignments)', 'Year Group': 'Jaargang (a cohort or volume)',
 'Admin': 'Beheerder (administrator, a person)', 'Full': 'Volledig (complete)', 'Rubrics': 'Rubrieken (newspaper sections)',
 'School Closure': 'School gesloten, same as School Closed', 'Support Staff': 'Ondersteunende staf',
}
FINAL = {k: NEW.get(k, dutch(v)) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('nl_NL_final.py', 'w', encoding='utf-8') as f:
    f.write("# nl_NL: every Gibbon string the app uses, as the app should show it (line-by-line read, 7 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's nl_NL file.\n"
            "# Klas (form group), lesgroep (class), vak (course), leerjaar, leerling, huiswerk, mentor, periode, rapport.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
src = open('nl_NL.py', encoding='utf-8').read()
b = {}; exec(src, b)
assert not re.search(r'^(T|P|GAPS|OVERRIDES|EXTRA)\[', src, re.M)
T = {k: dutch(v) for k, v in b['T'].items()}
P = {k: tuple(dutch(x) for x in v) for k, v in b['P'].items()}
src = re.sub(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
src = re.sub(r'^P = \{.*?\n(?=EXTRA = )', lambda _: 'P = ' + pprint.pformat(P, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
src = re.sub(r'^OVERRIDES = \{.*?\n\}\n', lambda _: 'OVERRIDES = ' + pprint.pformat({k: v for k, v in b['OVERRIDES'].items() if k not in FINAL}, width=200) + '\n', src, count=1, flags=re.S | re.M)
src = src.replace("# Vocabulary follows Gibbon core's nl_NL: klas (form group), vak (class), cursus (course),\n# jaargang (year group), opdrachten (homework),",
                  "# Second pass 7 Nov 2026 (nl_NL_2nd.py): klas (form group), lesgroep (class), vak (course),\n# leerjaar (year group), leerling (student), huiswerk (homework),")
open('nl_NL.py', 'w', encoding='utf-8').write(src)
c = {}; exec(src, c); print('ok', c['OVERRIDES'], c['EXTRA'] == b['EXTRA'])
