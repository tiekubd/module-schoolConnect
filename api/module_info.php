<?php
/**
 * School Connect API — Module version
 *
 * Tells the app which version of this module the school is running, for
 * the About screen, so an app/module mismatch is easy to spot when
 * troubleshooting.
 *
 * Returns two values, because they can differ:
 *   - filesVersion:     $moduleVersion from version.php, i.e. the files
 *                       actually uploaded to the server;
 *   - installedVersion: gibbonModule.version, i.e. what Gibbon recorded the
 *                       last time the module was installed or updated.
 * If the files are newer than the installed version, someone uploaded new
 * files but hasn't clicked Update in System Admin > Manage Modules yet.
 *
 * Requires sign-in, like every other endpoint. Contains no personal data.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('School Connect module_info.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

gibbonConnectRequireAuth($pdo);

$moduleVersion = null;
include __DIR__ . '/../version.php';

try {
    $stmt = $pdo->prepare("SELECT version FROM gibbonModule WHERE name = :name");
    $stmt->execute(['name' => 'School Connect']);
    $installedVersion = $stmt->fetchColumn();

    echo json_encode([
        'success'          => true,
        'filesVersion'     => $moduleVersion,
        'installedVersion' => $installedVersion === false ? null : $installedVersion,
    ]);
} catch (PDOException $e) {
    error_log('School Connect module_info.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
