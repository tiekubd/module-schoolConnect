<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config.php';

// Homework Calendar — a month-grid view of teacher-assigned homework due
// dates (pe.homeworkDueDateTime), structurally mirroring
// student_attendance.php's calendar (same 5-day-week/month grouping,
// same special-day/school-day handling), but each cell means "was
// homework due here" instead of "was I present here". NOT the Student
// Recorded Homework feature (gibbonPlannerEntryStudentHomework) — that's
// the student's own personal reminder, this is the official deadline.
//
// Academic Calendar integration: when Steve Gillott's Academic Calendar
// module (github.com/sgillott/module-academicCalendar, v1.1.00) is
// installed and active, this endpoint reads that module's own settings
// (gibbonSetting scope 'Academic Calendar') so the app shows the same
// homework/assessment calendar the school configured on the web: Markbook
// assessment dates, event-type visibility, classifications, colours,
// display basis, year-group enablement and same-day merging. The helper
// logic below deliberately mirrors that module's moduleFunctions.php and
// calendar_eventsJSON.php (verified against its source) rather than
// reinventing it. Without the module, behaviour is unchanged: homework only.
//
// Backward compatibility with older app builds: homework cells keep
// type 'homework' + 'items' exactly as before; assessments live in a
// separate 'assessments' key, and an assessment-only day stays type
// 'none', so builds that predate this simply ignore assessments.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? 'main');
$termID = trim($input['termID'] ?? '');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);
    $schoolYearID = $authUser['schoolYearID'];
    $viewerRole = $authUser['role'];

    // Same resolver used everywhere else in the app (my_timetable.php,
    // planner.php) — any Staff member can view anyone's calendar; a
    // Parent only their own genuinely linked child; anyone else silently
    // falls back to their own.
    $personID = gibbonConnectResolvePersonID(
        trim($input['viewPersonID'] ?? '') ?: null,
        $authUser,
        $pdo
    );

    // ── Academic Calendar helpers (mirroring the module's own logic) ──────

    function gibbonConnectAcNormalizeHex(string $color): ?string {
        $color = trim($color);
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? strtoupper($color) : null;
    }

    function gibbonConnectAcNormalizeKey(?string $key): string {
        $key = strtolower(trim((string) $key));
        if ($key === '') return '';
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $key), '_');
        return preg_match('/^[a-z0-9_]+$/', $key) === 1 ? $key : '';
    }

    function gibbonConnectAcYesNo(?string $value): string {
        return strtoupper(trim((string) $value)) === 'N' ? 'N' : 'Y';
    }

    // ac_colorFromPalette: deterministic colour per event type
    function gibbonConnectAcPalette(string $key): string {
        $palette = [
            '#1F77B4', '#FF7F0E', '#2CA02C', '#D62728', '#9467BD',
            '#8C564B', '#E377C2', '#7F7F7F', '#BCBD22', '#17BECF',
        ];
        return $palette[abs(crc32($key)) % count($palette)];
    }

    function gibbonConnectAcMixWithWhite(string $color, float $whiteRatio): string {
        $n = gibbonConnectAcNormalizeHex($color);
        if ($n === null) return '#FFFFFF';
        $whiteRatio = max(0.0, min(1.0, $whiteRatio));
        $base = 1 - $whiteRatio;
        $r = (int) round(hexdec(substr($n, 1, 2)) * $base + 255 * $whiteRatio);
        $g = (int) round(hexdec(substr($n, 3, 2)) * $base + 255 * $whiteRatio);
        $b = (int) round(hexdec(substr($n, 5, 2)) * $base + 255 * $whiteRatio);
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    // ac_getContrastingTextColor
    function gibbonConnectAcTextColor(string $background): string {
        $c = gibbonConnectAcNormalizeHex($background);
        if ($c === null) return '#111827';
        $lin = function (float $ch): float {
            return $ch <= 0.03928 ? $ch / 12.92 : pow(($ch + 0.055) / 1.055, 2.4);
        };
        $l = 0.2126 * $lin(hexdec(substr($c, 1, 2)) / 255)
           + 0.7152 * $lin(hexdec(substr($c, 3, 2)) / 255)
           + 0.0722 * $lin(hexdec(substr($c, 5, 2)) / 255);
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.05) ? '#FFFFFF' : '#111827';
    }

    // ac_decodeAssessmentClassificationDefinitions (incl. the older flat map)
    function gibbonConnectAcDecodeClassifications(?string $json): array {
        $defaults = [
            'formative' => ['label' => 'Formative', 'color' => '#F97316'],
            'summative' => ['label' => 'Summative', 'color' => '#1D4ED8'],
            'none'      => ['label' => 'Not Classified', 'color' => '#9CA3AF'],
        ];
        $decoded = empty($json) ? null : json_decode($json, true);
        if (!is_array($decoded)) return $defaults;

        $isFlatMap = true;
        foreach ($decoded as $value) {
            if (!is_string($value)) { $isFlatMap = false; break; }
        }
        if ($isFlatMap) {
            foreach (array_keys($defaults) as $key) {
                $color = gibbonConnectAcNormalizeHex((string) ($decoded[$key] ?? ''));
                if ($color !== null) $defaults[$key]['color'] = $color;
            }
            return $defaults;
        }

        $definitions = ['none' => $defaults['none']];
        foreach ($decoded as $rawKey => $value) {
            if (!is_array($value)) continue;
            $key = gibbonConnectAcNormalizeKey((string) $rawKey);
            if ($key === '') continue;
            $label = trim((string) ($value['label'] ?? ''));
            $color = gibbonConnectAcNormalizeHex((string) ($value['color'] ?? ''));
            if ($label === '' || $color === null) continue;
            $definitions[$key] = ['label' => $label, 'color' => $color];
        }
        // 'Not Classified' is a fixed fallback: only its colour is configurable
        $definitions['none']['label'] = $defaults['none']['label'];
        return $definitions;
    }

    // ac_decodeEventTypeMeta: {type: {visible, classification}}
    function gibbonConnectAcDecodeEventTypeMeta(?string $json): array {
        $map = empty($json) ? null : json_decode($json, true);
        if (!is_array($map)) return [];
        $clean = [];
        foreach ($map as $key => $value) {
            $type = trim((string) $key);
            if ($type === '' || !is_array($value)) continue;
            $classification = gibbonConnectAcNormalizeKey((string) ($value['classification'] ?? ''));
            $clean[$type] = [
                'visible' => strtoupper((string) ($value['visible'] ?? 'Y')) === 'N' ? 'N' : 'Y',
                'classification' => $classification === 'none' ? '' : $classification,
            ];
        }
        return $clean;
    }

    function gibbonConnectAcDecodeColorMap(?string $json): array {
        $map = empty($json) ? null : json_decode($json, true);
        if (!is_array($map)) return [];
        $clean = [];
        foreach ($map as $key => $value) {
            $type = trim((string) $key);
            $color = gibbonConnectAcNormalizeHex((string) $value);
            if ($type !== '' && $color !== null) $clean[$type] = $color;
        }
        return $clean;
    }

    function gibbonConnectAcParseIDList(?string $list): array {
        if (empty($list)) return [];
        $ids = array_filter(array_map('trim', explode(',', (string) $list)), function ($id) {
            return ctype_digit((string) $id);
        });
        return array_values(array_unique($ids));
    }

    // Returns the integration config. 'installed' => false means behave
    // exactly as before this integration existed (homework only).
    function gibbonConnectLoadAcademicCalendarConfig(PDO $pdo): array {
        $config = ['installed' => false, 'showHomework' => true, 'showAssessments' => false];

        $stmt = $pdo->prepare("SELECT active FROM gibbonModule WHERE name = 'Academic Calendar'");
        $stmt->execute();
        if ($stmt->fetchColumn() !== 'Y') return $config;

        $stmt = $pdo->prepare("SELECT name, value FROM gibbonSetting WHERE scope = 'Academic Calendar'");
        $stmt->execute();
        $s = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $definitions = gibbonConnectAcDecodeClassifications($s['assessmentClassificationColors'] ?? '');

        // ac_decodeDefaultAssessmentFilter: every key defaults to Y
        $filter = [];
        $filterData = json_decode((string) ($s['defaultAssessmentFilter'] ?? ''), true);
        foreach (array_keys($definitions) as $key) {
            $value = is_array($filterData) ? strtoupper((string) ($filterData[$key] ?? 'Y')) : 'Y';
            $filter[$key] = $value === 'N' ? 'N' : 'Y';
        }

        // ac_buildAssessmentClassificationStyles
        $styles = [];
        foreach ($definitions as $key => $def) {
            $styles[$key] = [
                'border' => $def['color'],
                'highlight' => gibbonConnectAcMixWithWhite($def['color'], $key === 'none' ? 0.96 : 0.92),
            ];
        }

        $basis = trim((string) ($s['assessmentDisplayBasis'] ?? ''));
        if (!in_array($basis, ['classCode', 'courseShortName', 'courseName', 'learningArea'], true)) {
            $basis = 'classCode';
        }

        return [
            'installed' => true,
            // Both use "true unless explicitly 'N'", as in the module
            'showHomework' => (string) ($s['showHomeworkEvents'] ?? '') !== 'N',
            'showAssessments' => (string) ($s['showAssessmentEvents'] ?? '') !== 'N',
            'eventTypeMeta' => gibbonConnectAcDecodeEventTypeMeta($s['eventTypeMeta'] ?? ''),
            'eventTypeColors' => gibbonConnectAcDecodeColorMap($s['eventTypeColors'] ?? ''),
            'definitions' => $definitions,
            'styles' => $styles,
            'filter' => $filter,
            'useClassificationColor' => gibbonConnectAcYesNo($s['useAssessmentClassificationColorInCalendar'] ?? 'N') === 'Y',
            'displayBasis' => $basis,
            'merge' => gibbonConnectAcYesNo($s['mergeSameDayAssessments'] ?? 'N') === 'Y',
            'enabledYearGroupIDs' => gibbonConnectAcParseIDList($s['gibbonYearGroupIDList'] ?? ''),
        ];
    }

    // ac_filterEventRowsByEnabledYearGroups: no setting = all enabled; a
    // course with no year groups is always kept
    function gibbonConnectAcRowInEnabledYearGroups(array $row, array $ac): bool {
        if (empty($ac['enabledYearGroupIDs'])) return true;
        $courseYearGroups = gibbonConnectAcParseIDList((string) ($row['gibbonYearGroupIDList'] ?? ''));
        if (empty($courseYearGroups)) return true;
        return count(array_intersect($courseYearGroups, $ac['enabledYearGroupIDs'])) > 0;
    }

    function gibbonConnectAcClassCode(array $row): string {
        $courseShort = trim((string) ($row['courseNameShort'] ?? ''));
        $classShort  = trim((string) ($row['classNameShort'] ?? ''));
        if ($courseShort !== '' && $classShort !== '') return $courseShort . '.' . $classShort;
        return trim($courseShort . $classShort);
    }

    // ac_getAssessmentDisplayValue (shared by homework and assessments)
    function gibbonConnectAcDisplayValue(array $row, string $basis, string $fallback): string {
        $options = [
            'classCode' => gibbonConnectAcClassCode($row),
            'courseShortName' => trim((string) ($row['courseNameShort'] ?? '')),
            'courseName' => trim((string) ($row['courseName'] ?? '')),
            'learningArea' => trim((string) ($row['learningArea'] ?? '')),
        ];
        if (!empty($options[$basis])) return $options[$basis];
        foreach (['learningArea', 'courseShortName', 'courseName', 'classCode'] as $b) {
            if (!empty($options[$b])) return $options[$b];
        }
        return $fallback;
    }

    // ac_resolveAssessmentEventColor: a Markbook column's own colour wins,
    // unless the classification-colour override is on
    function gibbonConnectAcResolveColor(string $columnColor, string $type, string $classification, array $ac): string {
        $classKey = ($classification !== '' && isset($ac['styles'][$classification])) ? $classification : 'none';
        $classColor = gibbonConnectAcNormalizeHex((string) ($ac['styles'][$classKey]['highlight'] ?? ''));
        $direct = gibbonConnectAcNormalizeHex($columnColor);
        if ($ac['useClassificationColor']) {
            return $classColor ?? $direct ?? gibbonConnectAcPalette($type);
        }
        if ($direct !== null) return $direct;
        return gibbonConnectAcNormalizeHex((string) ($ac['eventTypeColors'][$type] ?? '')) ?? gibbonConnectAcPalette($type);
    }

    // ── Terms ─────────────────────────────────────────────────────────────
    $termStmt = $pdo->prepare("
        SELECT gibbonSchoolYearTermID, name, firstDay, lastDay
        FROM gibbonSchoolYearTerm WHERE gibbonSchoolYearID = ? ORDER BY firstDay
    ");
    $termStmt->execute([$schoolYearID]);
    $terms = $termStmt->fetchAll(PDO::FETCH_ASSOC);

    $today = date('Y-m-d');
    $currentTermID = null;
    foreach ($terms as $t) {
        if ($today >= $t['firstDay'] && $today <= $t['lastDay']) {
            $currentTermID = $t['gibbonSchoolYearTermID'];
            break;
        }
    }
    if (!$currentTermID && !empty($terms)) {
        $currentTermID = end($terms)['gibbonSchoolYearTermID'];
    }

    $dowStmt = $pdo->query("SELECT sequenceNumber FROM gibbonDaysOfWeek WHERE schoolDay='Y'");
    $schoolDayNumbers = array_column($dowStmt->fetchAll(PDO::FETCH_ASSOC), 'sequenceNumber');

    $academicCalendar = gibbonConnectLoadAcademicCalendarConfig($pdo);

    // ── Homework fetch for a date range ──────────────────────────────────────
    // Same visibility rules as planner.php's get_upcoming_homework (verified
    // there against a live install): a Teacher always sees homework they
    // assigned; a Student/Parent only sees it when viewableStudents/
    // viewableParents='Y', gated by the enrollment-window guard. Unlike that
    // action (future-only "upcoming" reminders), this pulls every due date
    // in the given range regardless of past/future — a calendar should
    // still show what was due last week, not just what's ahead.
    function gibbonConnectFetchHomeworkByDate(PDO $pdo, string $personID, string $schoolYearID,
                                               string $viewerRole, string $first, string $last,
                                               array $ac): array {
        // Academic Calendar can switch homework off entirely
        if ($ac['installed'] && !$ac['showHomework']) return [];

        $viewableColumn = $viewerRole === 'Parent' ? 'viewableParents' : 'viewableStudents';
        $stmt = $pdo->prepare("
            SELECT pe.gibbonPlannerEntryID, pe.name AS lesson, pe.homeworkDueDateTime,
                   c.nameShort AS courseNameShort, cc.nameShort AS classNameShort,
                   c.name AS courseName, c.gibbonYearGroupIDList, d.name AS learningArea
            FROM gibbonPlannerEntry pe
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = pe.gibbonCourseClassID
            JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            LEFT JOIN gibbonDepartment d ON d.gibbonDepartmentID = c.gibbonDepartmentID
            JOIN gibbonCourseClassPerson ccp ON ccp.gibbonCourseClassID = pe.gibbonCourseClassID AND ccp.gibbonPersonID = ?
            WHERE c.gibbonSchoolYearID = ?
            AND pe.homework = 'Y'
            AND pe.homeworkDueDateTime >= ?
            AND pe.homeworkDueDateTime < ?
            AND (ccp.role = 'Teacher' OR (ccp.role = 'Student' AND pe.$viewableColumn = 'Y'))
            AND (ccp.dateEnrolled IS NULL OR ccp.dateEnrolled <= pe.date)
            AND (ccp.dateUnenrolled IS NULL OR ccp.dateUnenrolled > pe.homeworkDueDateTime)
            ORDER BY pe.homeworkDueDateTime ASC
        ");
        $stmt->execute([
            $personID, $schoolYearID,
            $first . ' 00:00:00',
            date('Y-m-d', strtotime($last . ' +1 day')) . ' 00:00:00',
        ]);

        $byDate = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($ac['installed'] && !gibbonConnectAcRowInEnabledYearGroups($row, $ac)) continue;

            $dueDateTime = new DateTime($row['homeworkDueDateTime']);
            $date = $dueDateTime->format('Y-m-d');
            $byDate[$date][] = [
                'gibbonPlannerEntryID' => $row['gibbonPlannerEntryID'],
                'lesson' => $row['lesson'],
                // With Academic Calendar, label by the school's chosen
                // display basis (its default, classCode, is identical to
                // the original "course.class" label)
                'courseClass' => $ac['installed']
                    ? gibbonConnectAcDisplayValue($row, $ac['displayBasis'], 'Homework')
                    : "{$row['courseNameShort']}.{$row['classNameShort']}",
                'timeStart' => $dueDateTime->format('H:i'),
            ];
        }
        return $byDate;
    }

    // ── Assessment fetch for a date range (Academic Calendar only) ─────────
    // Markbook column dates (gibbonMarkbookColumn.date — the 'Date' field,
    // shown when Markbook's enableGroupByTerm is on; NOT completeDate, which
    // is the 'Go Live Date'). Same person-centric scope and enrolment guard
    // as homework above; viewableStudents/viewableParents as in the module.
    // Returns [byDate, legend].
    function gibbonConnectFetchAssessmentsByDate(PDO $pdo, string $personID, string $schoolYearID,
                                                  string $viewerRole, string $first, string $last,
                                                  array $ac): array {
        if (!$ac['installed'] || !$ac['showAssessments']) return [[], []];

        $viewableColumn = $viewerRole === 'Parent' ? 'viewableParents' : 'viewableStudents';
        $stmt = $pdo->prepare("
            SELECT mc.gibbonMarkbookColumnID, mc.date AS assessmentDate, mc.name AS assessmentName,
                   mc.description AS assessmentDescription, mc.type AS assessmentType,
                   mc.columnColor AS assessmentColor,
                   c.nameShort AS courseNameShort, cc.nameShort AS classNameShort,
                   c.name AS courseName, c.gibbonYearGroupIDList, d.name AS learningArea
            FROM gibbonMarkbookColumn mc
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = mc.gibbonCourseClassID
            JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            LEFT JOIN gibbonDepartment d ON d.gibbonDepartmentID = c.gibbonDepartmentID
            JOIN gibbonCourseClassPerson ccp ON ccp.gibbonCourseClassID = mc.gibbonCourseClassID AND ccp.gibbonPersonID = ?
            WHERE c.gibbonSchoolYearID = ?
            AND mc.date IS NOT NULL
            AND mc.date >= ?
            AND mc.date <= ?
            AND (ccp.role = 'Teacher' OR (ccp.role = 'Student' AND mc.$viewableColumn = 'Y'))
            AND (ccp.dateEnrolled IS NULL OR ccp.dateEnrolled <= mc.date)
            AND (ccp.dateUnenrolled IS NULL OR ccp.dateUnenrolled > mc.date)
            ORDER BY mc.date, mc.sequenceNumber, mc.gibbonMarkbookColumnID
        ");
        $stmt->execute([$personID, $schoolYearID, $first, $last]);

        $items = [];   // keyed by merge key when merging, else sequential
        $legend = [];  // label => colour, in first-seen order
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!gibbonConnectAcRowInEnabledYearGroups($row, $ac)) continue;

            $type = trim((string) ($row['assessmentType'] ?? ''));
            if ($type === '') $type = 'Assessment';

            $meta = $ac['eventTypeMeta'][$type] ?? null;
            if (is_array($meta) && $meta['visible'] === 'N') continue;

            $classification = is_array($meta) ? $meta['classification'] : '';
            if ($classification !== '' && !isset($ac['definitions'][$classification])) $classification = '';
            $classKey = $classification !== '' ? $classification : 'none';
            if (($ac['filter'][$classKey] ?? 'Y') !== 'Y') continue;

            $name = trim((string) ($row['assessmentName'] ?? ''));
            if ($name === '') $name = 'Assessment';
            $subject = gibbonConnectAcDisplayValue($row, $ac['displayBasis'], 'Assessment');

            // ac_buildAssessmentEventTitle (student/parent form)
            $title = $subject;
            if (mb_strtolower($name) !== mb_strtolower($subject)) $title .= ' - ' . $name;

            $color = gibbonConnectAcResolveColor((string) ($row['assessmentColor'] ?? ''), $type, $classification, $ac);
            $borderColor = $ac['useClassificationColor'] ? ($ac['styles'][$classKey]['border'] ?? null) : null;
            $classificationLabel = $ac['definitions'][$classKey]['label'] ?? 'Not Classified';

            // Legend reflects what the colours mean: classifications when
            // the override is on, event types otherwise
            if ($ac['useClassificationColor']) {
                $legendLabel = $classificationLabel;
                $legendColor = $ac['styles'][$classKey]['border'] ?? $color;
            } else {
                $legendLabel = $type;
                $legendColor = gibbonConnectAcNormalizeHex((string) ($ac['eventTypeColors'][$type] ?? '')) ?? gibbonConnectAcPalette($type);
            }
            if (!isset($legend[$legendLabel])) $legend[$legendLabel] = $legendColor;

            $description = trim(html_entity_decode(strip_tags((string) ($row['assessmentDescription'] ?? '')), ENT_QUOTES, 'UTF-8'));

            $item = [
                'gibbonMarkbookColumnID' => $row['gibbonMarkbookColumnID'],
                'title' => $title,
                'name' => $name,
                'subject' => $subject,
                'courseClass' => gibbonConnectAcClassCode($row),
                'type' => $type,
                'classification' => $classificationLabel,
                'description' => $description,
                'color' => $color,
                'borderColor' => $borderColor,
                'textColor' => gibbonConnectAcTextColor($color),
                'mergedCount' => 1,
            ];

            if (!$ac['merge']) {
                $items[] = ['date' => $row['assessmentDate'], 'item' => $item];
                continue;
            }

            // ac_buildAssessmentMergeKey: same date, display value, title
            // and classification merge into one "subject xN" entry
            $mergeKey = implode('|', [$row['assessmentDate'], $subject, mb_strtolower($name), $classification]);
            if (!isset($items[$mergeKey])) {
                $items[$mergeKey] = ['date' => $row['assessmentDate'], 'item' => $item];
            } else {
                $items[$mergeKey]['item']['mergedCount']++;
                $items[$mergeKey]['item']['title'] = $subject . ' x' . $items[$mergeKey]['item']['mergedCount'];
            }
        }

        $byDate = [];
        foreach ($items as $entry) {
            $byDate[$entry['date']][] = $entry['item'];
        }

        $legendList = [];
        foreach ($legend as $label => $color) {
            $legendList[] = ['label' => (string) $label, 'color' => $color];
        }
        return [$byDate, $legendList];
    }

    // ── Build calendar for one term ────────────────────────────────────────
    // Same 5-day-week/month grouping as student_attendance.php's
    // buildCalendar(), different cell meaning: 'homework' (>=1 due date
    // that day, carries the list for tap-through) vs 'none'. Either may
    // also carry 'assessments' when Academic Calendar supplies them.
    function gibbonConnectBuildHomeworkCalendar(PDO $pdo, string $personID, string $schoolYearID, string $viewerRole,
                                                 array $terms, string $termID, array $schoolDayNumbers,
                                                 array $ac): array {
        $selectedTerm = null;
        foreach ($terms as $t) {
            if ($t['gibbonSchoolYearTermID'] === $termID) { $selectedTerm = $t; break; }
        }
        if (!$selectedTerm) return [];

        $firstDay = $selectedTerm['firstDay'];
        $lastDay  = $selectedTerm['lastDay'];

        $specialStmt = $pdo->prepare("
            SELECT date, type, name FROM gibbonSchoolYearSpecialDay
            WHERE gibbonSchoolYearTermID = ? ORDER BY date
        ");
        $specialStmt->execute([$termID]);
        $specialDays = [];
        foreach ($specialStmt->fetchAll(PDO::FETCH_ASSOC) as $sd) {
            $specialDays[$sd['date']] = $sd;
        }

        $homeworkByDate = gibbonConnectFetchHomeworkByDate($pdo, $personID, $schoolYearID, $viewerRole, $firstDay, $lastDay, $ac);
        [$assessmentsByDate, $assessmentLegend] = gibbonConnectFetchAssessmentsByDate($pdo, $personID, $schoolYearID, $viewerRole, $firstDay, $lastDay, $ac);

        $months = [];
        $current = new DateTime($firstDay);
        $end = new DateTime($lastDay); $end->modify('+1 day');
        $startOfWeek = clone $current;
        $dow = (int)$startOfWeek->format('N');
        if ($dow > 1) $startOfWeek->modify('-' . ($dow - 1) . ' days');

        while ($startOfWeek < $end) {
            $week = [];
            for ($d = 0; $d < 5; $d++) {
                $dateStr = $startOfWeek->format('Y-m-d');
                $dowN = (int)$startOfWeek->format('N');
                $inTerm = $dateStr >= $firstDay && $dateStr <= $lastDay;
                $assessments = $assessmentsByDate[$dateStr] ?? [];

                if (!$inTerm) {
                    $week[] = ['date' => $dateStr, 'type' => 'outside'];
                } elseif (isset($specialDays[$dateStr])) {
                    $week[] = ['date' => $dateStr, 'type' => 'special', 'label' => $specialDays[$dateStr]['name']];
                } elseif (!in_array($dowN, $schoolDayNumbers)) {
                    $week[] = ['date' => $dateStr, 'type' => 'noschool'];
                } elseif (isset($homeworkByDate[$dateStr])) {
                    $cell = ['date' => $dateStr, 'type' => 'homework', 'items' => $homeworkByDate[$dateStr]];
                    if ($assessments) $cell['assessments'] = $assessments;
                    $week[] = $cell;
                } else {
                    $cell = ['date' => $dateStr, 'type' => 'none'];
                    if ($assessments) $cell['assessments'] = $assessments;
                    $week[] = $cell;
                }
                $startOfWeek->modify('+1 day');
            }
            $startOfWeek->modify('+2 days');

            // Same month-boundary split as student_attendance.php's
            // buildCalendar() — these two calendars were built to mirror
            // each other, see that file for the full explanation. A
            // blanked cell here also correctly drops its 'items' and
            // 'assessments' arrays, so an entry only ever shows under the
            // month it actually belongs to, never duplicated into a
            // neighbouring month's row.
            $monthKeysInRow = [];
            foreach ($week as $cell) {
                $monthKeysInRow[(new DateTime($cell['date']))->format('Y-m')] = true;
            }

            foreach (array_keys($monthKeysInRow) as $monthKey) {
                $maskedWeek = [];
                foreach ($week as $cell) {
                    $cellMonthKey = (new DateTime($cell['date']))->format('Y-m');
                    $maskedWeek[] = ($cellMonthKey === $monthKey)
                        ? $cell
                        : ['date' => $cell['date'], 'type' => 'outside'];
                }

                $monthLabel = (new DateTime($monthKey . '-01'))->format('M Y');
                if (!isset($months[$monthKey])) {
                    $months[$monthKey] = ['label' => $monthLabel, 'weeks' => []];
                }
                $months[$monthKey]['weeks'][] = $maskedWeek;
            }
        }

        return [
            'termName' => $selectedTerm['name'],
            'months' => array_values($months),
            'assessmentLegend' => $assessmentLegend,
        ];
    }

    // What the app needs to know about the integration (legend/labels)
    $academicCalendarSummary = [
        'installed' => $academicCalendar['installed'],
        'showHomework' => $academicCalendar['showHomework'],
        'showAssessments' => $academicCalendar['showAssessments'],
    ];

    // ── ACTION: main ─────────────────────────────────────────────────────
    if ($action === 'main') {
        $calData = gibbonConnectBuildHomeworkCalendar($pdo, $personID, $schoolYearID, $viewerRole, $terms, $currentTermID ?? '', $schoolDayNumbers, $academicCalendar);
        echo json_encode([
            'success' => true,
            'terms' => $terms,
            'currentTermID' => $currentTermID,
            'calendar' => $calData,
            'academicCalendar' => $academicCalendarSummary,
        ]);
        exit;
    }

    // ── ACTION: calendar (term change) ──────────────────────────────────────
    if ($action === 'calendar') {
        $selectedTerm = null;
        foreach ($terms as $t) {
            if ($t['gibbonSchoolYearTermID'] === $termID) { $selectedTerm = $t; break; }
        }
        if (!$selectedTerm) {
            echo json_encode(['success' => false, 'message' => 'Term not found']);
            exit;
        }

        $calData = gibbonConnectBuildHomeworkCalendar($pdo, $personID, $schoolYearID, $viewerRole, $terms, $termID, $schoolDayNumbers, $academicCalendar);
        echo json_encode([
            'success' => true,
            'calendar' => $calData,
            'academicCalendar' => $academicCalendarSummary,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect homework_calendar.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
