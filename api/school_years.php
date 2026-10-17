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

    // ── LIST: all school years, marking which is Current and which is active ──
    if ($action === 'list') {
        $stmt = $pdo->query("
            SELECT gibbonSchoolYearID, name, status, sequenceNumber
            FROM gibbonSchoolYear
            ORDER BY sequenceNumber DESC
        ");
        $years = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($years as &$y) {
            $y['isActive'] = ($y['gibbonSchoolYearID'] == $authUser['schoolYearID']);
        }

        echo json_encode([
            'success'       => true,
            'years'         => $years,
            'activeYearID'  => $authUser['schoolYearID'],
            'isCurrentYear' => $authUser['isCurrentYear'],
        ]);
        exit;
    }

    // ── SWITCH: change the selected year for this session ─────────────────────
    if ($action === 'switch') {
        $yearID = trim($input['schoolYearID'] ?? '');
        if (empty($yearID)) {
            echo json_encode(['success' => false, 'message' => 'schoolYearID required']);
            exit;
        }

        // Validate the year actually exists before storing it
        $checkStmt = $pdo->prepare("SELECT gibbonSchoolYearID, name, status FROM gibbonSchoolYear WHERE gibbonSchoolYearID = ? LIMIT 1");
        $checkStmt->execute([$yearID]);
        $year = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$year) {
            echo json_encode(['success' => false, 'message' => 'School year not found']);
            exit;
        }

        // Store NULL for Current (this is what "switch back to Current" should
        // do, rather than pinning to a year that could later change status)
        $valueToStore = ($year['status'] === 'Current') ? null : $yearID;

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        preg_match('/Bearer\s+(\S+)/i', $authHeader, $m);
        $token = $m[1];

        $update = $pdo->prepare("UPDATE gibbonConnectSession SET selectedSchoolYearID = ? WHERE token = ?");
        $update->execute([$valueToStore, $token]);

        echo json_encode([
            'success'       => true,
            'schoolYearID'  => $yearID,
            'yearName'      => $year['name'],
            'isCurrentYear' => $year['status'] === 'Current',
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect school_years.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
