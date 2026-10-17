<?php
/**
 * School Connect API — Data Updater: Staff (submit)
 *
 * Writes a staff data update request to gibbonStaffUpdate — a PENDING
 * APPROVAL table, not the live gibbonStaff row. Mirrors core's
 * data_staffProcess.php under the 'Update Staff Data_my' scope (source
 * verified against GibbonEdu/core) — self-only, re-verifies the caller
 * owns this staff record server-side rather than trusting the app.
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
    error_log('School Connect data_staffProcess.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

if ($authUser['category'] !== 'Staff') {
    echo json_encode(['success' => false, 'message' => 'Only Staff accounts can update staff data.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $staffStmt = $pdo->prepare("SELECT * FROM gibbonStaff WHERE gibbonPersonID = :gibbonPersonID");
    $staffStmt->execute(['gibbonPersonID' => $authUser['personID']]);
    $liveStaff = $staffStmt->fetch(PDO::FETCH_ASSOC);

    if (!$liveStaff) {
        echo json_encode(['success' => false, 'message' => 'The selected record does not exist, or you do not have access to it.']);
        exit;
    }

    $gibbonStaffID = $liveStaff['gibbonStaffID'];

    $type = trim($input['type'] ?? '');
    if (!in_array($type, ['Teaching', 'Support'], true)) {
        $type = $liveStaff['type']; // preserve rather than accept an invalid value
    }

    $submitted = [
        'initials' => trim($input['initials'] ?? ''),
        'type' => $type,
        'jobTitle' => trim($input['jobTitle'] ?? ''),
        'firstAidQualified' => (trim($input['firstAidQualified'] ?? '') === 'Y') ? 'Y' : 'N',
        'countryOfOrigin' => trim($input['countryOfOrigin'] ?? ''),
        'qualifications' => trim($input['qualifications'] ?? ''),
        'biography' => trim($input['biography'] ?? ''),
    ];
    // Matches core: only meaningful when firstAidQualified='Y'.
    if ($submitted['firstAidQualified'] === 'Y') {
        $submitted['firstAidQualification'] = trim($input['firstAidQualification'] ?? '');
        $submitted['firstAidExpiry'] = !empty($input['firstAidExpiry']) ? $input['firstAidExpiry'] : null;
    } else {
        $submitted['firstAidQualification'] = '';
        $submitted['firstAidExpiry'] = null;
    }

    $dataChanged = false;
    foreach ($submitted as $key => $value) {
        $existingValue = $liveStaff[$key] ?? '';
        if (empty($value) && empty($existingValue)) continue;
        if ((string) $value !== (string) $existingValue) {
            $dataChanged = true;
            break;
        }
    }

    $status = $dataChanged ? 'Pending' : 'Complete';

    $pendingStmt = $pdo->prepare("SELECT gibbonStaffUpdateID FROM gibbonStaffUpdate WHERE gibbonStaffID = :gibbonStaffID AND gibbonPersonIDUpdater = :gibbonPersonIDUpdater AND status = 'Pending'");
    $pendingStmt->execute(['gibbonStaffID' => $gibbonStaffID, 'gibbonPersonIDUpdater' => $authUser['personID']]);
    $existingID = $pendingStmt->fetchColumn();

    $now = date('Y-m-d H:i:s');
    $writeParams = $submitted;
    $writeParams['status'] = $status;
    $writeParams['gibbonSchoolYearID'] = $authUser['schoolYearID'];
    $writeParams['gibbonPersonIDUpdater'] = $authUser['personID'];
    $writeParams['timestamp'] = $now;
    // fields (Custom Fields) is NOT NULL with no default — leave empty,
    // this pass doesn't populate it.
    $writeParams['fields'] = '';

    if ($existingID) {
        $writeParams['gibbonStaffUpdateID'] = $existingID;
        $pdo->prepare("
            UPDATE gibbonStaffUpdate SET
                `status` = :status, gibbonSchoolYearID = :gibbonSchoolYearID, initials = :initials, `type` = :type,
                jobTitle = :jobTitle, firstAidQualified = :firstAidQualified, firstAidQualification = :firstAidQualification,
                firstAidExpiry = :firstAidExpiry, countryOfOrigin = :countryOfOrigin, qualifications = :qualifications,
                biography = :biography, fields = :fields, gibbonPersonIDUpdater = :gibbonPersonIDUpdater, timestamp = :timestamp
            WHERE gibbonStaffUpdateID = :gibbonStaffUpdateID
        ")->execute($writeParams);
    } else {
        $writeParams['gibbonStaffID'] = $gibbonStaffID;
        $pdo->prepare("
            INSERT INTO gibbonStaffUpdate
                (`status`, gibbonSchoolYearID, gibbonStaffID, initials, `type`, jobTitle, firstAidQualified,
                 firstAidQualification, firstAidExpiry, countryOfOrigin, qualifications, biography, fields,
                 gibbonPersonIDUpdater, timestamp)
            VALUES
                (:status, :gibbonSchoolYearID, :gibbonStaffID, :initials, :type, :jobTitle, :firstAidQualified,
                 :firstAidQualification, :firstAidExpiry, :countryOfOrigin, :qualifications, :biography, :fields,
                 :gibbonPersonIDUpdater, :timestamp)
        ")->execute($writeParams);
    }

    // ── Admin notification (bell icon) — event name 'Staff Data Updates'.
    if ($dataChanged) {
        $dbaStmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'organisationDBA'");
        $dbaPersonID = $dbaStmt->fetchColumn();

        $recipientIDs = [];
        if (!empty($dbaPersonID)) {
            $recipientIDs[] = $dbaPersonID;
        }

        $eventStmt = $pdo->prepare("SELECT gibbonNotificationEventID FROM gibbonNotificationEvent WHERE moduleName = 'Data Updater' AND event = 'Staff Data Updates' AND active = 'Y'");
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
            $notificationText = 'A staff data update request has been submitted.';
            $actionLink = '/index.php?q=/modules/Data Updater/data_staff_manage.php';

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
        'message' => $dataChanged
            ? "Your request was completed successfully. An administrator will process your request as soon as possible. You will not see the updated data in the system until it has been processed."
            : "Thanks — your data is unchanged, so this has been recorded as reviewed and confirmed correct.",
    ]);
} catch (Exception $e) {
    error_log('School Connect data_staffProcess.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not submit staff data update.']);
}
