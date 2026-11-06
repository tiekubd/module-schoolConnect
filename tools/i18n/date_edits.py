import sys, re
L = '/home/claude/gibbon-connect/lib/screens/'
MONTHS = "const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];"
E = [
('form_group_attendance_screen.dart', f"""  String get _displayDate {{
    {MONTHS}
    return '${{_selectedDate.day}} ${{months[_selectedDate.month - 1]}} ${{_selectedDate.year}}';
  }}""", "  String get _displayDate => DateText.date(_selectedDate);", 1),
('attendance_screen.dart', f"""  String get _displayDate {{
    {MONTHS}
    return '${{_selectedDate.day}} ${{months[_selectedDate.month - 1]}} ${{_selectedDate.year}}';
  }}""", "  String get _displayDate => DateText.date(_selectedDate);", 1),
('log_behaviour_screen.dart', f"""  String get _displayDate {{
    {MONTHS}
    return '${{_date.day}} ${{months[_date.month - 1]}} ${{_date.year}}';
  }}""", "  String get _displayDate => DateText.date(_date);", 1),
('homework_calendar_screen.dart', """  String _formatFullDate(String dateStr) {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    final d = DateTime.parse(dateStr);
    return '${weekdays[d.weekday - 1]} ${d.day} ${months[d.month - 1]} ${d.year}';
  }""", "  String _formatFullDate(String dateStr) => DateText.weekdayDate(DateTime.parse(dateStr));", 1),
('my_timetable_screen.dart', f"""    {MONTHS}
    final first = DateTime.parse(_weekDates.first);
    final last = DateTime.parse(_weekDates.last);
    return '${{first.day}} ${{months[first.month - 1]}} - ${{last.day}} ${{months[last.month - 1]}} ${{last.year}}';""",
"""    final first = DateTime.parse(_weekDates.first);
    final last = DateTime.parse(_weekDates.last);
    return DateText.range(first, last);""", 1),
('my_timetable_screen.dart', f"""    const weekdays = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    {MONTHS}
""", "", 1),
('my_timetable_screen.dart', "Text(weekdays[dateObj.weekday - 1],", "Text(DateText.weekdayShort(dateObj),", 1),
('my_timetable_screen.dart', "Text('${dateObj.day} ${months[dateObj.month - 1]}',", "Text(DateText.dayMonth(dateObj),", 1),
('dashboard_screen.dart', f"""    {MONTHS}
    final dateLabel = '${{today.day}} ${{months[today.month - 1]}} ${{today.year}}';""", "    final dateLabel = DateText.date(today);", 1),
('dashboard_screen.dart', """  String _fmt(String? t) {
    if (t == null) return '';
    final parts = t.split(':');
    if (parts.length < 2) return t;
    final h = int.tryParse(parts[0]) ?? 0;
    final m = parts[1];
    final suffix = h >= 12 ? 'PM' : 'AM';
    final h12 = h == 0 ? 12 : (h > 12 ? h - 12 : h);
    return '$h12:$m $suffix';
  }""", "  String _fmt(String? t) => DateText.timeOfDay(t);", 1),
('planner_view_screen.dart', f"""  String _displayDate(String dateStr) {{
    {MONTHS}
    final d = DateTime.parse(dateStr);
    return '${{d.day}} ${{months[d.month - 1]}} ${{d.year}}';
  }}""", "  String _displayDate(String dateStr) => DateText.date(DateTime.parse(dateStr));", 1),
('notifications_screen.dart', """    if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
    if (diff.inHours < 24) return '${diff.inHours}h ago';
    if (diff.inDays < 7) return '${diff.inDays}d ago';
    const months = ['Jan','Feb','Mar','Apr','May','Jun',
                    'Jul','Aug','Sep','Oct','Nov','Dec'];
    return '${dt.day} ${months[dt.month - 1]} ${dt.year}';""",
"""    if (diff.inMinutes < 60) return tr('{count}m ago', {'count': diff.inMinutes});
    if (diff.inHours < 24) return tr('{count}h ago', {'count': diff.inHours});
    if (diff.inDays < 7) return tr('{count}d ago', {'count': diff.inDays});
    return DateText.date(dt);""", 1),
('planner_list_screen.dart', f"""  String _displayDate(DateTime d) {{
    {MONTHS}
    return '${{d.day}} ${{months[d.month - 1]}} ${{d.year}}';
  }}

  String _weekday(String dateStr) {{
    const weekdays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    final d = DateTime.parse(dateStr);
    return weekdays[d.weekday - 1];
  }}""", """  String _displayDate(DateTime d) => DateText.date(d);

  String _weekday(String dateStr) => DateText.weekday(DateTime.parse(dateStr));""", 1),
('facility_timetable_screen.dart', """  String get _displayDate {
    const months = ['Jan','Feb','Mar','Apr','May','Jun',
                    'Jul','Aug','Sep','Oct','Nov','Dec'];
    const days   = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    return '${days[_selectedDate.weekday - 1]} '
           '${_selectedDate.day} '
           '${months[_selectedDate.month - 1]} '
           '${_selectedDate.year}';
  }""", "  String get _displayDate => DateText.weekdayDate(_selectedDate);", 1),
('facility_timetable_screen.dart', """  String _fmt(String? t) {
    if (t == null) return '';
    final parts = t.split(':');
    if (parts.length < 2) return t;
    final h    = int.tryParse(parts[0]) ?? 0;
    final m    = parts[1];
    final sfx  = h >= 12 ? 'PM' : 'AM';
    final h12  = h == 0 ? 12 : (h > 12 ? h - 12 : h);
    return '$h12:$m $sfx';
  }""", "  String _fmt(String? t) => DateText.timeOfDay(t);", 1),
('master_timetable_screen.dart', """  String _fmt(String? t) {
    if (t == null) return '';
    final parts = t.split(':');
    if (parts.length < 2) return t;
    final h   = int.tryParse(parts[0]) ?? 0;
    final m   = parts[1];
    final sfx = h >= 12 ? 'PM' : 'AM';
    final h12 = h == 0 ? 12 : (h > 12 ? h - 12 : h);
    return '$h12:$m $sfx';
  }""", "  String _fmt(String? t) => DateText.timeOfDay(t);", 1),
('master_timetable_screen.dart', ".map((d) => Tab(text: d['dayShort'] ?? d['dayName']))",
    ".map((d) => Tab(text: tr('${d['dayShort'] ?? d['dayName'] ?? ''}')))", 1),
('parent_dashboard_screen.dart', """  String _fmtTime(String? t) {
    if (t == null || t.isEmpty) return '';
    final parts = t.split(':');
    if (parts.length < 2) return t;
    final h = int.tryParse(parts[0]) ?? 0;
    final m = parts[1];
    final sfx = h >= 12 ? 'PM' : 'AM';
    final h12 = h == 0 ? 12 : (h > 12 ? h - 12 : h);
    return '$h12:$m $sfx';
  }""", "  String _fmtTime(String? t) => DateText.timeOfDay(t);", 1),
('message_wall_screen.dart', """      final hour = dt.hour == 0 ? 12 : (dt.hour > 12 ? dt.hour - 12 : dt.hour);
      final period = dt.hour >= 12 ? 'PM' : 'AM';
      return '${dt.day}/${dt.month}/${dt.year} · $hour:${dt.minute.toString().padLeft(2, '0')} $period';""",
"""      return '${DateText.numeric(dt)} · ${DateText.time(dt)}';""", 1),
('finance_invoice_detail_screen.dart', """      final hour = d.hour == 0 ? 12 : (d.hour > 12 ? d.hour - 12 : d.hour);
      final period = d.hour >= 12 ? 'PM' : 'AM';
      return '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year} · $hour:${d.minute.toString().padLeft(2, '0')} $period';""",
"""      return '${DateText.numeric(d)} · ${DateText.time(d)}';""", 1),
('class_detail_screen.dart', "Text(day['dayShort'], style:", "Text(DateText.weekdayShortOf(day['date'], day['dayShort']), style:", 1),
('class_detail_screen.dart', "Text(day['month'], style:", "Text(DateText.monthShortOf(day['date'], day['month']), style:", 1),
('form_group_detail_screen.dart', "Text(day['dayShort'], style:", "Text(DateText.weekdayShortOf(day['date'], day['dayShort']), style:", 1),
('form_group_detail_screen.dart', "Text(day['month'], style:", "Text(DateText.monthShortOf(day['date'], day['month']), style:", 1),
]
src = {}; bad = 0
for f, old, new, n in E:
    p = L + f; s = src.setdefault(p, open(p, encoding='utf-8').read()); c = s.count(old)
    if c != n: print(f'MISMATCH {f}: expected {n}, found {c}: {old[:80]!r}'); bad += 1; continue
    src[p] = s.replace(old, new)
if bad: sys.exit(1)
# add the import where needed
for p, s in src.items():
    if 'DateText.' in s and "import 'date_text.dart';" not in s:
        s = re.sub(r"(import 'string_store\.dart';)", r"\1\nimport 'date_text.dart';", s, count=1)
        if "import 'date_text.dart';" not in s:
            s = re.sub(r"(import 'package:flutter/material\.dart';)", r"\1\nimport 'date_text.dart';", s, count=1)
    src[p] = s
for p, s in src.items(): open(p, 'w', encoding='utf-8').write(s)
print(f'applied {len(E)} edits across {len(src)} files')
