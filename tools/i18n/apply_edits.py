import sys
L = '/home/claude/gibbon-connect/lib/screens/'
E = [
# ── near-misses: use Gibbon's exact wording ─────────────────────────────
('planner_list_screen.dart', "tr('Choose a class')", "tr('Choose A Class')", 1),
('log_behaviour_screen.dart', "tr('Choose students')", "tr('Choose Students')", 1),
('student_search_screen.dart', "tr('Clear filters')", "tr('Clear Filters')", 1),
('user_list_screen.dart', "tr('Clear filters')", "tr('Clear Filters')", 1),
('student_homework_section.dart', "tr('Due date')", "tr('Due Date')", 1),
('student_homework_section.dart', "tr('Homework details')", "tr('Homework Details')", 1),
('course_manage_list_screen.dart', "tr('Include in Curriculum Map')", "tr('Include In Curriculum Map')", 1),
('data_updates_screen.dart', "'${tr('Last updated')}: $dateStr'", "'${tr('Last Updated')}: $dateStr'", 1),
('class_detail_screen.dart', "tr('Not taken')", "tr('Not Taken')", 1),
('student_personal_screen.dart', "'${tr('Privacy required')}: ${_v('privacy')}'", "'${tr('Privacy required:')} ${_v('privacy')}'", 1),
('finance_invoice_detail_screen.dart', "'${_documentLabel()} ${tr('To')} (${inv['invoiceTo']}):'",
    "'${_documentToLabel()} (${inv['invoiceTo']}):'", 1),
('finance_invoice_detail_screen.dart', "'${_documentLabel()} ${tr('To')} (${_invoice!['invoiceTo']})'",
    "'${_documentToLabel()} (${_invoice!['invoiceTo']})'", 1),
('finance_invoice_detail_screen.dart',
    "  String _documentLabel() => tr((_invoice?['documentType'] == 'receipt') ? 'Receipt' : 'Invoice');",
    "  String _documentLabel() => tr((_invoice?['documentType'] == 'receipt') ? 'Receipt' : 'Invoice');\n"
    "  String _documentToLabel() => tr((_invoice?['documentType'] == 'receipt') ? 'Receipt To' : 'Invoice To');", 1),
# ── starred labels: star outside the translated text ────────────────────
('special_days_screen.dart', "tr('Name *')", "'${tr('Name')} *'", 1),
('course_manage_list_screen.dart', "tr('Name *')", "'${tr('Name')} *'", 1),
('course_detail_screen.dart', "tr('Name *')", "'${tr('Name')} *'", 1),
('user_password_screen.dart', "tr('Password *')", "'${tr('Password')} *'", 1),
('user_password_screen.dart', "tr('Confirm Password *')", "'${tr('Confirm Password')} *'", 1),
('course_class_roster_screen.dart', "tr('Role *')", "'${tr('Role')} *'", 2),
('person_enrolment_screen.dart', "tr('Role *')", "'${tr('Role')} *'", 2),
('course_manage_list_screen.dart', "tr('Short Name *')", "'${tr('Short Name')} *'", 1),
('course_detail_screen.dart', "tr('Short Name *')", "'${tr('Short Name')} *'", 1),
('special_days_screen.dart', "tr('Type *')", "'${tr('Type')} *'", 1),
# ── counts → trN with {count} ───────────────────────────────────────────
('course_manage_list_screen.dart', "${c['classCount']} ${tr(c['classCount'] == 1 ? 'class' : 'classes')}",
    "${trN('{count} class', '{count} classes', int.tryParse('${c['classCount']}') ?? 0)}", 1),
('master_timetable_screen.dart', "'${classes.length} ${tr(classes.length == 1 ? 'class' : 'classes')}'",
    "trN('{count} class', '{count} classes', classes.length)", 1),
('student_attendance_screen.dart', "'$present ${tr('days')}'", "trN('{count} day', '{count} days', present)", 1),
('student_attendance_screen.dart', "'$partial ${tr('days')}'", "trN('{count} day', '{count} days', partial)", 1),
('student_attendance_screen.dart', "'$absent ${tr('days')}'", "trN('{count} day', '{count} days', absent)", 1),
('student_attendance_screen.dart', "'$noData ${tr('days')}'", "trN('{count} day', '{count} days', noData)", 1),
('student_attendance_screen.dart', "'$schoolDays ${tr(isYear ? 'school days in the school year' : 'school days in term')}'",
    "isYear\n                    ? trN('{count} school day in the school year', '{count} school days in the school year', schoolDays)\n                    : trN('{count} school day in term', '{count} school days in term', schoolDays)", 1),
('student_attendance_screen.dart', "'$absent ${tr('Absent').toLowerCase()} of $total ${tr('sessions recorded')}'",
    "tr('{absent} absent of {total} sessions recorded', {'absent': absent, 'total': total})", 1),
('facility_list_screen.dart', "'${_filtered.length} ${tr(_filtered.length == 1 ? 'facility' : 'facilities')}'",
    "trN('{count} facility', '{count} facilities', _filtered.length)", 1),
('facility_list_screen.dart', "'$usage ${tr('sessions')}'", "trN('{count} session', '{count} sessions', usage)", 1),
('family_list_screen.dart', "'$_total ${tr(_total == 1 ? 'family' : 'families')}'", "trN('{count} family', '{count} families', _total)", 1),
('students_screen.dart', "'${_filtered.length} ${tr(_filtered.length == 1 ? 'student' : 'students')}'",
    "trN('{count} student', '{count} students', _filtered.length)", 1),
('user_list_screen.dart', "'$_total ${tr(_total == 1 ? 'user' : 'users')}'", "trN('{count} user', '{count} users', _total)", 1),
('staff_manage_list_screen.dart', "'$_total ${tr(_total == 1 ? 'staff member' : 'staff members')}'",
    "trN('{count} staff member', '{count} staff members', _total)", 1),
('staff_directory_screen.dart', "'${_filtered.length} ${tr(_filtered.length == 1 ? 'staff member' : 'staff members')}'",
    "trN('{count} staff member', '{count} staff members', _filtered.length)", 1),
('student_enrolment_list_screen.dart', "'$_total ${tr(_total == 1 ? 'student enrolled' : 'students enrolled')}'",
    "trN('{count} student enrolled', '{count} students enrolled', _total)", 1),
('notifications_screen.dart', "'$count ${tr('times')}'", "trN('{count} time', '{count} times', count)", 1),
('user_password_screen.dart', "'${tr('At least')} $minLength ${tr('characters')}'",
    "trN('At least {count} character', 'At least {count} characters', minLength)", 1),
('user_edit_screen.dart', "'${tr('At least')} $minLength ${tr('characters')}'",
    "trN('At least {count} character', 'At least {count} characters', minLength)", 1),
('log_behaviour_screen.dart', "'${tr('Behaviour records saved for')} ${succeeded.length} ${tr('students')}!'",
    "trN('Behaviour records saved for {count} student!', 'Behaviour records saved for {count} students!', succeeded.length)", 1),
# ── sentence pieces → whole sentences with {placeholders} ───────────────
('about_screen.dart', "    return '${tr('Installed version is')} $installed. '\n        '${tr('Click Update next to School Connect in System Admin > Manage Modules.')}';",
    "    return '${tr('Installed version is {version}.', {'version': installed})} '\n        '${tr('Click Update next to School Connect in System Admin > Manage Modules.')}';", 1),
('about_screen.dart', "'${tr(\"Your school is responsible for your personal data in Gibbon. To see, \"\n                        \"correct or delete it, contact your school's data protection officer. \"\n                        'For questions about the app itself, contact')} $kSupportEmail.'",
    "tr(\"Your school is responsible for your personal data in Gibbon. To see, \"\n                        \"correct or delete it, contact your school's data protection officer. \"\n                        'For questions about the app itself, contact {email}.', {'email': kSupportEmail})", 1),
('school_year_banner.dart', "              '${tr('You are viewing school year')} $yearName, ${tr('which is not the '\n              'current year. Data may not look quite right (for example, '\n              'students who have left the school will not appear in '\n              'previous years).')}',",
    "              tr('You are viewing school year {year}, which is not the '\n              'current year. Data may not look quite right (for example, '\n              'students who have left the school will not appear in '\n              'previous years).', {'year': yearName}),", 1),
('impersonation_banner.dart', "'${tr('Viewing as')} ${ImpersonationStore.targetName}'",
    "tr('Viewing as {name}', {'name': ImpersonationStore.targetName})", 1),
('impersonation_banner.dart', "'${tr('until')} ${_time(ends)}'", "tr('until {time}', {'time': _time(ends)})", 1),
('impersonate_user_screen.dart', "'${tr('Your session will be switched to')} $name (${person['role'] ?? ''}).\\n\\n'",
    "'${tr('Your session will be switched to {name} ({role}).', {'name': name, 'role': person['role'] ?? ''})}\\n\\n'", 1),
('course_detail_screen.dart', "'${tr('Permanently delete')} \"${_course?['name']}\" ${tr('and all of its classes?')} '",
    "'${tr('Permanently delete \"{name}\" and all of its classes?', {'name': _course?['name']})} '", 1),
('course_detail_screen.dart', "'${tr('Combined short name')} \"${widget.courseNameShort}${_nameShortCtrl.text}\" '\n                    '${tr('is longer than 16 characters — this may cause display issues on the timetable and in dropdowns.')}'",
    "tr('Combined short name \"{name}\" is longer than 16 characters — this may cause display issues on the timetable and in dropdowns.',\n                        {'name': '${widget.courseNameShort}${_nameShortCtrl.text}'})", 1),
('person_enrolment_screen.dart', "'${tr('Remove enrolment in')} ${e['courseNameShort']}.${e['classNameShort']}?'",
    "tr('Remove enrolment in {class}?', {'class': '${e['courseNameShort']}.${e['classNameShort']}'})", 1),
('staff_manage_list_screen.dart', "'${tr('Remove')} $name ${tr('from staff?')} ${tr('Their user account is not affected.')}'",
    "'${tr('Remove {name} from staff?', {'name': name})} ${tr('Their user account is not affected.')}'", 1),
('special_days_screen.dart', "'${tr('Remove')} \"${_nameCtrl.text}\" ${tr('from the calendar?')}'",
    "tr('Remove \"{name}\" from the calendar?', {'name': _nameCtrl.text})", 1),
('course_class_roster_screen.dart', "'${tr('Remove')} ${p['preferredName']} ${p['surname']} ${tr('from this class?')}'",
    "tr('Remove {name} from this class?', {'name': '${p['preferredName']} ${p['surname']}'})", 1),
('family_detail_screen.dart', "'${tr('Remove')} $name ${tr('from this family?')} ${tr('This does not delete their user account.')}'",
    "'${tr('Remove {name} from this family?', {'name': name})} ${tr('This does not delete their user account.')}'", 1),
('student_enrolment_list_screen.dart', "'${tr('Unenroll')} $name ${tr('from this school year?')} ${tr('Their user account is not affected.')}'",
    "'${tr('Unenroll {name} from this school year?', {'name': name})} ${tr('Their user account is not affected.')}'", 1),
('student_emergency_screen.dart', "'${tr('Contact')} $n — ${tr('not on record')}'",
    "tr('Contact {number} — not on record', {'number': n})", 1),
]
src = {}
bad = 0
for f, old, new, n in E:
    p = L + f
    s = src.setdefault(p, open(p, encoding='utf-8').read())
    c = s.count(old)
    if c != n:
        print(f'MISMATCH {f}: expected {n}, found {c}: {old[:90]!r}'); bad += 1; continue
    src[p] = s.replace(old, new)
if bad: sys.exit(1)
for p, s in src.items(): open(p, 'w', encoding='utf-8').write(s)
print(f'applied {len(E)} edits across {len(src)} files')
