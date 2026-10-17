<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input       = json_decode(file_get_contents('php://input'), true);
$formGroupID = trim($input['formGroupID'] ?? '');

if (empty($formGroupID)) {
    echo json_encode(['success' => false, 'message' => 'formGroupID required']);
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

    // CHANGED: was "WHERE status='Current'" — now looks up the session's
    // active year. The "last 20 school days ending today" logic below
    // already clamps to the year's lastDay when today falls outside the
    // selected year, so viewing a past year correctly shows that year's
    // final 20 school days instead of nothing.
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

    // Get dates attendance was taken for this form group
    $attendStmt = $pdo->prepare("
        SELECT DISTINCT date
        FROM gibbonAttendanceLogPerson
        WHERE gibbonFormGroupID = ?
        AND context = 'Form Group'
        ORDER BY date DESC
    ");
    $attendStmt->execute([$formGroupID]);
    $takenDates = array_column($attendStmt->fetchAll(PDO::FETCH_ASSOC), 'date');
    $takenSet   = array_flip($takenDates);

    // Build last 20 school days
    $today   = new DateTime();
    $yearEnd = new DateTime($year['lastDay']);
    $start   = $today < $yearEnd ? $today : $yearEnd;

    $days  = [];
    $check = clone $start;
    $limit = 20;

    while (count($days) < $limit) {
        $dow = (int)$check->format('N');
        if (in_array($dow, $schoolDayNumbers)) {
            $dateStr = $check->format('Y-m-d');
            $days[]  = [
                'date'     => $dateStr,
                'taken'    => isset($takenSet[$dateStr]),
                'dayShort' => $check->format('D'),
                'dayNum'   => $check->format('j'),
                'month'    => $check->format('M'),
                'isToday'  => $dateStr === $today->format('Y-m-d'),
            ];
        }
        $check->modify('-1 day');
        if ($check < new DateTime($year['firstDay'])) break;
    }

    echo json_encode([
        'success' => true,
        'days'    => $days,
    ]);

} catch (PDOException $e) {
    error_log('School Connect form_group_history.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
