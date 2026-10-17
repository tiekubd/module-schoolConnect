<?php
/**
 * School Connect API — Data Updater: Personal (submit)
 *
 * Writes a personal data update request to gibbonPersonUpdate — a
 * PENDING APPROVAL table, not the live gibbonPerson row. Mirrors core's
 * data_personalProcess.php (source verified against GibbonEdu/core) for
 * the 34 core fields — see data_personal.php's header comment for the
 * full list of what's deliberately not replicated in this pass (Personal
 * Documents, Custom Fields, matching-address sync, multi-role-category
 * field visibility merge).
 *
 * Re-validates field visibility server-side (not just trusting the app
 * only sent visible fields) — a 'hidden' field's submitted value is
 * silently discarded rather than written, since the whole point of the
 * visibility setting is that a school doesn't want that field touched
 * via this flow at all.
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
    error_log('School Connect data_personalProcess.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

// No role gate — see data_personal.php's load endpoint for the full
// explanation. Access is scoped by the self-or-family-member check below.

$input = json_decode(file_get_contents('php://input'), true) ?? [];
// Empty gibbonPersonID = self-view, matching the load endpoint.
$targetPersonID = trim($input['gibbonPersonID'] ?? '') ?: $authUser['personID'];

const PERSONAL_FIELDS = [
    'title', 'surname', 'firstName', 'preferredName', 'officialName', 'nameInCharacters', 'dob',
    'email', 'emailAlternate',
    'address1', 'address1District', 'address1Country',
    'address2', 'address2District', 'address2Country',
    'phone1Type', 'phone1CountryCode', 'phone1',
    'phone2Type', 'phone2CountryCode', 'phone2',
    'phone3Type', 'phone3CountryCode', 'phone3',
    'phone4Type', 'phone4CountryCode', 'phone4',
    'languageFirst', 'languageSecond', 'languageThird',
    'countryOfBirth', 'ethnicity', 'religion',
    'emergency1Name', 'emergency1Number1', 'emergency1Number2', 'emergency1Relationship',
    'emergency2Name', 'emergency2Number1', 'emergency2Number2', 'emergency2Relationship',
    'profession', 'employer', 'jobTitle', 'vehicleRegistration',
];

try {
    // Same access check as data_personal.php's load.
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

    $personStmt = $pdo->prepare("SELECT gibbonRole.category FROM gibbonPerson LEFT JOIN gibbonRole ON (gibbonRole.gibbonRoleID = gibbonPerson.gibbonRoleIDPrimary) WHERE gibbonPerson.gibbonPersonID = :gibbonPersonID");
    $personStmt->execute(['gibbonPersonID' => $targetPersonID]);
    $roleCategory = $personStmt->fetchColumn() ?: 'Other';

    // Re-resolve field visibility server-side — see file header.
    $requiredFieldsSetting = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'User Admin' AND name = 'personalDataUpdaterRequiredFields'")->fetchColumn();
    $requiredFieldsByCategory = [];
    if (!empty($requiredFieldsSetting)) {
        $unserialized = @unserialize($requiredFieldsSetting, ['allowed_classes' => false]);
        if (is_array($unserialized)) {
            $requiredFieldsByCategory = $unserialized;
        }
    }
    $requiredFieldsForRole = $requiredFieldsByCategory[$roleCategory] ?? [];

    $fieldStates = [];
    foreach (PERSONAL_FIELDS as $field) {
        $setting = $requiredFieldsForRole[$field] ?? '';
        $fieldStates[$field] = in_array($setting, ['required', 'readonly', 'hidden'], true) ? $setting : 'shown';
    }

    // Current live values, used both for comparison and as the fallback
    // for any field this submission didn't touch (hidden/readonly fields
    // never came from the app in the first place).
    $liveStmt = $pdo->prepare("SELECT * FROM gibbonPerson WHERE gibbonPersonID = :gibbonPersonID");
    $liveStmt->execute(['gibbonPersonID' => $targetPersonID]);
    $liveValues = $liveStmt->fetch(PDO::FETCH_ASSOC);

    if (!$liveValues) {
        echo json_encode(['success' => false, 'message' => 'The specified record cannot be found.']);
        exit;
    }

    $submitted = [];
    foreach (PERSONAL_FIELDS as $field) {
        // Hidden or readonly fields: always keep the live value, ignoring
        // whatever the app sent (readonly = shown but not editable;
        // hidden = a school explicitly doesn't want this field touched
        // via this flow at all).
        if (in_array($fieldStates[$field], ['hidden', 'readonly'], true)) {
            $submitted[$field] = $liveValues[$field] ?? '';
            continue;
        }
        $submitted[$field] = trim((string) ($input[$field] ?? $liveValues[$field] ?? ''));
    }

    // Required-field validation (server-side — the app should already
    // enforce this, but don't trust it alone).
    foreach (PERSONAL_FIELDS as $field) {
        if ($fieldStates[$field] === 'required' && $submitted[$field] === '') {
            echo json_encode(['success' => false, 'message' => ucfirst($field) . ' is required.']);
            exit;
        }
    }

    // dob needs NULL, not an empty string, for the date column.
    $dob = !empty($submitted['dob']) ? $submitted['dob'] : null;

    // Email sanitization, matching core's FILTER_SANITIZE_EMAIL.
    $submitted['email'] = filter_var($submitted['email'], FILTER_SANITIZE_EMAIL);
    $submitted['emailAlternate'] = filter_var($submitted['emailAlternate'], FILTER_SANITIZE_EMAIL);

    // Phone sanitization — strip everything except digits and '+', and
    // default an entered-but-untyped number to 'Other' (matches core).
    for ($i = 1; $i <= 4; $i++) {
        $submitted["phone{$i}"] = preg_replace('/[^0-9+]/', '', $submitted["phone{$i}"]);
        if (!empty($submitted["phone{$i}"]) && empty($submitted["phone{$i}Type"])) {
            $submitted["phone{$i}Type"] = 'Other';
        }
    }

    // Privacy Options — gated by its own visibility flag, stored as CSV.
    $privacyEnabled = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'User Admin' AND name = 'privacyOptionVisibility'")->fetchColumn() === 'Y';
    if ($privacyEnabled) {
        $submittedPrivacy = $input['privacyOptions'] ?? [];
        $privacy = is_array($submittedPrivacy) ? implode(',', array_map('trim', $submittedPrivacy)) : '';
    } else {
        $privacy = $liveValues['privacy'] ?? '';
    }

    // COMPARE VALUES: has anything actually changed? Matches core's
    // empty-vs-empty-never-counts logic.
    $dataChanged = false;
    foreach ($submitted as $key => $value) {
        $existingValue = $liveValues[$key] ?? '';
        if (empty($value) && empty($existingValue)) continue;
        if ((string) $value !== (string) $existingValue) {
            $dataChanged = true;
            break;
        }
    }
    if (!$dataChanged && (string) $privacy !== (string) ($liveValues['privacy'] ?? '')) {
        $dataChanged = true;
    }

    $status = $dataChanged ? 'Pending' : 'Complete';

    $pendingStmt = $pdo->prepare("SELECT gibbonPersonUpdateID FROM gibbonPersonUpdate WHERE gibbonPersonID = :gibbonPersonID AND gibbonPersonIDUpdater = :gibbonPersonIDUpdater AND status = 'Pending'");
    $pendingStmt->execute(['gibbonPersonID' => $targetPersonID, 'gibbonPersonIDUpdater' => $authUser['personID']]);
    $existingID = $pendingStmt->fetchColumn();

    $now = date('Y-m-d H:i:s');
    $writeParams = $submitted;
    $writeParams['dob'] = $dob;
    $writeParams['status'] = $status;
    $writeParams['gibbonSchoolYearID'] = $authUser['schoolYearID'];
    $writeParams['gibbonPersonID'] = $targetPersonID;
    $writeParams['gibbonPersonIDUpdater'] = $authUser['personID'];
    $writeParams['timestamp'] = $now;
    $writeParams['privacy'] = $privacy;
    // fields (Custom Fields) column is NOT NULL with no default — write
    // an empty string since this pass doesn't populate it.
    $writeParams['fields'] = '';

    $columns = array_keys($writeParams);
    $setClause = implode(', ', array_map(fn($c) => "`$c` = :$c", $columns));

    if ($existingID) {
        $writeParams['gibbonPersonUpdateID'] = $existingID;
        $pdo->prepare("UPDATE gibbonPersonUpdate SET $setClause WHERE gibbonPersonUpdateID = :gibbonPersonUpdateID")->execute($writeParams);
    } else {
        $pdo->prepare("INSERT INTO gibbonPersonUpdate SET $setClause")->execute($writeParams);
    }

    // ── Admin notification (bell icon) — same pattern as Family's, just
    // a different event name/recipient list for 'Personal Data Updates'.
    if ($dataChanged) {
        $dbaStmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'organisationDBA'");
        $dbaPersonID = $dbaStmt->fetchColumn();

        $recipientIDs = [];
        if (!empty($dbaPersonID)) {
            $recipientIDs[] = $dbaPersonID;
        }

        $eventStmt = $pdo->prepare("SELECT gibbonNotificationEventID FROM gibbonNotificationEvent WHERE moduleName = 'Data Updater' AND event = 'Personal Data Updates' AND active = 'Y'");
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
            $notificationText = 'A personal data update request has been submitted.';
            $actionLink = '/index.php?q=/modules/Data Updater/data_personal_manage.php';

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
    error_log('School Connect data_personalProcess.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not submit personal data update.']);
}
