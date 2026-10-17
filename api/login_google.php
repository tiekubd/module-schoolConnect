<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$input   = json_decode($rawBody, true);
if (empty($input)) { $input = $_POST; }

$idToken = trim($input['idToken'] ?? '');
if (empty($idToken)) {
    echo json_encode(['success' => false, 'message' => 'Google ID token required']);
    exit;
}

// ── Web Client ID(s) ─────────────────────────────────────────────────────────
// Google's own guidance: an ID token from a mobile app can carry an
// audience matching ANY of your app's registered OAuth clients (Web, iOS,
// or Android), not just the "server" one passed as serverClientId — this
// varies by platform/plugin version. So we accept a whitelist of every
// client ID registered for this app, not a single value.
//
// EDIT THIS: fill in each of your app's Client IDs from Google Cloud
// Credentials (Web, iOS, and both Android entries — debug + release).
$GOOGLE_VALID_CLIENT_IDS = [
    '154495470461-3v99e853rcb2iru9du3haue9lo707ll4.apps.googleusercontent.com', // Web
    '154495470461-qghpeo022rt3ngrmssen6q939dh3g5p9.apps.googleusercontent.com', // iOS
    '154495470461-im0cl44mjj40puurqb07nlbjebk6n8ha.apps.googleusercontent.com', // Android (debug)
    '154495470461-3kstbuh8rlvgs9qqhi8l2h3q9tms20ba.apps.googleusercontent.com', // Android (Play Store / release)
];

// ── Verify the ID token with Google ─────────────────────────────────────────
// Using Google's tokeninfo endpoint rather than pulling in a JWT/JWK library.
// It validates signature, issuer, and expiry itself — a 200 response with a
// matching audience is sufficient proof of a genuine, unexpired Google token.
// Note: Google doesn't guarantee an SLA on this endpoint for high-volume
// production traffic; if this ever needs to scale past a single school's
// login volume, swap this block for local JWK-based verification instead.
$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

$ch = curl_init($verifyUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$verifyResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($verifyResponse === false || $httpCode !== 200) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired Google credentials']);
    exit;
}

$tokenInfo = json_decode($verifyResponse, true);

if (empty($tokenInfo['aud']) || !in_array($tokenInfo['aud'], $GOOGLE_VALID_CLIENT_IDS, true)) {
    echo json_encode(['success' => false, 'message' => 'Token audience mismatch']);
    exit;
}
if (($tokenInfo['email_verified'] ?? 'false') !== 'true') {
    echo json_encode(['success' => false, 'message' => 'Google email is not verified']);
    exit;
}

$email = trim($tokenInfo['email'] ?? '');
if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'No email returned by Google']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Defensive, self-healing table creation — see login.php for the full
    // rationale. Included here too since this endpoint can be a login entry
    // point on its own (a user's very first login could be via Google).
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

    // Match by email — same join key Gibbon's own OAuthGoogleAdapter uses
    // for its browser-based Google SSO flow, so behaviour stays consistent
    // between the web login page and this mobile endpoint.
    $stmt = $pdo->prepare("
        SELECT gibbonPersonID, firstName, surname, email, username,
               status, canLogin, gibbonRoleIDPrimary, image_240
        FROM gibbonPerson
        WHERE email = ? AND status = 'Full' AND canLogin = 'Y'
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Distinct message/code so the app can show a dedicated explanation
        // rather than a generic "login failed" — this almost always means
        // the Google account's email doesn't match any gibbonPerson.email,
        // not that anything actually went wrong.
        echo json_encode([
            'success' => false,
            'message' => 'No matching account found for this Google email',
            'errorCode' => 'NO_MATCHING_ACCOUNT',
        ]);
        exit;
    }

    // ── License check ────────────────────────────────────────────────────────
    // Shared with login.php and session_check.php — see license.php. Sends only this
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

    // Get role name AND category — see login.php for the full rationale.
    $roleStmt = $pdo->prepare("SELECT name, category FROM gibbonRole WHERE gibbonRoleID = ?");
    $roleStmt->execute([$user['gibbonRoleIDPrimary']]);
    $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
    $roleName = $role['name'] ?? 'Unknown';
    $roleCategory = $role['category'] ?? null;

    // ── Issue session token ──────────────────────────────────────────────────
    // Same gibbonConnectSession mechanism as the password login path, so
    // everything downstream (auth.php, apiPost, SessionExpiredException)
    // works identically regardless of which login method was used.
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
    error_log('School Connect login_google.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
