<?php
/**
 * School Connect API — Data Updater: Finance (submit)
 *
 * Writes a finance data update request to gibbonFinanceInvoiceeUpdate —
 * a PENDING APPROVAL table, not the live gibbonFinanceInvoicee row.
 * Mirrors core's data_financeProcess.php exactly (source verified
 * against GibbonEdu/core), including its "auto-accept" behavior (no
 * change = 'Complete' immediately) and its field-clearing rule: if
 * invoiceTo isn't 'Company', ALL company fields are reset to empty
 * regardless of what the app sent, rather than trusting the client to
 * have cleared them.
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
    error_log('School Connect data_financeProcess.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

if ($authUser['role'] !== 'Parent') {
    echo json_encode(['success' => false, 'message' => 'Only Parent accounts can update finance data.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$gibbonFinanceInvoiceeID = trim($input['gibbonFinanceInvoiceeID'] ?? '');

if (empty($gibbonFinanceInvoiceeID)) {
    echo json_encode(['success' => false, 'message' => 'gibbonFinanceInvoiceeID is required.']);
    exit;
}

try {
    // Same access check as the load endpoint.
    $accessStmt = $pdo->prepare("
        SELECT 1
        FROM gibbonFamilyAdult
        JOIN gibbonFamily ON (gibbonFamily.gibbonFamilyID = gibbonFamilyAdult.gibbonFamilyID)
        JOIN gibbonFamilyChild ON (gibbonFamilyChild.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
        JOIN gibbonPerson ON (gibbonPerson.gibbonPersonID = gibbonFamilyChild.gibbonPersonID)
        JOIN gibbonFinanceInvoicee ON (gibbonFinanceInvoicee.gibbonPersonID = gibbonPerson.gibbonPersonID)
        WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID
        AND gibbonFamilyAdult.childDataAccess = 'Y'
        AND gibbonPerson.status = 'Full'
        AND gibbonFinanceInvoicee.gibbonFinanceInvoiceeID = :gibbonFinanceInvoiceeID
    ");
    $accessStmt->execute(['gibbonPersonID' => $authUser['personID'], 'gibbonFinanceInvoiceeID' => $gibbonFinanceInvoiceeID]);
    $hasAccess = (bool) $accessStmt->fetchColumn();

    if (!$hasAccess) {
        echo json_encode(['success' => false, 'message' => 'The selected record does not exist, or you do not have access to it.']);
        exit;
    }

    $liveStmt = $pdo->prepare("SELECT * FROM gibbonFinanceInvoicee WHERE gibbonFinanceInvoiceeID = :gibbonFinanceInvoiceeID");
    $liveStmt->execute(['gibbonFinanceInvoiceeID' => $gibbonFinanceInvoiceeID]);
    $liveValues = $liveStmt->fetch(PDO::FETCH_ASSOC);

    if (!$liveValues) {
        echo json_encode(['success' => false, 'message' => 'The specified record cannot be found.']);
        exit;
    }

    $invoiceTo = (trim($input['invoiceTo'] ?? '') === 'Company') ? 'Company' : 'Family';

    if ($invoiceTo === 'Company') {
        $submitted = [
            'invoiceTo' => 'Company',
            'companyName' => trim($input['companyName'] ?? ''),
            'companyContact' => trim($input['companyContact'] ?? ''),
            'companyAddress' => trim($input['companyAddress'] ?? ''),
            'companyEmail' => trim($input['companyEmail'] ?? ''),
            'companyCCFamily' => (trim($input['companyCCFamily'] ?? 'N') === 'Y') ? 'Y' : 'N',
            'companyPhone' => trim($input['companyPhone'] ?? ''),
            'companyAll' => (trim($input['companyAll'] ?? 'Y') === 'N') ? 'N' : 'Y',
            'gibbonFinanceFeeCategoryIDList' => '',
        ];
        if ($submitted['companyAll'] === 'N') {
            $categories = $input['gibbonFinanceFeeCategoryIDList'] ?? [];
            $submitted['gibbonFinanceFeeCategoryIDList'] = is_array($categories) ? implode(',', $categories) : '';
        }
    } else {
        // Matches core exactly: anything other than 'Company' clears
        // every company field, regardless of what the app sent.
        $submitted = [
            'invoiceTo' => 'Family',
            'companyName' => '',
            'companyContact' => '',
            'companyAddress' => '',
            'companyEmail' => '',
            'companyCCFamily' => '',
            'companyPhone' => '',
            'companyAll' => '',
            'gibbonFinanceFeeCategoryIDList' => '',
        ];
    }

    // Required-field validation when billing a Company — matches core's
    // setRequired($required) on those 4 fields.
    if ($invoiceTo === 'Company') {
        foreach (['companyName', 'companyContact', 'companyAddress', 'companyEmail'] as $field) {
            if ($submitted[$field] === '') {
                echo json_encode(['success' => false, 'message' => ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $field)) . ' is required when billing a company.']);
                exit;
            }
        }
    }

    $dataChanged = false;
    foreach ($submitted as $key => $value) {
        $existingValue = $liveValues[$key] ?? '';
        if (empty($value) && empty($existingValue)) continue;
        if ((string) $value !== (string) $existingValue) {
            $dataChanged = true;
            break;
        }
    }

    $status = $dataChanged ? 'Pending' : 'Complete';

    $pendingStmt = $pdo->prepare("SELECT gibbonFinanceInvoiceeUpdateID FROM gibbonFinanceInvoiceeUpdate WHERE gibbonFinanceInvoiceeID = :gibbonFinanceInvoiceeID AND gibbonPersonIDUpdater = :gibbonPersonIDUpdater AND status = 'Pending'");
    $pendingStmt->execute(['gibbonFinanceInvoiceeID' => $gibbonFinanceInvoiceeID, 'gibbonPersonIDUpdater' => $authUser['personID']]);
    $existingID = $pendingStmt->fetchColumn();

    $now = date('Y-m-d H:i:s');
    $writeParams = $submitted;
    $writeParams['status'] = $status;
    $writeParams['gibbonSchoolYearID'] = $authUser['schoolYearID'];
    $writeParams['gibbonPersonIDUpdater'] = $authUser['personID'];
    $writeParams['timestamp'] = $now;

    if ($existingID) {
        $writeParams['gibbonFinanceInvoiceeUpdateID'] = $existingID;
        $pdo->prepare("
            UPDATE gibbonFinanceInvoiceeUpdate SET
                `status` = :status, gibbonSchoolYearID = :gibbonSchoolYearID, invoiceTo = :invoiceTo,
                companyName = :companyName, companyContact = :companyContact, companyAddress = :companyAddress,
                companyEmail = :companyEmail, companyCCFamily = :companyCCFamily, companyPhone = :companyPhone,
                companyAll = :companyAll, gibbonFinanceFeeCategoryIDList = :gibbonFinanceFeeCategoryIDList,
                gibbonPersonIDUpdater = :gibbonPersonIDUpdater, timestamp = :timestamp
            WHERE gibbonFinanceInvoiceeUpdateID = :gibbonFinanceInvoiceeUpdateID
        ")->execute($writeParams);
    } else {
        $writeParams['gibbonFinanceInvoiceeID'] = $gibbonFinanceInvoiceeID;
        $pdo->prepare("
            INSERT INTO gibbonFinanceInvoiceeUpdate
                (`status`, gibbonSchoolYearID, gibbonFinanceInvoiceeID, invoiceTo, companyName, companyContact,
                 companyAddress, companyEmail, companyCCFamily, companyPhone, companyAll,
                 gibbonFinanceFeeCategoryIDList, gibbonPersonIDUpdater, timestamp)
            VALUES
                (:status, :gibbonSchoolYearID, :gibbonFinanceInvoiceeID, :invoiceTo, :companyName, :companyContact,
                 :companyAddress, :companyEmail, :companyCCFamily, :companyPhone, :companyAll,
                 :gibbonFinanceFeeCategoryIDList, :gibbonPersonIDUpdater, :timestamp)
        ")->execute($writeParams);
    }

    // ── Admin notification (bell icon) — event name 'Finance Data Updates'.
    if ($dataChanged) {
        $dbaStmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'organisationDBA'");
        $dbaPersonID = $dbaStmt->fetchColumn();

        $recipientIDs = [];
        if (!empty($dbaPersonID)) {
            $recipientIDs[] = $dbaPersonID;
        }

        $eventStmt = $pdo->prepare("SELECT gibbonNotificationEventID FROM gibbonNotificationEvent WHERE moduleName = 'Data Updater' AND event = 'Finance Data Updates' AND active = 'Y'");
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
            $notificationText = 'A finance data update request has been submitted.';
            $actionLink = '/index.php?q=/modules/Data Updater/data_finance_manage.php';

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
    error_log('School Connect data_financeProcess.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not submit finance data update.']);
}
