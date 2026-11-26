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

$input    = json_decode(file_get_contents('php://input'), true);
$date     = trim($input['date'] ?? date('Y-m-d'));
$requestedPersonID = trim($input['personID'] ?? '');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);

    $personID = gibbonConnectResolvePersonID(
        $requestedPersonID,
        $authUser,
        $pdo
    );

    // Look up the role of whoever's timetable is being requested server-side —
    // never trust a client-sent role, since that determines which query branch
    // (and therefore which classes) gets returned.
    $roleLookup = $pdo->prepare("
        SELECT gibbonRole.name, gibbonRole.category
        FROM gibbonPerson
        JOIN gibbonRole ON (gibbonRole.gibbonRoleID = gibbonPerson.gibbonRoleIDPrimary)
        WHERE gibbonPerson.gibbonPersonID = ?
        LIMIT 1
    ");
    $roleLookup->execute([$personID]);
    $roleRow = $roleLookup->fetch(PDO::FETCH_ASSOC);
    $role = $roleRow['name'] ?? 'Student';

    // Any staff role other than Administrator gets a teacher's timetable
    // (the classes they teach). By name alone, a school's own staff roles
    // ("Principal", "Admin2", "Support Staff"…) matched no branch below and
    // got an empty timetable. Same rule as courses.php.
    if ($role !== 'Administrator' && ($roleRow['category'] ?? '') === 'Staff') {
        $role = 'Teacher';
    }

    // ── Get active school year ──────────────────────────────────────────────
    // CHANGED: was a hardcoded "WHERE status='Current'" lookup — now uses
    // the session's active year (defaults to Current, see auth.php).
    $schoolYearID = $authUser['schoolYearID'];
    if (!$schoolYearID) {
        echo json_encode(['success' => false, 'message' => 'No active school year']);
        exit;
    }

    // ── Resolve which Timetable this person actually belongs to ────────────
    // BUG FIX: previously we picked "any" gibbonTTDayDate row matching the
    // date, scoped only by school year. When a school has multiple active
    // Timetables (e.g. Early Years / Lower School / Middle & Upper School),
    // that's ambiguous — MySQL returns whichever row it finds first with no
    // ordering, so this silently defaulted to whichever Timetable happens
    // to sort first (in practice, usually the oldest/lowest-ID one), even
    // for a person whose actual classes live in a completely different
    // Timetable. The fix: figure out which Timetable this person's classes
    // are actually scheduled under FIRST, then resolve the day within that
    // specific Timetable — not a schoolwide guess.
    //
    // We look at the person's overall enrolment for the year (not just
    // today), so this still resolves correctly on days they have no
    // lessons at all (e.g. Saturday), rather than only working on days
    // they happen to have a class.
    $resolvedTTID = null;

    if ($role === 'Student' || $role === 'Teacher' || $role === 'Administrator') {

        $enrolmentRole = ($role === 'Teacher') ? 'Teacher' : 'Student';

        // For an Administrator who also teaches, prefer resolving via their
        // teaching classes (same logic as the isAlsoTeacher branch below).
        if ($role === 'Administrator') {
            $checkTeachStmt = $pdo->prepare("
                SELECT COUNT(*) FROM gibbonCourseClassPerson
                WHERE gibbonPersonID = ? AND role = 'Teacher'
            ");
            $checkTeachStmt->execute([$personID]);
            $enrolmentRole = ($checkTeachStmt->fetchColumn() > 0) ? 'Teacher' : null;
        }

        if ($enrolmentRole) {
            $ttResolveStmt = $pdo->prepare("
                SELECT gibbonTTDay.gibbonTTID, COUNT(*) as cnt
                FROM gibbonCourseClassPerson
                JOIN gibbonCourseClass ON (gibbonCourseClass.gibbonCourseClassID = gibbonCourseClassPerson.gibbonCourseClassID)
                JOIN gibbonCourse ON (gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID)
                JOIN gibbonTTDayRowClass ON (gibbonTTDayRowClass.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
                JOIN gibbonTTDay ON (gibbonTTDay.gibbonTTDayID = gibbonTTDayRowClass.gibbonTTDayID)
                WHERE gibbonCourseClassPerson.gibbonPersonID = ?
                AND   gibbonCourseClassPerson.role = ?
                AND   gibbonCourse.gibbonSchoolYearID = ?
                GROUP BY gibbonTTDay.gibbonTTID
                ORDER BY cnt DESC
                LIMIT 1
            ");
            $ttResolveStmt->execute([$personID, $enrolmentRole, $schoolYearID]);
            $ttResolveRow = $ttResolveStmt->fetch(PDO::FETCH_ASSOC);
            $resolvedTTID = $ttResolveRow['gibbonTTID'] ?? null;
        }
    }

    // ── Resolve the TT day for this calendar date ──────────────────────────
    // gibbonTTDayDate maps real dates → gibbonTTDayID. If we resolved a
    // specific Timetable above, scope to it; otherwise (pure Administrator
    // with no personal classes, or a person with literally zero class
    // enrolment) fall back to the old best-effort behaviour, since there's
    // no enrolment to disambiguate from in that case.
    if ($resolvedTTID) {
        $dayStmt = $pdo->prepare("
            SELECT
                gibbonTTDayDate.gibbonTTDayID,
                gibbonTTDay.name        AS ttDayName,
                gibbonTTDay.nameShort   AS ttDayShort,
                gibbonTTDay.gibbonTTID,
                gibbonTTDay.gibbonTTColumnID
            FROM gibbonTTDayDate
            JOIN gibbonTTDay ON (gibbonTTDay.gibbonTTDayID = gibbonTTDayDate.gibbonTTDayID)
            WHERE gibbonTTDayDate.date = ?
            AND   gibbonTTDay.gibbonTTID = ?
            LIMIT 1
        ");
        $dayStmt->execute([$date, $resolvedTTID]);
    } else {
        $dayStmt = $pdo->prepare("
            SELECT
                gibbonTTDayDate.gibbonTTDayID,
                gibbonTTDay.name        AS ttDayName,
                gibbonTTDay.nameShort   AS ttDayShort,
                gibbonTTDay.gibbonTTID,
                gibbonTTDay.gibbonTTColumnID
            FROM gibbonTTDayDate
            JOIN gibbonTTDay ON (gibbonTTDay.gibbonTTDayID = gibbonTTDayDate.gibbonTTDayID)
            JOIN gibbonTT    ON (gibbonTT.gibbonTTID = gibbonTTDay.gibbonTTID)
            WHERE gibbonTTDayDate.date = ?
            AND   gibbonTT.gibbonSchoolYearID = ?
            LIMIT 1
        ");
        $dayStmt->execute([$date, $schoolYearID]);
    }
    $ttDay = $dayStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ttDay) {
        // Not a timetabled day (weekend, holiday, no TT set up)
        echo json_encode([
            'success'      => true,
            'date'         => $date,
            'ttDayName'    => null,
            'ttDayShort'   => null,
            'periods'      => [],
            'noTimetable'  => true,
        ]);
        exit;
    }

    $ttDayID    = $ttDay['gibbonTTDayID'];
    $ttColumnID = $ttDay['gibbonTTColumnID'];

    // ── Get all periods (rows) for this TT column ──────────────────────────
    $rowStmt = $pdo->prepare("
        SELECT
            gibbonTTColumnRow.gibbonTTColumnRowID,
            gibbonTTColumnRow.name        AS periodName,
            gibbonTTColumnRow.nameShort   AS periodShort,
            gibbonTTColumnRow.type,
            gibbonTTColumnRow.timeStart,
            gibbonTTColumnRow.timeEnd
        FROM gibbonTTColumnRow
        WHERE gibbonTTColumnRow.gibbonTTColumnID = ?
        ORDER BY gibbonTTColumnRow.timeStart
    ");
    $rowStmt->execute([$ttColumnID]);
    $allPeriods = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allPeriods)) {
        echo json_encode([
            'success'     => true,
            'date'        => $date,
            'ttDayName'   => $ttDay['ttDayName'],
            'ttDayShort'  => $ttDay['ttDayShort'],
            'periods'     => [],
            'noTimetable' => false,
        ]);
        exit;
    }

    // Collect all period row IDs for the IN clause
    $rowIDs = array_column($allPeriods, 'gibbonTTColumnRowID');
    $placeholders = implode(',', array_fill(0, count($rowIDs), '?'));

    // ── Get classes scheduled in these periods for this person ─────────────
    if ($role === 'Administrator') {
        // Admin who also teaches: first check if they have personal teaching classes.
        // If yes, show those (same as Teacher view). If no, show all school classes.
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM gibbonCourseClassPerson
            WHERE gibbonPersonID = ? AND role = 'Teacher'
        ");
        $checkStmt->execute([$personID]);
        $isAlsoTeacher = $checkStmt->fetchColumn() > 0;

        if ($isAlsoTeacher) {
            // Show only this admin's own teaching timetable
            $classStmt = $pdo->prepare("
                SELECT
                    gibbonTTDayRowClass.gibbonTTColumnRowID,
                    gibbonCourse.name        AS courseName,
                    gibbonCourse.nameShort   AS courseShort,
                    gibbonCourseClass.name   AS className,
                    gibbonCourseClass.nameShort AS classShort,
                    gibbonCourseClass.gibbonCourseClassID,
                    gibbonSpace.name         AS roomName,
                    CONCAT(teacher.preferredName, ' ', teacher.surname) AS teacherName
                FROM gibbonTTDayRowClass
                JOIN gibbonCourseClass ON (
                    gibbonCourseClass.gibbonCourseClassID = gibbonTTDayRowClass.gibbonCourseClassID
                )
                JOIN gibbonCourse ON (
                    gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID
                )
                JOIN gibbonCourseClassPerson myRole ON (
                    myRole.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID
                    AND myRole.gibbonPersonID = ?
                    AND myRole.role = 'Teacher'
                )
                LEFT JOIN gibbonSpace ON (
                    gibbonSpace.gibbonSpaceID = gibbonTTDayRowClass.gibbonSpaceID
                )
                LEFT JOIN gibbonCourseClassPerson tcp ON (
                    tcp.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID
                    AND tcp.role = 'Teacher'
                )
                LEFT JOIN gibbonPerson teacher ON (
                    teacher.gibbonPersonID = tcp.gibbonPersonID
                )
                WHERE gibbonTTDayRowClass.gibbonTTDayID = ?
                AND   gibbonTTDayRowClass.gibbonTTColumnRowID IN ($placeholders)
                AND   gibbonCourse.gibbonSchoolYearID = ?
                GROUP BY gibbonTTDayRowClass.gibbonTTDayRowClassID
                ORDER BY gibbonTTDayRowClass.gibbonTTColumnRowID
            ");
            $params = array_merge([$personID, $ttDayID], $rowIDs, [$schoolYearID]);
            $classStmt->execute($params);
        } else {
            // Pure admin (no teaching): show all school classes
            $classStmt = $pdo->prepare("
                SELECT
                    gibbonTTDayRowClass.gibbonTTColumnRowID,
                    gibbonCourse.name        AS courseName,
                    gibbonCourse.nameShort   AS courseShort,
                    gibbonCourseClass.name   AS className,
                    gibbonCourseClass.nameShort AS classShort,
                    gibbonCourseClass.gibbonCourseClassID,
                    gibbonSpace.name         AS roomName,
                    CONCAT(teacher.preferredName, ' ', teacher.surname) AS teacherName
                FROM gibbonTTDayRowClass
                JOIN gibbonCourseClass ON (
                    gibbonCourseClass.gibbonCourseClassID = gibbonTTDayRowClass.gibbonCourseClassID
                )
                JOIN gibbonCourse ON (
                    gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID
                )
                LEFT JOIN gibbonSpace ON (
                    gibbonSpace.gibbonSpaceID = gibbonTTDayRowClass.gibbonSpaceID
                )
                LEFT JOIN gibbonCourseClassPerson tcp ON (
                    tcp.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID
                    AND tcp.role = 'Teacher'
                )
                LEFT JOIN gibbonPerson teacher ON (
                    teacher.gibbonPersonID = tcp.gibbonPersonID
                )
                WHERE gibbonTTDayRowClass.gibbonTTDayID = ?
                AND   gibbonTTDayRowClass.gibbonTTColumnRowID IN ($placeholders)
                AND   gibbonCourse.gibbonSchoolYearID = ?
                GROUP BY gibbonTTDayRowClass.gibbonTTDayRowClassID
                ORDER BY gibbonTTDayRowClass.gibbonTTColumnRowID
            ");
            $params = array_merge([$ttDayID], $rowIDs, [$schoolYearID]);
            $classStmt->execute($params);
        }

    } elseif ($role === 'Teacher') {
        $classStmt = $pdo->prepare("
            SELECT
                gibbonTTDayRowClass.gibbonTTColumnRowID,
                gibbonCourse.name        AS courseName,
                gibbonCourse.nameShort   AS courseShort,
                gibbonCourseClass.name   AS className,
                gibbonCourseClass.nameShort AS classShort,
                gibbonCourseClass.gibbonCourseClassID,
                gibbonSpace.name         AS roomName,
                CONCAT(teacher.preferredName, ' ', teacher.surname) AS teacherName
            FROM gibbonTTDayRowClass
            JOIN gibbonCourseClass ON (
                gibbonCourseClass.gibbonCourseClassID = gibbonTTDayRowClass.gibbonCourseClassID
            )
            JOIN gibbonCourse ON (
                gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID
            )
            JOIN gibbonCourseClassPerson myRole ON (
                myRole.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID
                AND myRole.gibbonPersonID = ?
                AND myRole.role = 'Teacher'
            )
            LEFT JOIN gibbonSpace ON (
                gibbonSpace.gibbonSpaceID = gibbonTTDayRowClass.gibbonSpaceID
            )
            LEFT JOIN gibbonCourseClassPerson tcp ON (
                tcp.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID
                AND tcp.role = 'Teacher'
            )
            LEFT JOIN gibbonPerson teacher ON (
                teacher.gibbonPersonID = tcp.gibbonPersonID
            )
            WHERE gibbonTTDayRowClass.gibbonTTDayID = ?
            AND   gibbonTTDayRowClass.gibbonTTColumnRowID IN ($placeholders)
            AND   gibbonCourse.gibbonSchoolYearID = ?
            GROUP BY gibbonTTDayRowClass.gibbonTTDayRowClassID
            ORDER BY gibbonTTDayRowClass.gibbonTTColumnRowID
        ");
        $params = array_merge([$personID, $ttDayID], $rowIDs, [$schoolYearID]);
        $classStmt->execute($params);

    } else {
        // Student
        $classStmt = $pdo->prepare("
            SELECT
                gibbonTTDayRowClass.gibbonTTColumnRowID,
                gibbonCourse.name        AS courseName,
                gibbonCourse.nameShort   AS courseShort,
                gibbonCourseClass.name   AS className,
                gibbonCourseClass.nameShort AS classShort,
                gibbonCourseClass.gibbonCourseClassID,
                gibbonSpace.name         AS roomName,
                CONCAT(teacher.preferredName, ' ', teacher.surname) AS teacherName
            FROM gibbonTTDayRowClass
            JOIN gibbonCourseClass ON (
                gibbonCourseClass.gibbonCourseClassID = gibbonTTDayRowClass.gibbonCourseClassID
            )
            JOIN gibbonCourse ON (
                gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID
            )
            JOIN gibbonCourseClassPerson myRole ON (
                myRole.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID
                AND myRole.gibbonPersonID = ?
                AND myRole.role = 'Student'
            )
            LEFT JOIN gibbonSpace ON (
                gibbonSpace.gibbonSpaceID = gibbonTTDayRowClass.gibbonSpaceID
            )
            LEFT JOIN gibbonCourseClassPerson tcp ON (
                tcp.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID
                AND tcp.role = 'Teacher'
            )
            LEFT JOIN gibbonPerson teacher ON (
                teacher.gibbonPersonID = tcp.gibbonPersonID
            )
            WHERE gibbonTTDayRowClass.gibbonTTDayID = ?
            AND   gibbonTTDayRowClass.gibbonTTColumnRowID IN ($placeholders)
            AND   gibbonCourse.gibbonSchoolYearID = ?
            GROUP BY gibbonTTDayRowClass.gibbonTTDayRowClassID
            ORDER BY gibbonTTDayRowClass.gibbonTTColumnRowID
        ");
        $params = array_merge([$personID, $ttDayID], $rowIDs, [$schoolYearID]);
        $classStmt->execute($params);
    }

    $classRows = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    // Index classes by period row ID (one class per period per person)
    $classByPeriod = [];
    foreach ($classRows as $row) {
        $rid = $row['gibbonTTColumnRowID'];
        // Keep first match per period (GROUP BY handles multiples at DB level,
        // but just in case of edge cases with co-teachers)
        if (!isset($classByPeriod[$rid])) {
            $classByPeriod[$rid] = $row;
        }
    }

    // ── Merge periods with classes ─────────────────────────────────────────
    $now        = date('H:i:s');
    $periods    = [];

    foreach ($allPeriods as $period) {
        $rid   = $period['gibbonTTColumnRowID'];
        $cls   = $classByPeriod[$rid] ?? null;

        // Determine if this period is current, past, or upcoming
        $status = 'upcoming';
        if ($period['timeEnd'] < $now) {
            $status = 'past';
        } elseif ($period['timeStart'] <= $now && $now <= $period['timeEnd']) {
            $status = 'current';
        }

        $periods[] = [
            'gibbonTTColumnRowID' => $rid,
            'periodName'   => $period['periodName'],
            'periodShort'  => $period['periodShort'],
            'type'         => $period['type'],
            'timeStart'    => $period['timeStart'],
            'timeEnd'      => $period['timeEnd'],
            'status'       => $status,
            'class'        => $cls ? [
                'gibbonCourseClassID' => $cls['gibbonCourseClassID'],
                'courseName'          => $cls['courseName'],
                'courseShort'         => $cls['courseShort'],
                'classShort'          => $cls['classShort'],
                'roomName'            => $cls['roomName'],
                'teacherName'         => $cls['teacherName'],
            ] : null,
        ];
    }

    // ── Lessons in the person's OTHER timetables today (module 1.16.22+) ──
    // A teacher whose classes are spread over several timetables (one per
    // year group) has lessons outside the timetable resolved above. As
    // Gibbon core v30's ClassesLayer, which takes a person's lessons from
    // every timetable: add them as periods of their own, drop this
    // timetable's empty periods/breaks they overlap, and keep the day in
    // time order. Pure administrators (school-wide list) are left as they
    // were.
    $schoolWide = $role === 'Administrator' && empty($isAlsoTeacher);
    if (!$schoolWide) {
        // Same role as the queries above: Teacher for Teacher / Administrator,
        // otherwise the student's own classes (a parent sees their child's).
        $myRole = ($role === 'Teacher' || $role === 'Administrator') ? 'Teacher' : 'Student';
        $otherStmt = $pdo->prepare("
            SELECT ttcr.gibbonTTColumnRowID, ttcr.name AS periodName, ttcr.nameShort AS periodShort,
                   ttcr.type, ttcr.timeStart, ttcr.timeEnd,
                   c.name AS courseName, c.nameShort AS courseShort,
                   cc.name AS className, cc.nameShort AS classShort, cc.gibbonCourseClassID,
                   sp.name AS roomName,
                   (SELECT CONCAT(p.preferredName, ' ', p.surname) FROM gibbonCourseClassPerson t
                      JOIN gibbonPerson p ON p.gibbonPersonID = t.gibbonPersonID
                     WHERE t.gibbonCourseClassID = cc.gibbonCourseClassID AND t.role = 'Teacher' LIMIT 1) AS teacherName
            FROM gibbonTTDayDate ttdd
            JOIN gibbonTTDay ttd ON ttd.gibbonTTDayID = ttdd.gibbonTTDayID
            JOIN gibbonTTDayRowClass ttdrc ON ttdrc.gibbonTTDayID = ttd.gibbonTTDayID
            JOIN gibbonTTColumnRow ttcr ON ttcr.gibbonTTColumnRowID = ttdrc.gibbonTTColumnRowID
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = ttdrc.gibbonCourseClassID
            JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            JOIN gibbonCourseClassPerson me ON me.gibbonCourseClassID = cc.gibbonCourseClassID
                AND me.gibbonPersonID = ? AND me.role = ?
            LEFT JOIN gibbonTTDayRowClassException ttex ON ttex.gibbonTTDayRowClassID = ttdrc.gibbonTTDayRowClassID
                AND ttex.gibbonPersonID = me.gibbonPersonID
            LEFT JOIN gibbonSpace sp ON sp.gibbonSpaceID = ttdrc.gibbonSpaceID
            WHERE ttdd.date = ? AND ttd.gibbonTTID <> ? AND c.gibbonSchoolYearID = ?
            AND ttex.gibbonTTDayRowClassExceptionID IS NULL
            GROUP BY ttdrc.gibbonTTDayRowClassID
            ORDER BY ttcr.timeStart
        ");
        $otherStmt->execute([$personID, $myRole, $date, $ttDay['gibbonTTID'], $schoolYearID]);
        $others = $otherStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($others) {
            // This timetable's periods with nothing in them that an extra
            // lesson overlaps no longer apply to this person.
            $periods = array_values(array_filter($periods, function ($p) use ($others) {
                if ($p['class'] !== null) return true;
                foreach ($others as $o) {
                    if ($p['timeStart'] < $o['timeEnd'] && $p['timeEnd'] > $o['timeStart']) return false;
                }
                return true;
            }));
            foreach ($others as $o) {
                $status = 'upcoming';
                if ($o['timeEnd'] < $now) {
                    $status = 'past';
                } elseif ($o['timeStart'] <= $now && $now <= $o['timeEnd']) {
                    $status = 'current';
                }
                $periods[] = [
                    'gibbonTTColumnRowID' => $o['gibbonTTColumnRowID'],
                    'periodName'   => $o['periodName'],
                    'periodShort'  => $o['periodShort'],
                    'type'         => $o['type'],
                    'timeStart'    => $o['timeStart'],
                    'timeEnd'      => $o['timeEnd'],
                    'status'       => $status,
                    'class'        => [
                        'gibbonCourseClassID' => $o['gibbonCourseClassID'],
                        'courseName'          => $o['courseName'],
                        'courseShort'         => $o['courseShort'],
                        'classShort'          => $o['classShort'],
                        'roomName'            => $o['roomName'],
                        'teacherName'         => $o['teacherName'],
                    ],
                ];
            }
            usort($periods, fn($a, $b) => [$a['timeStart'], $a['timeEnd']] <=> [$b['timeStart'], $b['timeEnd']]);
        }
    }

    echo json_encode([
        'success'       => true,
        'date'          => $date,
        'ttDayName'     => $ttDay['ttDayName'],
        'ttDayShort'    => $ttDay['ttDayShort'],
        'periods'       => $periods,
        'noTimetable'   => false,
        'isCurrentYear' => $authUser['isCurrentYear'],
    ]);

} catch (PDOException $e) {
    error_log('School Connect timetable.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
