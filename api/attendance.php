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
                gibbonAttendanceLogPerson.direction
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
            VALUES (?, ?, ?, ?, '', 'Class', '', ?, ?, ?, ?, '" . gibbonConnectNow() . "')
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
            VALUES (?, ?, ?, ?, '" . gibbonConnectNow() . "')
        ");
        $summaryInsert->execute([$classID, $ttSlotID, $takerID, $date]);

        echo json_encode(['success' => true, 'message' => 'Attendance saved successfully']);
        exit;
    }

    // ── MY FORM GROUPS TODAY (dashboard card) ─────────────────────────────
    // The form groups this person tutors (tutor 1, 2 or 3) in the current
    // school year with attendance switched on, and whether form group
    // attendance has been taken for each today. Same rule Gibbon core's
    // Attendance page uses for "My Form Group" (modules/Attendance/
    // attendance.php, v30): gibbonPersonIDTutor/2/3 + attendance = 'Y'.
    //
    // schoolOpen mirrors core's isSchoolOpen(): the date is inside a term
    // of the year, its weekday is a school day, and it isn't a School
    // Closure special day. The app hides the card when the school is
    // closed or when the session is viewing a past/future year.
    if ($action === 'my_form_groups_today') {
        $date = trim($input['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $schoolYearID = $authUser['schoolYearID'];

        if (!$schoolYearID || empty($authUser['isCurrentYear'])) {
            echo json_encode(['success' => true, 'schoolOpen' => false, 'formGroups' => [], 'date' => $date]);
            exit;
        }

        // Is the school open on $date?
        $open = false;
        $term = $pdo->prepare("SELECT 1 FROM gibbonSchoolYearTerm
            WHERE gibbonSchoolYearID = ? AND ? BETWEEN firstDay AND lastDay LIMIT 1");
        $term->execute([$schoolYearID, $date]);
        if ($term->fetchColumn()) {
            $dow = $pdo->prepare("SELECT 1 FROM gibbonDaysOfWeek WHERE nameShort = ? AND schoolDay = 'Y' LIMIT 1");
            $dow->execute([date('D', strtotime($date))]);
            if ($dow->fetchColumn()) {
                $closed = $pdo->prepare("SELECT 1 FROM gibbonSchoolYearSpecialDay
                    WHERE type = 'School Closure' AND date = ? LIMIT 1");
                $closed->execute([$date]);
                $open = !$closed->fetchColumn();
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                fg.gibbonFormGroupID,
                fg.name,
                fg.nameShort,
                (SELECT COUNT(*)
                   FROM gibbonStudentEnrolment se
                   JOIN gibbonPerson p ON (p.gibbonPersonID = se.gibbonPersonID)
                  WHERE se.gibbonFormGroupID = fg.gibbonFormGroupID
                    AND se.gibbonSchoolYearID = fg.gibbonSchoolYearID
                    AND p.status = 'Full') AS studentCount,
                log.timestampTaken,
                CONCAT(taker.preferredName, ' ', taker.surname) AS takerName
            FROM gibbonFormGroup fg
            LEFT JOIN gibbonAttendanceLogFormGroup log ON (
                log.gibbonAttendanceLogFormGroupID = (
                    SELECT l2.gibbonAttendanceLogFormGroupID
                      FROM gibbonAttendanceLogFormGroup l2
                     WHERE l2.gibbonFormGroupID = fg.gibbonFormGroupID AND l2.date = ?
                  ORDER BY l2.timestampTaken DESC LIMIT 1)
            )
            LEFT JOIN gibbonPerson taker ON (taker.gibbonPersonID = log.gibbonPersonIDTaker)
            WHERE fg.gibbonSchoolYearID = ?
              AND fg.attendance = 'Y'
              AND (fg.gibbonPersonIDTutor = ? OR fg.gibbonPersonIDTutor2 = ? OR fg.gibbonPersonIDTutor3 = ?)
            ORDER BY LENGTH(fg.name), fg.name
        ");
        $pid = $authUser['personID'];
        $stmt->execute([$date, $schoolYearID, $pid, $pid, $pid]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Absent / late counts for groups already taken today
        $counts = $pdo->prepare("
            SELECT
                SUM(lp.direction = 'Out') AS absent,
                SUM(c.scope = 'Onsite - Late') AS late
            FROM gibbonAttendanceLogPerson lp
            JOIN gibbonAttendanceCode c ON (c.gibbonAttendanceCodeID = lp.gibbonAttendanceCodeID)
            WHERE lp.gibbonFormGroupID = ? AND lp.date = ? AND lp.context = 'Form Group'
        ");
        foreach ($groups as &$g) {
            $g['taken'] = !empty($g['timestampTaken']);
            $g['takenAt'] = $g['taken'] ? substr($g['timestampTaken'], 11, 5) : null;
            $g['absent'] = 0;
            $g['late'] = 0;
            if ($g['taken']) {
                $counts->execute([$g['gibbonFormGroupID'], $date]);
                $c = $counts->fetch(PDO::FETCH_ASSOC);
                $g['absent'] = (int) ($c['absent'] ?? 0);
                $g['late'] = (int) ($c['late'] ?? 0);
            }
            $g['studentCount'] = (int) $g['studentCount'];
            unset($g['timestampTaken']);
        }
        unset($g);

        echo json_encode([
            'success'    => true,
            'schoolOpen' => $open,
            'formGroups' => $groups,
            'date'       => $date,
        ]);
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
                gibbonAttendanceLogPerson.direction
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
            VALUES (?, ?, ?, ?, '', 'Form Group', '', ?, ?, ?, '" . gibbonConnectNow() . "')
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
            VALUES (?, ?, ?, '" . gibbonConnectNow() . "')
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