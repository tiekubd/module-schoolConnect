<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// Distinct from what Add User does: that flow creates a NEW person and
// their first enrolment together in one step. This manages enrolling
// EXISTING people (already in the system — maybe enrolled in a prior year,
// maybe created without the "create linked student enrolment" checkbox)
// into the ACTIVE (possibly switched) school year specifically. Matches
// Gibbon's Admissions > Student Enrolment. Auto-Enrol Courses and the
// form-group-change notification/student-note side effects from the real
// editProcess.php are intentionally left out — the former per direct
// instruction, the latter as notification infrastructure this app doesn't
// otherwise have.

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

    // ── LIST ─────────────────────────────────────────────────────────────────
    if ($action === 'list') {
        $search = trim($input['search'] ?? '');
        $page   = max(1, (int)($input['page'] ?? 1));
        $limit  = min(50, max(1, (int)($input['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $where = "se.gibbonSchoolYearID = ?";
        $params = [$schoolYearID];
        if ($search !== '') {
            $where .= " AND (p.preferredName LIKE ? OR p.surname LIKE ? OR p.username LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM gibbonStudentEnrolment se
            JOIN gibbonPerson p ON p.gibbonPersonID = se.gibbonPersonID
            WHERE $where
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT se.gibbonStudentEnrolmentID, se.gibbonPersonID, se.rollOrder,
                   p.preferredName, p.surname, p.username, p.status, p.dateStart, p.dateEnd,
                   yg.gibbonYearGroupID, yg.name AS yearGroupName, yg.nameShort AS yearGroupShort,
                   fg.gibbonFormGroupID, fg.name AS formGroupName, fg.nameShort AS formGroupShort
            FROM gibbonStudentEnrolment se
            JOIN gibbonPerson p ON p.gibbonPersonID = se.gibbonPersonID
            LEFT JOIN gibbonYearGroup yg ON yg.gibbonYearGroupID = se.gibbonYearGroupID
            LEFT JOIN gibbonFormGroup fg ON fg.gibbonFormGroupID = se.gibbonFormGroupID
            WHERE $where
            ORDER BY p.surname, p.preferredName
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Flag rows the same way Gibbon's own list does: Full status but
        // outside the person's start/end date range.
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['flagged'] = $r['status'] === 'Full'
                && ((!empty($r['dateStart']) && $r['dateStart'] > $today)
                    || (!empty($r['dateEnd']) && $r['dateEnd'] < $today));
        }
        unset($r);

        echo json_encode([
            'success' => true, 'enrolments' => $rows,
            'total' => $total, 'page' => $page, 'limit' => $limit,
            'isCurrentYear' => $authUser['isCurrentYear'],
        ]);
        exit;
    }

    // ── UNENROLLED STUDENTS (for Add) ───────────────────────────────────────
    if ($action === 'unenrolled_students') {
        // No search filtering anymore — matches Gibbon's own Add form,
        // which shows a plain "Please select..." list of everyone not yet
        // enrolled this year rather than requiring a search. $search is
        // still accepted (harmless no-op if omitted) in case a future
        // caller wants it, but the current Flutter screen doesn't send one.
        $search = trim($input['search'] ?? '');
        $where = "1=1";
        $params = [$schoolYearID];
        if ($search !== '') {
            $where .= " AND (p.preferredName LIKE ? OR p.surname LIKE ? OR p.username LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $stmt = $pdo->prepare("
            SELECT p.gibbonPersonID, p.preferredName, p.surname, p.username
            FROM gibbonPerson p
            WHERE p.gibbonRoleIDPrimary IN (SELECT gibbonRoleID FROM gibbonRole WHERE category = 'Student')
            AND p.status IN ('Full', 'Expected')
            AND p.gibbonPersonID NOT IN (
                SELECT gibbonPersonID FROM gibbonStudentEnrolment WHERE gibbonSchoolYearID = ?
            )
            AND $where
            ORDER BY p.surname, p.preferredName
            LIMIT 300
        ");
        // schoolYearID placeholder is first (subquery), then search placeholders
        $stmt->execute($params);
        echo json_encode(['success' => true, 'students' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── GET ONE (for Edit, includes school history) ─────────────────────────
    if ($action === 'get') {
        $id = trim($input['gibbonStudentEnrolmentID'] ?? '');
        $stmt = $pdo->prepare("
            SELECT se.*, p.preferredName, p.surname, p.username
            FROM gibbonStudentEnrolment se
            JOIN gibbonPerson p ON p.gibbonPersonID = se.gibbonPersonID
            WHERE se.gibbonStudentEnrolmentID = ?
        ");
        $stmt->execute([$id]);
        $enrolment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enrolment) {
            echo json_encode(['success' => false, 'message' => 'Enrolment not found']);
            exit;
        }

        $historyStmt = $pdo->prepare("
            SELECT sy.name AS yearName, sy.sequenceNumber, fg.name AS formGroupName
            FROM gibbonStudentEnrolment se
            JOIN gibbonSchoolYear sy ON sy.gibbonSchoolYearID = se.gibbonSchoolYearID
            JOIN gibbonFormGroup fg ON fg.gibbonFormGroupID = se.gibbonFormGroupID
            WHERE se.gibbonPersonID = ?
            ORDER BY sy.sequenceNumber
        ");
        $historyStmt->execute([$enrolment['gibbonPersonID']]);

        echo json_encode([
            'success' => true, 'enrolment' => $enrolment,
            'history' => $historyStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
        exit;
    }

    // ── ADD ──────────────────────────────────────────────────────────────────
    if ($action === 'add') {
        $personID = trim($input['gibbonPersonID'] ?? '');
        $yearGroupID = trim($input['gibbonYearGroupID'] ?? '');
        $formGroupID = trim($input['gibbonFormGroupID'] ?? '');
        $rollOrder = trim($input['rollOrder'] ?? '');
        $rollOrder = $rollOrder === '' ? null : $rollOrder;

        if (empty($personID) || empty($yearGroupID) || empty($formGroupID)) {
            echo json_encode(['success' => false, 'message' => 'Student, Year Group, and Form Group are required']);
            exit;
        }

        $personStmt = $pdo->prepare("SELECT gibbonPersonID FROM gibbonPerson WHERE gibbonPersonID = ? AND (status = 'Full' OR status = 'Expected')");
        $personStmt->execute([$personID]);
        if (!$personStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Student not found or not active']);
            exit;
        }

        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonStudentEnrolment WHERE gibbonPersonID = ? AND gibbonSchoolYearID = ?");
        $dupStmt->execute([$personID, $schoolYearID]);
        if ($dupStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'This student is already enrolled in this school year']);
            exit;
        }

        if ($rollOrder !== null) {
            $rollStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonStudentEnrolment WHERE rollOrder = ? AND gibbonFormGroupID = ?");
            $rollStmt->execute([$rollOrder, $formGroupID]);
            if ($rollStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'That roll order is already used in this form group']);
                exit;
            }
        }

        $pdo->prepare("
            INSERT INTO gibbonStudentEnrolment (gibbonPersonID, gibbonSchoolYearID, gibbonYearGroupID, gibbonFormGroupID, rollOrder)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$personID, $schoolYearID, $yearGroupID, $formGroupID, $rollOrder]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── EDIT ─────────────────────────────────────────────────────────────────
    if ($action === 'edit') {
        $id = trim($input['gibbonStudentEnrolmentID'] ?? '');
        $yearGroupID = trim($input['gibbonYearGroupID'] ?? '');
        $formGroupID = trim($input['gibbonFormGroupID'] ?? '');
        $rollOrder = trim($input['rollOrder'] ?? '');
        $rollOrder = $rollOrder === '' ? null : $rollOrder;

        if (empty($id) || empty($yearGroupID) || empty($formGroupID)) {
            echo json_encode(['success' => false, 'message' => 'Year Group and Form Group are required']);
            exit;
        }

        if ($rollOrder !== null) {
            $rollStmt = $pdo->prepare("
                SELECT COUNT(*) FROM gibbonStudentEnrolment
                WHERE rollOrder = ? AND gibbonFormGroupID = ? AND gibbonStudentEnrolmentID <> ?
            ");
            $rollStmt->execute([$rollOrder, $formGroupID, $id]);
            if ($rollStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'That roll order is already used in this form group']);
                exit;
            }
        }

        $pdo->prepare("
            UPDATE gibbonStudentEnrolment SET gibbonYearGroupID = ?, gibbonFormGroupID = ?, rollOrder = ?
            WHERE gibbonStudentEnrolmentID = ?
        ")->execute([$yearGroupID, $formGroupID, $rollOrder, $id]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── DELETE ───────────────────────────────────────────────────────────────
    // ── AUTO-ENROL DEFAULT ──────────────────────────────────────────────────
    // Reads the school's actual configured default (gibbonSetting, same
    // table Gibbon's own System Admin settings write to) rather than
    // guessing. The toggle itself is inert on our side — see the Flutter
    // screen for why — this just makes sure its default state matches what
    // the school actually has configured, same as the password policy.
    if ($action === 'get_auto_enrol_default') {
        $stmt = $pdo->prepare("SELECT value FROM gibbonSetting WHERE scope = 'Timetable Admin' AND name = 'autoEnrolCourses'");
        $stmt->execute();
        $value = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'default' => $value !== false ? $value : 'N']);
        exit;
    }

    if ($action === 'delete') {
        $id = trim($input['gibbonStudentEnrolmentID'] ?? '');
        if (empty($id)) {
            echo json_encode(['success' => false, 'message' => 'gibbonStudentEnrolmentID required']);
            exit;
        }
        $pdo->prepare("DELETE FROM gibbonStudentEnrolment WHERE gibbonStudentEnrolmentID = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect admin_student_enrolment.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
