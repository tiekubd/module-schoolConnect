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

$action = $input['action'] ?? 'list_timetables';
$ttID   = $input['gibbonTTID'] ?? '';

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername, $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);

    if ($authUser['category'] !== 'Staff') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not permitted to view the master timetable']);
        exit;
    }

    // ── ACTION: list_timetables ───────────────────────────────────────────────
    // Returns all timetables grouped by school year
    if ($action === 'list_timetables') {
        $stmt = $pdo->query("
            SELECT
                tt.gibbonTTID,
                tt.name          AS ttName,
                tt.gibbonSchoolYearID,
                sy.name          AS yearName,
                sy.status        AS yearStatus,
                sy.sequenceNumber
            FROM gibbonTT tt
            JOIN gibbonSchoolYear sy ON sy.gibbonSchoolYearID = tt.gibbonSchoolYearID
            ORDER BY sy.sequenceNumber DESC, tt.name
        ");
        $timetables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // CHANGED: default used to always be the Current year's first
        // timetable. Now it prefers a timetable belonging to the session's
        // active (possibly switched) year, falling back to Current if that
        // year has no timetable of its own.
        $default = null;
        foreach ($timetables as $tt) {
            if ($tt['gibbonSchoolYearID'] == $authUser['schoolYearID']) {
                $default = $tt['gibbonTTID'];
                break;
            }
        }
        if ($default === null) {
            foreach ($timetables as $tt) {
                if ($tt['yearStatus'] === 'Current') {
                    $default = $tt['gibbonTTID'];
                    break;
                }
            }
        }

        echo json_encode([
            'success'       => true,
            'timetables'    => $timetables,
            'default'       => $default,
            'isCurrentYear' => $authUser['isCurrentYear'],
        ]);
        exit;
    }

    // ── ACTION: get_master ────────────────────────────────────────────────────
    // Returns all days + periods + classes for a given timetable.
    // Not year-selector-dependent — the timetable itself belongs to a
    // specific gibbonSchoolYearID regardless of what's currently selected,
    // since the person is choosing a specific timetable from the dropdown.
    if ($action === 'get_master') {
        if (empty($ttID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonTTID required']);
            exit;
        }

        // TT info
        $ttStmt = $pdo->prepare("
            SELECT tt.name AS ttName, sy.name AS yearName, tt.gibbonSchoolYearID
            FROM gibbonTT tt
            JOIN gibbonSchoolYear sy ON sy.gibbonSchoolYearID = tt.gibbonSchoolYearID
            WHERE tt.gibbonTTID = ?
        ");
        $ttStmt->execute([$ttID]);
        $ttInfo = $ttStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ttInfo) {
            echo json_encode(['success' => false, 'message' => 'Timetable not found']);
            exit;
        }

        $schoolYearID = $ttInfo['gibbonSchoolYearID'];

        // All TT days for this timetable
        // Faithful port of Gibbon core's TimetableDayGateway::selectTTDaysByID()
        // ordering (source verified) — a LIKE-based weekday cascade, not a
        // plain alphabetical sort. This was previously just "ORDER BY
        // td.name", which is why Fri/Mon/Thu/Tue/Wed showed up alphabetically
        // instead of in weekday order.
        // Known limitation, inherited from core itself (not something we're
        // introducing): this only groups by WEEKDAY — it has no opinion on
        // ordering within a day for rotation/cycle schools (e.g. "Mon 1 Red"
        // vs "Mon 2 Blue"). We add td.name as an explicit final tiebreaker so
        // that ordering is at least deterministic, rather than left to
        // whatever arbitrary order MySQL happens to return ties in.
        $dayStmt = $pdo->prepare("
            SELECT
                td.gibbonTTDayID,
                td.name          AS dayName,
                td.nameShort     AS dayShort,
                td.gibbonTTColumnID
            FROM gibbonTTDay td
            WHERE td.gibbonTTID = ?
            ORDER BY (td.name LIKE '%Mon%') DESC,
                     (td.name LIKE '%Tue%') DESC,
                     (td.name LIKE '%Wed%') DESC,
                     (td.name LIKE '%Thu%') DESC,
                     (td.name LIKE '%Fri%') DESC,
                     (td.name LIKE '%Sat%') DESC,
                     td.name
        ");
        $dayStmt->execute([$ttID]);
        $days = $dayStmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];

        foreach ($days as $day) {
            $ttDayID    = $day['gibbonTTDayID'];
            $ttColumnID = $day['gibbonTTColumnID'];

            // Periods (rows) for this column
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

            $periods = [];

            foreach ($rows as $row) {
                $rowID = $row['gibbonTTColumnRowID'];

                // Classes in this period for this day
                $classStmt = $pdo->prepare("
                    SELECT
                        c.name          AS courseName,
                        c.nameShort     AS courseShort,
                        cc.nameShort    AS classShort,
                        s.name          AS location,
                        CONCAT(p.preferredName, ' ', p.surname) AS teacherName
                    FROM gibbonTTDayRowClass rc
                    JOIN gibbonCourseClass cc   ON cc.gibbonCourseClassID = rc.gibbonCourseClassID
                    JOIN gibbonCourse c         ON c.gibbonCourseID       = cc.gibbonCourseID
                    LEFT JOIN gibbonSpace s     ON s.gibbonSpaceID        = rc.gibbonSpaceID
                    LEFT JOIN gibbonCourseClassPerson tcp ON (
                        tcp.gibbonCourseClassID = cc.gibbonCourseClassID
                        AND tcp.role = 'Teacher'
                    )
                    LEFT JOIN gibbonPerson p    ON p.gibbonPersonID = tcp.gibbonPersonID
                    WHERE rc.gibbonTTDayID       = ?
                    AND   rc.gibbonTTColumnRowID = ?
                    AND   c.gibbonSchoolYearID   = ?
                    GROUP BY rc.gibbonTTDayRowClassID
                    ORDER BY c.nameShort, cc.nameShort
                ");
                $classStmt->execute([$ttDayID, $rowID, $schoolYearID]);
                $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

                $periods[] = [
                    'periodName'  => $row['periodName'],
                    'periodShort' => $row['periodShort'],
                    'timeStart'   => $row['timeStart'],
                    'timeEnd'     => $row['timeEnd'],
                    'type'        => $row['type'],
                    'classes'     => $classes,
                ];
            }

            $result[] = [
                'dayName'  => $day['dayName'],
                'dayShort' => $day['dayShort'],
                'periods'  => $periods,
            ];
        }

        echo json_encode([
            'success'    => true,
            'ttName'     => $ttInfo['ttName'],
            'yearName'   => $ttInfo['yearName'],
            'days'       => $result,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect timetable_master.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
