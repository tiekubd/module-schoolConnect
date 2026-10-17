<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');

// ── session_check.php ────────────────────────────────────────────────────────
// Called silently on app cold-start when a stored token already exists for
// the last-used school (see SchoolUrlScreen). Validates the token exactly
// like every other protected endpoint (gibbonConnectRequireAuth handles the
// 401 + exit automatically if it's invalid/expired), then returns the same
// full `user` shape login.php/login_google.php return — so the app can skip
// straight to the Dashboard on a valid token, without ever showing the
// login screen, rather than only using the token for API calls AFTER
// someone has already manually logged in.

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Sends 401 + exits on its own if the token is missing/invalid/expired —
    // nothing further to do in that case, this line just won't return.
    $authUser = gibbonConnectRequireAuth($pdo);

    $stmt = $pdo->prepare("
        SELECT gibbonPersonID, firstName, surname, email, username,
               status, canLogin, image_240
        FROM gibbonPerson
        WHERE gibbonPersonID = ? AND status = 'Full' AND canLogin = 'Y'
        LIMIT 1
    ");
    $stmt->execute([$authUser['personID']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Account was disabled/deleted since the token was issued.
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Account is no longer active']);
        exit;
    }

    // ── License check ────────────────────────────────────────────────────────
    // Shared with login.php and login_google.php — see license.php. Sends only this
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

    $responseUser = [
        'id'        => $user['gibbonPersonID'],
        'firstName' => $user['firstName'],
        'lastName'  => $user['surname'],
        'email'     => $user['email'],
        'username'  => $user['username'],
        'role'      => $authUser['role'],
        'category'  => $authUser['category'],
        'image_240' => $user['image_240'],
    ];

    if (isset($licenseData['status'])) {
        $responseUser['licenseStatus'] = $licenseData['status'];
    }
    if (isset($licenseData['trial_days_left'])) {
        $responseUser['trialDaysLeft'] = $licenseData['trial_days_left'];
    }
    if (isset($licenseData['trial_expires'])) {
        $responseUser['trialExpires'] = $licenseData['trial_expires'];
    }

    echo json_encode(['success' => true, 'user' => $responseUser]);

} catch (PDOException $e) {
    error_log('School Connect session_check.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
