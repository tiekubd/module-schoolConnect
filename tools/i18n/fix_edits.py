import sys, re
L = '/home/claude/gibbon-connect/lib/screens/'
E = [
# student counts
('attendance_screen.dart', "Text('${_students.length} students', style:", "Text(trN('{count} student', '{count} students', _students.length), style:", 1),
('form_group_attendance_screen.dart', "Text('${_students.length} students', style:", "Text(trN('{count} student', '{count} students', _students.length), style:", 1),
('courses_screen.dart', "Text('${cls['studentCount']} students',", "Text(trN('{count} student', '{count} students', int.tryParse('${cls['studentCount']}') ?? 0),", 1),
('form_groups_screen.dart', "Text('${fg['studentCount']} students',", "Text(trN('{count} student', '{count} students', int.tryParse('${fg['studentCount']}') ?? 0),", 1),
# attendance code names, translated like core (__($attendanceCodeName))
('attendance_screen.dart', "Text(code['name'], style:", "Text(tr('${code['name']}'), style:", 1),
('form_group_attendance_screen.dart', "Text(code['name'], style:", "Text(tr('${code['name']}'), style:", 1),
# stored error messages
('form_group_attendance_screen.dart', "_errorMessage = 'Could not load data';", "_errorMessage = tr('Could not load data');", 1),
('attendance_screen.dart', "_errorMessage = 'Could not load data';", "_errorMessage = tr('Could not load data');", 1),
('role_picker_screen.dart', "_errorMessage = 'Could not load roles';", "_errorMessage = tr('Could not load roles');", 1),
('form_group_detail_screen.dart', "_errorMessage = 'Could not load students';", "_errorMessage = tr('Could not load students');", 1),
# notification module chip, translated like core's module names
('notifications_screen.dart', "                                                n['moduleName'],", "                                                tr('${n['moduleName']}'),", 1),
# homework calendar: month heading from the cells' dates, weekday letters and legend in the language
('homework_calendar_screen.dart', "    const dayHeaders = ['M', 'T', 'W', 'T', 'F'];", "    final dayHeaders = DateText.weekdayLettersMonToFri();", 1),
('homework_calendar_screen.dart', "                  Text(month['label'],", "                  Text(_monthHeading(month),", 1),
('homework_calendar_screen.dart', "                          label: (l['label'] ?? '').toString(),", "                          label: tr((l['label'] ?? '').toString()),", 1),
('homework_calendar_screen.dart', "  Widget _buildCalendar(Map<String, dynamic> calData) {",
"""  // "nov. 2026" in the person's language, from the first day of the month
  // shown; falls back to the server's English label.
  String _monthHeading(dynamic month) {
    for (final week in List<dynamic>.from(month['weeks'] ?? [])) {
      for (final cell in List<dynamic>.from(week ?? [])) {
        if (cell is Map && cell['type'] != 'outside' && cell['type'] != 'empty') {
          final d = DateText.parse(cell['date']);
          if (d != null) return DateText.monthYear(d);
        }
      }
    }
    return '${month['label'] ?? ''}';
  }

  Widget _buildCalendar(Map<String, dynamic> calData) {""", 1),
]
src = {}; bad = 0
for f, old, new, n in E:
    p = L + f; s = src.setdefault(p, open(p, encoding='utf-8').read()); c = s.count(old)
    if c != n: print(f'MISMATCH {f}: expected {n}, found {c}: {old[:80]!r}'); bad += 1; continue
    src[p] = s.replace(old, new)
if bad: sys.exit(1)
for p, s in src.items():
    if 'DateText.' in s and "import 'date_text.dart';" not in s:
        s = re.sub(r"(import 'string_store\.dart';)", r"\1\nimport 'date_text.dart';", s, count=1)
    if re.search(r"\btrN?\(", s) and "import 'string_store.dart';" not in s:
        s = re.sub(r"(import 'package:flutter/material\.dart';)", r"\1\nimport 'string_store.dart';", s, count=1)
    open(p, 'w', encoding='utf-8').write(s)
print(f'applied {len(E)} edits across {len(src)} files')
