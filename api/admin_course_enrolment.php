<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// "By Class" and "By Person" in Gibbon are two different entry points into
// the SAME table (gibbonCourseClassPerson), with nearly identical add/edit
// logic duplicated across their respective process files. Rather than
// mirror that duplication, this endpoint has ONE shared add/edit/delete
// implementation and two view-specific list/search action sets on top.
//
// Trimmed on purpose (see scope discussion): no embedded live timetable
// widget, no bulk checkbox actions (Copy to class, bulk Mark as Left, bulk
// Reportable toggle), no separate paginated "Former/Old Enrolment" table
// (folded into the main list with a "Left" flag instead), and the giant
// "All Users"/"All Classes" fallback dropdowns are replaced with search.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? '');

// Add-mode role list matches Gibbon's own Add form exactly — "Left" roles
// are only reachable via Edit (that's how Gibbon's own UI works: you don't
// add someone as "Left", you mark them left afterward).
const GC_ADD_ROLES = ['Student', 'Teacher', 'Assistant', 'Technician', 'Parent'];
const GC_EDIT_ROLES = ['Student', 'Student - Left', 'Teacher', 'Teacher - Left', 'Assistant', 'Technician', 'Parent'];

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);

    if ($authUser['role'] !== 'Administrator') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not permitted']);
        exit;
    }

    $schoolYearID = $authUser['schoolYearID'];

    // ── SHARED: ADD (upsert) ────────────────────────────────────────────────
    // Mirrors both addProcess.php files exactly: if the person is already in
    // the class, update their role/re-activate rather than reject as a
    // duplicate (this is how Gibbon itself handles "re-adding" someone).
    if ($action === 'add_enrolment') {
        $personID = trim($input['gibbonPersonID'] ?? '');
        $classID = trim($input['gibbonCourseClassID'] ?? '');
        $role = trim($input['role'] ?? '');

        if (empty($personID) || empty($classID) || empty($role)) {
            echo json_encode(['success' => false, 'message' => 'Person, class, and role are required']);
            exit;
        }

        $checkStmt = $pdo->prepare("SELECT * FROM gibbonCourseClassPerson WHERE gibbonPersonID = ? AND gibbonCourseClassID = ?");
        $checkStmt->execute([$personID, $classID]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $pdo->prepare("
                INSERT INTO gibbonCourseClassPerson (gibbonPersonID, gibbonCourseClassID, role, dateEnrolled)
                VALUES (?, ?, ?, ?)
            ")->execute([$personID, $classID, $role, date('Y-m-d')]);
        } else {
            $dateEnrolled = ($existing['role'] !== $role || empty($existing['dateEnrolled'])) ? date('Y-m-d') : $existing['dateEnrolled'];
            $pdo->prepare("
                UPDATE gibbonCourseClassPerson SET role = ?, dateEnrolled = ?, dateUnenrolled = NULL
                WHERE gibbonPersonID = ? AND gibbonCourseClassID = ?
            ")->execute([$role, $dateEnrolled, $personID, $classID]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ── SHARED: EDIT ─────────────────────────────────────────────────────────
    // dateEnrolled/dateUnenrolled recompute mirrors the two real
    // editProcess.php files — using the byPerson version's logic (it
    // correctly clears dateUnenrolled when a role changes away from "Left"
    // back to active; the byClass version doesn't, which looks like an
    // inconsistency on Gibbon's own side rather than something to copy).
    if ($action === 'edit_enrolment') {
        $id = trim($input['gibbonCourseClassPersonID'] ?? '');
        $role = trim($input['role'] ?? '');
        $reportable = trim($input['reportable'] ?? 'Y');

        if (empty($id) || empty($role)) {
            echo json_encode(['success' => false, 'message' => 'Role is required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM gibbonCourseClassPerson WHERE gibbonCourseClassPersonID = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Enrolment not found']);
            exit;
        }

        $roleChanged = $role !== $existing['role'];
        $goingToLeft = stripos($role, 'Left') !== false;

        $dateEnrolled = ($roleChanged && !$goingToLeft) ? date('Y-m-d') : $existing['dateEnrolled'];
        $dateUnenrolled = $goingToLeft && $roleChanged
            ? date('Y-m-d')
            : ($roleChanged ? null : $existing['dateUnenrolled']);

        $pdo->prepare("
            UPDATE gibbonCourseClassPerson SET role = ?, dateEnrolled = ?, dateUnenrolled = ?, reportable = ?
            WHERE gibbonCourseClassPersonID = ?
        ")->execute([$role, $dateEnrolled, $dateUnenrolled, $reportable, $id]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── SHARED: DELETE ───────────────────────────────────────────────────────
    if ($action === 'delete_enrolment') {
        $id = trim($input['gibbonCourseClassPersonID'] ?? '');
        if (empty($id)) {
            echo json_encode(['success' => false, 'message' => 'gibbonCourseClassPersonID required']);
            exit;
        }
        $pdo->prepare("DELETE FROM gibbonCourseClassPerson WHERE gibbonCourseClassPersonID = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── BY CLASS: list courses + classes with roster stats ──────────────────
    if ($action === 'list_courses') {
        $search = trim($input['search'] ?? '');
        $where = "c.gibbonSchoolYearID = ?";
        $params = [$schoolYearID];
        if ($search !== '') {
            $where .= " AND (c.name LIKE ? OR c.nameShort LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like;
        }

        $stmt = $pdo->prepare("
            SELECT c.gibbonCourseID, c.name AS courseName, c.nameShort AS courseNameShort,
                   cc.gibbonCourseClassID, cc.name AS className, cc.nameShort AS classNameShort
            FROM gibbonCourse c
            JOIN gibbonCourseClass cc ON cc.gibbonCourseID = c.gibbonCourseID
            WHERE $where
            ORDER BY c.nameShort, cc.nameShort
        ");
        $stmt->execute($params);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($classes)) {
            $ids = array_column($classes, 'gibbonCourseClassID');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $statsStmt = $pdo->prepare("
                SELECT ccp.gibbonCourseClassID,
                    SUM(CASE WHEN ccp.role LIKE 'Teacher%' AND ccp.role NOT LIKE '%Left%' THEN 1 ELSE 0 END) AS teachersTotal,
                    SUM(CASE WHEN ccp.role LIKE 'Student%' AND ccp.role NOT LIKE '%Left%' AND p.status = 'Full' THEN 1 ELSE 0 END) AS studentsActive,
                    SUM(CASE WHEN ccp.role LIKE 'Student%' AND ccp.role NOT LIKE '%Left%' AND p.status = 'Expected' THEN 1 ELSE 0 END) AS studentsExpected,
                    SUM(CASE WHEN ccp.role LIKE 'Student%' AND ccp.role NOT LIKE '%Left%' THEN 1 ELSE 0 END) AS studentsTotal
                FROM gibbonCourseClassPerson ccp
                JOIN gibbonPerson p ON p.gibbonPersonID = ccp.gibbonPersonID
                WHERE ccp.gibbonCourseClassID IN ($placeholders)
                GROUP BY ccp.gibbonCourseClassID
            ");
            $statsStmt->execute($ids);
            $statsByClass = [];
            foreach ($statsStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $statsByClass[$s['gibbonCourseClassID']] = $s;
            }

            // enrolmentMin/enrolmentMax aren't confirmed columns on this
            // install — read defensively via SELECT * and only include if
            // actually present, rather than guessing and risking a query
            // failure on a school where they don't exist.
            $limitsStmt = $pdo->prepare("SELECT * FROM gibbonCourseClass WHERE gibbonCourseClassID IN ($placeholders)");
            $limitsStmt->execute($ids);
            $limitsByClass = [];
            foreach ($limitsStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $limitsByClass[$l['gibbonCourseClassID']] = [
                    'enrolmentMin' => $l['enrolmentMin'] ?? null,
                    'enrolmentMax' => $l['enrolmentMax'] ?? null,
                ];
            }

            foreach ($classes as &$c) {
                $stats = $statsByClass[$c['gibbonCourseClassID']] ?? ['teachersTotal' => 0, 'studentsActive' => 0, 'studentsExpected' => 0, 'studentsTotal' => 0];
                $c = array_merge($c, $stats, $limitsByClass[$c['gibbonCourseClassID']] ?? ['enrolmentMin' => null, 'enrolmentMax' => null]);
            }
            unset($c);
        }

        echo json_encode(['success' => true, 'classes' => $classes]);
        exit;
    }

    // ── BY CLASS: full roster for one class ──────────────────────────────────
    if ($action === 'get_class_roster') {
        $classID = trim($input['gibbonCourseClassID'] ?? '');
        if (empty($classID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonCourseClassID required']);
            exit;
        }

        $classStmt = $pdo->prepare("
            SELECT cc.gibbonCourseClassID, cc.name AS className, cc.nameShort AS classNameShort,
                   c.gibbonCourseID, c.name AS courseName, c.nameShort AS courseNameShort
            FROM gibbonCourseClass cc JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            WHERE cc.gibbonCourseClassID = ?
        ");
        $classStmt->execute([$classID]);
        $class = $classStmt->fetch(PDO::FETCH_ASSOC);
        if (!$class) {
            echo json_encode(['success' => false, 'message' => 'Class not found']);
            exit;
        }

        $rosterStmt = $pdo->prepare("
            SELECT ccp.gibbonCourseClassPersonID, ccp.role, ccp.reportable, ccp.dateEnrolled, ccp.dateUnenrolled,
                   p.gibbonPersonID, p.preferredName, p.surname, p.status
            FROM gibbonCourseClassPerson ccp
            JOIN gibbonPerson p ON p.gibbonPersonID = ccp.gibbonPersonID
            WHERE ccp.gibbonCourseClassID = ?
            ORDER BY (ccp.role LIKE '%Left%'), ccp.role, p.surname, p.preferredName
        ");
        $rosterStmt->execute([$classID]);
        $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($roster as &$r) {
            $r['isLeft'] = stripos($r['role'], 'Left') !== false;
        }
        unset($r);

        echo json_encode(['success' => true, 'class' => $class, 'roster' => $roster]);
        exit;
    }

    // ── BY CLASS: search people to add ───────────────────────────────────────
    if ($action === 'search_people') {
        $q = trim($input['search'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode(['success' => true, 'people' => []]);
            exit;
        }
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("
            SELECT gibbonPersonID, preferredName, surname, username
            FROM gibbonPerson
            WHERE status IN ('Full', 'Expected')
            AND (preferredName LIKE ? OR surname LIKE ? OR username LIKE ?)
            ORDER BY surname, preferredName
            LIMIT 30
        ");
        $stmt->execute([$like, $like, $like]);
        echo json_encode(['success' => true, 'people' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── BY PERSON: list students + staff for the active year ───────────────
    if ($action === 'list_people') {
        $search = trim($input['search'] ?? '');
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(50, max(1, (int)($input['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $searchClause = '';
        $searchParamsPerBranch = [];
        if ($search !== '') {
            $searchClause = " AND (p.preferredName LIKE ? OR p.surname LIKE ? OR p.username LIKE ?)";
            $like = '%' . $search . '%';
            $searchParamsPerBranch = [$like, $like, $like];
        }

        $sql = "
            (SELECT p.gibbonPersonID, p.preferredName, p.surname, p.username, 'Student' AS roleCategory,
                    yg.nameShort AS yearGroup, fg.nameShort AS formGroup
             FROM gibbonPerson p
             JOIN gibbonStudentEnrolment se ON se.gibbonPersonID = p.gibbonPersonID AND se.gibbonSchoolYearID = ?
             JOIN gibbonYearGroup yg ON yg.gibbonYearGroupID = se.gibbonYearGroupID
             JOIN gibbonFormGroup fg ON fg.gibbonFormGroupID = se.gibbonFormGroupID
             WHERE p.status = 'Full' $searchClause)
            UNION ALL
            (SELECT p.gibbonPersonID, p.preferredName, p.surname, p.username, 'Staff' AS roleCategory,
                    NULL AS yearGroup, NULL AS formGroup
             FROM gibbonPerson p
             JOIN gibbonStaff s ON s.gibbonPersonID = p.gibbonPersonID
             WHERE p.status = 'Full' $searchClause)
            ORDER BY surname, preferredName
            LIMIT $limit OFFSET $offset
        ";
        $params = array_merge([$schoolYearID], $searchParamsPerBranch, $searchParamsPerBranch);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'people' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── BY PERSON: one person's class enrolments (active year) ─────────────
    if ($action === 'get_person_enrolment') {
        $personID = trim($input['gibbonPersonID'] ?? '');
        if (empty($personID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonPersonID required']);
            exit;
        }

        $personStmt = $pdo->prepare("SELECT preferredName, surname, username FROM gibbonPerson WHERE gibbonPersonID = ?");
        $personStmt->execute([$personID]);
        $person = $personStmt->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            echo json_encode(['success' => false, 'message' => 'Person not found']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT ccp.gibbonCourseClassPersonID, ccp.role, ccp.reportable, ccp.dateEnrolled, ccp.dateUnenrolled,
                   cc.gibbonCourseClassID, cc.nameShort AS classNameShort, c.nameShort AS courseNameShort, c.name AS courseName
            FROM gibbonCourseClassPerson ccp
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = ccp.gibbonCourseClassID
            JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            WHERE ccp.gibbonPersonID = ? AND c.gibbonSchoolYearID = ?
            ORDER BY (ccp.role LIKE '%Left%'), c.nameShort, cc.nameShort
        ");
        $stmt->execute([$personID, $schoolYearID]);
        $enrolment = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($enrolment as &$e) {
            $e['isLeft'] = stripos($e['role'], 'Left') !== false;
        }
        unset($e);

        echo json_encode(['success' => true, 'person' => $person, 'enrolment' => $enrolment]);
        exit;
    }

    // ── BY PERSON: search classes to add ─────────────────────────────────────
    if ($action === 'search_classes') {
        $q = trim($input['search'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode(['success' => true, 'classes' => []]);
            exit;
        }
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("
            SELECT cc.gibbonCourseClassID, cc.nameShort AS classNameShort, c.nameShort AS courseNameShort, c.name AS courseName
            FROM gibbonCourseClass cc
            JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            WHERE c.gibbonSchoolYearID = ? AND (c.name LIKE ? OR c.nameShort LIKE ? OR cc.nameShort LIKE ?)
            ORDER BY c.nameShort, cc.nameShort
            LIMIT 30
        ");
        $stmt->execute([$schoolYearID, $like, $like, $like]);
        echo json_encode(['success' => true, 'classes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── ROLE LISTS ───────────────────────────────────────────────────────────
    if ($action === 'roles') {
        echo json_encode(['success' => true, 'addRoles' => GC_ADD_ROLES, 'editRoles' => GC_EDIT_ROLES]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect admin_course_enrolment.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
