<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true);
$personID = trim($input['personID'] ?? '');
$action   = trim($input['action'] ?? 'main');
$termID   = trim($input['termID'] ?? '');

if (empty($personID)) {
    echo json_encode(['success' => false, 'message' => 'personID required']);
    exit;
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
    $role     = $authUser['role'];

    // ── Access control ────────────────────────────────────────────────────────
    if (in_array($role, ['Administrator', 'Teacher', 'Support Staff'], true)) {
        // can view anyone
    } elseif ($role === 'Parent') {
        $parentCheck = $pdo->prepare("
            SELECT fa.gibbonPersonID
            FROM gibbonFamilyAdult fa
            JOIN gibbonFamilyChild fc ON fc.gibbonFamilyID = fa.gibbonFamilyID
            WHERE fa.gibbonPersonID = ? AND fc.gibbonPersonID = ?
            AND   fa.childDataAccess = 'Y' LIMIT 1
        ");
        $parentCheck->execute([$authUser['personID'], $personID]);
        if (!$parentCheck->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not permitted to view this student']);
            exit;
        }
    } else {
        $personID = $authUser['personID'];
    }

    // ── School year ───────────────────────────────────────────────────────────
    // CHANGED: was a hardcoded "WHERE status='Current'" lookup — now uses
    // the session's active year.
    $yearStmt = $pdo->prepare("
        SELECT gibbonSchoolYearID, firstDay, lastDay, name AS yearName
        FROM gibbonSchoolYear WHERE gibbonSchoolYearID = ? LIMIT 1
    ");
    $yearStmt->execute([$authUser['schoolYearID']]);
    $year = $yearStmt->fetch(PDO::FETCH_ASSOC);
    if (!$year) { echo json_encode(['success' => false, 'message' => 'No active school year']); exit; }
    $schoolYearID = $year['gibbonSchoolYearID'];
    $yearFirstDay = $year['firstDay'];
    $yearLastDay  = $year['lastDay'];

    // ── School days of week ───────────────────────────────────────────────────
    $dowStmt = $pdo->query("SELECT sequenceNumber FROM gibbonDaysOfWeek WHERE schoolDay='Y'");
    $schoolDayNumbers = array_column($dowStmt->fetchAll(PDO::FETCH_ASSOC), 'sequenceNumber');

    // ── Terms ─────────────────────────────────────────────────────────────────
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
        // Viewing a past year (or no term matches today) — default to the
        // most recent term in that year rather than leaving this null.
        $currentTermID = end($terms)['gibbonSchoolYearTermID'];
    }

    // ── Helper: attendance totals for a date range ────────────────────────────
    // Present/Partial/Absent classification ported from Gibbon core's
    // Attendance\StudentHistoryData::getAttendanceData() (source verified) —
    // this is NOT a simple direction split. A log's gibbonAttendanceCode.scope
    // determines the bucket: direction='Out' AND scope='Offsite' -> Absent;
    // scope in ('Onsite - Late','Offsite - Late','Offsite - Left') -> Partial;
    // everything else (including plain 'Offsite' with direction='In') -> Present.
    function getAttendanceTotals(PDO $pdo, string $personID,
                                  string $first, string $last,
                                  array $terms, array $schoolDayNumbers,
                                  string $today): array {
        $stmt = $pdo->prepare("
            SELECT
                CASE
                    WHEN ac.direction = 'Out' AND ac.scope = 'Offsite' THEN 'absent'
                    WHEN ac.scope IN ('Onsite - Late', 'Offsite - Late', 'Offsite - Left') THEN 'partial'
                    ELSE 'present'
                END AS status,
                COUNT(DISTINCT alp.date) AS dayCount
            FROM gibbonAttendanceLogPerson alp
            JOIN gibbonAttendanceCode ac ON ac.gibbonAttendanceCodeID = alp.gibbonAttendanceCodeID
            -- 'Person' included alongside 'Form Group' — see
            -- student_profile.php for the full explanation (confirmed via
            -- a live test: School Admin > Attendance Settings'
            -- recordFirstClassAsSchool writes a second, separate row with
            -- context='Person' when a first-period Class attendance
            -- record gets promoted to school-wide attendance).
            WHERE alp.gibbonPersonID = ? AND alp.context IN ('Form Group', 'Person')
            AND   alp.date >= ? AND alp.date <= ?
            GROUP BY status
        ");
        $stmt->execute([$personID, $first, $last]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $present = 0; $absent = 0; $partial = 0; $recorded = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'present') $present = (int)$r['dayCount'];
            if ($r['status'] === 'absent')  $absent  = (int)$r['dayCount'];
            if ($r['status'] === 'partial') $partial = (int)$r['dayCount'];
            $recorded += (int)$r['dayCount'];
        }

        // Count school days up to today within this range
        $cutoff = min($today, $last);
        $schoolDays = 0;
        foreach ($terms as $t) {
            $tFirst = max($t['firstDay'], $first);
            $tLast  = min($t['lastDay'],  $cutoff);
            if ($tFirst > $tLast) continue;

            $sdStmt = $pdo->prepare("
                SELECT date FROM gibbonSchoolYearSpecialDay
                WHERE gibbonSchoolYearTermID = ? AND date >= ? AND date <= ?
            ");
            $sdStmt->execute([$t['gibbonSchoolYearTermID'], $tFirst, $tLast]);
            $closures = array_flip(array_column(
                $sdStmt->fetchAll(PDO::FETCH_ASSOC), 'date'));

            $d = new DateTime($tFirst);
            $e = new DateTime($tLast); $e->modify('+1 day');
            while ($d < $e) {
                $dow = (int)$d->format('N');
                $ds  = $d->format('Y-m-d');
                if (in_array($dow, $schoolDayNumbers) && !isset($closures[$ds])) {
                    $schoolDays++;
                }
                $d->modify('+1 day');
            }
        }

        return [
            'present'    => $present,
            'partial'    => $partial,
            'absent'     => $absent,
            'noData'     => max(0, $schoolDays - $recorded),
            'schoolDays' => $schoolDays,
        ];
    }

    // ── Helper: build calendar for one term ───────────────────────────────────
    function buildCalendar(PDO $pdo, string $personID, array $terms,
                            string $termID, array $schoolDayNumbers,
                            string $today): array {
        $selectedTerm = null;
        foreach ($terms as $t) {
            if ($t['gibbonSchoolYearTermID'] === $termID) {
                $selectedTerm = $t; break;
            }
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

        $attendStmt = $pdo->prepare("
            SELECT alp.date, ac.nameShort, ac.name AS codeName, ac.direction, ac.scope
            FROM gibbonAttendanceLogPerson alp
            JOIN gibbonAttendanceCode ac ON ac.gibbonAttendanceCodeID = alp.gibbonAttendanceCodeID
            -- Same 'Person' inclusion as getAttendanceTotals() above.
            WHERE alp.gibbonPersonID = ? AND alp.context IN ('Form Group', 'Person')
            AND   alp.date >= ? AND alp.date <= ? ORDER BY alp.date
        ");
        $attendStmt->execute([$personID, $firstDay, $lastDay]);
        $attendMap = [];
        foreach ($attendStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $attendMap[$a['date']] = $a;
        }

        $months = [];
        $current = new DateTime($firstDay);
        $end     = new DateTime($lastDay); $end->modify('+1 day');
        $startOfWeek = clone $current;
        $dow = (int)$startOfWeek->format('N');
        if ($dow > 1) $startOfWeek->modify('-' . ($dow - 1) . ' days');

        while ($startOfWeek < $end) {
            $week = [];
            for ($d = 0; $d < 5; $d++) {
                $dateStr  = $startOfWeek->format('Y-m-d');
                $dowN     = (int)$startOfWeek->format('N');
                $inTerm   = $dateStr >= $firstDay && $dateStr <= $lastDay;
                $isFuture = $dateStr > $today;

                if (!$inTerm) {
                    $week[] = ['date' => $dateStr, 'type' => 'outside'];
                } elseif (isset($specialDays[$dateStr])) {
                    $week[] = ['date' => $dateStr, 'type' => 'special',
                               'label' => $specialDays[$dateStr]['name']];
                } elseif (!in_array($dowN, $schoolDayNumbers)) {
                    $week[] = ['date' => $dateStr, 'type' => 'noschool'];
                } elseif (isset($attendMap[$dateStr])) {
                    $a = $attendMap[$dateStr];
                    // Same rule as getAttendanceTotals — see comment there.
                    if ($a['direction'] === 'Out' && $a['scope'] === 'Offsite') {
                        $status = 'absent';
                    } elseif (in_array($a['scope'], ['Onsite - Late', 'Offsite - Late', 'Offsite - Left'], true)) {
                        $status = 'partial';
                    } else {
                        $status = 'present';
                    }
                    $week[] = ['date'     => $dateStr,
                               'type'     => $status,
                               'code'     => $a['nameShort'],
                               'codeName' => $a['codeName'],
                               'direction'=> $a['direction']];
                } elseif ($isFuture) {
                    $week[] = ['date' => $dateStr, 'type' => 'future'];
                } else {
                    $week[] = ['date' => $dateStr, 'type' => 'nodata'];
                }
                $startOfWeek->modify('+1 day');
            }
            $startOfWeek->modify('+2 days');

            // Group each week row under EVERY month it touches — a row
            // spanning a boundary (e.g. Mon Aug 31 - Fri Sep 4) appears
            // under both Aug and Sep, with the days belonging to the
            // OTHER month blanked out (same 'outside' placeholder already
            // used for out-of-term days) rather than showing real data
            // under the wrong month's heading. Keeps the compact 5-cell
            // week-row grid while still respecting real month boundaries —
            // previously the whole row was bucketed under whichever day
            // happened to be first, which visually misattributed trailing
            // days to the wrong month (confirmed independently by two
            // testers noticing the same thing).
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

        return ['termName' => $selectedTerm['name'], 'months' => array_values($months)];
    }

    // ── ACTION: main ──────────────────────────────────────────────────────────
    if ($action === 'main') {
        // Whole-year totals for the summary strip
        $yearTotals = getAttendanceTotals($pdo, $personID,
            $yearFirstDay, $yearLastDay, $terms, $schoolDayNumbers, $today);

        // Default term totals for the donut
        $termTotals = null;
        foreach ($terms as $t) {
            if ($t['gibbonSchoolYearTermID'] === $currentTermID) {
                $termTotals = getAttendanceTotals($pdo, $personID,
                    $t['firstDay'], $t['lastDay'], $terms, $schoolDayNumbers, $today);
                break;
            }
        }

        // Class absences (whole year)
        $classAbsStmt = $pdo->prepare("
            SELECT gc.name AS courseName, gc.nameShort AS courseShort,
                   gcc.nameShort AS classShort, COUNT(*) AS absenceCount,
                   (SELECT COUNT(DISTINCT alp2.date)
                    FROM gibbonAttendanceLogPerson alp2
                    WHERE alp2.gibbonCourseClassID = gcc.gibbonCourseClassID
                    AND   alp2.context = 'Class'
                    AND   alp2.date >= ? AND alp2.date <= ? LIMIT 1) AS totalSessions
            FROM gibbonAttendanceLogPerson alp
            JOIN gibbonAttendanceCode ac ON ac.gibbonAttendanceCodeID = alp.gibbonAttendanceCodeID
            JOIN gibbonCourseClass gcc   ON gcc.gibbonCourseClassID   = alp.gibbonCourseClassID
            JOIN gibbonCourse gc         ON gc.gibbonCourseID         = gcc.gibbonCourseID
            WHERE alp.gibbonPersonID = ? AND alp.context = 'Class'
            AND   ac.direction = 'Out' AND alp.date >= ? AND alp.date <= ?
            AND   gc.gibbonSchoolYearID = ?
            GROUP BY gcc.gibbonCourseClassID ORDER BY absenceCount DESC, gc.nameShort
        ");
        $classAbsStmt->execute([
            $yearFirstDay, $yearLastDay,
            $personID, $yearFirstDay, $yearLastDay, $schoolYearID,
        ]);
        $classAbsences = $classAbsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Calendar for default term
        $calData = buildCalendar($pdo, $personID, $terms,
                                  $currentTermID, $schoolDayNumbers, $today);

        echo json_encode([
            'success'       => true,
            'yearName'      => $year['yearName'],
            'yearTotals'    => $yearTotals,
            'termTotals'    => $termTotals,
            'classAbsences' => $classAbsences,
            'terms'         => $terms,
            'currentTermID' => $currentTermID,
            'calendar'      => $calData,
            'isCurrentYear' => $authUser['isCurrentYear'],
        ]);
        exit;
    }

    // ── ACTION: calendar (term change) ────────────────────────────────────────
    if ($action === 'calendar') {
        // 'YEAR' is a client-side sentinel (not a real gibbonSchoolYearTermID)
        // for the "School Year" dropdown option — matches Gibbon core's own
        // attendance summary, which is whole-year, not per-term like the rest
        // of this screen. Deliberately no calendar grid for this view (that's
        // still per-term only) — just the same donut totals, computed over
        // the full year's date range instead of one term's.
        if ($termID === 'YEAR') {
            $yearTotalsForView = getAttendanceTotals($pdo, $personID,
                $yearFirstDay, $yearLastDay, $terms, $schoolDayNumbers, $today);

            echo json_encode([
                'success'    => true,
                'termTotals' => $yearTotalsForView,
                'calendar'   => null,
            ]);
            exit;
        }

        // Find selected term
        $selectedTerm = null;
        foreach ($terms as $t) {
            if ($t['gibbonSchoolYearTermID'] === $termID) {
                $selectedTerm = $t; break;
            }
        }
        if (!$selectedTerm) {
            echo json_encode(['success' => false, 'message' => 'Term not found']); exit;
        }

        $termTotals = getAttendanceTotals($pdo, $personID,
            $selectedTerm['firstDay'], $selectedTerm['lastDay'],
            $terms, $schoolDayNumbers, $today);

        $calData = buildCalendar($pdo, $personID, $terms,
                                  $termID, $schoolDayNumbers, $today);

        echo json_encode([
            'success'    => true,
            'termTotals' => $termTotals,
            'calendar'   => $calData,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect student_attendance.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
