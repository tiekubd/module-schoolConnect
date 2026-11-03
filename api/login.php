<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Read JSON body sent by Flutter
$rawBody = file_get_contents('php://input');
$input   = json_decode($rawBody, true);
if (empty($input)) { $input = $_POST; }

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Username and password required']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Defensive, self-healing table creation — CHANGEDB.php is meant to
    // create this via Gibbon's own module install SQL, but that mechanism
    // has proven unreliable across different Gibbon core versions/hosts.
    // CREATE TABLE IF NOT EXISTS costs essentially nothing once the table
    // exists, and guarantees every login works regardless of whether the
    // installer actually ran the SQL correctly on this particular site.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gibbonConnectSession` (
          `gibbonConnectSessionID` int(11) NOT NULL AUTO_INCREMENT,
          `token` varchar(64) NOT NULL,
          `gibbonPersonID` varchar(20) NOT NULL,
          `role` varchar(50) NOT NULL,
          `selectedSchoolYearID` int(10) unsigned DEFAULT NULL,
          `selectedRoleID` varchar(20) DEFAULT NULL,
          `created` datetime NOT NULL,
          `expires` datetime NOT NULL,
          `lastUsed` datetime NOT NULL,
          PRIMARY KEY (`gibbonConnectSessionID`),
          UNIQUE KEY `token` (`token`),
          KEY `idx_gibbonConnectSession_token` (`token`),
          KEY `idx_gibbonConnectSession_expires` (`expires`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $stmt = $pdo->prepare("
        SELECT gibbonPersonID, firstName, surname, email, username,
               passwordStrong, passwordStrongSalt, status, canLogin,
               gibbonRoleIDPrimary, image_240
        FROM gibbonPerson
        WHERE username = ? AND status = 'Full' AND canLogin = 'Y'
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Check if account exists but is inactive/locked
        $checkStmt = $pdo->prepare("SELECT status, canLogin FROM gibbonPerson WHERE username = ? LIMIT 1");
        $checkStmt->execute([$username]);
        $check = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($check) {
            if ($check['status'] !== 'Full') {
                echo json_encode(['success' => false, 'message' => 'Account is not active']);
            } elseif ($check['canLogin'] !== 'Y') {
                echo json_encode(['success' => false, 'message' => 'Login not permitted for this account']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        }
        exit;
    }

    $passwordValid = false;
    $storedHash = $user['passwordStrong'] ?? '';
    $salt       = $user['passwordStrongSalt'] ?? '';

    if (!empty($storedHash) && !empty($salt)) {
        $computed = hash('sha256', $salt . $password);
        $passwordValid = ($computed === $storedHash);
    }

    if (!$passwordValid) {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        exit;
    }

    // ── License check ────────────────────────────────────────────────────────
    // Shared with login_google.php and session_check.php — see license.php. Sends only this
    // server's host name, caches an "allowed" answer for 24 hours on this
    // server, and fails OPEN if the licence server can't be reached.
    require_once __DIR__ . '/license.php';
    [$licenseAllowed, $licenseData] = gibbonConnectCheckLicense($pdo);

    if (!$licenseAllowed) {
        echo json_encode([
            'success' => false,
            'message' => gibbonConnectLicenseMessage($licenseData),
        ]);
        exit;
    }

    // Get role name AND category from gibbonRoleIDPrimary. Category
    // ('Staff', 'Student', 'Parent', 'Other') is Gibbon's own fixed
    // classification, unaffected by a school renaming/adding custom
    // roles (e.g. "Principal" is still category 'Staff') — the app's
    // dashboard tile visibility checks this, not just the literal name.
    $roleStmt = $pdo->prepare("
        SELECT name, category FROM gibbonRole WHERE gibbonRoleID = ?
    ");
    $roleStmt->execute([$user['gibbonRoleIDPrimary']]);
    $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
    $roleName = $role['name'] ?? 'Unknown';
    $roleCategory = $role['category'] ?? null;

    // ── Issue session token ──────────────────────────────────────────────────
    // This token is required by every other School Connect endpoint (see
    // auth.php). It is NOT a JWT — just a random opaque string stored
    // server-side, so it can be revoked instantly by deleting the row.
    $token = bin2hex(random_bytes(32));
    $tokenExpires = date('Y-m-d H:i:s', strtotime('+30 days'));

    $insertSession = $pdo->prepare("
        INSERT INTO gibbonConnectSession (token, gibbonPersonID, role, created, expires, lastUsed)
        VALUES (?, ?, ?, NOW(), ?, NOW())
    ");
    $insertSession->execute([$token, $user['gibbonPersonID'], $roleName, $tokenExpires]);

    $responseUser = [
        'id'        => $user['gibbonPersonID'],
        'firstName' => $user['firstName'],
        'lastName'  => $user['surname'],
        'email'     => $user['email'],
        'username'  => $user['username'],
        'role'      => $roleName,
        'category'  => $roleCategory,
        'image_240' => $user['image_240'],
    ];

    // Surface trial info to the app, if present, so it can show a banner/reminder
    if (isset($licenseData['status'])) {
        $responseUser['licenseStatus'] = $licenseData['status'];
    }
    if (isset($licenseData['trial_days_left'])) {
        $responseUser['trialDaysLeft'] = $licenseData['trial_days_left'];
    }
    if (isset($licenseData['trial_expires'])) {
        $responseUser['trialExpires'] = $licenseData['trial_expires'];
    }

    echo json_encode([
        'success' => true,
        'user'    => $responseUser,
        'token'   => $token,
    ]);

} catch (PDOException $e) {
    error_log('School Connect login.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>