# One-off: second pass on de_DE (7 Nov 2026). Writes de_DE_final.py; rewrites de_DE.py with the trailing
# per-key lines folded in. Terms agreed with Tieku: Klasse = form group (7a), Klassenlehrer/in = tutor,
# Lerngruppe = teaching class (Gibbon: Klasse), Kurs, Jahrgangsstufe, Fachbereich, Zeugnis, Abgabe.
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('dede_cur.json', encoding='utf-8'))
NEW = {
 'Add Class': 'Lerngruppe hinzufügen', 'All Staff': 'Alle Mitarbeiter/innen', 'All Students': 'Alle Schüler/innen', 'By Form Group': 'Nach Klasse',
 'Choose A Class': 'Wählen Sie eine Lerngruppe', 'Choose Students': 'Schüler/innen auswählen', 'Class': 'Lerngruppe',
 'Class should not run below this number of students.': 'Die Lerngruppe sollte nicht mit weniger Schüler/innen stattfinden.',
 'Classes': 'Lerngruppen', 'Complete': 'Abgeschlossen', 'Course Enrolment by Class': 'Kurseinschreibung nach Lerngruppe',
 'Data displayed in full Student Profile': 'Im vollständigen Schülerprofil angezeigte Daten', 'Department': 'Fachbereich',
 'Due Date': 'Abgabedatum', 'Due at {time} on {date}': 'Abgabe am {date} um {time}', 'Due on {date} at {time}.': 'Abgabe am {date} um {time}.',
 'Edit Class': 'Lerngruppe bearbeiten', 'Follow Up': 'Nachverfolgung', 'Form Group': 'Klasse', 'Head of Year': 'Jahrgangsstufenleitung',
 'Home': 'Privat', 'Homework + Due Dates': 'Hausaufgaben und Abgabetermine',
 'If the specified company is not paying all fees, which categories are they paying?': 'Wenn die angegebene Firma nicht alle Gebühren zahlt, welche Kategorien zahlt sie?',
 'Include all staff, regardless of status, start date, end date, etc.': 'Alle Mitarbeiter/innen einbeziehen, unabhängig von Status, Startdatum, Enddatum usw.',
 'Include all students, regardless of status and current enrolment. Some data may not display.': 'Alle Schüler/innen einbeziehen, unabhängig von Status und aktueller Einschreibung. Einige Daten werden möglicherweise nicht angezeigt.',
 'Manage Courses & Classes': 'Kurse und Lerngruppen verwalten', 'My Classes': 'Meine Lerngruppen', 'My Form Group': 'Meine Klasse',
 'No upcoming due dates!': 'Keine anstehenden Abgabetermine!', 'Partial': 'Teilweise anwesend', 'Personal': 'Persönliche Daten',
 'Report': 'Zeugnis', 'Reports': 'Zeugnisse', 'Reset User Password': 'Benutzerpasswort zurücksetzen',
 'Should all items be billed to the specified company, or just some?': 'Sollen alle Posten der angegebenen Firma in Rechnung gestellt werden oder nur einige?',
 'Should this student be automatically enrolled in courses for their Form Group?': 'Soll diese/r Schüler/in automatisch in die Kurse der eigenen Klasse eingeschrieben werden?',
 'Support': 'Nicht-pädagogisch', 'Teachers': 'Lehrkräfte', 'Teaching': 'Pädagogisch', 'There are no lessons for this class.': 'Für diese Lerngruppe gibt es keine Stunden.',
 'Title': 'Anrede', 'Tutor': 'Klassenlehrer/in', 'Unit, Building, Street': 'Wohnung, Gebäude, Straße', 'View Reports': 'Zeugnisse ansehen',
 'Website': 'Website', 'Work': 'Dienstlich', 'students': 'Schüler/innen',
 'Teacher': 'Lehrer/in', 'Support Staff': 'Nicht-pädagogisches Personal', 'Assistant': 'Unterrichtsassistenz', 'Full': 'Aktiv',
 'Crowd Assessment': 'Gemeinschaftsbewertung', 'Departments': 'Fachbereiche',
}
WHY = {
 'Form Group': 'Formulargruppe ("a group of forms", as in paperwork); in German schools the 7a is the Klasse',
 'Class': 'Klasse (now the form group); teaching class = Lerngruppe', 'Tutor': 'Tutor; the 7a has a Klassenlehrer/in',
 'Teacher': 'missing (plural entry only)', 'Full': 'Voll (full, as a container)', 'Crowd Assessment': 'Mengenbewertung (a quantity rating)',
 'Departments': 'Abteilungen (company departments); schools have Fachbereiche', 'Home': 'Home; phone type',
 'Support Staff': 'Hilfskraft (an unskilled helper)', 'Report': 'Bericht; Gibbon reports are report cards',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('de_DE_final.py', 'w', encoding='utf-8') as f:
    f.write("# de_DE: every Gibbon string the app uses, as the app should show it (line-by-line read, 7 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's de_DE file.\n"
            "# Klasse (form group), Lerngruppe (class), Klassenlehrer/in, Kurs, Jahrgangsstufe, Zeitraum, Fachbereich, Zeugnis.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
src = open('de_DE.py', encoding='utf-8').read()
b = {}; exec(src, b)
R = [('Fehlzeiten in der Klasse', 'Fehlzeiten im Unterricht'), ('Klassenzimmer', '\0'), ('Klassenliste', 'Lerngruppenliste'),
     ('Klasseneinschreibungen', 'Einschreibungen in Lerngruppen'), ('Klassenname', 'Lerngruppenname'), ('Klassen', 'Lerngruppen'),
     ('Klasse', 'Lerngruppe'), ('\0', 'Klassenzimmer'), ('Stammgruppen', 'Klassen'), ('Stammgruppe', 'Klasse')]
def fix(s):
    for a, c in R: s = s.replace(a, c)
    return s
T = {k: fix(v) for k, v in b['T'].items()}
P = {k: tuple(fix(x) for x in v) for k, v in b['P'].items()}
T['Tutor 2'] = 'Klassenlehrer/in 2'
m = re.search(r'^(T|P|GAPS|OVERRIDES|EXTRA)\[', src, re.M)
head = src[:m.start()].rstrip() + '\n' if m else src
head = re.sub(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', head, count=1, flags=re.S | re.M)
head = re.sub(r'^P = \{.*?\n(?=EXTRA = )', lambda _: 'P = ' + pprint.pformat(P, width=200, sort_dicts=False) + '\n\n', head, count=1, flags=re.S | re.M)
head = re.sub(r'^EXTRA = \{.*?\}\n', lambda _: 'EXTRA = ' + pprint.pformat(b['EXTRA'], width=200) + '\n', head, count=1, flags=re.S | re.M)
head = re.sub(r'^OVERRIDES = \{.*?\}\n', lambda _: 'OVERRIDES = ' + pprint.pformat({k: v for k, v in b['OVERRIDES'].items() if k not in FINAL}, width=200) + '\n', head, count=1, flags=re.S | re.M)
head = re.sub(r'^# Stammgruppe \(form group; a school can rename it with a String\n# Replacement, e\.g\. to Stammgruppe\), Jahrgangsstufe',
              '# Klasse (form group, as 7a; second pass 7 Nov 2026), Lerngruppe (class), Klassenlehrer/in (tutor), Jahrgangsstufe', head, flags=re.M)
open('de_DE.py', 'w', encoding='utf-8').write(head)
