<?php
/**
 * School Connect API — Data Updater: Family (load)
 *
 * Loads a family's editable address/language fields for the Data Updater
 * Family form. Mirrors core's data_family.php (source verified against
 * GibbonEdu/core) — including its access rule (childDataAccess='Y' on
 * THAT specific family link, which core requires even for a parent's own
 * family) and its "resume a pending edit" behavior: if this same person
 * already has a Pending gibbonFamilyUpdate row for this family, that row's
 * values are returned instead of gibbonFamily's current values, so the app
 * shows what they last submitted rather than the (unapproved) live data.
 *
 * SCOPING DECISION: gated to Parent role only, matching Reports/Data
 * Updates. Notification-on-submit (core's NotificationEvent, which also
 * touches email/in-app-bell delivery) is deliberately not replicated here
 * — the admin already reviews pending requests via the website's own
 * Data Updater > Manage screens, per the agreed scope for this pass.
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
    error_log('School Connect data_family.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

if ($authUser['role'] !== 'Parent') {
    echo json_encode(['success' => false, 'message' => 'Only Parent accounts can update family data.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$gibbonFamilyID = trim($input['gibbonFamilyID'] ?? '');

if (empty($gibbonFamilyID)) {
    echo json_encode(['success' => false, 'message' => 'gibbonFamilyID is required.']);
    exit;
}

try {
    // Access check — matches core exactly: requires childDataAccess='Y' on
    // THIS specific family link, even when the family is the caller's own.
    $accessStmt = $pdo->prepare("
        SELECT gibbonFamily.gibbonFamilyID, gibbonFamily.name
        FROM gibbonFamily
        JOIN gibbonFamilyAdult ON (gibbonFamilyAdult.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
        WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID
        AND gibbonFamilyAdult.childDataAccess = 'Y'
        AND gibbonFamily.gibbonFamilyID = :gibbonFamilyID
    ");
    $accessStmt->execute(['gibbonPersonID' => $authUser['personID'], 'gibbonFamilyID' => $gibbonFamilyID]);
    $family = $accessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$family) {
        echo json_encode(['success' => false, 'message' => 'The selected record does not exist, or you do not have access to it.']);
        exit;
    }

    // Check for an existing Pending edit by this same person for this
    // family — if found, resume from those (unapproved) values instead of
    // gibbonFamily's current live data.
    $pendingStmt = $pdo->prepare("
        SELECT * FROM gibbonFamilyUpdate
        WHERE gibbonFamilyID = :gibbonFamilyID AND gibbonPersonIDUpdater = :gibbonPersonIDUpdater AND status = 'Pending'
    ");
    $pendingStmt->execute(['gibbonFamilyID' => $gibbonFamilyID, 'gibbonPersonIDUpdater' => $authUser['personID']]);
    $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    if ($pending) {
        $values = [
            'nameAddress' => $pending['nameAddress'],
            'homeAddress' => $pending['homeAddress'],
            'homeAddressDistrict' => $pending['homeAddressDistrict'],
            'homeAddressCountry' => $pending['homeAddressCountry'],
            'languageHomePrimary' => $pending['languageHomePrimary'],
            'languageHomeSecondary' => $pending['languageHomeSecondary'],
        ];
    } else {
        $familyStmt = $pdo->prepare("SELECT nameAddress, homeAddress, homeAddressDistrict, homeAddressCountry, languageHomePrimary, languageHomeSecondary FROM gibbonFamily WHERE gibbonFamilyID = :gibbonFamilyID");
        $familyStmt->execute(['gibbonFamilyID' => $gibbonFamilyID]);
        $values = $familyStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Dropdown options — queried live from the school's own configured
    // lists (gibbonCountry/gibbonLanguage), not hardcoded, so this always
    // matches whatever this specific install actually offers.
    $countries = $pdo->query("SELECT printable_name FROM gibbonCountry ORDER BY printable_name")->fetchAll(PDO::FETCH_COLUMN);
    $languages = $pdo->query("SELECT name FROM gibbonLanguage ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'familyName' => $family['name'],
        'hasPendingEdit' => $pending !== false,
        'values' => $values,
        'countries' => $countries,
        'languages' => $languages,
    ]);
} catch (Exception $e) {
    error_log('School Connect data_family.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load family data.']);
}
