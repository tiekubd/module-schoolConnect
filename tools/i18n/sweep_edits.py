import sys
L = '/home/claude/gibbon-connect/lib/'
S = 'screens/'
E = [
# SnackBars
(S+'form_group_attendance_screen.dart', "const SnackBar(content: Text('Could not save attendance'), backgroundColor: Colors.red)",
    "SnackBar(content: Text(tr('Could not save attendance')), backgroundColor: Colors.red)", 1),
(S+'attendance_screen.dart', "const SnackBar(content: Text('Could not save attendance'), backgroundColor: Colors.red)",
    "SnackBar(content: Text(tr('Could not save attendance')), backgroundColor: Colors.red)", 1),
(S+'role_picker_screen.dart', "const SnackBar(content: Text('Could not switch role'), backgroundColor: Colors.red)",
    "SnackBar(content: Text(tr('Could not switch role')), backgroundColor: Colors.red)", 1),
(S+'my_timetable_screen.dart', "showSnackBar(const SnackBar(\n                        content: Text('Lesson planning is coming soon'),",
    "showSnackBar(SnackBar(\n                        content: Text(tr('Lesson planning is coming soon')),", 1),
# Legend
(S+'attendance_screen.dart', "const Text('Legend: ', style: TextStyle(fontSize: 12, color: Colors.grey)),",
    "Text('${tr('Legend')}: ', style: const TextStyle(fontSize: 12, color: Colors.grey)),", 1),
(S+'form_group_attendance_screen.dart', "const Text('Legend: ', style: TextStyle(fontSize: 12, color: Colors.grey)),",
    "Text('${tr('Legend')}: ', style: const TextStyle(fontSize: 12, color: Colors.grey)),", 1),
# Role picker (paragraph is Gibbon's own msgid, word for word)
(S+'role_picker_screen.dart', "title: const Text('Role Switcher', style: TextStyle(color: Colors.white, fontSize: 16)),",
    "title: Text(tr('Role Switcher'), style: const TextStyle(color: Colors.white, fontSize: 16)),", 1),
(S+'role_picker_screen.dart', "const Text('ROLE SWITCHER',\n                          style: TextStyle(",
    "Text(tr('Role Switcher').toUpperCase(),\n                          style: const TextStyle(", 1),
(S+'role_picker_screen.dart', "const Text(\n                        'You have multiple roles within the system. Use the '\n                        'list below to switch role:',\n                        style: TextStyle(fontSize: 13, color: Colors.black87),",
    "Text(\n                        tr('You have multiple roles within the system. Use the '\n                        'list below to switch role:'),\n                        style: const TextStyle(fontSize: 13, color: Colors.black87),", 1),
(S+'role_picker_screen.dart', ": const Text('Switch',\n                                      style: TextStyle(fontWeight: FontWeight.bold)),",
    ": Text(tr('Switch'),\n                                      style: const TextStyle(fontWeight: FontWeight.bold)),", 1),
# Dashboard tooltips and About tile
(S+'dashboard_screen.dart', "tooltip: 'Data Updater',", "tooltip: tr('Data Updater'),", 1),
(S+'dashboard_screen.dart', "tooltip: 'Message Wall',", "tooltip: tr('Message Wall'),", 1),
(S+'dashboard_screen.dart', "tooltip: 'Switch Role',", "tooltip: tr('Switch Role'),", 1),
(S+'dashboard_screen.dart', "tooltip: 'Switch School Year',", "tooltip: tr('Switch School Year'),", 1),
(S+'dashboard_screen.dart', "tooltip: 'Notifications',", "tooltip: tr('Notifications'),", 1),
(S+'dashboard_screen.dart', "tooltip: 'Logout',", "tooltip: tr('Logout'),", 1),
(S+'dashboard_screen.dart', "title: const Text('About & Privacy',", "title: Text(tr('About & Privacy'),", 1),
(S+'dashboard_screen.dart', "subtitle: const Text('App version, connected school and your data'),", "subtitle: Text(tr('App version, connected school and your data')),", 1),
(S+'parent_dashboard_screen.dart', "tooltip: 'Switch Role',", "tooltip: tr('Switch Role'),", 1),
(S+'parent_dashboard_screen.dart', "tooltip: 'Switch School Year',", "tooltip: tr('Switch School Year'),", 1),
(S+'parent_dashboard_screen.dart', "tooltip: 'About & Privacy',", "tooltip: tr('About & Privacy'),", 1),
(S+'parent_dashboard_screen.dart', "tooltip: 'Logout',", "tooltip: tr('Logout'),", 1),
('widgets/dashboard_home_action.dart', "tooltip: 'Dashboard',", "tooltip: tr('Dashboard'),", 1),
# Student search status chips (values are Gibbon's Full / Expected / Left / Pending)
(S+'student_search_screen.dart', "label: Text('Status: ${s[0].toUpperCase()}${s.substring(1)}'),",
    "label: Text('${tr('Status')}: ${tr('${s[0].toUpperCase()}${s.substring(1)}')}'),", 1),
(S+'student_search_screen.dart', "label: Text('Status: ${_statusFilter[0].toUpperCase()}${_statusFilter.substring(1)}',",
    "label: Text('${tr('Status')}: ${tr('${_statusFilter[0].toUpperCase()}${_statusFilter.substring(1)}')}',", 1),
# Form group detail
(S+'form_group_detail_screen.dart', "const Text('Last 20 school days',", "Text(tr('Last 20 school days'),", 1),
(S+'form_group_detail_screen.dart', "? const Text('No history available', style: TextStyle(color: Colors.grey))",
    "? Text(tr('No history available'), style: const TextStyle(color: Colors.grey))", 1),
(S+'form_group_detail_screen.dart', "const Text('Taken', style: TextStyle(fontSize: 11, color: Colors.grey)),",
    "Text(tr('Taken'), style: const TextStyle(fontSize: 11, color: Colors.grey)),", 1),
(S+'form_group_detail_screen.dart', "const Text('Not taken', style: TextStyle(fontSize: 11, color: Colors.grey)),",
    "Text(tr('Not Taken'), style: const TextStyle(fontSize: 11, color: Colors.grey)),", 1),
(S+'form_group_detail_screen.dart', "const Text('Today', style: TextStyle(fontSize: 11, color: Colors.grey)),",
    "Text(tr('Today'), style: const TextStyle(fontSize: 11, color: Colors.grey)),", 1),
(S+'form_group_detail_screen.dart', "hintText: 'Search students...',", "hintText: tr('Search students...'),", 1),
(S+'form_group_detail_screen.dart', "? const Center(child: Text('No students found'))", "? Center(child: Text(tr('No students found')))", 1),
# Sentences with values (the last two are Gibbon's own msgids)
(S+'attendance_screen.dart', "'This class is not scheduled on $_displayDate. You can still save attendance if needed.',",
    "tr('This class is not scheduled on {date}. You can still save attendance if needed.', {'date': _displayDate}),", 1),
(S+'planner_view_screen.dart', "'Due on ${_displayDate((_lesson!['homeworkDueDateTime'] as String).substring(0, 10))} '\n                                'at ${(_lesson!['homeworkDueDateTime'] as String).substring(11, 16)}.',",
    "tr('Due on {date} at {time}.', {\n                                  'date': _displayDate((_lesson!['homeworkDueDateTime'] as String).substring(0, 10)),\n                                  'time': (_lesson!['homeworkDueDateTime'] as String).substring(11, 16),\n                                }),", 1),
(S+'student_profile_screen.dart', "'This student has one or more $level risk medical conditions.',",
    "tr('This student has one or more {level} risk medical conditions.', {'level': level}),", 1),
# About: Powered by Gibbon card and the two notices
(S+'about_screen.dart', "                  const Row(\n                    children: [\n                      Icon(Icons.favorite, size: 16, color: Color(0xFF0072BB)),\n                      SizedBox(width: 8),\n                      Text('Powered by Gibbon', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),",
    "                  Row(\n                    children: [\n                      const Icon(Icons.favorite, size: 16, color: Color(0xFF0072BB)),\n                      const SizedBox(width: 8),\n                      Text(tr('Powered by Gibbon'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),", 1),
(S+'about_screen.dart', "                  const Text(\n                    'School Connect is a third-party application that is compatible with '\n                    'Gibbon, the free and open-source school management platform. It is not '\n                    'an official Gibbon Foundation release and is not affiliated with or '\n                    'endorsed by the Gibbon Foundation.',\n                    style: TextStyle(",
    "                  Text(\n                    tr('School Connect is a third-party application that is compatible with '\n                    'Gibbon, the free and open-source school management platform. It is not '\n                    'an official Gibbon Foundation release and is not affiliated with or '\n                    'endorsed by the Gibbon Foundation.'),\n                    style: const TextStyle(", 1),
(S+'about_screen.dart', "                  const Text(\n                    'Gibbon™ is a trademark of Gibbon Education Ltd. (Hong Kong), used with permission.',\n                    style: TextStyle(",
    "                  Text(\n                    tr('Gibbon™ is a trademark of Gibbon Education Ltd. (Hong Kong), used with permission.'),\n                    style: const TextStyle(", 1),
]
src = {}; bad = 0
for f, old, new, n in E:
    p = L + f; s = src.setdefault(p, open(p, encoding='utf-8').read()); c = s.count(old)
    if c != n: print(f'MISMATCH {f}: expected {n}, found {c}: {old[:90]!r}'); bad += 1; continue
    src[p] = s.replace(old, new)
if bad: sys.exit(1)
for p, s in src.items(): open(p, 'w', encoding='utf-8').write(s)
print(f'applied {len(E)} edits across {len(src)} files')
