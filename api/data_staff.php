<?php
/**
 * School Connect API — Data Updater: Staff (load)
 *
 * Loads the AUTHENTICATED STAFF MEMBER'S OWN staff record fields. Mirrors
 * core's data_staff.php under the 'Update Staff Data_my' scope (source
 * verified against GibbonEdu/core) — self-service only, no "choose a
 * person" step, since unlike Family/Personal/Medical/Finance this data
 * type has no family/child-access dimension at all. The '_any' scope
 * (an admin updating someone else's staff record) is out of scope here.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__.'/auth.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('School Connect data_staff.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

if ($authUser['category'] !== 'Staff') {
    echo json_encode(['success' => false, 'message' => 'Only Staff accounts can update staff data.']);
    exit;
}

try {
    $staffStmt = $pdo->prepare("SELECT * FROM gibbonStaff WHERE gibbonPersonID = :gibbonPersonID");
    $staffStmt->execute(['gibbonPersonID' => $authUser['personID']]);
    $liveStaff = $staffStmt->fetch(PDO::FETCH_ASSOC);

    if (!$liveStaff) {
        echo json_encode(['success' => false, 'message' => 'The selected record does not exist, or you do not have access to it.']);
        exit;
    }

    $gibbonStaffID = $liveStaff['gibbonStaffID'];

    $pendingStmt = $pdo->prepare("SELECT * FROM gibbonStaffUpdate WHERE gibbonStaffID = :gibbonStaffID AND gibbonPersonIDUpdater = :gibbonPersonIDUpdater AND status = 'Pending'");
    $pendingStmt->execute(['gibbonStaffID' => $gibbonStaffID, 'gibbonPersonIDUpdater' => $authUser['personID']]);
    $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    $values = $pending ?: $liveStaff;

    $countries = $pdo->query("SELECT printable_name FROM gibbonCountry ORDER BY printable_name")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'hasPendingEdit' => $pending !== false,
        'values' => [
            'initials' => $values['initials'] ?? '',
            'type' => $values['type'] ?? '',
            'jobTitle' => $values['jobTitle'] ?? '',
            'firstAidQualified' => $values['firstAidQualified'] ?? '',
            'firstAidQualification' => $values['firstAidQualification'] ?? '',
            'firstAidExpiry' => $values['firstAidExpiry'] ?? '',
            'countryOfOrigin' => $values['countryOfOrigin'] ?? '',
            'qualifications' => $values['qualifications'] ?? '',
            'biography' => $values['biography'] ?? '',
        ],
        'countries' => $countries,
    ]);
} catch (Exception $e) {
    error_log('School Connect data_staff.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load staff data.']);
}
