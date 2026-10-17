<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true);
$classID = trim($input['classID'] ?? '');

if (empty($classID)) {
    echo json_encode(['success' => false, 'message' => 'classID required']);
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

    // CHANGED: was "WHERE status='Current'" — now uses the session's
    // active year. See form_group_history.php for the same pattern.
    $yearStmt = $pdo->prepare("
        SELECT gibbonSchoolYearID, firstDay, lastDay
        FROM gibbonSchoolYear WHERE gibbonSchoolYearID = ? LIMIT 1
    ");
    $yearStmt->execute([$authUser['schoolYearID']]);
    $year = $yearStmt->fetch(PDO::FETCH_ASSOC);
    if (!$year) {
        echo json_encode(['success' => false, 'message' => 'No active school year']);
        exit;
    }

    // Get school days of week
    $daysStmt = $pdo->query("
        SELECT nameShort, sequenceNumber
        FROM gibbonDaysOfWeek
        WHERE schoolDay = 'Y'
        ORDER BY sequenceNumber
    ");
    $schoolDays = $daysStmt->fetchAll(PDO::FETCH_ASSOC);
    $schoolDayNumbers = array_map(fn($d) => (int)$d['sequenceNumber'], $schoolDays);

    // Get timetable slots for this class, keyed by gibbonTTDayID
    $ttStmt = $pdo->prepare("
        SELECT 
            gibbonTTDayRowClass.gibbonTTDayRowClassID,
            gibbonTTDayRowClass.gibbonTTDayID,
            gibbonTTColumnRow.nameShort AS periodShort,
            gibbonTTColumnRow.timeStart
        FROM gibbonTTDayRowClass
        JOIN gibbonTTColumnRow ON (gibbonTTColumnRow.gibbonTTColumnRowID = gibbonTTDayRowClass.gibbonTTColumnRowID)
        WHERE gibbonTTDayRowClass.gibbonCourseClassID = ?
        ORDER BY gibbonTTColumnRow.timeStart
    ");
    $ttStmt->execute([$classID]);
    $ttRows = $ttStmt->fetchAll(PDO::FETCH_ASSOC);
    $hasTimetable = !empty($ttRows);

    // Key slots by gibbonTTDayID -> [slots]
    $slotsByTTDay = [];
    foreach ($ttRows as $row) {
        $dayID = $row['gibbonTTDayID'];
        if (!isset($slotsByTTDay[$dayID])) $slotsByTTDay[$dayID] = [];
        $slotsByTTDay[$dayID][] = $row;
    }

    // Get attendance taken for this class, keyed by date -> [gibbonTTDayRowClassID]
    $attendStmt = $pdo->prepare("
        SELECT DISTINCT date, gibbonTTDayRowClassID
        FROM gibbonAttendanceLogPerson
        WHERE gibbonCourseClassID = ?
        AND context = 'Class'
        ORDER BY date DESC
    ");
    $attendStmt->execute([$classID]);
    $attendRecords = $attendStmt->fetchAll(PDO::FETCH_ASSOC);

    $takenByDate = [];
    foreach ($attendRecords as $r) {
        $d = $r['date'];
        if (!isset($takenByDate[$d])) $takenByDate[$d] = [];
        $takenByDate[$d][] = $r['gibbonTTDayRowClassID'];
    }

    // Get date -> gibbonTTDayID mapping for last 60 days
    $today   = new DateTime();
    $cutoff  = (clone $today)->modify('-60 days');
    $dateMapStmt = $pdo->prepare("
        SELECT gibbonTTDayDate.date, gibbonTTDayDate.gibbonTTDayID
        FROM gibbonTTDayDate
        WHERE date >= ? AND date <= ?
        ORDER BY date DESC
    ");
    $dateMapStmt->execute([
        $cutoff->format('Y-m-d'),
        $today->format('Y-m-d'),
    ]);
    $dateMapRows = $dateMapStmt->fetchAll(PDO::FETCH_ASSOC);
    $ttDayByDate = [];
    foreach ($dateMapRows as $r) {
        $ttDayByDate[$r['date']] = $r['gibbonTTDayID'];
    }

    // Build last 10 REAL, CONSECUTIVE school days — matching Gibbon's own
    // getLastNSchoolDays(..., 10, ...) used on the real "View Daily
    // Attendance" screen. Every real school day gets an entry now; days
    // this class wasn't timetabled to run are marked scheduled=false
    // (rendered blank, not clickable) rather than being dropped from the
    // list entirely. Dropping them was the actual bug — it made the strip
    // silently walk back further and further in calendar time until it
    // found 10 days this class happened to meet, which for a once-a-week
    // class meant weeks of spread rather than a real recent window.
    $yearEnd = new DateTime($year['lastDay']);
    $start   = $today < $yearEnd ? $today : $yearEnd;
    $days    = [];
    $check   = clone $start;
    $limit   = 10;

    while (count($days) < $limit) {
        $dow = (int)$check->format('N');
        if (in_array($dow, $schoolDayNumbers)) {
            $dateStr  = $check->format('Y-m-d');
            $isToday  = $dateStr === $today->format('Y-m-d');
            $takenIDs = $takenByDate[$dateStr] ?? [];

            if ($hasTimetable) {
                $ttDayID     = $ttDayByDate[$dateStr] ?? null;
                $slotsForDay = $ttDayID ? ($slotsByTTDay[$ttDayID] ?? []) : [];
                $scheduled   = !empty($slotsForDay);

                $slots = array_map(fn($slot) => [
                    'ttDayRowClassID' => $slot['gibbonTTDayRowClassID'],
                    'periodShort'     => $slot['periodShort'],
                    'timeStart'       => substr($slot['timeStart'], 0, 5),
                    'taken'           => in_array($slot['gibbonTTDayRowClassID'], $takenIDs)
                                         || (in_array(null, $takenIDs) && !empty($takenIDs)),
                ], $slotsForDay);

                $days[] = [
                    'date'      => $dateStr,
                    'dayShort'  => $check->format('D'),
                    'dayNum'    => $check->format('j'),
                    'month'     => $check->format('M'),
                    'isToday'   => $isToday,
                    'scheduled' => $scheduled,
                    'slots'     => $slots,
                    'taken'     => !empty($takenIDs),
                ];
            } else {
                // No timetable data at all for this class — every school
                // day is treated as takeable, matching the app's existing
                // fallback behavior for classes without a timetable.
                $days[] = [
                    'date'      => $dateStr,
                    'dayShort'  => $check->format('D'),
                    'dayNum'    => $check->format('j'),
                    'month'     => $check->format('M'),
                    'isToday'   => $isToday,
                    'scheduled' => true,
                    'slots'     => [],
                    'taken'     => !empty($takenIDs),
                ];
            }
        }
        $check->modify('-1 day');
        if ($check < new DateTime($year['firstDay'])) break;
    }

    echo json_encode([
        'success'      => true,
        'days'         => $days,
        'hasTimetable' => $hasTimetable,
    ]);

} catch (PDOException $e) {
    error_log('School Connect attendance_history.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
