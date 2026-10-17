<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// Rebuilt to match Gibbon core's real "View Student Profiles" page
// (modules/Students/student_view.php + StudentGateway::queryStudentsBySchoolYear,
// source verified) rather than being a type-to-search-only screen:
//   - Loads the full list immediately, no search text required.
//   - Default (allStudents=false) mirrors core's default filter exactly:
//     must have a gibbonStudentEnrolment row for the CURRENT school year,
//     status='Full', and dateStart/dateEnd bounding today.
//   - allStudents=true drops all of that and instead just requires the
//     person to hold a Student role at all (via gibbonRoleIDAll), matching
//     core's "Include all students, regardless of status and current
//     enrolment" checkbox exactly — this can surface students with no
//     current-year enrolment at all (year/form group will be null then).
//   - Status (full/expected) and Date (starting/ended) filters only apply
//     when allStudents=true — core only reveals them once that box is
//     checked, and only offers 'full'/'expected' for students (not 'left',
//     unlike the Manage Users page's status filter list).

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$search = trim($input['search'] ?? '');
$allStudents = ($input['allStudents'] ?? false) === true || ($input['allStudents'] ?? '') === 'on';
$status = trim($input['status'] ?? ''); // full | expected — only applied when allStudents
$date   = trim($input['date'] ?? '');   // starting | ended — only applied when allStudents
$sort   = trim($input['sort'] ?? 'surname'); // surname | preferredName | formGroup | yearGroup
$page   = max(1, (int)($input['page'] ?? 1));
$limit  = min(50, max(1, (int)($input['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);

    // Category rather than a literal role-name list — 'Administrator',
    // 'Teacher', 'Support Staff' together are just Gibbon's default Staff
    // roles, but a school-added custom staff role (e.g. "Principal")
    // still belongs here and would fail a name-list check.
    if ($authUser['category'] !== 'Staff') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not permitted to search students']);
        exit;
    }

    $schoolYearID = $authUser['schoolYearID'];

    $params = [$schoolYearID];
    $where = "1=1";

    if ($allStudents) {
        // Matches core exactly: any person holding a Student role at all,
        // via gibbonRoleIDAll (a comma-separated list of every role a
        // person has ever held), not restricted to current enrolment.
        $joinType = 'LEFT';
        $where .= " AND gibbonPerson.gibbonPersonID IN (
            SELECT gibbonPerson.gibbonPersonID FROM gibbonPerson
            JOIN gibbonRole ON FIND_IN_SET(gibbonRole.gibbonRoleID, gibbonPerson.gibbonRoleIDAll)
            WHERE gibbonRole.category = 'Student'
        )";
        if (!empty($status)) {
            $where .= " AND gibbonPerson.status = ?";
            $params[] = ucfirst($status);
        }
        if ($date === 'starting') {
            $where .= " AND gibbonPerson.dateStart IS NOT NULL AND gibbonPerson.dateStart >= ?";
            $params[] = date('Y-m-d');
        } elseif ($date === 'ended') {
            $where .= " AND gibbonPerson.dateEnd IS NOT NULL AND gibbonPerson.dateEnd <= ?";
            $params[] = date('Y-m-d');
        }
    } else {
        $joinType = 'INNER';
        $where .= " AND gibbonPerson.status = 'Full'
                     AND (gibbonPerson.dateStart IS NULL OR gibbonPerson.dateStart <= ?)
                     AND (gibbonPerson.dateEnd IS NULL OR gibbonPerson.dateEnd >= ?)";
        $params[] = date('Y-m-d');
        $params[] = date('Y-m-d');
    }

    if ($search !== '') {
        $where .= " AND (
            gibbonPerson.preferredName LIKE ? OR gibbonPerson.firstName LIKE ? OR gibbonPerson.surname LIKE ?
            OR gibbonPerson.studentID LIKE ?
            OR CONCAT(gibbonPerson.preferredName, ' ', gibbonPerson.surname) LIKE ?
            OR CONCAT(gibbonPerson.firstName, ' ', gibbonPerson.surname) LIKE ?
        )";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    }

    $orderBy = match ($sort) {
        'preferredName' => 'gibbonPerson.preferredName',
        'formGroup'     => 'gibbonFormGroup.nameShort',
        'yearGroup'     => 'gibbonYearGroup.nameShort',
        default         => 'gibbonPerson.surname, gibbonPerson.preferredName',
    };

    // Both queries share the same JOIN type (fixed per allStudents mode
    // above) — INNER for the default (must have current enrolment), LEFT
    // for All Students (enrolment may be null).
    $countSql = "
        SELECT COUNT(DISTINCT gibbonPerson.gibbonPersonID)
        FROM gibbonPerson
        $joinType JOIN gibbonStudentEnrolment ON (
            gibbonStudentEnrolment.gibbonPersonID = gibbonPerson.gibbonPersonID
            AND gibbonStudentEnrolment.gibbonSchoolYearID = ?
        )
        WHERE $where
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listSql = "
        SELECT DISTINCT
            gibbonPerson.gibbonPersonID,
            gibbonPerson.firstName,
            gibbonPerson.preferredName,
            gibbonPerson.surname,
            gibbonPerson.image_240,
            gibbonPerson.studentID,
            gibbonPerson.status,
            gibbonYearGroup.name AS yearGroup,
            gibbonYearGroup.nameShort AS yearGroupShort,
            gibbonFormGroup.name AS formGroup,
            gibbonFormGroup.nameShort AS formGroupShort
        FROM gibbonPerson
        $joinType JOIN gibbonStudentEnrolment ON (
            gibbonStudentEnrolment.gibbonPersonID = gibbonPerson.gibbonPersonID
            AND gibbonStudentEnrolment.gibbonSchoolYearID = ?
        )
        LEFT JOIN gibbonYearGroup ON (
            gibbonStudentEnrolment.gibbonYearGroupID = gibbonYearGroup.gibbonYearGroupID
        )
        LEFT JOIN gibbonFormGroup ON (
            gibbonStudentEnrolment.gibbonFormGroupID = gibbonFormGroup.gibbonFormGroupID
        )
        WHERE $where
        ORDER BY $orderBy
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($listSql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'students' => $students,
        'total'    => $total,
        'page'     => $page,
        'limit'    => $limit,
    ]);

} catch (PDOException $e) {
    error_log('School Connect student_search.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
