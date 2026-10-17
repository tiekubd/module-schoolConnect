<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// Same distinction as Student Enrolment: Add User creates a NEW person (and
// optionally their gibbonStaff record together, via the checkbox). This
// manages linking EXISTING Staff-role people who don't have a gibbonStaff
// record yet — matches Gibbon's Staff > Manage Staff. Skipped on purpose:
// bulk "Mark as Left", New Staff/Staff Left notification events (no
// notification infrastructure here), and the Facilities/Contracts
// sub-tables from the real edit screen (separate CRUD systems, rare edits,
// better on desktop).

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

    // ── LIST ─────────────────────────────────────────────────────────────────
    if ($action === 'list') {
        $search = trim($input['search'] ?? '');
        $type = trim($input['type'] ?? ''); // '', 'Teaching', 'Support'
        $allStaff = trim($input['allStaff'] ?? '') === 'Y'; // include Expected/Left
        $page   = max(1, (int)($input['page'] ?? 1));
        $limit  = min(50, max(1, (int)($input['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $where = "1=1";
        $params = [];
        if (!$allStaff) {
            $where .= " AND p.status = 'Full'";
        }
        if ($type !== '') {
            $where .= " AND s.type = ?";
            $params[] = $type;
        }
        if ($search !== '') {
            $where .= " AND (p.preferredName LIKE ? OR p.surname LIKE ? OR p.username LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM gibbonStaff s
            JOIN gibbonPerson p ON p.gibbonPersonID = s.gibbonPersonID
            WHERE $where
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT s.gibbonStaffID, s.type, s.initials, s.jobTitle,
                   p.gibbonPersonID, p.title, p.preferredName, p.surname, p.username, p.status, p.image_240
            FROM gibbonStaff s
            JOIN gibbonPerson p ON p.gibbonPersonID = s.gibbonPersonID
            WHERE $where
            ORDER BY p.surname, p.preferredName
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);

        echo json_encode([
            'success' => true, 'staff' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total, 'page' => $page, 'limit' => $limit,
        ]);
        exit;
    }

    // ── POTENTIAL STAFF (for Add) ────────────────────────────────────────────
    if ($action === 'potential_staff') {
        $stmt = $pdo->query("
            SELECT p.gibbonPersonID, p.preferredName, p.surname, p.username
            FROM gibbonPerson p
            WHERE p.gibbonRoleIDPrimary IN (SELECT gibbonRoleID FROM gibbonRole WHERE category = 'Staff')
            AND p.status IN ('Full', 'Expected')
            AND p.gibbonPersonID NOT IN (SELECT gibbonPersonID FROM gibbonStaff)
            ORDER BY p.surname, p.preferredName
            LIMIT 300
        ");
        echo json_encode(['success' => true, 'people' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── COVERAGE SETTING (whether to show the Staff Coverage section) ──────
    if ($action === 'coverage_setting') {
        $stmt = $pdo->prepare("SELECT value FROM gibbonSetting WHERE scope = 'Staff' AND name = 'coverageInternal'");
        $stmt->execute();
        $value = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'enabled' => $value === 'Y']);
        exit;
    }

    // ── COUNTRIES ────────────────────────────────────────────────────────────
    // Reads the actual gibbonCountry table — confirmed via Gibbon's own
    // Gibbon\Forms\DatabaseFormFactory::createSelectCountry(), which uses
    // printable_name as both the value and the display label. Not a
    // hardcoded list — whatever this school's Gibbon install has in that
    // table is exactly what shows here.
    if ($action === 'countries') {
        $stmt = $pdo->query("SELECT printable_name FROM gibbonCountry ORDER BY printable_name");
        echo json_encode(['success' => true, 'countries' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
        exit;
    }

    // ── GET ONE ──────────────────────────────────────────────────────────────
    if ($action === 'get') {
        $id = trim($input['gibbonStaffID'] ?? '');
        $stmt = $pdo->prepare("
            SELECT s.*, p.title, p.preferredName, p.surname, p.username, p.dateStart, p.dateEnd
            FROM gibbonStaff s
            JOIN gibbonPerson p ON p.gibbonPersonID = s.gibbonPersonID
            WHERE s.gibbonStaffID = ?
        ");
        $stmt->execute([$id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) {
            echo json_encode(['success' => false, 'message' => 'Staff record not found']);
            exit;
        }
        echo json_encode(['success' => true, 'staff' => $staff]);
        exit;
    }

    // ── ADD ──────────────────────────────────────────────────────────────────
    if ($action === 'add') {
        $personID = trim($input['gibbonPersonID'] ?? '');
        $initials = trim($input['initials'] ?? '');
        $initials = $initials === '' ? null : $initials;
        $type = trim($input['type'] ?? '');
        $jobTitle = trim($input['jobTitle'] ?? '');
        $firstAidQualified = trim($input['firstAidQualified'] ?? 'N');
        $firstAidQualification = trim($input['firstAidQualification'] ?? '');
        $firstAidExpiry = trim($input['firstAidExpiry'] ?? '');
        $firstAidExpiry = ($firstAidQualified === 'Y' && $firstAidExpiry !== '') ? $firstAidExpiry : null;
        $countryOfOrigin = trim($input['countryOfOrigin'] ?? '');
        $qualifications = trim($input['qualifications'] ?? '');
        $biographicalGrouping = trim($input['biographicalGrouping'] ?? '');
        $biographicalGroupingPriority = trim($input['biographicalGroupingPriority'] ?? '0');
        $biography = trim($input['biography'] ?? '');
        $coverageExclude = trim($input['coverageExclude'] ?? 'N');
        $coveragePriority = trim($input['coveragePriority'] ?? '0');

        if (empty($personID) || empty($type)) {
            echo json_encode(['success' => false, 'message' => 'Person and Type are required']);
            exit;
        }

        if ($initials !== null) {
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonStaff WHERE gibbonPersonID = ? OR initials = ?");
            $dupStmt->execute([$personID, $initials]);
        } else {
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonStaff WHERE gibbonPersonID = ?");
            $dupStmt->execute([$personID]);
        }
        if ($dupStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'This person is already staff, or those initials are taken']);
            exit;
        }

        $pdo->prepare("
            INSERT INTO gibbonStaff
                (gibbonPersonID, initials, type, jobTitle, firstAidQualified, firstAidQualification, firstAidExpiry,
                 countryOfOrigin, qualifications, biographicalGrouping, biographicalGroupingPriority, biography,
                 coverageExclude, coveragePriority)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $personID, $initials, $type, $jobTitle, $firstAidQualified, $firstAidQualification, $firstAidExpiry,
            $countryOfOrigin, $qualifications, $biographicalGrouping, $biographicalGroupingPriority, $biography,
            $coverageExclude, $coveragePriority,
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── EDIT ─────────────────────────────────────────────────────────────────
    if ($action === 'edit') {
        $id = trim($input['gibbonStaffID'] ?? '');
        $initials = trim($input['initials'] ?? '');
        $initials = $initials === '' ? null : $initials;
        $type = trim($input['type'] ?? '');
        $jobTitle = trim($input['jobTitle'] ?? '');
        $dateStart = trim($input['dateStart'] ?? '');
        $dateStart = $dateStart === '' ? null : $dateStart;
        $dateEnd = trim($input['dateEnd'] ?? '');
        $dateEnd = $dateEnd === '' ? null : $dateEnd;
        $firstAidQualified = trim($input['firstAidQualified'] ?? 'N');
        $firstAidQualification = trim($input['firstAidQualification'] ?? '');
        $firstAidExpiry = trim($input['firstAidExpiry'] ?? '');
        $firstAidExpiry = ($firstAidQualified === 'Y' && $firstAidExpiry !== '') ? $firstAidExpiry : null;
        $countryOfOrigin = trim($input['countryOfOrigin'] ?? '');
        $qualifications = trim($input['qualifications'] ?? '');
        $biographicalGrouping = trim($input['biographicalGrouping'] ?? '');
        $biographicalGroupingPriority = trim($input['biographicalGroupingPriority'] ?? '0');
        $biography = trim($input['biography'] ?? '');
        $coverageExclude = trim($input['coverageExclude'] ?? 'N');
        $coveragePriority = trim($input['coveragePriority'] ?? '0');

        if (empty($id) || empty($type)) {
            echo json_encode(['success' => false, 'message' => 'Type is required']);
            exit;
        }

        if ($initials !== null) {
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonStaff WHERE initials = ? AND gibbonStaffID <> ?");
            $dupStmt->execute([$initials, $id]);
            if ($dupStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Those initials are already taken']);
                exit;
            }
        }

        $personStmt = $pdo->prepare("SELECT gibbonPersonID FROM gibbonStaff WHERE gibbonStaffID = ?");
        $personStmt->execute([$id]);
        $personID = $personStmt->fetchColumn();
        if (!$personID) {
            echo json_encode(['success' => false, 'message' => 'Staff record not found']);
            exit;
        }

        $pdo->prepare("
            UPDATE gibbonStaff SET
                initials = ?, type = ?, jobTitle = ?, firstAidQualified = ?, firstAidQualification = ?, firstAidExpiry = ?,
                countryOfOrigin = ?, qualifications = ?, biographicalGrouping = ?, biographicalGroupingPriority = ?,
                biography = ?, coverageExclude = ?, coveragePriority = ?
            WHERE gibbonStaffID = ?
        ")->execute([
            $initials, $type, $jobTitle, $firstAidQualified, $firstAidQualification, $firstAidExpiry,
            $countryOfOrigin, $qualifications, $biographicalGrouping, $biographicalGroupingPriority,
            $biography, $coverageExclude, $coveragePriority, $id,
        ]);

        $pdo->prepare("UPDATE gibbonPerson SET dateStart = ?, dateEnd = ? WHERE gibbonPersonID = ?")
            ->execute([$dateStart, $dateEnd, $personID]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── DELETE ───────────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = trim($input['gibbonStaffID'] ?? '');
        if (empty($id)) {
            echo json_encode(['success' => false, 'message' => 'gibbonStaffID required']);
            exit;
        }
        $pdo->prepare("DELETE FROM gibbonStaff WHERE gibbonStaffID = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect admin_staff.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
