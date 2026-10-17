<?php
/**
 * School Connect API — Data Updater: Family (submit)
 *
 * Writes a family data update request to gibbonFamilyUpdate — a PENDING
 * APPROVAL table, not the live gibbonFamily row. Mirrors core's
 * data_familyProcess.php exactly (source verified against GibbonEdu/core),
 * including its "auto-accept" behavior: if nothing actually changed from
 * the current live data, status is set to 'Complete' immediately (counts
 * as "reviewed and confirmed correct") rather than 'Pending' (which
 * requires admin approval before it reaches gibbonFamily).
 *
 * If this same person already has a Pending row for this family, it's
 * UPDATEd in place rather than creating a second pending request — same
 * per-editor (not per-family) resume behavior as data_family.php's load.
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
    error_log('School Connect data_familyProcess.php DB connection error: ' . $e->getMessage());
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
    // Same access check as data_family.php's load — re-verified here
    // rather than trusting the app already checked it on load, since a
    // family link's childDataAccess could theoretically change between
    // load and submit.
    $accessStmt = $pdo->prepare("
        SELECT gibbonFamily.*
        FROM gibbonFamily
        JOIN gibbonFamilyAdult ON (gibbonFamilyAdult.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
        WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID
        AND gibbonFamilyAdult.childDataAccess = 'Y'
        AND gibbonFamily.gibbonFamilyID = :gibbonFamilyID
    ");
    $accessStmt->execute(['gibbonPersonID' => $authUser['personID'], 'gibbonFamilyID' => $gibbonFamilyID]);
    $currentValues = $accessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentValues) {
        echo json_encode(['success' => false, 'message' => 'The selected record does not exist, or you do not have access to it.']);
        exit;
    }

    $submitted = [
        'nameAddress' => trim($input['nameAddress'] ?? ''),
        'homeAddress' => trim($input['homeAddress'] ?? ''),
        'homeAddressDistrict' => trim($input['homeAddressDistrict'] ?? ''),
        'homeAddressCountry' => trim($input['homeAddressCountry'] ?? ''),
        'languageHomePrimary' => trim($input['languageHomePrimary'] ?? ''),
        'languageHomeSecondary' => trim($input['languageHomeSecondary'] ?? ''),
    ];

    // Same comparison core uses: empty-vs-empty never counts as a change,
    // so switching between '' and null-ish values doesn't force a Pending
    // status for no real reason.
    $dataChanged = false;
    foreach ($submitted as $key => $value) {
        $existingValue = $currentValues[$key] ?? '';
        if (empty($value) && empty($existingValue)) continue;
        if ($value != $existingValue) {
            $dataChanged = true;
            break;
        }
    }

    $status = $dataChanged ? 'Pending' : 'Complete';

    // Resume-or-create: same per-editor Pending lookup as the load endpoint.
    $pendingStmt = $pdo->prepare("
        SELECT gibbonFamilyUpdateID FROM gibbonFamilyUpdate
        WHERE gibbonFamilyID = :gibbonFamilyID AND gibbonPersonIDUpdater = :gibbonPersonIDUpdater AND status = 'Pending'
    ");
    $pendingStmt->execute(['gibbonFamilyID' => $gibbonFamilyID, 'gibbonPersonIDUpdater' => $authUser['personID']]);
    $existingID = $pendingStmt->fetchColumn();

    $now = date('Y-m-d H:i:s');

    if ($existingID) {
        $updateStmt = $pdo->prepare("
            UPDATE gibbonFamilyUpdate
            SET `status` = :status, gibbonSchoolYearID = :gibbonSchoolYearID,
                nameAddress = :nameAddress, homeAddress = :homeAddress,
                homeAddressDistrict = :homeAddressDistrict, homeAddressCountry = :homeAddressCountry,
                languageHomePrimary = :languageHomePrimary, languageHomeSecondary = :languageHomeSecondary,
                gibbonPersonIDUpdater = :gibbonPersonIDUpdater, timestamp = :timestamp
            WHERE gibbonFamilyUpdateID = :gibbonFamilyUpdateID
        ");
        $updateStmt->execute([
            'status' => $status,
            'gibbonSchoolYearID' => $authUser['schoolYearID'],
            'nameAddress' => $submitted['nameAddress'],
            'homeAddress' => $submitted['homeAddress'],
            'homeAddressDistrict' => $submitted['homeAddressDistrict'],
            'homeAddressCountry' => $submitted['homeAddressCountry'],
            'languageHomePrimary' => $submitted['languageHomePrimary'],
            'languageHomeSecondary' => $submitted['languageHomeSecondary'],
            'gibbonPersonIDUpdater' => $authUser['personID'],
            'timestamp' => $now,
            'gibbonFamilyUpdateID' => $existingID,
        ]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO gibbonFamilyUpdate
                (`status`, gibbonSchoolYearID, gibbonFamilyID, nameAddress, homeAddress,
                 homeAddressDistrict, homeAddressCountry, languageHomePrimary, languageHomeSecondary,
                 gibbonPersonIDUpdater, timestamp)
            VALUES
                (:status, :gibbonSchoolYearID, :gibbonFamilyID, :nameAddress, :homeAddress,
                 :homeAddressDistrict, :homeAddressCountry, :languageHomePrimary, :languageHomeSecondary,
                 :gibbonPersonIDUpdater, :timestamp)
        ");
        $insertStmt->execute([
            'status' => $status,
            'gibbonSchoolYearID' => $authUser['schoolYearID'],
            'gibbonFamilyID' => $gibbonFamilyID,
            'nameAddress' => $submitted['nameAddress'],
            'homeAddress' => $submitted['homeAddress'],
            'homeAddressDistrict' => $submitted['homeAddressDistrict'],
            'homeAddressCountry' => $submitted['homeAddressCountry'],
            'languageHomePrimary' => $submitted['languageHomePrimary'],
            'languageHomeSecondary' => $submitted['languageHomeSecondary'],
            'gibbonPersonIDUpdater' => $authUser['personID'],
            'timestamp' => $now,
        ]);
    }

    // ── Admin notification (bell icon) ──────────────────────────────────
    // Matches core: only fires when data actually changed (an
    // auto-accepted no-change submission doesn't need admin attention).
    // Recipients are (1) whoever the school has configured as their
    // organisationDBA, and (2) any admin who's specifically subscribed to
    // the 'Family Data Updates' notification event via
    // gibbonNotificationListener — same two recipient sources core uses
    // for this event (source verified against GibbonEdu/core).
    //
    // DELIBERATELY NOT REPLICATED: email delivery (per-recipient
    // receiveNotificationEmails preference + Gibbon's mailer/SMTP/twig
    // template setup) — a genuinely separate, bigger piece. This covers
    // the in-app bell icon only.
    if ($dataChanged) {
        $dbaStmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'organisationDBA'");
        $dbaPersonID = $dbaStmt->fetchColumn();

        $recipientIDs = [];
        if (!empty($dbaPersonID)) {
            $recipientIDs[] = $dbaPersonID;
        }

        // Additional listeners who've subscribed to this specific event.
        // This event's scopes default to 'All' (core doesn't pass any
        // scope filter for this particular NotificationEvent call), so
        // only scopeType='All' listeners apply here — no per-class/
        // per-year scoped subscriptions to resolve for this event type.
        $eventStmt = $pdo->prepare("SELECT gibbonNotificationEventID FROM gibbonNotificationEvent WHERE moduleName = 'Data Updater' AND event = 'Family Data Updates' AND active = 'Y'");
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
            $listenerIDs = $listenerStmt->fetchAll(PDO::FETCH_COLUMN);
            $recipientIDs = array_merge($recipientIDs, $listenerIDs);
        }

        $recipientIDs = array_unique($recipientIDs);

        if (!empty($recipientIDs)) {
            $notificationText = 'A family data update request has been submitted.';
            $actionLink = '/index.php?q=/modules/Data Updater/data_family_manage.php';

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
                $existingNotifStmt->execute([
                    'gibbonPersonID' => $recipientID,
                    'text' => $notificationText,
                    'actionLink' => $actionLink,
                ]);
                $existingNotif = $existingNotifStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingNotif) {
                    $updateNotifStmt->execute(['count' => $existingNotif['count'] + 1, 'id' => $existingNotif['gibbonNotificationID']]);
                } else {
                    $insertNotifStmt->execute([
                        'gibbonPersonID' => $recipientID,
                        'text' => $notificationText,
                        'actionLink' => $actionLink,
                    ]);
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'message' => $dataChanged
            ? "Your request was completed successfully. An administrator will process your request as soon as possible. You will not see the updated data in the system until it has been processed."
            : "Thanks — your data is unchanged, so this has been recorded as reviewed and confirmed correct.",
    ]);
} catch (Exception $e) {
    error_log('School Connect data_familyProcess.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not submit family data update.']);
}
