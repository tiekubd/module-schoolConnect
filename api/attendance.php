<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// One row in System Admin > View Logs (Type: School Connect) each time
// attendance is saved from the app, so a school can tell app registers from
// website ones (Gibbon's attendance tables don't record which was used).
// Logging never blocks the save.
// ── "Taken" status and Gibbon's best-guess pre-fill ─────────────────────────
// The same rules as Gibbon core v30's Take Attendance pages
// (attendance_take_byFormGroup.php, attendance_take_byCourseClass.php):
//   - taken: every summary row for the group and date (time, who), as the
//     green "Attendance has been taken at the following times" message lists.
//   - pre-fill, for a student with nothing saved in this register yet: the
//     latest record for that student on that day in any context (an absence
//     entered in advance, the form group register, another class), else the
//     school's default code (Attendance settings). Form group: class records
//     count only if "Count Class Attendance as School Attendance" is on, and a
//     code whose Prefill is N from another form group or a class falls back to
//     the default. Class: other classes count only if "Cross-Fill Classes" is
//     on, and any code whose Prefill is N falls back to the default.
function gibbonConnectAttendanceSetting(PDO $pdo, string $name): string
{
    $stmt = $pdo->prepare("SELECT value FROM gibbonSetting WHERE scope = 'Attendance' AND name = ?");
    $stmt->execute([$name]);
    return (string) ($stmt->fetchColumn() ?: '');
}

function gibbonConnectDefaultCodeID(PDO $pdo, string $settingName): ?string
{
    $name = gibbonConnectAttendanceSetting($pdo, $settingName);
    if ($name === '') return null;
    $stmt = $pdo->prepare("SELECT gibbonAttendanceCodeID FROM gibbonAttendanceCode WHERE name = ? AND active = 'Y' LIMIT 1");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (string) $id;
}

