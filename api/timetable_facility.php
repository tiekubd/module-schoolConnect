<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
if (empty($input)) { $input = $_POST; }

$action     = $input['action'] ?? 'list';
$facilityID = $input['facilityID'] ?? '';
$date       = $input['date'] ?? date('Y-m-d');

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

    // ── ACTION: list ──────────────────────────────────────────────────────────
    // Returns all spaces with usage count in the selected school year
    // CHANGED: usage count was hardcoded to "y2.status = 'Current'" —
    // now filters by the session's active year instead.
    if ($action === 'list') {
        $stmt = $pdo->prepare("
            SELECT
                s.gibbonSpaceID,
                s.name,
                s.type,
                s.capacity,
                s.bookable,
                (
                    SELECT COUNT(*)
                    FROM gibbonTTDayRowClass rc2
                    JOIN gibbonTTDay td2    ON td2.gibbonTTDayID   = rc2.gibbonTTDayID
                    JOIN gibbonTT tt2       ON tt2.gibbonTTID      = td2.gibbonTTID
                    WHERE rc2.gibbonSpaceID = s.gibbonSpaceID
                    AND   tt2.gibbonSchoolYearID = ?
                ) AS usageCount
            FROM gibbonSpace s
            WHERE s.active = 'Y'
            ORDER BY s.type, s.name
        ");
        $stmt->execute([$schoolYearID]);
        $spaces = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by type
        $grouped = [];
        foreach ($spaces as $sp) {
            $grouped[$sp['type']][] = $sp;
        }

        echo json_encode(['success' => true, 'spaces' => $spaces, 'grouped' => $grouped]);
        exit;
    }

    // ── ACTION: day ───────────────────────────────────────────────────────────
    // Returns timetable for a specific facility on a given date
    if ($action === 'day') {
        if (empty($facilityID)) {
            echo json_encode(['success' => false, 'message' => 'facilityID required']);
            exit;
        }

        // Get facility details
        $fStmt = $pdo->prepare("SELECT name, type, capacity FROM gibbonSpace WHERE gibbonSpaceID = ?");
        $fStmt->execute([$facilityID]);
        $facility = $fStmt->fetch(PDO::FETCH_ASSOC);

        if (!$facility) {
            echo json_encode(['success' => false, 'message' => 'Facility not found']);
            exit;
        }

        // CHANGED: was "WHERE status='Current'" — now the session's active year.
        $year = $schoolYearID;

        // Find the TT day for the given date
        $dayStmt = $pdo->prepare("
            SELECT
                gibbonTTDayDate.gibbonTTDayID,
                gibbonTTDay.name        AS ttDayName,
                gibbonTTDay.nameShort   AS ttDayShort,
                gibbonTTDay.gibbonTTColumnID
            FROM gibbonTTDayDate
            JOIN gibbonTTDay ON gibbonTTDay.gibbonTTDayID = gibbonTTDayDate.gibbonTTDayID
            JOIN gibbonTT    ON gibbonTT.gibbonTTID       = gibbonTTDay.gibbonTTID
            WHERE gibbonTTDayDate.date = ?
            AND   gibbonTT.gibbonSchoolYearID = ?
            LIMIT 1
        ");
        $dayStmt->execute([$date, $year]);
        $ttDay = $dayStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ttDay) {
            echo json_encode([
                'success'     => true,
                'noTimetable' => true,
                'facility'    => $facility,
                'periods'     => [],
            ]);
            exit;
        }

        $ttDayID    = $ttDay['gibbonTTDayID'];
        $ttColumnID = $ttDay['gibbonTTColumnID'];

        // Get all periods for this column
        $rowStmt = $pdo->prepare("
            SELECT
                gibbonTTColumnRowID,
                name        AS periodName,
                nameShort   AS periodShort,
                timeStart,
                timeEnd,
                type
            FROM gibbonTTColumnRow
            WHERE gibbonTTColumnID = ?
            ORDER BY timeStart
        ");
        $rowStmt->execute([$ttColumnID]);
        $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo json_encode(['success' => true, 'noTimetable' => true, 'periods' => []]);
            exit;
        }

        // Get classes in this facility for each period
        $rowIDs       = array_column($rows, 'gibbonTTColumnRowID');
        $placeholders = implode(',', array_fill(0, count($rowIDs), '?'));

        // Same ONLY_FULL_GROUP_BY portability guard as staff_directory.php
        // — the teacher LEFT JOIN could genuinely match more than one row
        // for a co-taught class, and strict SQL mode (MySQL's default
        // since 5.7.5, MariaDB's since 10.2.4) won't pick one on its own.
        $classStmt = $pdo->prepare("
            SELECT
                rc.gibbonTTColumnRowID,
                MIN(c.name)          AS courseName,
                MIN(c.nameShort)     AS courseShort,
                MIN(cc.name)         AS className,
                MIN(cc.nameShort)    AS classShort,
                MIN(cc.gibbonCourseClassID) AS gibbonCourseClassID,
                MIN(CONCAT(p.preferredName, ' ', p.surname)) AS teacherName
            FROM gibbonTTDayRowClass rc
            JOIN gibbonCourseClass cc   ON cc.gibbonCourseClassID = rc.gibbonCourseClassID
            JOIN gibbonCourse c         ON c.gibbonCourseID       = cc.gibbonCourseID
            LEFT JOIN gibbonCourseClassPerson tcp ON (
                tcp.gibbonCourseClassID = cc.gibbonCourseClassID
                AND tcp.role = 'Teacher'
            )
            LEFT JOIN gibbonPerson p    ON p.gibbonPersonID = tcp.gibbonPersonID
            WHERE rc.gibbonTTDayID       = ?
            AND   rc.gibbonSpaceID        = ?
            AND   rc.gibbonTTColumnRowID IN ($placeholders)
            AND   c.gibbonSchoolYearID    = ?
            GROUP BY rc.gibbonTTDayRowClassID
            ORDER BY rc.gibbonTTColumnRowID
        ");
        $params = array_merge([$ttDayID, $facilityID], $rowIDs, [$year]);
        $classStmt->execute($params);
        $classes = [];
        foreach ($classStmt->fetchAll(PDO::FETCH_ASSOC) as $cls) {
            $classes[$cls['gibbonTTColumnRowID']] = $cls;
        }

        // Current time for status
        $now = date('H:i:s');

        // Build periods array
        $periods = [];
        foreach ($rows as $row) {
            $rid = $row['gibbonTTColumnRowID'];
            $cls = $classes[$rid] ?? null;

            $status = 'upcoming';
            if ($now >= $row['timeEnd']) {
                $status = 'past';
            } elseif ($now >= $row['timeStart'] && $now < $row['timeEnd']) {
                $status = 'current';
            }

            $periods[] = [
                'periodName'  => $row['periodName'],
                'periodShort' => $row['periodShort'],
                'timeStart'   => $row['timeStart'],
                'timeEnd'     => $row['timeEnd'],
                'type'        => $row['type'],
                'status'      => $status,
                'class'       => $cls ? [
                    'courseName'  => $cls['courseName'],
                    'courseShort' => $cls['courseShort'],
                    'classShort'  => $cls['classShort'],
                    'teacherName' => $cls['teacherName'] ?? '',
                    'roomName'    => '',  // already know the room
                ] : null,
            ];
        }

        echo json_encode([
            'success'     => true,
            'noTimetable' => false,
            'facility'    => $facility,
            'ttDayName'   => $ttDay['ttDayName'],
            'periods'     => $periods,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect timetable_facility.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
