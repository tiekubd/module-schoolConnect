<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// Scope note: no rich HTML description editor (plain text field — no WYSIWYG
// exists anywhere else in this app), no "Copy All To Next Year" bulk
// operation (once-a-year, deliberate, better on desktop — same reasoning as
// Manage School Years), no Custom Fields (consistent everywhere else). The
// cascading delete logic IS ported faithfully though — skipping that would
// leave orphaned timetable/enrolment rows, a real data integrity problem
// rather than just a missing nicety.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
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

    if ($authUser['role'] !== 'Administrator') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not permitted']);
        exit;
    }

    $schoolYearID = $authUser['schoolYearID'];

    // ── LEARNING AREAS ──────────────────────────────────────────────────────
    if ($action === 'learning_areas') {
        $stmt = $pdo->query("SELECT gibbonDepartmentID, name FROM gibbonDepartment WHERE type = 'Learning Area' ORDER BY name");
        echo json_encode(['success' => true, 'learningAreas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── ENROLMENT DEFAULTS ──────────────────────────────────────────────────
    if ($action === 'enrolment_defaults') {
        $stmt = $pdo->prepare("SELECT name, value FROM gibbonSetting WHERE scope = 'Timetable Admin' AND name IN ('enrolmentMinDefault', 'enrolmentMaxDefault')");
        $stmt->execute();
        $settings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['name']] = $row['value'];
        }
        echo json_encode([
            'success' => true,
            'enrolmentMinDefault' => $settings['enrolmentMinDefault'] ?? null,
            'enrolmentMaxDefault' => $settings['enrolmentMaxDefault'] ?? null,
        ]);
        exit;
    }

    // ── LIST COURSES ─────────────────────────────────────────────────────────
    if ($action === 'list_courses') {
        $search = trim($input['search'] ?? '');
        $yearGroupID = trim($input['gibbonYearGroupID'] ?? '');

        $where = "c.gibbonSchoolYearID = ?";
        $params = [$schoolYearID];
        if ($search !== '') {
            $where .= " AND (c.name LIKE ? OR c.nameShort LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like;
        }
        if ($yearGroupID !== '') {
            $where .= " AND FIND_IN_SET(?, c.gibbonYearGroupIDList)";
            $params[] = $yearGroupID;
        }

        $stmt = $pdo->prepare("
            SELECT c.gibbonCourseID, c.name, c.nameShort, d.name AS department,
                   (SELECT COUNT(*) FROM gibbonCourseClass cc WHERE cc.gibbonCourseID = c.gibbonCourseID) AS classCount
            FROM gibbonCourse c
            LEFT JOIN gibbonDepartment d ON d.gibbonDepartmentID = c.gibbonDepartmentID
            WHERE $where
            ORDER BY c.nameShort, c.name
        ");
        $stmt->execute($params);
        echo json_encode(['success' => true, 'courses' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── GET ONE COURSE (+ its classes with roster stats) ────────────────────
    if ($action === 'get_course') {
        $courseID = trim($input['gibbonCourseID'] ?? '');
        $stmt = $pdo->prepare("
            SELECT c.*, sy.name AS yearName
            FROM gibbonCourse c JOIN gibbonSchoolYear sy ON sy.gibbonSchoolYearID = c.gibbonSchoolYearID
            WHERE c.gibbonCourseID = ?
        ");
        $stmt->execute([$courseID]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$course) {
            echo json_encode(['success' => false, 'message' => 'Course not found']);
            exit;
        }
        $course['yearGroupIDs'] = !empty($course['gibbonYearGroupIDList']) ? explode(',', $course['gibbonYearGroupIDList']) : [];

        $classStmt = $pdo->prepare("SELECT * FROM gibbonCourseClass WHERE gibbonCourseID = ? ORDER BY nameShort, name");
        $classStmt->execute([$courseID]);
        $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

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
            foreach ($classes as &$cl) {
                $stats = $statsByClass[$cl['gibbonCourseClassID']] ?? ['teachersTotal' => 0, 'studentsActive' => 0, 'studentsExpected' => 0, 'studentsTotal' => 0];
                $cl = array_merge($cl, $stats);
            }
            unset($cl);
        }

        echo json_encode(['success' => true, 'course' => $course, 'classes' => $classes]);
        exit;
    }

    // ── ADD COURSE ───────────────────────────────────────────────────────────
    if ($action === 'add_course') {
        $departmentID = trim($input['gibbonDepartmentID'] ?? '');
        $departmentID = $departmentID === '' ? null : $departmentID;
        $name = trim($input['name'] ?? '');
        $nameShort = trim($input['nameShort'] ?? '');
        $orderBy = trim($input['orderBy'] ?? '');
        $description = trim($input['description'] ?? '');
        $map = trim($input['map'] ?? 'N');
        $yearGroupIDs = $input['yearGroupIDs'] ?? [];
        $yearGroupIDList = is_array($yearGroupIDs) ? implode(',', $yearGroupIDs) : '';

        if (empty($name) || empty($nameShort)) {
            echo json_encode(['success' => false, 'message' => 'Name and Short Name are required']);
            exit;
        }

        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonCourse WHERE name = ? AND gibbonSchoolYearID = ?");
        $dupStmt->execute([$name, $schoolYearID]);
        if ($dupStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'A course with this name already exists this year']);
            exit;
        }

        $pdo->prepare("
            INSERT INTO gibbonCourse (gibbonDepartmentID, gibbonSchoolYearID, name, nameShort, orderBy, description, map, gibbonYearGroupIDList)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$departmentID, $schoolYearID, $name, $nameShort, $orderBy, $description, $map, $yearGroupIDList]);

        echo json_encode(['success' => true, 'gibbonCourseID' => $pdo->lastInsertId()]);
        exit;
    }

    // ── EDIT COURSE ──────────────────────────────────────────────────────────
    if ($action === 'edit_course') {
        $courseID = trim($input['gibbonCourseID'] ?? '');
        $departmentID = trim($input['gibbonDepartmentID'] ?? '');
        $departmentID = $departmentID === '' ? null : $departmentID;
        $name = trim($input['name'] ?? '');
        $nameShort = trim($input['nameShort'] ?? '');
        $orderBy = trim($input['orderBy'] ?? '');
        $description = trim($input['description'] ?? '');
        $map = trim($input['map'] ?? 'N');
        $yearGroupIDs = $input['yearGroupIDs'] ?? [];
        $yearGroupIDList = is_array($yearGroupIDs) ? implode(',', $yearGroupIDs) : '';

        if (empty($courseID) || empty($name) || empty($nameShort)) {
            echo json_encode(['success' => false, 'message' => 'Name and Short Name are required']);
            exit;
        }

        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonCourse WHERE name = ? AND gibbonSchoolYearID = ? AND gibbonCourseID <> ?");
        $dupStmt->execute([$name, $schoolYearID, $courseID]);
        if ($dupStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'A course with this name already exists this year']);
            exit;
        }

        $pdo->prepare("
            UPDATE gibbonCourse SET gibbonDepartmentID = ?, name = ?, nameShort = ?, orderBy = ?, description = ?, map = ?, gibbonYearGroupIDList = ?
            WHERE gibbonCourseID = ?
        ")->execute([$departmentID, $name, $nameShort, $orderBy, $description, $map, $yearGroupIDList, $courseID]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── DELETE COURSE (full cascade) ─────────────────────────────────────────
    if ($action === 'delete_course') {
        $courseID = trim($input['gibbonCourseID'] ?? '');
        if (empty($courseID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonCourseID required']);
            exit;
        }

        $classIDStmt = $pdo->prepare("SELECT gibbonCourseClassID FROM gibbonCourseClass WHERE gibbonCourseID = ?");
        $classIDStmt->execute([$courseID]);
        $classIDs = $classIDStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($classIDs as $classID) {
            $pdo->prepare("
                DELETE trdce FROM gibbonTTDayRowClassException trdce
                JOIN gibbonTTDayRowClass trdc ON trdc.gibbonTTDayRowClassID = trdce.gibbonTTDayRowClassID
                WHERE trdc.gibbonCourseClassID = ?
            ")->execute([$classID]);
            $pdo->prepare("DELETE FROM gibbonTTDayRowClass WHERE gibbonCourseClassID = ?")->execute([$classID]);
            $pdo->prepare("DELETE FROM gibbonCourseClassPerson WHERE gibbonCourseClassID = ?")->execute([$classID]);
        }
        $pdo->prepare("DELETE FROM gibbonCourseClass WHERE gibbonCourseID = ?")->execute([$courseID]);
        $pdo->prepare("DELETE FROM gibbonCourse WHERE gibbonCourseID = ?")->execute([$courseID]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── GET ONE CLASS ────────────────────────────────────────────────────────
    if ($action === 'get_class') {
        $classID = trim($input['gibbonCourseClassID'] ?? '');
        $stmt = $pdo->prepare("
            SELECT cc.*, c.name AS courseName, c.nameShort AS courseNameShort
            FROM gibbonCourseClass cc JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            WHERE cc.gibbonCourseClassID = ?
        ");
        $stmt->execute([$classID]);
        $class = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$class) {
            echo json_encode(['success' => false, 'message' => 'Class not found']);
            exit;
        }
        echo json_encode(['success' => true, 'class' => $class]);
        exit;
    }

    // ── ADD CLASS ────────────────────────────────────────────────────────────
    if ($action === 'add_class') {
        $courseID = trim($input['gibbonCourseID'] ?? '');
        $name = trim($input['name'] ?? '');
        $nameShort = trim($input['nameShort'] ?? '');
        $reportable = trim($input['reportable'] ?? 'Y');
        $attendance = trim($input['attendance'] ?? 'N');
        $enrolmentMin = trim($input['enrolmentMin'] ?? '');
        $enrolmentMin = ($enrolmentMin !== '' && is_numeric($enrolmentMin)) ? $enrolmentMin : null;
        $enrolmentMax = trim($input['enrolmentMax'] ?? '');
        $enrolmentMax = ($enrolmentMax !== '' && is_numeric($enrolmentMax)) ? $enrolmentMax : null;

        if (empty($courseID) || empty($name) || empty($nameShort)) {
            echo json_encode(['success' => false, 'message' => 'Name and Short Name are required']);
            exit;
        }

        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonCourseClass WHERE (name = ? OR nameShort = ?) AND gibbonCourseID = ?");
        $dupStmt->execute([$name, $nameShort, $courseID]);
        if ($dupStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'A class with this name or short name already exists for this course']);
            exit;
        }

        $pdo->prepare("
            INSERT INTO gibbonCourseClass (gibbonCourseID, name, nameShort, reportable, attendance, enrolmentMin, enrolmentMax)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$courseID, $name, $nameShort, $reportable, $attendance, $enrolmentMin, $enrolmentMax]);

        echo json_encode(['success' => true, 'gibbonCourseClassID' => $pdo->lastInsertId()]);
        exit;
    }

    // ── EDIT CLASS ───────────────────────────────────────────────────────────
    if ($action === 'edit_class') {
        $classID = trim($input['gibbonCourseClassID'] ?? '');
        $courseID = trim($input['gibbonCourseID'] ?? '');
        $name = trim($input['name'] ?? '');
        $nameShort = trim($input['nameShort'] ?? '');
        $reportable = trim($input['reportable'] ?? 'Y');
        $attendance = trim($input['attendance'] ?? 'N');
        $enrolmentMin = trim($input['enrolmentMin'] ?? '');
        $enrolmentMin = ($enrolmentMin !== '' && is_numeric($enrolmentMin)) ? $enrolmentMin : null;
        $enrolmentMax = trim($input['enrolmentMax'] ?? '');
        $enrolmentMax = ($enrolmentMax !== '' && is_numeric($enrolmentMax)) ? $enrolmentMax : null;

        if (empty($classID) || empty($name) || empty($nameShort)) {
            echo json_encode(['success' => false, 'message' => 'Name and Short Name are required']);
            exit;
        }

        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonCourseClass WHERE (name = ? OR nameShort = ?) AND gibbonCourseID = ? AND gibbonCourseClassID <> ?");
        $dupStmt->execute([$name, $nameShort, $courseID, $classID]);
        if ($dupStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'A class with this name or short name already exists for this course']);
            exit;
        }

        $pdo->prepare("
            UPDATE gibbonCourseClass SET name = ?, nameShort = ?, reportable = ?, attendance = ?, enrolmentMin = ?, enrolmentMax = ?
            WHERE gibbonCourseClassID = ?
        ")->execute([$name, $nameShort, $reportable, $attendance, $enrolmentMin, $enrolmentMax, $classID]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── DELETE CLASS (full cascade) ──────────────────────────────────────────
    if ($action === 'delete_class') {
        $classID = trim($input['gibbonCourseClassID'] ?? '');
        if (empty($classID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonCourseClassID required']);
            exit;
        }

        $pdo->prepare("
            DELETE trdce FROM gibbonTTDayRowClassException trdce
            JOIN gibbonTTDayRowClass trdc ON trdc.gibbonTTDayRowClassID = trdce.gibbonTTDayRowClassID
            WHERE trdc.gibbonCourseClassID = ?
        ")->execute([$classID]);
        $pdo->prepare("DELETE FROM gibbonTTDayRowClass WHERE gibbonCourseClassID = ?")->execute([$classID]);
        $pdo->prepare("DELETE FROM gibbonCourseClassPerson WHERE gibbonCourseClassID = ?")->execute([$classID]);
        $pdo->prepare("DELETE FROM gibbonCourseClass WHERE gibbonCourseClassID = ?")->execute([$classID]);

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect admin_courses.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
