<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// Ports the real Gibbon\UI\Timetable system (Structure, ClassesLayer,
// ActivitiesLayer, TimetableItem) for a personal "My Timetable" view.
// Deliberately NOT ported: the generic period-grid skeleton
// (TimetableColumnGateway) — each class item already carries its own
// timeStart/timeEnd, so a personal view doesn't need the background grid;
// the other 6 layers (Staff Cover/Duty/Absence, Bookings, both Calendar
// layers) — Classes + Activities only, per the v1 scope call; and the real
// "Add Lesson Plan" logic (Planner permissions, existing-lesson lookup) —
// the button is a always-visible dummy per instruction.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? '');

// ── Faithful port of TimetableItem's getKey()/checkOverlap()/constrainTiming() ──
// A tiny, dependency-free struct, same as the real class.
function gibbonConnectMakeItem(array $data): array {
    return array_merge([
        'id' => null, 'title' => null, 'label' => null, 'subtitle' => null,
        'description' => null, 'period' => null, 'location' => null,
        'type' => null, 'link' => null, 'color' => null, 'style' => null,
        'date' => null, 'allDay' => false, 'timeStart' => null, 'timeEnd' => null,
        'active' => true, 'isCurrent' => false, 'statuses' => [],
    ], $data);
}

function gibbonConnectItemKey(array $item): string {
    return $item['date'] . '-' . $item['timeStart'] . '-' . $item['timeEnd'];
}

function gibbonConnectConstrainTiming(array &$item, ?string $timeStart, ?string $timeEnd): void {
    if ($item['allDay']) return;

    if (!empty($timeStart)) {
        if ($item['timeEnd'] < $timeStart) $item['active'] = false;
        if ($item['timeStart'] < $timeStart) $item['timeStart'] = $timeStart;
    }
    if (!empty($timeEnd)) {
        if ($item['timeStart'] > $timeEnd) $item['active'] = false;
        if ($item['timeEnd'] > $timeEnd) $item['timeEnd'] = $timeEnd;
    }
    if ($item['timeStart'] == $item['timeEnd']) $item['active'] = false;
}

