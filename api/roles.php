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

    // ── LIST: all roles this person holds, marking which is active ────────────
    if ($action === 'list') {
        $personStmt = $pdo->prepare("
            SELECT gibbonRoleIDPrimary, gibbonRoleIDAll
            FROM gibbonPerson WHERE gibbonPersonID = ? LIMIT 1
        ");
        $personStmt->execute([$authUser['personID']]);
        $personRow = $personStmt->fetch(PDO::FETCH_ASSOC);

        $primaryRoleID = $personRow['gibbonRoleIDPrimary'] ?? null;
        $allRoleIDsRaw = !empty($personRow['gibbonRoleIDAll']) ? $personRow['gibbonRoleIDAll'] : $primaryRoleID;
        $roleIDs = array_values(array_filter(array_map('trim', explode(',', (string)$allRoleIDsRaw))));
        if (empty($roleIDs) && $primaryRoleID) {
            $roleIDs = [$primaryRoleID];
        }

        $roles = [];
        if (!empty($roleIDs)) {
            $placeholders = implode(',', array_fill(0, count($roleIDs), '?'));
            $roleStmt = $pdo->prepare("
                SELECT gibbonRoleID, name
                FROM gibbonRole
                WHERE gibbonRoleID IN ($placeholders)
                ORDER BY name
            ");
            $roleStmt->execute($roleIDs);
            foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $roles[] = [
                    'gibbonRoleID' => $r['gibbonRoleID'],
                    'name'         => $r['name'],
                    'isPrimary'    => $r['gibbonRoleID'] === $primaryRoleID,
                    'isActive'     => $r['gibbonRoleID'] === $authUser['roleID'],
                ];
            }
        }

        echo json_encode([
            'success'          => true,
            'roles'            => $roles,
            'activeRoleID'     => $authUser['roleID'],
            'activeRoleName'   => $authUser['role'],
            'hasMultipleRoles' => $authUser['hasMultipleRoles'],
        ]);
        exit;
    }

    // ── SWITCH: change the selected role for this session ─────────────────────
    if ($action === 'switch') {
        $roleID = trim($input['roleID'] ?? '');
        if (empty($roleID)) {
            echo json_encode(['success' => false, 'message' => 'roleID required']);
            exit;
        }

        // Re-derive this person's actual available roles server-side —
        // never trust the client to only send a roleID it was shown.
        $personStmt = $pdo->prepare("
            SELECT gibbonRoleIDPrimary, gibbonRoleIDAll
            FROM gibbonPerson WHERE gibbonPersonID = ? LIMIT 1
        ");
        $personStmt->execute([$authUser['personID']]);
        $personRow = $personStmt->fetch(PDO::FETCH_ASSOC);

        $primaryRoleID = $personRow['gibbonRoleIDPrimary'] ?? null;
        $allRoleIDsRaw = !empty($personRow['gibbonRoleIDAll']) ? $personRow['gibbonRoleIDAll'] : $primaryRoleID;
        $availableRoleIDs = array_values(array_filter(array_map('trim', explode(',', (string)$allRoleIDsRaw))));

        if (!in_array($roleID, $availableRoleIDs, true)) {
            echo json_encode(['success' => false, 'message' => 'You do not hold that role']);
            exit;
        }

        $roleStmt = $pdo->prepare("SELECT name FROM gibbonRole WHERE gibbonRoleID = ? LIMIT 1");
        $roleStmt->execute([$roleID]);
        $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if (!$role) {
            echo json_encode(['success' => false, 'message' => 'Role not found']);
            exit;
        }

        // Store NULL when switching back to the primary role, mirroring the
        // school-year switcher's approach — keeps the "normal" state clean
        // rather than pinning to an ID that could theoretically change.
        $valueToStore = ($roleID === $primaryRoleID) ? null : $roleID;

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        preg_match('/Bearer\s+(\S+)/i', $authHeader, $m);
        $token = $m[1];

        $update = $pdo->prepare("UPDATE gibbonConnectSession SET selectedRoleID = ? WHERE token = ?");
        $update->execute([$valueToStore, $token]);

        echo json_encode([
            'success'  => true,
            'roleID'   => $roleID,
            'roleName' => $role['name'],
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect roles.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