function gibbonConnectTakenLog(PDO $pdo, string $table, string $idColumn, string $id, string $date): array
{
    $stmt = $pdo->prepare("SELECT l.timestampTaken, p.preferredName, p.surname
        FROM $table l JOIN gibbonPerson p ON p.gibbonPersonID = l.gibbonPersonIDTaker
        WHERE l.$idColumn = ? AND l.date = ? ORDER BY l.timestampTaken");
    $stmt->execute([$id, $date]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['time' => substr((string) $r['timestampTaken'], 11, 5),
                  'takenBy' => trim($r['preferredName'] . ' ' . $r['surname'])];
    }
    return $out;
}

/**
 * personID => ['codeID' => …, 'context' => …] for each student without a
 * record in this register, from earlier records that day. Students left out
 * get the default code.
 */
function gibbonConnectPrefill(PDO $pdo, array $personIDs, string $date, string $context, string $groupID): array
{
    $out = [];
    if (!$personIDs) return $out;
    if ($context === 'Form Group') {
        $includeClass = gibbonConnectAttendanceSetting($pdo, 'countClassAsSchool') === 'Y';
    } else {
        $includeClass = gibbonConnectAttendanceSetting($pdo, 'crossFillClasses') === 'Y';
    }
    $stmt = $pdo->prepare("SELECT l.gibbonAttendanceCodeID, l.context, l.gibbonFormGroupID, c.prefill
        FROM gibbonAttendanceLogPerson l
        JOIN gibbonAttendanceCode c ON c.gibbonAttendanceCodeID = l.gibbonAttendanceCodeID
        WHERE l.gibbonPersonID = ? AND l.date = ?" . ($includeClass ? '' : " AND NOT l.context = 'Class'") . "
        ORDER BY l.timestampTaken DESC LIMIT 1");
    foreach ($personIDs as $pid) {
        $stmt->execute([$pid, $date]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$log) continue;
        if ($log['prefill'] === 'N') {
            if ($context === 'Class') continue;
            if ($log['context'] === 'Class') continue;
            if ($log['context'] === 'Form Group' && (string) (int) $log['gibbonFormGroupID'] !== (string) (int) $groupID) continue;
        }
        $out[(string) $pid] = ['codeID' => (string) $log['gibbonAttendanceCodeID'], 'context' => (string) $log['context']];
    }
    return $out;
}

function gibbonConnectLogAttendance(PDO $pdo, $takerID, string $context, $id, string $date, int $count): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO gibbonLog SET
                gibbonSchoolYearID = (SELECT gibbonSchoolYearID FROM gibbonSchoolYear WHERE status = 'Current' LIMIT 1),
                gibbonModuleID = (SELECT gibbonModuleID FROM gibbonModule WHERE name = 'School Connect'),
                gibbonPersonID = :p, title = 'Attendance taken', serialisedArray = :a, ip = :ip");
        $stmt->execute([
            'p'  => $takerID,
            'a'  => serialize(['context' => $context, ($context === 'Class' ? 'gibbonCourseClassID' : 'gibbonFormGroupID') => $id,
                               'date' => $date, 'students' => $count, 'method' => 'School Connect']),
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 15),
        ]);
    } catch (Throwable $e) {
        error_log('School Connect attendance log error: ' . $e->getMessage());
    }
}

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

        // Taken status and pre-fill (app 1.16.19+; older apps ignore them).
        $studentStmt = $pdo->prepare("SELECT gibbonPersonID FROM gibbonCourseClassPerson
            WHERE gibbonCourseClassID = ? AND role = 'Student'");
        $studentStmt->execute([$classID]);
        $missing = array_values(array_filter($studentStmt->fetchAll(PDO::FETCH_COLUMN),
            fn($pid) => !isset($byPerson[$pid])));

        // (object): with no marks saved yet, an empty PHP array would encode
        // as a JSON list [], which the app can't read as student -> mark.
        echo json_encode([
            'success'       => true,
            'attendance'    => (object) $byPerson,
            'date'          => $date,
            'taken'         => gibbonConnectTakenLog($pdo, 'gibbonAttendanceLogCourseClass', 'gibbonCourseClassID', $classID, $date),
            'prefill'       => (object) gibbonConnectPrefill($pdo, $missing, $date, 'Class', $classID),
            'defaultCodeID' => gibbonConnectDefaultCodeID($pdo, 'defaultClassAttendanceType'),
        ]);
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
        gibbonConnectLogAttendance($pdo, $takerID, 'Class', $classID, $date, count($records));

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

        // Taken status and pre-fill (app 1.16.19+; older apps ignore them).
        // Students enrolled in the form group on that date, as core lists them.
        $studentStmt = $pdo->prepare("SELECT gibbonStudentEnrolment.gibbonPersonID
            FROM gibbonStudentEnrolment JOIN gibbonPerson USING (gibbonPersonID)
            WHERE gibbonStudentEnrolment.gibbonFormGroupID = ? AND gibbonPerson.status = 'Full'
            AND (gibbonPerson.dateStart IS NULL OR gibbonPerson.dateStart <= ?)
            AND (gibbonPerson.dateEnd IS NULL OR gibbonPerson.dateEnd >= ?)");
        $studentStmt->execute([$formGroupID, $date, $date]);
        $missing = array_values(array_filter($studentStmt->fetchAll(PDO::FETCH_COLUMN),
            fn($pid) => !isset($byPerson[$pid])));

        // (object): with no marks saved yet, an empty PHP array would encode
        // as a JSON list [], which the app can't read as student -> mark.
        echo json_encode([
            'success'       => true,
            'attendance'    => (object) $byPerson,
            'date'          => $date,
            'taken'         => gibbonConnectTakenLog($pdo, 'gibbonAttendanceLogFormGroup', 'gibbonFormGroupID', $formGroupID, $date),
            'prefill'       => (object) gibbonConnectPrefill($pdo, $missing, $date, 'Form Group', $formGroupID),
            'defaultCodeID' => gibbonConnectDefaultCodeID($pdo, 'defaultFormGroupAttendanceType'),
        ]);
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
        gibbonConnectLogAttendance($pdo, $takerID, 'Form Group', $formGroupID, $date, count($records));

        echo json_encode(['success' => true, 'message' => 'Attendance saved successfully']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect attendance.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>