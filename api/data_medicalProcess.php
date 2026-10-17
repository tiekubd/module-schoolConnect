<?php
/**
 * School Connect API — Data Updater: Medical (submit)
 *
 * Writes a medical data update request. Two-table write, matching core's
 * data_medicalProcess.php (source verified against GibbonEdu/core):
 * 1. gibbonPersonMedicalUpdate — general fields (one row).
 * 2. gibbonPersonMedicalConditionUpdate — one row per condition, linked
 *    via gibbonPersonMedicalUpdateID from step 1. Existing conditions are
 *    updated in place; a new condition (if submitted) is inserted.
 *
 * ATTACHMENT SAFETY: the app never sends or sees the `attachment` column
 * for existing conditions — this endpoint looks up the current value
 * directly (from the live condition, or from an existing pending update
 * row if resuming) and writes it back unchanged. This mirrors core's own
 * fallback behavior when its upload field is left empty (it preserves
 * the existing value rather than blanking it) — see file research notes.
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
    error_log('School Connect data_medicalProcess.php DB connection error: ' . $e->getMessage());
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
    // Same access check as the load endpoint.
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

    $liveMedicalStmt = $pdo->prepare("SELECT * FROM gibbonPersonMedical WHERE gibbonPersonID = :gibbonPersonID");
    $liveMedicalStmt->execute(['gibbonPersonID' => $targetPersonID]);
    $liveMedical = $liveMedicalStmt->fetch(PDO::FETCH_ASSOC);
    $gibbonPersonMedicalID = $liveMedical['gibbonPersonMedicalID'] ?? null;

    // ── General fields ──────────────────────────────────────────────────
    $generalSubmitted = [
        'longTermMedication' => trim($input['longTermMedication'] ?? 'N') ?: 'N',
        'longTermMedicationDetails' => trim($input['longTermMedicationDetails'] ?? ''),
        'comment' => trim($input['comment'] ?? ''),
    ];

    // dataChanged starts true if there's no live record at all yet
    // (matches core: $dataChanged = empty($values)).
    $dataChanged = empty($liveMedical);
    foreach ($generalSubmitted as $key => $value) {
        $existingValue = $liveMedical[$key] ?? '';
        if (empty($value) && empty($existingValue)) continue;
        if ((string) $value !== (string) $existingValue) {
            $dataChanged = true;
        }
    }

    $pendingStmt = $pdo->prepare("SELECT gibbonPersonMedicalUpdateID FROM gibbonPersonMedicalUpdate WHERE gibbonPersonID = :gibbonPersonID AND gibbonPersonIDUpdater = :gibbonPersonIDUpdater AND status = 'Pending'");
    $pendingStmt->execute(['gibbonPersonID' => $targetPersonID, 'gibbonPersonIDUpdater' => $authUser['personID']]);
    $existingUpdateID = $pendingStmt->fetchColumn();

    $now = date('Y-m-d H:i:s');
    $generalWrite = $generalSubmitted;
    $generalWrite['gibbonSchoolYearID'] = $authUser['schoolYearID'];
    $generalWrite['gibbonPersonMedicalID'] = $gibbonPersonMedicalID;
    $generalWrite['gibbonPersonID'] = $targetPersonID;
    $generalWrite['gibbonPersonIDUpdater'] = $authUser['personID'];
    $generalWrite['timestamp'] = $now;
    // fields (Custom Fields) is nullable — leave untouched, this pass
    // doesn't populate it.
    $generalWrite['fields'] = '';

    if ($existingUpdateID) {
        $generalWrite['gibbonPersonMedicalUpdateID'] = $existingUpdateID;
        $pdo->prepare("
            UPDATE gibbonPersonMedicalUpdate SET
                gibbonSchoolYearID = :gibbonSchoolYearID, gibbonPersonMedicalID = :gibbonPersonMedicalID,
                gibbonPersonID = :gibbonPersonID, longTermMedication = :longTermMedication,
                longTermMedicationDetails = :longTermMedicationDetails, comment = :comment, fields = :fields,
                gibbonPersonIDUpdater = :gibbonPersonIDUpdater, timestamp = :timestamp
            WHERE gibbonPersonMedicalUpdateID = :gibbonPersonMedicalUpdateID
        ")->execute($generalWrite);
        $gibbonPersonMedicalUpdateID = $existingUpdateID;
    } else {
        $pdo->prepare("
            INSERT INTO gibbonPersonMedicalUpdate
                (gibbonSchoolYearID, gibbonPersonMedicalID, gibbonPersonID, longTermMedication,
                 longTermMedicationDetails, comment, fields, gibbonPersonIDUpdater, timestamp)
            VALUES
                (:gibbonSchoolYearID, :gibbonPersonMedicalID, :gibbonPersonID, :longTermMedication,
                 :longTermMedicationDetails, :comment, :fields, :gibbonPersonIDUpdater, :timestamp)
        ")->execute($generalWrite);
        $gibbonPersonMedicalUpdateID = $pdo->lastInsertId();
    }

    // ── Existing conditions ──────────────────────────────────────────────
    $submittedConditions = $input['conditions'] ?? [];
    $partialFail = false;

    foreach ($submittedConditions as $cond) {
        $conditionID = trim((string) ($cond['gibbonPersonMedicalConditionID'] ?? ''));
        $conditionUpdateID = trim((string) ($cond['gibbonPersonMedicalConditionUpdateID'] ?? '0'));

        // Look up the current value (live condition, or the existing
        // pending update row if resuming) to compare against AND to
        // safely carry the attachment forward untouched.
        $currentCondition = null;
        if ($conditionUpdateID !== '0' && !empty($conditionUpdateID)) {
            $lookupStmt = $pdo->prepare("SELECT * FROM gibbonPersonMedicalConditionUpdate WHERE gibbonPersonMedicalConditionUpdateID = :id");
            $lookupStmt->execute(['id' => $conditionUpdateID]);
            $currentCondition = $lookupStmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$currentCondition && !empty($conditionID)) {
            $lookupStmt = $pdo->prepare("SELECT * FROM gibbonPersonMedicalCondition WHERE gibbonPersonMedicalConditionID = :id");
            $lookupStmt->execute(['id' => $conditionID]);
            $currentCondition = $lookupStmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$currentCondition) {
            $dataChanged = true; // matches core: missing condition counts as a change
            $currentCondition = [];
        }

        $condWrite = [
            'gibbonPersonMedicalID' => $gibbonPersonMedicalID,
            'gibbonPersonMedicalUpdateID' => $gibbonPersonMedicalUpdateID,
            'name' => trim((string) ($cond['name'] ?? '')),
            'gibbonAlertLevelID' => trim((string) ($cond['gibbonAlertLevelID'] ?? '')),
            'triggers' => trim((string) ($cond['triggers'] ?? '')),
            'reaction' => trim((string) ($cond['reaction'] ?? '')),
            'response' => trim((string) ($cond['response'] ?? '')),
            'medication' => trim((string) ($cond['medication'] ?? '')),
            'lastEpisode' => !empty($cond['lastEpisode']) ? $cond['lastEpisode'] : null,
            'lastEpisodeTreatment' => trim((string) ($cond['lastEpisodeTreatment'] ?? '')),
            'comment' => trim((string) ($cond['comment'] ?? '')),
            'attachment' => $currentCondition['attachment'] ?? null, // preserved untouched, see file header
            'gibbonPersonIDUpdater' => $authUser['personID'],
            'timestamp' => $now,
        ];

        foreach ($condWrite as $key => $value) {
            if (!array_key_exists($key, $currentCondition)) continue;
            $existingValue = $currentCondition[$key] ?? '';
            if (empty($value) && empty($existingValue)) continue;
            if ((string) $value !== (string) $existingValue) {
                $dataChanged = true;
            }
        }

        if ($conditionUpdateID !== '0' && !empty($conditionUpdateID)) {
            $condWrite['gibbonPersonMedicalConditionUpdateID'] = $conditionUpdateID;
            $pdo->prepare("
                UPDATE gibbonPersonMedicalConditionUpdate SET
                    gibbonPersonMedicalUpdateID = :gibbonPersonMedicalUpdateID, gibbonPersonMedicalID = :gibbonPersonMedicalID,
                    name = :name, gibbonAlertLevelID = :gibbonAlertLevelID, triggers = :triggers, reaction = :reaction,
                    response = :response, medication = :medication, lastEpisode = :lastEpisode,
                    lastEpisodeTreatment = :lastEpisodeTreatment, comment = :comment, attachment = :attachment,
                    gibbonPersonIDUpdater = :gibbonPersonIDUpdater, timestamp = :timestamp
                WHERE gibbonPersonMedicalConditionUpdateID = :gibbonPersonMedicalConditionUpdateID
            ")->execute($condWrite);
        } else {
            $condWrite['gibbonPersonMedicalConditionID'] = $conditionID ?: null;
            $pdo->prepare("
                INSERT INTO gibbonPersonMedicalConditionUpdate
                    (gibbonPersonMedicalUpdateID, gibbonPersonMedicalConditionID, gibbonPersonMedicalID, name,
                     gibbonAlertLevelID, triggers, reaction, response, medication, lastEpisode,
                     lastEpisodeTreatment, comment, attachment, gibbonPersonIDUpdater, timestamp)
                VALUES
                    (:gibbonPersonMedicalUpdateID, :gibbonPersonMedicalConditionID, :gibbonPersonMedicalID, :name,
                     :gibbonAlertLevelID, :triggers, :reaction, :response, :medication, :lastEpisode,
                     :lastEpisodeTreatment, :comment, :attachment, :gibbonPersonIDUpdater, :timestamp)
            ")->execute($condWrite);
        }
    }

    // ── New condition (optional) ─────────────────────────────────────────
    $newCondition = $input['newCondition'] ?? null;
    if (!empty($newCondition) && !empty(trim($newCondition['name'] ?? '')) && !empty(trim($newCondition['gibbonAlertLevelID'] ?? ''))) {
        $dataChanged = true;
        $newWrite = [
            'gibbonPersonMedicalUpdateID' => $gibbonPersonMedicalUpdateID,
            'gibbonPersonMedicalID' => $gibbonPersonMedicalID,
            'name' => trim($newCondition['name']),
            'gibbonAlertLevelID' => trim($newCondition['gibbonAlertLevelID']),
            'triggers' => trim($newCondition['triggers'] ?? ''),
            'reaction' => trim($newCondition['reaction'] ?? ''),
            'response' => trim($newCondition['response'] ?? ''),
            'medication' => trim($newCondition['medication'] ?? ''),
            'lastEpisode' => !empty($newCondition['lastEpisode']) ? $newCondition['lastEpisode'] : null,
            'lastEpisodeTreatment' => trim($newCondition['lastEpisodeTreatment'] ?? ''),
            'comment' => trim($newCondition['comment'] ?? ''),
            'attachment' => null,
            'gibbonPersonIDUpdater' => $authUser['personID'],
            'timestamp' => $now,
        ];
        $pdo->prepare("
            INSERT INTO gibbonPersonMedicalConditionUpdate
                (gibbonPersonMedicalUpdateID, gibbonPersonMedicalID, name, gibbonAlertLevelID, triggers, reaction,
                 response, medication, lastEpisode, lastEpisodeTreatment, comment, attachment,
                 gibbonPersonIDUpdater, timestamp)
            VALUES
                (:gibbonPersonMedicalUpdateID, :gibbonPersonMedicalID, :name, :gibbonAlertLevelID, :triggers, :reaction,
                 :response, :medication, :lastEpisode, :lastEpisodeTreatment, :comment, :attachment,
                 :gibbonPersonIDUpdater, :timestamp)
        ")->execute($newWrite);
    } elseif (!empty($newCondition) && (!empty(trim($newCondition['name'] ?? '')) || !empty(trim($newCondition['gibbonAlertLevelID'] ?? '')))) {
        // Partially filled new-condition fields — name and risk are both
        // required together, matching core's validation.
        $partialFail = true;
    }

    $status = $dataChanged ? 'Pending' : 'Complete';
    $pdo->prepare("UPDATE gibbonPersonMedicalUpdate SET `status` = :status WHERE gibbonPersonMedicalUpdateID = :id")
        ->execute(['status' => $status, 'id' => $gibbonPersonMedicalUpdateID]);

    // ── Admin notification (bell icon) — event name 'Medical Form
    // Updates' (not 'Medical Data Updates' — confirmed against core's
    // actual gibbonNotificationEvent row).
    if ($dataChanged) {
        $dbaStmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'organisationDBA'");
        $dbaPersonID = $dbaStmt->fetchColumn();

        $recipientIDs = [];
        if (!empty($dbaPersonID)) {
            $recipientIDs[] = $dbaPersonID;
        }

        $eventStmt = $pdo->prepare("SELECT gibbonNotificationEventID FROM gibbonNotificationEvent WHERE moduleName = 'Data Updater' AND event = 'Medical Form Updates' AND active = 'Y'");
        $eventStmt->execute();
        $eventID = $eventStmt->fetchColumn();

        if (!empty($eventID)) {
            $listenerStmt = $pdo->prepare("
                SELECT DISTINCT gibbonPerson.gibbonPersonID FROM gibbonNotificationListener
                JOIN gibbonPerson ON (gibbonNotificationListener.gibbonPersonID = gibbonPerson.gibbonPersonID)
                WHERE gibbonNotificationEventID = :gibbonNotificationEventID
                AND gibbonPerson.status = 'Full'
                AND (gibbonPerson.dateStart IS NULL OR gibbonPerson.dateStart <= :today)
                AND (gibbonPerson.dateEnd IS NULL OR gibbonPerson.dateEnd >= :today)
                AND scopeType = 'All'
            ");
            $listenerStmt->execute(['gibbonNotificationEventID' => $eventID, 'today' => date('Y-m-d')]);
            $recipientIDs = array_merge($recipientIDs, $listenerStmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $recipientIDs = array_unique($recipientIDs);

        if (!empty($recipientIDs)) {
            $notificationText = 'A medical data update request has been submitted.';
            $actionLink = '/index.php?q=/modules/Data Updater/data_medical_manage.php';

            $existingNotifStmt = $pdo->prepare("
                SELECT gibbonNotificationID, count FROM gibbonNotification
                WHERE gibbonPersonID = :gibbonPersonID AND text = :text AND actionLink = :actionLink
                AND gibbonModuleID = (SELECT gibbonModuleID FROM gibbonModule WHERE name = 'Data Updater')
                AND status = 'New'
            ");
            $updateNotifStmt = $pdo->prepare("UPDATE gibbonNotification SET count = :count, timestamp = NOW() WHERE gibbonNotificationID = :id");
            $insertNotifStmt = $pdo->prepare("
                INSERT INTO gibbonNotification (gibbonPersonID, gibbonModuleID, text, actionLink, timestamp)
                VALUES (:gibbonPersonID, (SELECT gibbonModuleID FROM gibbonModule WHERE name = 'Data Updater'), :text, :actionLink, NOW())
            ");

            foreach ($recipientIDs as $recipientID) {
                $existingNotifStmt->execute(['gibbonPersonID' => $recipientID, 'text' => $notificationText, 'actionLink' => $actionLink]);
                $existingNotif = $existingNotifStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingNotif) {
                    $updateNotifStmt->execute(['count' => $existingNotif['count'] + 1, 'id' => $existingNotif['gibbonNotificationID']]);
                } else {
                    $insertNotifStmt->execute(['gibbonPersonID' => $recipientID, 'text' => $notificationText, 'actionLink' => $actionLink]);
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'partialFail' => $partialFail,
        'message' => $partialFail
            ? "Your request was submitted, but the new condition wasn't saved — both a name and a risk level are required to add one."
            : ($dataChanged
                ? "Your request was completed successfully. An administrator will process your request as soon as possible. You will not see the updated data in the system until it has been processed."
                : "Thanks — your data is unchanged, so this has been recorded as reviewed and confirmed correct."),
    ]);
} catch (Exception $e) {
    error_log('School Connect data_medicalProcess.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not submit medical data update.']);
}
