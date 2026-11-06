import sys
L = '/home/claude/gibbon-connect/lib/screens/'
E = [
('dashboard_screen.dart', "Text('${tr('Role')}: ${_user['role']}',", "Text('${tr('Role')}: ${tr('${_user['role'] ?? ''}')}',", 1),
('impersonate_user_screen.dart', "{'name': name, 'role': person['role'] ?? ''}", "{'name': name, 'role': tr('${person['role'] ?? ''}')}", 1),
('impersonate_user_screen.dart', "subtitle: Text([p['role'], p['username']]", "subtitle: Text([p['role'] == null ? null : tr('${p['role']}'), p['username']]", 1),
('user_password_screen.dart', "- ${_troubleshoot!['roleName'] ?? ''}'", "- ${tr('${_troubleshoot!['roleName'] ?? ''}')}'", 1),
('user_list_screen.dart', "subtitle: Text('${u['roleName'] ?? '—'} · ${u['username'] ?? ''}'),",
    "subtitle: Text('${u['roleName'] == null ? '—' : tr('${u['roleName']}')} · ${u['username'] ?? ''}'),", 1),
('role_picker_screen.dart', "child: Text(r['name'], style: const TextStyle(fontSize: 15)),", "child: Text(tr('${r['name']}'), style: const TextStyle(fontSize: 15)),", 1),
('course_class_roster_screen.dart', "Text(p['role'] ?? '', style:", "Text(tr('${p['role'] ?? ''}'), style:", 1),
('course_class_roster_screen.dart', "items: _addRoles.map((r) => DropdownMenuItem(value: r, child: Text(r))).toList(),",
    "items: _addRoles.map((r) => DropdownMenuItem(value: r, child: Text(tr(r)))).toList(),", 1),
('course_class_roster_screen.dart', "items: _editRoles.map((r) => DropdownMenuItem(value: r, child: Text(r))).toList(),",
    "items: _editRoles.map((r) => DropdownMenuItem(value: r, child: Text(tr(r)))).toList(),", 1),
('person_enrolment_screen.dart', "subtitle: Text(e['role'] ?? '', style:", "subtitle: Text(tr('${e['role'] ?? ''}'), style:", 1),
('person_enrolment_screen.dart', "items: _addRoles.map((r) => DropdownMenuItem(value: r, child: Text(r))).toList(),",
    "items: _addRoles.map((r) => DropdownMenuItem(value: r, child: Text(tr(r)))).toList(),", 1),
('person_enrolment_screen.dart', "items: _editRoles.map((r) => DropdownMenuItem(value: r, child: Text(r))).toList(),",
    "items: _editRoles.map((r) => DropdownMenuItem(value: r, child: Text(tr(r)))).toList(),", 1),
('user_edit_screen.dart', "labels: {for (var r in _roles) r['gibbonRoleID'].toString(): r['name']},",
    "labels: {for (var r in _roles) r['gibbonRoleID'].toString(): tr('${r['name']}')},", 1),
('user_edit_screen.dart', "title: Text(r['name'] + (isPrimary ? ' (${tr('primary')})' : ''),",
    "title: Text(tr('${r['name']}') + (isPrimary ? ' (${tr('primary')})' : ''),", 1),
]
src = {}; bad = 0
for f, old, new, n in E:
    p = L + f; s = src.setdefault(p, open(p, encoding='utf-8').read()); c = s.count(old)
    if c != n: print(f'MISMATCH {f}: expected {n}, found {c}: {old[:80]!r}'); bad += 1; continue
    src[p] = s.replace(old, new)
if bad: sys.exit(1)
for p, s in src.items(): open(p, 'w', encoding='utf-8').write(s)
print(f'applied {len(E)} edits across {len(src)} files')
