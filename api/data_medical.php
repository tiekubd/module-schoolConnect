<?php
/**
 * School Connect API — Data Updater: Medical (load)
 *
 * Loads a student's Medical Data: general fields (long-term medication,
 * comment) plus their list of individual medical conditions. Mirrors
 * core's data_medical.php (source verified against GibbonEdu/core).
 *
 * TWO-TABLE SHAPE: general fields live in gibbonPersonMedical (one row
 * per person); conditions live in gibbonPersonMedicalCondition (many rows
 * per person, linked via gibbonPersonMedicalID). Same "resume a pending
 * edit" behavior as Family/Personal — if this same person already has a
 * Pending gibbonPersonMedicalUpdate row, its conditions
 * (gibbonPersonMedicalConditionUpdate) are shown instead of the live data.
 *
 * DELIBERATELY NOT REPLICATED: Personal Documents-style attachments per
 * condition, and Custom Fields — same scope as Personal Data. The
 * `attachment` column is never exposed to the app at all; the submit
 * endpoint preserves it directly from the DB, unseen by the client.
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
    error_log('School Connect data_medical.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

if ($authUser['role'] !== 'Parent') {
    echo json_encode(['success' => false, 'message' => 'Only Parent accounts can update medical data.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$targetPersonID = trim($input['gibbonPersonID'] ?? '');

if (empty($targetPersonID)) {
    echo json_encode(['success' => false, 'message' => 'gibbonPersonID is required.']);
    exit;
}

try {
    // Access check — same self-or-family-member rule as Personal Data
    // (source verified). In practice this only ever matters for
    // Students, since that's the only role category Medical entries
    // appear for on the Data Updates list.
    // Cast to int before comparing — see data_personal.php's load
    // endpoint for the full explanation (ZEROFILL string-formatting
    // mismatch between gibbonConnectSession and gibbonPerson).
    $isSelf = ((int) $targetPersonID === (int) $authUser['personID']);
    $hasAccess = $isSelf;

    if (!$hasAccess) {
        $accessStmt = $pdo->prepare("
            SELECT 1
            FROM gibbonFamilyAdult
            JOIN gibbonFamily ON (gibbonFamily.gibbonFamilyID = gibbonFamilyAdult.gibbonFamilyID)
            WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID AND gibbonFamilyAdult.childDataAccess = 'Y'
            AND gibbonFamily.gibbonFamilyID IN (
                SELECT gibbonFamilyID FROM gibbonFamilyChild WHERE gibbonPersonID = :targetPersonID1
                UNION
                SELECT gibbonFamilyID FROM gibbonFamilyAdult WHERE gibbonPersonID = :targetPersonID2
            )
            LIMIT 1
        ");
        $accessStmt->execute([
            'gibbonPersonID' => $authUser['personID'],
            'targetPersonID1' => $targetPersonID,
            'targetPersonID2' => $targetPersonID,
        ]);
        $hasAccess = (bool) $accessStmt->fetchColumn();
    }

    if (!$hasAccess) {
        echo json_encode(['success' => false, 'message' => 'The selected record does not exist, or you do not have access to it.']);
        exit;
    }

    $personStmt = $pdo->prepare("SELECT preferredName, surname FROM gibbonPerson WHERE gibbonPersonID = :gibbonPersonID AND status = 'Full'");
    $personStmt->execute(['gibbonPersonID' => $targetPersonID]);
    $person = $personStmt->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        echo json_encode(['success' => false, 'message' => 'The specified record cannot be found.']);
        exit;
    }

    // Existing pending edit for this specific person+editor?
    $pendingStmt = $pdo->prepare("SELECT * FROM gibbonPersonMedicalUpdate WHERE gibbonPersonID = :gibbonPersonID AND gibbonPersonIDUpdater = :gibbonPersonIDUpdater AND status = 'Pending'");
    $pendingStmt->execute(['gibbonPersonID' => $targetPersonID, 'gibbonPersonIDUpdater' => $authUser['personID']]);
    $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    $liveMedicalStmt = $pdo->prepare("SELECT * FROM gibbonPersonMedical WHERE gibbonPersonID = :gibbonPersonID");
    $liveMedicalStmt->execute(['gibbonPersonID' => $targetPersonID]);
    $liveMedical = $liveMedicalStmt->fetch(PDO::FETCH_ASSOC);

    $gibbonPersonMedicalID = $liveMedical['gibbonPersonMedicalID'] ?? null;

    if ($pending) {
        $generalValues = [
            'longTermMedication' => $pending['longTermMedication'],
            'longTermMedicationDetails' => $pending['longTermMedicationDetails'],
            'comment' => $pending['comment'],
        ];
        $conditionsStmt = $pdo->prepare("SELECT * FROM gibbonPersonMedicalConditionUpdate WHERE gibbonPersonMedicalUpdateID = :gibbonPersonMedicalUpdateID ORDER BY gibbonPersonMedicalConditionUpdateID");
        $conditionsStmt->execute(['gibbonPersonMedicalUpdateID' => $pending['gibbonPersonMedicalUpdateID']]);
        $rawConditions = $conditionsStmt->fetchAll(PDO::FETCH_ASSOC);
        $conditions = array_map(fn($c) => [
            'gibbonPersonMedicalConditionID' => $c['gibbonPersonMedicalConditionID'],
            'gibbonPersonMedicalConditionUpdateID' => $c['gibbonPersonMedicalConditionUpdateID'],
            'name' => $c['name'],
            'gibbonAlertLevelID' => $c['gibbonAlertLevelID'],
            'triggers' => $c['triggers'],
            'reaction' => $c['reaction'],
            'response' => $c['response'],
            'medication' => $c['medication'],
            'lastEpisode' => $c['lastEpisode'],
            'lastEpisodeTreatment' => $c['lastEpisodeTreatment'],
            'comment' => $c['comment'],
        ], $rawConditions);
    } else {
        $generalValues = [
            'longTermMedication' => $liveMedical['longTermMedication'] ?? '',
            'longTermMedicationDetails' => $liveMedical['longTermMedicationDetails'] ?? '',
            'comment' => $liveMedical['comment'] ?? '',
        ];
        if (!empty($gibbonPersonMedicalID)) {
            $conditionsStmt = $pdo->prepare("SELECT * FROM gibbonPersonMedicalCondition WHERE gibbonPersonMedicalID = :gibbonPersonMedicalID ORDER BY gibbonPersonMedicalConditionID");
            $conditionsStmt->execute(['gibbonPersonMedicalID' => $gibbonPersonMedicalID]);
            $rawConditions = $conditionsStmt->fetchAll(PDO::FETCH_ASSOC);
            $conditions = array_map(fn($c) => [
                'gibbonPersonMedicalConditionID' => $c['gibbonPersonMedicalConditionID'],
                'gibbonPersonMedicalConditionUpdateID' => 0,
                'name' => $c['name'],
                'gibbonAlertLevelID' => $c['gibbonAlertLevelID'],
                'triggers' => $c['triggers'],
                'reaction' => $c['reaction'],
                'response' => $c['response'],
                'medication' => $c['medication'],
                'lastEpisode' => $c['lastEpisode'],
                'lastEpisodeTreatment' => $c['lastEpisodeTreatment'],
                'comment' => $c['comment'],
            ], $rawConditions);
        } else {
            $conditions = [];
        }
    }

    $alertLevels = $pdo->query("SELECT gibbonAlertLevelID, name FROM gibbonAlertLevel ORDER BY sequenceNumber DESC")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'personName' => trim(($person['preferredName'] ?? '') . ' ' . ($person['surname'] ?? '')),
        'hasPendingEdit' => $pending !== false,
        'gibbonPersonMedicalID' => $gibbonPersonMedicalID,
        'generalValues' => $generalValues,
        'conditions' => $conditions,
        'alertLevels' => $alertLevels,
    ]);
} catch (Exception $e) {
    error_log('School Connect data_medical.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load medical data.']);
}
