# One-off: second pass on it_IT (7 Nov 2026). Writes it_IT_final.py; updates T/P/OVERRIDES in it_IT.py.
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('itit_cur.json', encoding='utf-8'))
NEW = {
 'Teacher': 'Insegnante', 'Full': 'Attivo', 'Messenger': 'Messaggistica', 'Crowd Assessment': 'Valutazione Collettiva', 'Tracking': 'Monitoraggio',
 'Rubrics': 'Griglie di Valutazione', 'Individual Needs': 'Esigenze Individuali', 'Home': 'Casa', 'House': 'Squadra',
 'Report': 'Pagella', 'Reports': 'Pagelle', 'View Reports': 'Visualizza Pagelle', 'Due Date': 'Data di Consegna',
 'Homework + Due Dates': 'Compiti e Consegne', 'Due Soon': 'Consegna Imminente', 'No upcoming due dates!': 'Nessuna consegna in arrivo!',
 'Support': 'Non Docente', 'Teaching': 'Docente', 'Support Staff': 'Personale Non Docente', 'Partial': 'Presenza Parziale',
 'Tutor': 'Coordinatore di Classe', 'Teacher - Left': 'Insegnante - Uscito', 'Start Date': 'Data di Inizio',
}
WHY = {
 'Teacher': 'missing (plural entry only)', 'Full': 'Completo (complete)', 'Messenger': 'Messaggi, same as Messages',
 'Crowd Assessment': 'Lista Valutazione (assessment list)', 'Rubrics': 'Rubriche (address books)', 'Home': 'Home (home page); phone type',
 'Report': 'Rapporto; Gibbon reports are report cards', 'Tutor': '<tutor (with a stray <)', 'Due Date': 'Scadenza (a payment deadline)',
 'House': 'Sede (a branch office)',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('it_IT_final.py', 'w', encoding='utf-8') as f:
    f.write("# it_IT: every Gibbon string the app uses, as the app should show it (line-by-line read, 7 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's it_IT file.\n"
            "# Gruppo Classe (form group), Classe, Corso, Anno di Corso, Periodo, Coordinatore di Classe, Pagella, Consegna.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
src = open('it_IT.py', encoding='utf-8').read()
b = {}; exec(src, b)
T, P = dict(b['T']), dict(b['P'])
T['Tutor 2'] = 'Coordinatore di Classe 2'
P['Trial — {count} day left'] = ('Periodo di prova — manca {count} giorno', 'Periodo di prova — mancano {count} giorni')
assert not re.search(r'^(T|P|GAPS|OVERRIDES|EXTRA)\[', src, re.M)
src = re.sub(r'^T = \{.*?\n(?=P = )', lambda _: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
src = re.sub(r'^P = \{.*?\n(?=EXTRA = )', lambda _: 'P = ' + pprint.pformat(P, width=200, sort_dicts=False) + '\n\n', src, count=1, flags=re.S | re.M)
src = re.sub(r'^OVERRIDES = \{.*?\n\}\n', lambda _: 'OVERRIDES = ' + pprint.pformat({k: v for k, v in b['OVERRIDES'].items() if k not in FINAL}, width=200) + '\n', src, count=1, flags=re.S | re.M)
open('it_IT.py', 'w', encoding='utf-8').write(src)
c = {}; exec(src, c); print('ok', c['T']['Tutor 2'], c['OVERRIDES'], c['EXTRA'] == b['EXTRA'], len(c['GAPS']) == len(b['GAPS']))
