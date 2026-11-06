import sys, re
L = '/home/claude/gibbon-connect/lib/screens/'
E = [
('attendance_menu_screen.dart', "? 'Taken today at ${fg['takenAt'] ?? ''}. Tap to review or change'",
    "? tr('Taken today at {time}. Tap to review or change', {'time': DateText.timeOfDay(fg['takenAt']?.toString())})"),
('homework_calendar_screen.dart', "badge = '${items.length} due';", "badge = trN('{count} due', '{count} due', items.length);"),
('homework_calendar_screen.dart', "badge = '${assessments.length} assess.';", "badge = trN('{count} assess.', '{count} assess.', assessments.length);"),
('homework_calendar_screen.dart', "badge = '$total items';", "badge = trN('{count} item', '{count} items', total);"),
('dashboard_screen.dart', "summary = '$upcoming more class${upcoming == 1 ? '' : 'es'} after this · $total today';",
    "summary = trN('{count} more class after this · {total} today', '{count} more classes after this · {total} today', upcoming, {'total': total});"),
('dashboard_screen.dart', "summary = 'Last class of the day';", "summary = tr('Last class of the day');"),
('dashboard_screen.dart', "summary = '$upcoming class${upcoming == 1 ? '' : 'es'} remaining · $past done';",
    "summary = trN('{count} class remaining · {done} done', '{count} classes remaining · {done} done', upcoming, {'done': past});"),
('dashboard_screen.dart', "summary = '$upcoming class${upcoming == 1 ? '' : 'es'} today';",
    "summary = trN('{count} class today', '{count} classes today', upcoming);"),
('dashboard_screen.dart', "summary = 'All $total classes done for today';",
    "summary = trN('All {count} class done for today', 'All {count} classes done for today', total);"),
('data_updates_screen.dart', "      return '${date.day}/${date.month}/${date.year}';", "      return DateText.numeric(date);"),
('student_reports_screen.dart', "      return '${date.day}/${date.month}/${date.year}';", "      return DateText.numeric(date);"),
('parent_dashboard_screen.dart', "'$day, $start–$end${location.isNotEmpty ? ' · $location' : ''}',",
    "'${tr('$day')}, $start–$end${location.isNotEmpty ? ' · $location' : ''}',"),
('homework_due_dates_card.dart', """    final time = '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    final date = '${dt.day.toString().padLeft(2, '0')}/${dt.month.toString().padLeft(2, '0')}/${dt.year}';
    return 'Due at $time on $date';""",
    """    return tr('Due at {time} on {date}', {'time': DateText.time(dt), 'date': DateText.numeric(dt)});"""),
('log_behaviour_screen.dart', "? 'Could not save for: ${failed.join(', ')}'", "? tr('Could not save for: {names}', {'names': failed.join(', ')})"),
('log_behaviour_screen.dart', ": 'Saved for ${succeeded.length}, but failed for: ${failed.join(', ')}'),",
    ": trN('Saved for {count}, but failed for: {names}', 'Saved for {count}, but failed for: {names}', succeeded.length, {'names': failed.join(', ')})),"),
]
src = {}; bad = 0
for f, old, new in E:
    p = L + f; s = src.setdefault(p, open(p, encoding='utf-8').read()); c = s.count(old)
    if c != 1: print(f'MISMATCH {f}: found {c}: {old[:80]!r}'); bad += 1; continue
    src[p] = s.replace(old, new)
if bad: sys.exit(1)
for p, s in src.items():
    if 'DateText.' in s and "import 'date_text.dart';" not in s:
        s = re.sub(r"(import 'string_store\.dart';)", r"\1\nimport 'date_text.dart';", s, count=1) if "import 'string_store.dart';" in s else re.sub(r"(import 'package:flutter/material\.dart';)", r"\1\nimport 'date_text.dart';", s, count=1)
    if re.search(r"\btrN?\(", s) and "import 'string_store.dart';" not in s:
        s = re.sub(r"(import 'package:flutter/material\.dart';)", r"\1\nimport 'string_store.dart';", s, count=1)
    open(p, 'w', encoding='utf-8').write(s)
print(f'applied {len(E)} edits across {len(src)} files')
