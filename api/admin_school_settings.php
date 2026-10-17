<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// View-only by design — Days of the Week, School Years, Terms, and Special
// Days are all infrequently-touched structural/calendar settings, better
// created and edited carefully in Gibbon's own web UI (see the "School
// Admin" scope discussion). This endpoint only ever reads.
//
// Uses SELECT * throughout rather than naming specific columns: unlike
// gibbonPerson/gibbonFamily* (used extensively elsewhere in this app, so
// their columns are already confirmed from real usage), gibbonDaysOfWeek's
// full column list hasn't been directly confirmed here — SELECT * sidesteps
// guessing at names for a read-only feature where there's no reason to
// restrict the column list anyway.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? '');

// ── Build the special-days calendar for one term ────────────────────────────
// Mirrors Gibbon's own schoolYearSpecialDay_manage.php: pads the term's date
// range out to full Monday-Sunday weeks, marks non-school days (from
// gibbonDaysOfWeek) and existing special days, grouped by month for display.
function gibbonConnectBuildSpecialDaysCalendar(PDO $pdo, array $term): array {
    $firstDay = $term['firstDay'];
    $lastDay = $term['lastDay'];

    $nonSchoolStmt = $pdo->query("SELECT nameShort FROM gibbonDaysOfWeek WHERE schoolDay = 'N'");
    $nonSchoolDays = array_column($nonSchoolStmt->fetchAll(PDO::FETCH_ASSOC), 'nameShort');

    $start = new DateTime($firstDay);
    while ($start->format('D') !== 'Mon') { $start->modify('-1 day'); }
    $end = new DateTime($lastDay);
    while ($end->format('D') !== 'Sun') { $end->modify('+1 day'); }

    $sdStmt = $pdo->prepare("
        SELECT gibbonSchoolYearSpecialDayID, date, name, type
        FROM gibbonSchoolYearSpecialDay
        WHERE date BETWEEN ? AND ?
    ");
    $sdStmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    $specialByDate = [];
    foreach ($sdStmt->fetchAll(PDO::FETCH_ASSOC) as $sd) {
        $specialByDate[$sd['date']] = $sd;
    }

    $months = [];
    $current = clone $start;
    while ($current <= $end) {
        $week = [];
        for ($d = 0; $d < 7; $d++) {
            $dateStr = $current->format('Y-m-d');
            $dow = $current->format('D');
            $inTerm = $dateStr >= $firstDay && $dateStr <= $lastDay;

            if (!$inTerm) {
                $week[] = ['date' => $dateStr, 'type' => 'outside'];
            } elseif (isset($specialByDate[$dateStr])) {
                $sd = $specialByDate[$dateStr];
                $week[] = [
                    'date' => $dateStr, 'type' => 'special',
                    'gibbonSchoolYearSpecialDayID' => $sd['gibbonSchoolYearSpecialDayID'],
                    'name' => $sd['name'], 'specialType' => $sd['type'],
                ];
            } elseif (in_array($dow, $nonSchoolDays, true)) {
                $week[] = ['date' => $dateStr, 'type' => 'nonschoolday'];
            } else {
                $week[] = ['date' => $dateStr, 'type' => 'normal'];
            }
            $current->modify('+1 day');
        }

        $monthKey = '';
        foreach ($week as $cell) {
            if ($cell['type'] !== 'outside') { $monthKey = substr($cell['date'], 0, 7); break; }
        }
        if ($monthKey === '') $monthKey = substr($week[0]['date'], 0, 7);

        $monthLabel = (new DateTime($monthKey . '-01'))->format('M Y');
        if (!isset($months[$monthKey])) {
            $months[$monthKey] = ['label' => $monthLabel, 'weeks' => []];
        }
        $months[$monthKey]['weeks'][] = $week;
    }

    return array_values($months);
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

    if ($authUser['role'] !== 'Administrator') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not permitted']);
        exit;
    }

    // ── DAYS OF THE WEEK ────────────────────────────────────────────────────
    if ($action === 'days_of_week') {
        $stmt = $pdo->query("SELECT * FROM gibbonDaysOfWeek ORDER BY sequenceNumber");
        echo json_encode(['success' => true, 'days' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── SCHOOL YEARS ─────────────────────────────────────────────────────────
    if ($action === 'school_years') {
        $stmt = $pdo->query("SELECT * FROM gibbonSchoolYear ORDER BY sequenceNumber DESC");
        echo json_encode(['success' => true, 'years' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── TERMS ────────────────────────────────────────────────────────────────
    if ($action === 'terms') {
        $stmt = $pdo->query("
            SELECT t.*, y.name AS yearName, y.sequenceNumber AS yearSequence
            FROM gibbonSchoolYearTerm t
            JOIN gibbonSchoolYear y ON y.gibbonSchoolYearID = t.gibbonSchoolYearID
            ORDER BY y.sequenceNumber DESC, t.firstDay
        ");
        echo json_encode(['success' => true, 'terms' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── SPECIAL DAYS: CALENDAR ──────────────────────────────────────────────
    // Special Days is the one School Admin area with real CRUD (school
    // closures need to be addable on the go) — see the scope discussion
    // elsewhere; Timing Change/Off Timetable get basic fields only (no time
    // pickers or year/form group targeting — that stays on the website).
    if ($action === 'special_days_calendar') {
        $termID = trim($input['gibbonSchoolYearTermID'] ?? '');
        if (empty($termID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonSchoolYearTermID required']);
            exit;
        }
        $termStmt = $pdo->prepare("SELECT * FROM gibbonSchoolYearTerm WHERE gibbonSchoolYearTermID = ?");
        $termStmt->execute([$termID]);
        $term = $termStmt->fetch(PDO::FETCH_ASSOC);
        if (!$term) {
            echo json_encode(['success' => false, 'message' => 'Term not found']);
            exit;
        }

        $months = gibbonConnectBuildSpecialDaysCalendar($pdo, $term);
        echo json_encode(['success' => true, 'termName' => $term['name'], 'months' => $months]);
        exit;
    }

    // ── SPECIAL DAYS: GET ONE ───────────────────────────────────────────────
    if ($action === 'get_special_day') {
        $id = trim($input['gibbonSchoolYearSpecialDayID'] ?? '');
        $stmt = $pdo->prepare("SELECT * FROM gibbonSchoolYearSpecialDay WHERE gibbonSchoolYearSpecialDayID = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Not found']);
            exit;
        }
        echo json_encode(['success' => true, 'specialDay' => $row]);
        exit;
    }

    // ── SPECIAL DAYS: ADD ────────────────────────────────────────────────────
    if ($action === 'add_special_day') {
        $date = trim($input['date'] ?? '');
        $type = trim($input['type'] ?? '');
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $termID = trim($input['gibbonSchoolYearTermID'] ?? '');
        $cancelClasses = trim($input['cancelClasses'] ?? 'N');
        $cancelActivities = trim($input['cancelActivities'] ?? 'N');
        $cancelDuty = trim($input['cancelDuty'] ?? 'N');
        $cancelBookings = trim($input['cancelBookings'] ?? 'N');

        if (empty($date) || empty($type) || empty($name) || empty($termID)) {
            echo json_encode(['success' => false, 'message' => 'Date, type, name, and term are required']);
            exit;
        }

        // One special day per date, matching Gibbon's own uniqueness rule.
        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonSchoolYearSpecialDay WHERE date = ?");
        $dupStmt->execute([$date]);
        if ($dupStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'A special day already exists on this date']);
            exit;
        }

        // schoolOpen/Start/End/Close intentionally omitted (left NULL) and
        // gibbonYearGroupIDList/gibbonFormGroupIDList left blank — the
        // trimmed scope for Timing Change / Off Timetable.
        $pdo->prepare("
            INSERT INTO gibbonSchoolYearSpecialDay
                (date, type, name, description, gibbonSchoolYearTermID,
                 gibbonYearGroupIDList, gibbonFormGroupIDList,
                 cancelClasses, cancelActivities, cancelDuty, cancelBookings)
            VALUES (?, ?, ?, ?, ?, '', '', ?, ?, ?, ?)
        ")->execute([$date, $type, $name, $description, $termID, $cancelClasses, $cancelActivities, $cancelDuty, $cancelBookings]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── SPECIAL DAYS: EDIT ───────────────────────────────────────────────────
    if ($action === 'edit_special_day') {
        $id = trim($input['gibbonSchoolYearSpecialDayID'] ?? '');
        $type = trim($input['type'] ?? '');
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $cancelClasses = trim($input['cancelClasses'] ?? 'N');
        $cancelActivities = trim($input['cancelActivities'] ?? 'N');
        $cancelDuty = trim($input['cancelDuty'] ?? 'N');
        $cancelBookings = trim($input['cancelBookings'] ?? 'N');

        if (empty($id) || empty($type) || empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Type and name are required']);
            exit;
        }

        $pdo->prepare("
            UPDATE gibbonSchoolYearSpecialDay SET
                type = ?, name = ?, description = ?,
                cancelClasses = ?, cancelActivities = ?, cancelDuty = ?, cancelBookings = ?
            WHERE gibbonSchoolYearSpecialDayID = ?
        ")->execute([$type, $name, $description, $cancelClasses, $cancelActivities, $cancelDuty, $cancelBookings, $id]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── SPECIAL DAYS: DELETE ─────────────────────────────────────────────────
    if ($action === 'delete_special_day') {
        $id = trim($input['gibbonSchoolYearSpecialDayID'] ?? '');
        if (empty($id)) {
            echo json_encode(['success' => false, 'message' => 'gibbonSchoolYearSpecialDayID required']);
            exit;
        }
        $pdo->prepare("DELETE FROM gibbonSchoolYearSpecialDay WHERE gibbonSchoolYearSpecialDayID = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect admin_school_settings.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
