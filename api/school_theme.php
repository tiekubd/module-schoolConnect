<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config.php';

// Deliberately UNAUTHENTICATED — called from SchoolUrlScreen BEFORE login,
// so the app (including the login screen itself) can already be branded in
// the school's theme colour rather than waiting until after sign-in.
// themeColour is not sensitive data — it's the same cosmetic setting any
// anonymous visitor already sees via the public site's own CSS
// (body-gradient-{colour} in main.css) — so this is a narrow, deliberate
// exception to every other endpoint's auth.php requirement. Returns ONLY
// the colour name, nothing else, to keep that exception as narrow as
// possible. Runs against this school's own install/DB only (same as every
// other endpoint) — no cross-school data exposure.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("SELECT value FROM gibbonSetting WHERE name = 'themeColour' LIMIT 1");
    $stmt->execute();
    // 'purple' matches Gibbon core's own fallback default (src/View/Page.php),
    // used if the setting row is somehow missing entirely.
    $themeColour = $stmt->fetchColumn() ?: 'purple';

    echo json_encode(['success' => true, 'themeColour' => $themeColour]);

} catch (PDOException $e) {
    error_log('School Connect school_theme.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
?>
