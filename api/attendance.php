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

$input  = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? '');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);

    // ── GET CODES ─────────────────────────────────────────────────────────
    if ($action === 'get_codes') {
        $stmt = $pdo->query("
            SELECT gibbonAttendanceCodeID, name, nameShort, direction, scope
            FROM gibbonAttendanceCode
            WHERE active = 'Y'
            ORDER BY sequenceNumber
        ");
        echo json_encode(['success' => true, 'codes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── GET CLASS ATTENDANCE ───────────────────────────────────────────────
    if ($action === 'get_attendance') {
        $classID = trim($input['classID'] ?? '');
        $date    = trim($input['date'] ?? date('Y-m-d'));

        $stmt = $pdo->prepare("
            SELECT
                gibbonAttendanceLogPerson.gibbonPersonID,
                gibbonAttendanceCodeID,
                gibbonAttendanceCode.name AS codeName,
                gibbonAttendanceCode.nameShort AS codeShort,
                direction
            FROM gibbonAttendanceLogPerson
            JOIN gibbonAttendanceCode USING (gibbonAttendanceCodeID)
            WHERE gibbonCourseClassID = ?
            AND date = ?
            AND context = 'Class'
        ");
        $stmt->execute([$classID, $date]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byPerson = [];
        foreach ($records as $r) {
            $byPerson[$r['gibbonPersonID']] = $r;
        }
        echo json_encode(['success' => true, 'attendance' => $byPerson, 'date' => $date]);
        exit;
    }

    // ── SAVE CLASS ATTENDANCE ──────────────────────────────────────────────
    if ($action === 'save_attendance') {
        $classID = trim($input['classID'] ?? '');
        $takerID = $authUser['personID']; // never trust client-sent takerID
        $date    = trim($input['date'] ?? date('Y-m-d'));
        $records = $input['records'] ?? [];

        if (empty($classID) || empty($records)) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }

        // Look up the gibbonTTDayRowClassID for this class on this date.
        // Gibbon's web UI uses this to recognise that attendance has been
        // officially taken for a timetabled period; without it the row is
        // treated as a best-guess default, not actual data.
        $ttSlotID = null;
        $ttSlotStmt = $pdo->prepare("
            SELECT rc.gibbonTTDayRowClassID
            FROM gibbonTTDayRowClass rc
            JOIN gibbonTTDayDate dd ON dd.gibbonTTDayID = rc.gibbonTTDayID
            WHERE rc.gibbonCourseClassID = ?
            AND   dd.date = ?
            LIMIT 1
        ");
        $ttSlotStmt->execute([$classID, $date]);
        $ttSlot = $ttSlotStmt->fetch(PDO::FETCH_ASSOC);
        if ($ttSlot) {
            $ttSlotID = $ttSlot['gibbonTTDayRowClassID'];
        }

        $del = $pdo->prepare("
            DELETE FROM gibbonAttendanceLogPerson
            WHERE gibbonCourseClassID = ? AND date = ? AND context = 'Class'
        ");
        $del->execute([$classID, $date]);

        $codeStmt = $pdo->prepare("
            SELECT gibbonAttendanceCodeID, direction, name
            FROM gibbonAttendanceCode WHERE gibbonAttendanceCodeID = ?
        ");

        $insert = $pdo->prepare("
            INSERT INTO gibbonAttendanceLogPerson
            (gibbonAttendanceCodeID, gibbonPersonID, direction, type, reason,
             context, comment, date, gibbonPersonIDTaker, gibbonCourseClassID,
             gibbonTTDayRowClassID, timestampTaken)
            VALUES (?, ?, ?, ?, '', 'Class', '', ?, ?, ?, ?, NOW())
        ");

        foreach ($records as $rec) {
            $codeStmt->execute([$rec['codeID']]);
            $code = $codeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$code) continue;
            $insert->execute([
                $rec['codeID'], $rec['personID'], $code['direction'],
                $code['name'], $date, $takerID, $classID, $ttSlotID,
            ]);
        }

        // Write the course-class summary row that Gibbon's UI checks to
        // display the green "Attendance has been taken" banner. Without this,
        // Gibbon shows the "best-guess" warning even though the per-person
        // rows are correctly saved in gibbonAttendanceLogPerson.
        $summaryDel = $pdo->prepare("
            DELETE FROM gibbonAttendanceLogCourseClass
            WHERE gibbonCourseClassID = ? AND date = ?
        ");
        $summaryDel->execute([$classID, $date]);

        $summaryInsert = $pdo->prepare("
            INSERT INTO gibbonAttendanceLogCourseClass
            (gibbonCourseClassID, gibbonTTDayRowClassID, gibbonPersonIDTaker, date, timestampTaken)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $summaryInsert->execute([$classID, $ttSlotID, $takerID, $date]);

        echo json_encode(['success' => true, 'message' => 'Attendance saved successfully']);
        exit;
    }

    // ── GET FORM GROUP ATTENDANCE ──────────────────────────────────────────
    if ($action === 'get_form_group_attendance') {
        $formGroupID = trim($input['formGroupID'] ?? '');
        $date        = trim($input['date'] ?? date('Y-m-d'));

        $stmt = $pdo->prepare("
            SELECT
                gibbonAttendanceLogPerson.gibbonPersonID,
                gibbonAttendanceCodeID,
                gibbonAttendanceCode.name AS codeName,
                gibbonAttendanceCode.nameShort AS codeShort,
                direction
            FROM gibbonAttendanceLogPerson
            JOIN gibbonAttendanceCode USING (gibbonAttendanceCodeID)
            WHERE gibbonFormGroupID = ?
            AND date = ?
            AND context = 'Form Group'
        ");
        $stmt->execute([$formGroupID, $date]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byPerson = [];
        foreach ($records as $r) {
            $byPerson[$r['gibbonPersonID']] = $r;
        }
        echo json_encode(['success' => true, 'attendance' => $byPerson, 'date' => $date]);
        exit;
    }

    // ── SAVE FORM GROUP ATTENDANCE ─────────────────────────────────────────
    if ($action === 'save_form_group_attendance') {
        $formGroupID = trim($input['formGroupID'] ?? '');
        $takerID     = $authUser['personID']; // never trust client-sent takerID
        $date        = trim($input['date'] ?? date('Y-m-d'));
        $records     = $input['records'] ?? [];

        if (empty($formGroupID) || empty($records)) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }

        $del = $pdo->prepare("
            DELETE FROM gibbonAttendanceLogPerson
            WHERE gibbonFormGroupID = ? AND date = ? AND context = 'Form Group'
        ");
        $del->execute([$formGroupID, $date]);

        $codeStmt = $pdo->prepare("
            SELECT gibbonAttendanceCodeID, direction, name
            FROM gibbonAttendanceCode WHERE gibbonAttendanceCodeID = ?
        ");

        $insert = $pdo->prepare("
            INSERT INTO gibbonAttendanceLogPerson
            (gibbonAttendanceCodeID, gibbonPersonID, direction, type, reason,
             context, comment, date, gibbonPersonIDTaker, gibbonFormGroupID, timestampTaken)
            VALUES (?, ?, ?, ?, '', 'Form Group', '', ?, ?, ?, NOW())
        ");

        foreach ($records as $rec) {
            $codeStmt->execute([$rec['codeID']]);
            $code = $codeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$code) continue;
            $insert->execute([
                $rec['codeID'], $rec['personID'], $code['direction'],
                $code['name'], $date, $takerID, $formGroupID,
            ]);
        }

        // Write the form-group summary row that Gibbon's UI checks to
        // display the green "Attendance has been taken" banner.
        $summaryDel = $pdo->prepare("
            DELETE FROM gibbonAttendanceLogFormGroup
            WHERE gibbonFormGroupID = ? AND date = ?
        ");
        $summaryDel->execute([$formGroupID, $date]);

        $summaryInsert = $pdo->prepare("
            INSERT INTO gibbonAttendanceLogFormGroup
            (gibbonFormGroupID, gibbonPersonIDTaker, date, timestampTaken)
            VALUES (?, ?, ?, NOW())
        ");
        $summaryInsert->execute([$formGroupID, $takerID, $date]);

        echo json_encode(['success' => true, 'message' => 'Attendance saved successfully']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect attendance.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>