// De-duplicate items with an identical date+time key (e.g. a co-taught
// class showing once per teacher collapses to a single visual block) —
// matches Timetable::checkLayers()'s "identical timed items" grouping.
//
// BUT: a real scheduled item (layer Classes/Activities) must always win
// over a generic period-skeleton filler (Break/Free/Info) that happens to
// share the exact same time key — e.g. this school has a structural period
// literally named "Activities" running 14:20-15:30, and when a real
// Activities-module event (Ultimate Frisbee) is also scheduled 14:20-15:30,
// the filler must not be allowed to claim the key first and silently
// swallow the real item. Two genuinely real items sharing an identical key
// (co-taught class, double-booked activity slot) still collapse into one
// card with an incremented overlapCount, same as before.
function gibbonConnectDeduplicate(array $items): array {
    $isRealLayer = fn($item) => in_array($item['layer'] ?? '', ['Classes', 'Activities'], true);

    $grouped = [];
    foreach ($items as $item) {
        if ($item['allDay']) { $grouped[uniqid('', true)] = $item; continue; }
        $key = gibbonConnectItemKey($item);

        if (!isset($grouped[$key])) {
            $item['overlapCount'] = 1;
            $grouped[$key] = $item;
        } elseif ($isRealLayer($item) && !$isRealLayer($grouped[$key])) {
            // Real item displaces a filler that only claimed this key
            // because nothing else was known to occupy it yet.
            $item['overlapCount'] = 1;
            $grouped[$key] = $item;
        } elseif ($isRealLayer($item) === $isRealLayer($grouped[$key])) {
            // Both real, or both fillers — genuine duplicate, collapse as before.
            $grouped[$key]['overlapCount'] = ($grouped[$key]['overlapCount'] ?? 1) + 1;
        }
        // else: a filler arriving after a real item already claimed this
        // key — drop the filler, the real item stands.
    }
    return array_values($grouped);
}

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

    // Uses the shared resolver from auth.php (same one already used
    // elsewhere in the app) rather than a one-off version — Admin/Teacher/
    // Support Staff can view any student's timetable; Parent only their
    // own genuinely linked child (verified via gibbonFamilyAdult +
    // gibbonFamilyChild + childDataAccess='Y'); anyone else silently falls
    // back to their own timetable, matching this helper's established
    // convention everywhere it's used.
    $personID = gibbonConnectResolvePersonID(
        trim($input['viewPersonID'] ?? '') ?: null,
        $authUser,
        $pdo
    );

    // ── LIST TIMETABLES (for the "switch timetable" picker) ─────────────────
    if ($action === 'get_timetables') {
        $stmt = $pdo->prepare("
            SELECT gibbonTTID, name FROM gibbonTT
            WHERE gibbonSchoolYearID = ?
            ORDER BY name
        ");
        $stmt->execute([$schoolYearID]);
        echo json_encode(['success' => true, 'timetables' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── GET WEEK ─────────────────────────────────────────────────────────────
    if ($action === 'get_week') {
        $dateParam = trim($input['date'] ?? '') ?: date('Y-m-d');
        $gibbonTTID = trim($input['gibbonTTID'] ?? '');

        // Resolve which Timetable this person actually belongs to, if none
        // was explicitly requested.
        //
        // BUG FIX: this used to be a straight port of Gibbon core's own
        // Structure::loadTimetables() fallback — "just take the first
        // Timetable for the year, ordered by name" — which is exactly what
        // Gibbon's own web dashboard does too (confirmed by reproducing the
        // same wrong default there independently). That's fine for a school
        // with one Timetable, but for a school running several (e.g. Early
        // Years / Lower School / Middle & Upper School), it silently shows
        // whichever Timetable happens to sort first alphabetically,
        // regardless of which one the logged-in person is actually
        // scheduled under — "Sample Timetable" sorting before "TT14" being
        // exactly this case.
        //
        // Fix: look at the person's own class enrolment (same join pattern
        // as the Classes layer below — any non-"Left" role) for the current
        // school year, and pick whichever Timetable most of their classes
        // are actually scheduled in. Only fall back to the old
        // alphabetical-first behaviour if the person has no resolvable
        // class enrolment at all (e.g. a pure Administrator with no
        // teaching load, or someone not yet scheduled).
        if (empty($gibbonTTID)) {
            $ttResolveStmt = $pdo->prepare("
                SELECT ttd.gibbonTTID, COUNT(*) as cnt
                FROM gibbonCourseClassPerson ccp
                JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = ccp.gibbonCourseClassID
                JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
                JOIN gibbonTTDayRowClass ttdrc ON ttdrc.gibbonCourseClassID = cc.gibbonCourseClassID
                JOIN gibbonTTDay ttd ON ttd.gibbonTTDayID = ttdrc.gibbonTTDayID
                WHERE ccp.gibbonPersonID = ?
                AND   ccp.role NOT LIKE '%Left%'
                AND   c.gibbonSchoolYearID = ?
                GROUP BY ttd.gibbonTTID
                ORDER BY cnt DESC
                LIMIT 1
            ");
            $ttResolveStmt->execute([$personID, $schoolYearID]);
            $gibbonTTID = $ttResolveStmt->fetchColumn() ?: null;
        }

        // Last-resort fallback: person has no resolvable class enrolment
        // (pure Administrator with no teaching load, brand-new/unscheduled
        // person) — nothing to disambiguate from, so fall back to the old
        // best-effort behaviour rather than showing nothing at all.
        if (empty($gibbonTTID)) {
            $ttStmt = $pdo->prepare("SELECT gibbonTTID FROM gibbonTT WHERE gibbonSchoolYearID = ? ORDER BY name LIMIT 1");
            $ttStmt->execute([$schoolYearID]);
            $gibbonTTID = $ttStmt->fetchColumn() ?: null;
        }

        // ── Real school week, not a hardcoded Mon-Sun ───────────────────────
        // Matches Structure::loadWeekdays() + calculateDateRange(): the week
        // is built from the school's actual defined school days, so a
        // Mon-Fri school produces exactly 5 columns, not 7.
        $daysStmt = $pdo->query("
            SELECT nameShort, sequenceNumber FROM gibbonDaysOfWeek
            WHERE schoolDay = 'Y' ORDER BY sequenceNumber
        ");
        $schoolWeekdays = $daysStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($schoolWeekdays)) {
            echo json_encode(['success' => false, 'message' => 'No school days configured']);
            exit;
        }
        $firstSchoolDay = $schoolWeekdays[0]['nameShort'];

        $current = new DateTime($dateParam);
        // Walk back to the first school day of this week (e.g. Monday),
        // matching Structure::calculateDateRange()'s search loop.
        $cursor = clone $current;
        for ($i = 0; $i < 7; $i++) {
            if ($cursor->format('D') === $firstSchoolDay) break;
            $cursor->modify('-1 day');
        }
        $weekDates = [];
        $check = clone $cursor;
        for ($i = 0; $i < 7 && count($weekDates) < count($schoolWeekdays); $i++) {
            foreach ($schoolWeekdays as $wd) {
                if ($check->format('D') === $wd['nameShort']) {
                    $weekDates[] = $check->format('Y-m-d');
                    break;
                }
            }
            $check->modify('+1 day');
        }
        if (empty($weekDates)) {
            echo json_encode(['success' => false, 'message' => 'Could not compute week range']);
            exit;
        }
        $rangeStart = $weekDates[0];
        $rangeEnd = end($weekDates);

        // ── Special days within this range (closures / timing changes) ─────
        $sdStmt = $pdo->prepare("
            SELECT * FROM gibbonSchoolYearSpecialDay WHERE date BETWEEN ? AND ?
        ");
        $sdStmt->execute([$rangeStart, $rangeEnd]);
        $specialDaysByDate = [];
        foreach ($sdStmt->fetchAll(PDO::FETCH_ASSOC) as $sd) {
            $specialDaysByDate[$sd['date']] = $sd;
        }

        // Any date that falls outside every school term is implicitly a
        // closure — matches Gibbon's own Structure::loadSpecialDays() /
        // SchoolYearTermGateway::getTermsDatesByDateRange() (source
        // verified against GibbonEdu/core), which deliberately queries
        // across ALL school years, not just the currently-selected one —
        // a week can genuinely straddle a school-year boundary (e.g. the
        // last few days of August before a new school year's Term 1
        // starts in September), and a date belonging to a LATER school
        // year's term is still correctly "in term", just not in the
        // year $schoolYearID happens to refer to. Filtering this query by
        // gibbonSchoolYearID was the actual bug: it made a date that's
        // genuinely in session (per a different year's term) look
        // exactly like a real closure.
        $termStmt = $pdo->query("SELECT firstDay, lastDay FROM gibbonSchoolYearTerm");
        $terms = $termStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($weekDates as $date) {
            if (isset($specialDaysByDate[$date])) continue; // real entry already covers this date
            $inAnyTerm = false;
            foreach ($terms as $term) {
                if ($date >= $term['firstDay'] && $date <= $term['lastDay']) { $inAnyTerm = true; break; }
            }
            if (!$inAnyTerm) {
                $specialDaysByDate[$date] = ['date' => $date, 'type' => 'School Closure'];
            }
        }

        // ── Day-cycle info (name/color) for column headers ──────────────────
        // gibbonTTDay carries a color/fontColor per rotation day (e.g. "Mon 1
        // Red" vs "Mon 2 Blue") for schools running a rotating cycle rather
        // than a plain repeating week — surfaced here so the client can color
        // each day header to match Gibbon's own convention.
        $dayInfoStmt = $pdo->prepare("
            SELECT ttdd.date, ttd.name, ttd.nameShort, ttd.color, ttd.fontColor, ttd.gibbonTTColumnID
            FROM gibbonTTDayDate ttdd
            JOIN gibbonTTDay ttd ON ttd.gibbonTTDayID = ttdd.gibbonTTDayID
            WHERE ttd.gibbonTTID = ? AND ttdd.date BETWEEN ? AND ?
        ");
        $dayInfoStmt->execute([$gibbonTTID, $rangeStart, $rangeEnd]);
        $dayInfoByDate = [];
        foreach ($dayInfoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dayInfoByDate[$row['date']] = $row;
        }

        // ── Full period skeleton — every gibbonTTColumnRow for each date's
        // column, not just Breaks. Matches Gibbon's own weekly view, which
        // shows every period slot (Lesson, Break, Pastoral, Other) whether
        // or not something is actually scheduled in it — an empty Period 3
        // still shows as an empty Period 3, not as a gap in the day. ───────
        $columnIDs = array_unique(array_filter(array_column($dayInfoByDate, 'gibbonTTColumnID')));
        $allRowsByColumn = [];
        if (!empty($columnIDs)) {
            $placeholders = implode(',', array_fill(0, count($columnIDs), '?'));
            $rowStmt = $pdo->prepare("
                SELECT gibbonTTColumnRowID, gibbonTTColumnID, name, nameShort, timeStart, timeEnd, type
                FROM gibbonTTColumnRow
                WHERE gibbonTTColumnID IN ($placeholders)
                ORDER BY timeStart
            ");
            $rowStmt->execute(array_values($columnIDs));
            foreach ($rowStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $allRowsByColumn[$r['gibbonTTColumnID']][] = $r;
            }
        }

        $items = [];

        // ── CLASSES LAYER ────────────────────────────────────────────────────
        // Tracks which specific column-row slots end up with a real class,
        // per date, so the period-skeleton pass below knows which slots to
        // skip (already covered) versus render as free/info.
        $filledSlotsByDate = [];
        if (!empty($gibbonTTID)) {
            $classStmt = $pdo->prepare("
                SELECT ttdd.date, ttcr.gibbonTTColumnRowID, ttcr.name AS period, ttcr.timeStart, ttcr.timeEnd,
                       ccp.role, cc.gibbonCourseClassID, cc.nameShort AS classNameShort,
                       c.nameShort AS courseNameShort, c.name AS courseName, sp.name AS spaceName
                FROM gibbonTTDayDate ttdd
                JOIN gibbonTTDay ttd ON ttd.gibbonTTDayID = ttdd.gibbonTTDayID
                JOIN gibbonTTDayRowClass ttdrc ON ttdrc.gibbonTTDayID = ttdd.gibbonTTDayID
                JOIN gibbonTTColumnRow ttcr ON ttcr.gibbonTTColumnRowID = ttdrc.gibbonTTColumnRowID
                JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = ttdrc.gibbonCourseClassID
                JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
                JOIN gibbonCourseClassPerson ccp ON ccp.gibbonCourseClassID = cc.gibbonCourseClassID AND ccp.gibbonPersonID = ?
                LEFT JOIN gibbonSpace sp ON sp.gibbonSpaceID = ttdrc.gibbonSpaceID
                WHERE ttd.gibbonTTID = ?
                AND ttdd.date BETWEEN ? AND ?
                AND ccp.role NOT LIKE '%Left%'
                ORDER BY ttdd.date, ttcr.timeStart
            ");
            $classStmt->execute([$personID, $gibbonTTID, $rangeStart, $rangeEnd]);
            $classRows = $classStmt->fetchAll(PDO::FETCH_ASSOC);

            // Teachers per class — fetched and still returned even though
            // the card no longer displays it (matching Gibbon's own compact
            // class box, which shows period/time/course/room only, not
            // teacher). Cheap to keep available rather than remove outright
            // in case it's wanted again, e.g. on tap-through.
            $classIDs = array_unique(array_column($classRows, 'gibbonCourseClassID'));
            $teachersByClass = [];
            if (!empty($classIDs)) {
                $placeholders = implode(',', array_fill(0, count($classIDs), '?'));
                $teacherStmt = $pdo->prepare("
                    SELECT ccp.gibbonCourseClassID, p.preferredName, p.surname
                    FROM gibbonCourseClassPerson ccp
                    JOIN gibbonPerson p ON p.gibbonPersonID = ccp.gibbonPersonID
                    WHERE ccp.gibbonCourseClassID IN ($placeholders) AND ccp.role LIKE 'Teacher%'
                ");
                $teacherStmt->execute(array_values($classIDs));
                foreach ($teacherStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    $teachersByClass[$t['gibbonCourseClassID']][] = $t['preferredName'] . ' ' . $t['surname'];
                }
            }

            // Existing lesson plans for these slots — batched in one query
            // rather than per-row. Keyed by class+date+time so it matches
            // the exact period, not just "any plan that day for this class".
            $plansByKey = [];
            if (!empty($classIDs)) {
                $placeholders = implode(',', array_fill(0, count($classIDs), '?'));
                $planStmt = $pdo->prepare("
                    SELECT gibbonPlannerEntryID, gibbonCourseClassID, date, timeStart
                    FROM gibbonPlannerEntry
                    WHERE gibbonCourseClassID IN ($placeholders) AND date BETWEEN ? AND ?
                ");
                $planStmt->execute(array_merge(array_values($classIDs), [$rangeStart, $rangeEnd]));
                foreach ($planStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $key = $p['gibbonCourseClassID'] . '|' . $p['date'] . '|' . $p['timeStart'];
                    $plansByKey[$key] = $p['gibbonPlannerEntryID'];
                }
            }

            foreach ($classRows as $row) {
                $specialDay = $specialDaysByDate[$row['date']] ?? null;
                if ($specialDay && $specialDay['type'] === 'School Closure') continue;
                if ($specialDay && !empty($specialDay['cancelClasses']) && $specialDay['cancelClasses'] === 'Y') continue;

                $planKey = $row['gibbonCourseClassID'] . '|' . $row['date'] . '|' . $row['timeStart'];
                $existingPlanID = $plansByKey[$planKey] ?? null;

                // Mark this slot filled regardless of the closure/cancel
                // checks above — if the class was cancelled for a special
                // day, the slot should fall through to render as free, not
                // be silently left out of the skeleton entirely.
                $item = gibbonConnectMakeItem([
                    'type' => 'Class',
                    'layer' => 'Classes',
                    'period' => $row['period'],
                    'title' => $row['courseNameShort'] . '.' . $row['classNameShort'],
                    'label' => $row['courseName'],
                    'location' => $row['spaceName'] ?? '',
                    'description' => !empty($teachersByClass[$row['gibbonCourseClassID']])
                        ? implode(', ', $teachersByClass[$row['gibbonCourseClassID']]) : '',
                    'date' => $row['date'],
                    'timeStart' => $row['timeStart'],
                    'timeEnd' => $row['timeEnd'],
                    'gibbonCourseClassID' => $row['gibbonCourseClassID'],
                    'role' => $row['role'],
                    // The checkmark (any viewer, tap to open the real
                    // lesson plan) replaces the dummy "+" whenever a plan
                    // already exists — matches Gibbon's own convention.
                    'gibbonPlannerEntryID' => $existingPlanID,
                    // Dummy per instruction — but at least gated to the actual
                    // teacher of THIS class (their own role row), not shown to
                    // students or other viewers, matching real Gibbon's
                    // Planner-permission gate in spirit even without a full
                    // permission system. Only relevant when there's no
                    // existing plan yet — the checkmark takes over otherwise.
                    'lessonPlanButton' => empty($existingPlanID) && stripos($row['role'], 'Teacher') !== false,
                ]);

                if ($specialDay && $specialDay['type'] === 'Timing Change') {
                    gibbonConnectConstrainTiming($item, $specialDay['schoolStart'] ?? null, $specialDay['schoolEnd'] ?? null);
                }
                if ($item['active']) {
                    $items[] = $item;
                    $filledSlotsByDate[$row['date']][$row['gibbonTTColumnRowID']] = true;
                }
            }

            // ── Remaining periods for each date — Break rows keep their
            // existing lightweight treatment; any other unfilled slot
            // (Lesson with nothing scheduled, or a Pastoral/Other row like
            // Registration/Tutorial/Meetings/Clinics/Collaboration) renders
            // as a similar lightweight info row using its own real name.
            foreach ($weekDates as $date) {
                $specialDay = $specialDaysByDate[$date] ?? null;
                if ($specialDay && $specialDay['type'] === 'School Closure') continue;

                $columnID = $dayInfoByDate[$date]['gibbonTTColumnID'] ?? null;
                if (empty($columnID) || empty($allRowsByColumn[$columnID])) continue;

                foreach ($allRowsByColumn[$columnID] as $row) {
                    if (!empty($filledSlotsByDate[$date][$row['gibbonTTColumnRowID']])) continue;

                    $items[] = gibbonConnectMakeItem([
                        'type' => $row['type'] === 'Break' ? 'Break' : ($row['type'] === 'Lesson' ? 'Free' : 'Info'),
                        'layer' => $row['type'] === 'Break' ? 'Break' : 'Info',
                        'title' => $row['name'],
                        'period' => $row['name'],
                        'date' => $date,
                        'timeStart' => $row['timeStart'],
                        'timeEnd' => $row['timeEnd'],
                    ]);
                }
            }
        }

        // ── ACTIVITIES LAYER ─────────────────────────────────────────────────
        // Faithful port of Gibbon\UI\Timetable\Layers\ActivitiesLayer +
        // ActivityGateway::selectActiveEnrolledActivities (source verified):
        //   - UNION of student-enrolled (gibbonActivityStudent, status='Accepted')
        //     and staff-led (gibbonActivityStaff) activities — a teacher running
        //     an activity sees it on their own timetable too, not just students'.
        //   - Date bounds come from the Activities module's own 'dateType'
        //     setting: 'Term' uses the enclosing term's firstDay/lastDay
        //     (matched via FIND_IN_SET against gibbonSchoolYearTermIDList,
        //     which can legitimately match multiple terms — each becomes its
        //     own row/window, same as core, so gaps between terms are
        //     respected rather than treating the whole year as one span);
        //     anything else uses the activity's own programStart/programEnd.
        //     Read live rather than assumed, since this school's setting
        //     ('Term') would have made the previous hardcoded programStart/
        //     programEnd bound silently wrong.
        //   - gibbonPerson.status = 'Full' excludes withdrawn/pending people
        //     from both branches; gibbonActivityCategory.accessEnrolmentDate
        //     gates the student-enrolled branch only (core applies this gate
        //     only to gibbonActivityStudent, not gibbonActivityStaff — a
        //     staff member running an activity isn't subject to the
        //     student enrollment-access window). Both verified against live
        //     schema (gibbonPerson.status enum includes 'Full';
        //     gibbonActivityCategory.accessEnrolmentDate is a nullable datetime)
        //     before being added, rather than assumed from core alone.
        $dateTypeStmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope='Activities' AND name='dateType' LIMIT 1");
        $activityDateType = $dateTypeStmt->fetchColumn() ?: 'Program';

        $dateCols = $activityDateType === 'Term'
            ? 'term.firstDay AS dateStart, term.lastDay AS dateEnd'
            : 'a.programStart AS dateStart, a.programEnd AS dateEnd';
        $termJoin = $activityDateType === 'Term'
            ? 'JOIN gibbonSchoolYearTerm term ON FIND_IN_SET(term.gibbonSchoolYearTermID, a.gibbonSchoolYearTermIDList)'
            : '';

        $actStmt = $pdo->prepare("
            SELECT a.gibbonActivityID, asl.gibbonActivitySlotID, a.name, $dateCols,
                   asl.timeStart, asl.timeEnd, asl.locationExternal,
                   dow.nameShort AS dayOfWeek, sp.name AS spaceName
            FROM gibbonActivityStudent ast
            JOIN gibbonActivity a ON a.gibbonActivityID = ast.gibbonActivityID
            JOIN gibbonPerson p ON p.gibbonPersonID = ast.gibbonPersonID
            JOIN gibbonActivitySlot asl ON asl.gibbonActivityID = a.gibbonActivityID
            JOIN gibbonDaysOfWeek dow ON dow.gibbonDaysOfWeekID = asl.gibbonDaysOfWeekID
            LEFT JOIN gibbonSpace sp ON sp.gibbonSpaceID = asl.gibbonSpaceID
            LEFT JOIN gibbonActivityCategory cat ON cat.gibbonActivityCategoryID = a.gibbonActivityCategoryID
            $termJoin
            WHERE ast.gibbonPersonID = ?
            AND ast.status = 'Accepted'
            AND a.gibbonSchoolYearID = ?
            AND a.active = 'Y'
            AND p.status = 'Full'
            AND (cat.gibbonActivityCategoryID IS NULL OR CURRENT_TIMESTAMP >= cat.accessEnrolmentDate)

            UNION ALL

            SELECT a.gibbonActivityID, asl.gibbonActivitySlotID, a.name, $dateCols,
                   asl.timeStart, asl.timeEnd, asl.locationExternal,
                   dow.nameShort AS dayOfWeek, sp.name AS spaceName
            FROM gibbonActivityStaff acs
            JOIN gibbonActivity a ON a.gibbonActivityID = acs.gibbonActivityID
            JOIN gibbonPerson p ON p.gibbonPersonID = acs.gibbonPersonID
            JOIN gibbonActivitySlot asl ON asl.gibbonActivityID = a.gibbonActivityID
            JOIN gibbonDaysOfWeek dow ON dow.gibbonDaysOfWeekID = asl.gibbonDaysOfWeekID
            LEFT JOIN gibbonSpace sp ON sp.gibbonSpaceID = asl.gibbonSpaceID
            $termJoin
            WHERE acs.gibbonPersonID = ?
            AND a.gibbonSchoolYearID = ?
            AND a.active = 'Y'
            AND p.status = 'Full'
        ");
        $actStmt->execute([$personID, $schoolYearID, $personID, $schoolYearID]);
        $activityRows = $actStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($weekDates as $date) {
            $specialDay = $specialDaysByDate[$date] ?? null;
            if ($specialDay && $specialDay['type'] === 'School Closure') continue;
            if ($specialDay && !empty($specialDay['cancelActivities']) && $specialDay['cancelActivities'] === 'Y') continue;

            $dateObj = new DateTime($date);
            $weekdayName = $dateObj->format('D');

            foreach ($activityRows as $row) {
                if ($row['dayOfWeek'] !== $weekdayName) continue;
                if (!empty($row['dateStart']) && $date < $row['dateStart']) continue;
                if (!empty($row['dateEnd']) && $date > $row['dateEnd']) continue;

                $item = gibbonConnectMakeItem([
                    'type' => 'Activity',
                    'layer' => 'Activities',
                    'title' => $row['name'],
                    'subtitle' => $row['spaceName'] ?? $row['locationExternal'] ?? '',
                    'date' => $date,
                    'timeStart' => $row['timeStart'],
                    'timeEnd' => $row['timeEnd'],
                    'gibbonActivityID' => $row['gibbonActivityID'],
                    'gibbonActivitySlotID' => $row['gibbonActivitySlotID'],
                ]);
                $items[] = $item;
            }
        }

        // De-duplicate identical-time items (co-taught classes, double-booked
        // activity slots) — matches checkLayers()'s grouping behavior.
        $items = gibbonConnectDeduplicate($items);

        // Mark current period (today + now within timeStart/timeEnd).
        $today = date('Y-m-d');
        $now = date('H:i:s');
        foreach ($items as &$item) {
            $item['isCurrent'] = $item['date'] === $today
                && !empty($item['timeStart']) && !empty($item['timeEnd'])
                && $now >= $item['timeStart'] && $now < $item['timeEnd'];
        }
        unset($item);

        // Group by date for the client.
        $byDate = [];
        foreach ($weekDates as $date) { $byDate[$date] = []; }
        foreach ($items as $item) {
            $byDate[$item['date']][] = $item;
        }
        foreach ($byDate as &$dayItems) {
            usort($dayItems, fn($a, $b) => ($a['timeStart'] ?? '') <=> ($b['timeStart'] ?? ''));
        }
        unset($dayItems);

        // Trim dayInfo down to just what the client needs for header styling.
        // A closure day (real or synthesized out-of-term) shouldn't show a
        // real rotation-day color — gibbonTTDayDate can still map a date to
        // a cycle day even when that date is outside term, since the cycle
        // mapping and the term dates are configured independently.
        $dayInfoOut = [];
        // Explicit closure map — date => name, or true if no name was set
        // (synthesized out-of-term closures never have one; a real
        // gibbonSchoolYearSpecialDay row usually does, e.g. "Autumn
        // Break", but isn't guaranteed to). Without this, the client only
        // ever sees an empty 'days' array for a closed date — identical to
        // what a genuinely-scheduled-but-empty day looks like — with no
        // way to tell "closed" from "nothing happens to be scheduled".
        // Matches the website's own timetableDay.twig.html: specialDay.type
        // == 'School Closure' shows a red "School Closed" banner with
        // specialDay.name, not a blank/generic empty state.
        $closures = [];
        foreach ($weekDates as $date) {
            $isClosure = ($specialDaysByDate[$date]['type'] ?? null) === 'School Closure';
            $info = $dayInfoByDate[$date] ?? null;
            if ($info && !$isClosure) {
                $dayInfoOut[$date] = [
                    'name' => $info['name'], 'nameShort' => $info['nameShort'],
                    'color' => $info['color'], 'fontColor' => $info['fontColor'],
                ];
            }
            if ($isClosure) {
                $closureName = $specialDaysByDate[$date]['name'] ?? '';
                $closures[$date] = $closureName !== '' ? $closureName : true;
            }
        }

        echo json_encode([
            'success' => true,
            'gibbonTTID' => $gibbonTTID,
            'weekDates' => $weekDates,
            'days' => $byDate,
            // Cast to object: PHP's json_encode() can't tell an empty map
            // from an empty list — both are just array() internally — so
            // an empty $dayInfoOut (every date in the week is a closure,
            // as happens for a week entirely outside term) would otherwise
            // serialize as JSON `[]` instead of `{}`. That silently breaks
            // any client parsing this field as a map/object, even though
            // the response is a perfectly valid success — worth guarding
            // here so the API's shape is guaranteed regardless of what any
            // particular client does with it.
            'dayInfo' => (object) $dayInfoOut,
            // Same (object) cast reasoning as dayInfo above — a week with
            // zero closures (the common case) would otherwise serialize as
            // JSON `[]` instead of `{}`.
            'closures' => (object) $closures,
            'today' => $today,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect my_timetable.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
