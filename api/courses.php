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

$input = json_decode(file_get_contents('php://input'), true);
// personID and role now come from the validated session token below,
// never from the request body — see auth.php.

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);
    $personID = $authUser['personID'];
    $role     = $authUser['role'];

    // Any staff role other than Administrator lists the classes it teaches,
    // as a Teacher does. Branching on the role's NAME alone sent a school's
    // own staff roles ("Principal", "Head of Department", a limited admin
    // copy such as "Admin2") to the student query below, so My Classes came
    // back empty. Gibbon's role category is fixed whatever the role is
    // called (see auth.php). Administrator keeps its "every class" list.
    if ($role !== 'Administrator' && ($authUser['category'] ?? '') === 'Staff') {
        $role = 'Teacher';
    }

    // Attendance menu's By Class (scope 'attendance', module 1.16.23+): as
    // Gibbon core v30's Attendance By Class page, whose class picker offers
    // My Classes and All Classes to anyone with that permission
    // (DatabaseFormFactory::createSelectClass, attendance = 'Y'). So: every
    // class this year that takes attendance, or none without the
    // permission. Without the scope (browsing Classes) nothing changes.
    $attendanceScope = (($input['scope'] ?? '') === 'attendance');
    $listAll = $role === 'Administrator';
    $classAccess = true;
    if ($attendanceScope) {
        $classAccess = gibbonConnectAttendanceAccess($pdo, $authUser)['class'];
        $listAll = $classAccess;
    }

    // CHANGED: was a hardcoded "WHERE status='Current'" lookup. Now uses
    // whatever year the user has selected (defaults to Current — see auth.php).
    $schoolYearID = $authUser['schoolYearID'];
    if (!$schoolYearID) {
        echo json_encode(['success' => false, 'message' => 'No active school year found']);
        exit;
    }

    // Get year groups for filter
    $ygStmt = $pdo->query("
        SELECT gibbonYearGroupID, name, nameShort
        FROM gibbonYearGroup
        ORDER BY sequenceNumber
    ");
    $yearGroups = $ygStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($listAll) {
        $stmt = $pdo->prepare("
            SELECT
                gibbonCourse.gibbonCourseID,
                gibbonCourse.name AS courseName,
                gibbonCourse.nameShort AS courseShort,
                gibbonCourse.gibbonYearGroupIDList,
                gibbonCourseClass.gibbonCourseClassID,
                gibbonCourseClass.name AS className,
                gibbonCourseClass.nameShort AS classShort,
                gibbonDepartment.name AS department,
                COUNT(DISTINCT CASE WHEN gibbonCourseClassPerson.role='Student'
                    THEN gibbonCourseClassPerson.gibbonPersonID END) AS studentCount
            FROM gibbonCourse
            JOIN gibbonCourseClass ON (gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID)
            LEFT JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
            LEFT JOIN gibbonDepartment ON (gibbonCourse.gibbonDepartmentID = gibbonDepartment.gibbonDepartmentID)
            WHERE gibbonCourse.gibbonSchoolYearID = ?
            GROUP BY gibbonCourseClass.gibbonCourseClassID
            ORDER BY gibbonCourse.nameShort, gibbonCourseClass.nameShort
        ");
        $stmt->execute([$schoolYearID]);

    } elseif ($role === 'Teacher') {
        $stmt = $pdo->prepare("
            SELECT
                gibbonCourse.gibbonCourseID,
                gibbonCourse.name AS courseName,
                gibbonCourse.nameShort AS courseShort,
                gibbonCourse.gibbonYearGroupIDList,
                gibbonCourseClass.gibbonCourseClassID,
                gibbonCourseClass.name AS className,
                gibbonCourseClass.nameShort AS classShort,
                gibbonDepartment.name AS department,
                COUNT(DISTINCT CASE WHEN ccp2.role='Student'
                    THEN ccp2.gibbonPersonID END) AS studentCount
            FROM gibbonCourse
            JOIN gibbonCourseClass ON (gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID)
            JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
            LEFT JOIN gibbonCourseClassPerson ccp2 ON (ccp2.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
            LEFT JOIN gibbonDepartment ON (gibbonCourse.gibbonDepartmentID = gibbonDepartment.gibbonDepartmentID)
            WHERE gibbonCourse.gibbonSchoolYearID = ?
            AND gibbonCourseClassPerson.gibbonPersonID = ?
            AND gibbonCourseClassPerson.role = 'Teacher'
            GROUP BY gibbonCourseClass.gibbonCourseClassID
            ORDER BY gibbonCourse.nameShort, gibbonCourseClass.nameShort
        ");
        $stmt->execute([$schoolYearID, $personID]);

    } else {
        $stmt = $pdo->prepare("
            SELECT
                gibbonCourse.gibbonCourseID,
                gibbonCourse.name AS courseName,
                gibbonCourse.nameShort AS courseShort,
                gibbonCourse.gibbonYearGroupIDList,
                gibbonCourseClass.gibbonCourseClassID,
                gibbonCourseClass.name AS className,
                gibbonCourseClass.nameShort AS classShort,
                gibbonDepartment.name AS department,
                NULL AS studentCount
            FROM gibbonCourse
            JOIN gibbonCourseClass ON (gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID)
            JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
            LEFT JOIN gibbonDepartment ON (gibbonCourse.gibbonDepartmentID = gibbonDepartment.gibbonDepartmentID)
            WHERE gibbonCourse.gibbonSchoolYearID = ?
            AND gibbonCourseClassPerson.gibbonPersonID = ?
            AND gibbonCourseClassPerson.role = 'Student'
            ORDER BY gibbonCourse.nameShort, gibbonCourseClass.nameShort
        ");
        $stmt->execute([$schoolYearID, $personID]);
    }

    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($attendanceScope) {
        $takesAttendance = array_flip($pdo->query("SELECT gibbonCourseClassID FROM gibbonCourseClass WHERE attendance = 'Y'")
            ->fetchAll(PDO::FETCH_COLUMN));
        $classes = $classAccess
            ? array_values(array_filter($classes, fn($c) => isset($takesAttendance[$c['gibbonCourseClassID']])))
            : [];
        // mine: the person is in the class (any role but "... - Left"), so the
        // app can list My Classes first and All Classes after, as core's picker.
        $mineStmt = $pdo->prepare("SELECT DISTINCT gibbonCourseClassID FROM gibbonCourseClassPerson
            WHERE gibbonPersonID = ? AND NOT role LIKE '% - Left%'");
        $mineStmt->execute([$personID]);
        $mine = array_flip($mineStmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($classes as &$c) $c['mine'] = isset($mine[$c['gibbonCourseClassID']]);
        unset($c);
    }

    echo json_encode([
        'success'       => true,
        'allClasses'    => $listAll,
        'classes'       => $classes,
        'count'         => count($classes),
        'yearGroups'    => $yearGroups,
        'isCurrentYear' => $authUser['isCurrentYear'],
    ]);

} catch (PDOException $e) {
    error_log('School Connect courses.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
