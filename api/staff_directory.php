<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? 'list');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);

    // Staff directory is visible to staff only — category, not a literal
    // role-name list (see auth.php header comment for why).
    if ($authUser['category'] !== 'Staff') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not permitted']);
        exit;
    }

    // CHANGED: was a hardcoded "WHERE status='Current'" lookup — used here
    // to resolve which form group a staff member tutors, and which classes
    // they teach, in the selected year.
    $schoolYearID = $authUser['schoolYearID'];

    // ── LIST all staff ────────────────────────────────────────────────────────
    if ($action === 'list') {
        // Matches Gibbon core's own StaffGateway::queryAllStaff exactly
        // (source verified): default is status='Full' only — no date/
        // enrolment checks at all, genuinely simpler than the Students
        // page's default filter. "All Staff" just drops this one condition.
        $allStaff = ($input['allStaff'] ?? false) === true || ($input['allStaff'] ?? '') === 'on';

        $where = $allStaff ? "1=1" : "p.status = 'Full'";

        // MIN() on the joined-table columns: p.* are safe on their
        // own (grouped by p's own primary key), but s.* and fg.* aren't
        // guaranteed single-valued per person under strict SQL mode —
        // fg especially, since the 3-way tutor/tutor2/tutor3 OR join can
        // genuinely match more than one form group for the same staff
        // member. ONLY_FULL_GROUP_BY is MySQL's default since 5.7.5 and
        // MariaDB's since 10.2.4 — some hosts have it off, some don't,
        // so this needs to be portable rather than relying on server config.
        $stmt = $pdo->prepare("
            SELECT
                p.gibbonPersonID,
                p.title,
                p.preferredName,
                p.surname,
                p.email,
                p.image_240,
                p.status,
                MIN(s.type) AS type,
                MIN(s.initials) AS initials,
                MIN(s.jobTitle) AS jobTitle,
                MIN(s.biographicalGrouping) AS biographicalGrouping,
                MIN(fg.name)  AS formGroup,
                MIN(fg.nameShort) AS formGroupShort
            FROM gibbonStaff s
            JOIN gibbonPerson p ON p.gibbonPersonID = s.gibbonPersonID
            LEFT JOIN gibbonFormGroup fg ON (
                fg.gibbonPersonIDTutor  = p.gibbonPersonID
                OR fg.gibbonPersonIDTutor2 = p.gibbonPersonID
                OR fg.gibbonPersonIDTutor3 = p.gibbonPersonID
            ) AND fg.gibbonSchoolYearID = ?
            WHERE $where
            GROUP BY p.gibbonPersonID
            ORDER BY p.surname, p.preferredName
        ");
        $stmt->execute([$schoolYearID]);
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'staff' => $staff]);
        exit;
    }

    // ── GET one staff profile ─────────────────────────────────────────────────
    if ($action === 'profile') {
        $personID = trim($input['personID'] ?? '');
        if (empty($personID)) {
            echo json_encode(['success' => false, 'message' => 'personID required']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT
                p.gibbonPersonID,
                p.title,
                p.preferredName,
                p.surname,
                p.email,
                p.username,
                p.image_240,
                p.phone1, p.phone1Type,
                h.name AS house,
                s.type,
                s.initials,
                s.jobTitle,
                s.biography,
                s.qualifications,
                s.countryOfOrigin,
                s.firstAidQualified,
                s.biographicalGrouping,
                fg.name      AS formGroup,
                fg.nameShort AS formGroupShort
            FROM gibbonStaff s
            JOIN gibbonPerson p ON p.gibbonPersonID = s.gibbonPersonID
            LEFT JOIN gibbonHouse h ON h.gibbonHouseID = p.gibbonHouseID
            LEFT JOIN gibbonFormGroup fg ON (
                fg.gibbonPersonIDTutor  = p.gibbonPersonID
                OR fg.gibbonPersonIDTutor2 = p.gibbonPersonID
                OR fg.gibbonPersonIDTutor3 = p.gibbonPersonID
            ) AND fg.gibbonSchoolYearID = ?
            WHERE p.gibbonPersonID = ?
            LIMIT 1
        ");
        $stmt->execute([$schoolYearID, $personID]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            echo json_encode(['success' => false, 'message' => 'Staff member not found']);
            exit;
        }

        // Get classes they teach this year
        $classStmt = $pdo->prepare("
            SELECT
                MIN(c.name)  AS courseName,
                MIN(c.nameShort) AS courseShort,
                MIN(cc.nameShort) AS classShort,
                COUNT(DISTINCT CASE WHEN ccp2.role='Student'
                    THEN ccp2.gibbonPersonID END) AS studentCount
            FROM gibbonCourseClassPerson ccp
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = ccp.gibbonCourseClassID
            JOIN gibbonCourse c       ON c.gibbonCourseID       = cc.gibbonCourseID
            LEFT JOIN gibbonCourseClassPerson ccp2
                ON ccp2.gibbonCourseClassID = cc.gibbonCourseClassID
            WHERE ccp.gibbonPersonID = ?
            AND   ccp.role = 'Teacher'
            AND   c.gibbonSchoolYearID = ?
            GROUP BY cc.gibbonCourseClassID
            ORDER BY c.nameShort, cc.nameShort
        ");
        $classStmt->execute([$personID, $schoolYearID]);
        $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'member'  => $member,
            'classes' => $classes,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect staff_directory.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
