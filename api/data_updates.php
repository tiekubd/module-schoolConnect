<?php
/**
 * School Connect API — Data Updater: My Data Updates
 *
 * Read-only landing list for the Data Updater feature. Returns the
 * authenticated person, their children (via childDataAccess='Y'), and
 * any co-adults sharing a family record, each with per-type status
 * (Personal/Family/Medical/Finance/Staff) — mirrors core's
 * data_updates.php + DataUpdaterGateway::selectUpdatableUsersByPerson() /
 * selectDataUpdatesByPerson() (source verified against GibbonEdu/core).
 * Generic for any role, matching core's actual behavior — not
 * Parent-specific. A Staff member with no family just sees themself.
 *
 * SCOPING DECISION: core determines which data types are visible per
 * school via the full gibbonAction/gibbonPermission system
 * (isActionAccessible()). Replicating that is out of scope for now — this
 * endpoint hardcodes all 5 types and always includes them, rather than
 * checking per-school/per-role whether each is actually enabled.
 *
 * EDITING IS OUT OF SCOPE for this endpoint/screen — approval and data
 * entry stay on the Gibbon web admin side for now. This is status display
 * only, same division of responsibility as Reports.
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
    error_log('School Connect data_updates.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

// No role gate — this mirrors core's actual behavior: My Data Updates is
// a generic self-service page for any role, not Parent-specific. A Staff
// member with no family just sees themself (Family/Finance N/A, Personal/
// Staff editable), matching the real website exactly.

try {
    // ── Settings ──────────────────────────────────────────────────────────
    $settingStmt = $pdo->query("SELECT name, value FROM gibbonSetting WHERE scope = 'Data Updater' AND name IN ('requiredUpdates', 'requiredUpdatesByType', 'cutoffDate')");
    $settingsRaw = $settingStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $requiredUpdates = ($settingsRaw['requiredUpdates'] ?? 'N') === 'Y';
    $requiredUpdatesByType = $requiredUpdates && !empty($settingsRaw['requiredUpdatesByType'])
        ? explode(',', $settingsRaw['requiredUpdatesByType'])
        : [];
    $cutoffDate = $requiredUpdates ? ($settingsRaw['cutoffDate'] ?? null) : null;

    // ── Updatable people: self, children (childDataAccess='Y'), co-adults ──
    // Mirrors selectUpdatableUsersByPerson() exactly, including the
    // UNION ALL structure and sequence ordering (self, then children,
    // then co-adults).
    $peopleStmt = $pdo->prepare("
        (SELECT gibbonPerson.surname, gibbonPerson.preferredName, gibbonPerson.image_240, gibbonPerson.gibbonPersonID, 0 as sequenceNumber
            FROM gibbonPerson
            WHERE gibbonPerson.gibbonPersonID = :gibbonPersonID AND gibbonPerson.status = 'Full')
        UNION ALL
        (SELECT child.surname, child.preferredName, child.image_240, child.gibbonPersonID, 1 as sequenceNumber
            FROM gibbonFamilyAdult
            JOIN gibbonFamily ON (gibbonFamilyAdult.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
            JOIN gibbonFamilyChild ON (gibbonFamilyChild.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
            JOIN gibbonPerson as child ON (gibbonFamilyChild.gibbonPersonID = child.gibbonPersonID)
            WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID
            AND gibbonFamilyAdult.childDataAccess = 'Y' AND child.status = 'Full')
        UNION ALL
        (SELECT adult.surname, adult.preferredName, adult.image_240, adult.gibbonPersonID, 2 as sequenceNumber
            FROM gibbonFamilyAdult
            JOIN gibbonFamily ON (gibbonFamilyAdult.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
            JOIN gibbonFamilyAdult as familyAdult ON (familyAdult.gibbonFamilyID = gibbonFamily.gibbonFamilyID AND familyAdult.gibbonPersonID <> :gibbonPersonID)
            JOIN gibbonPerson as adult ON (familyAdult.gibbonPersonID = adult.gibbonPersonID)
            WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID AND adult.status = 'Full')
        ORDER BY sequenceNumber, surname, preferredName
    ");
    $peopleStmt->execute(['gibbonPersonID' => $authUser['personID']]);
    $people = $peopleStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Per-person, per-type update status ──────────────────────────────────
    // Mirrors selectDataUpdatesByPerson(), restricted to the 4 Parent-
    // relevant types (Staff omitted — see the scoping note above).
    $updateStmt = $pdo->prepare("
        (SELECT 'Personal' as type, gibbonPerson.gibbonPersonID as id, IFNULL(gibbonPersonUpdate.timestamp, 0) as lastUpdated, '' as name
            FROM gibbonPerson
            LEFT JOIN gibbonPersonUpdate ON (gibbonPersonUpdate.gibbonPersonID = gibbonPerson.gibbonPersonID)
            WHERE gibbonPerson.gibbonPersonID = :gibbonPersonID ORDER BY gibbonPersonUpdate.timestamp DESC LIMIT 1)
        UNION ALL
        (SELECT 'Medical' as type, gibbonPerson.gibbonPersonID as id, IFNULL(gibbonPersonMedicalUpdate.timestamp, 0) as lastUpdated, '' as name
            FROM gibbonPerson
            JOIN gibbonRole ON (FIND_IN_SET(gibbonRole.gibbonRoleID, gibbonPerson.gibbonRoleIDAll))
            LEFT JOIN gibbonPersonMedicalUpdate ON (gibbonPersonMedicalUpdate.gibbonPersonID = gibbonPerson.gibbonPersonID)
            WHERE gibbonPerson.gibbonPersonID = :gibbonPersonID AND gibbonRole.category = 'Student'
            ORDER BY gibbonPersonMedicalUpdate.timestamp DESC LIMIT 1)
        UNION ALL
        (SELECT 'Finance' as type, gibbonFinanceInvoicee.gibbonFinanceInvoiceeID as id, IFNULL(gibbonFinanceInvoiceeUpdate.timestamp, 0) as lastUpdated, '' as name
            FROM gibbonPerson
            JOIN gibbonFinanceInvoicee ON (gibbonFinanceInvoicee.gibbonPersonID = gibbonPerson.gibbonPersonID)
            LEFT JOIN gibbonFinanceInvoiceeUpdate ON (gibbonFinanceInvoiceeUpdate.gibbonFinanceInvoiceeID = gibbonFinanceInvoicee.gibbonFinanceInvoiceeID)
            WHERE gibbonPerson.gibbonPersonID = :gibbonPersonID
            ORDER BY gibbonFinanceInvoiceeUpdate.timestamp DESC LIMIT 1)
        UNION ALL
        (SELECT 'Family' as type, gibbonFamilyAdult.gibbonFamilyID as id, IFNULL(gibbonFamilyUpdate.timestamp, 0) as lastUpdated, gibbonFamily.name
            FROM gibbonFamilyAdult
            JOIN gibbonFamily ON (gibbonFamily.gibbonFamilyID = gibbonFamilyAdult.gibbonFamilyID)
            LEFT JOIN gibbonFamilyUpdate ON (gibbonFamilyUpdate.gibbonFamilyID = gibbonFamilyAdult.gibbonFamilyID)
            WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID ORDER BY gibbonFamilyUpdate.timestamp DESC LIMIT 1)
        UNION ALL
        (SELECT 'Family' as type, gibbonFamilyChild.gibbonFamilyID as id, IFNULL(gibbonFamilyUpdate.timestamp, 0) as lastUpdated, gibbonFamily.name
            FROM gibbonFamilyChild
            JOIN gibbonFamily ON (gibbonFamily.gibbonFamilyID = gibbonFamilyChild.gibbonFamilyID)
            JOIN gibbonFamilyAdult ON (gibbonFamilyAdult.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
            LEFT JOIN gibbonFamilyUpdate ON (gibbonFamilyUpdate.gibbonFamilyID = gibbonFamilyChild.gibbonFamilyID)
            WHERE gibbonFamilyChild.gibbonPersonID = :gibbonPersonID AND gibbonFamilyAdult.gibbonPersonID = :gibbonPersonIDSource
            ORDER BY gibbonFamilyUpdate.timestamp DESC LIMIT 1)
        UNION ALL
        (SELECT 'Staff' as type, gibbonPerson.gibbonPersonID as id, IFNULL(gibbonStaffUpdate.timestamp, 0) as lastUpdated, '' as name
            FROM gibbonPerson
            JOIN gibbonRole ON (FIND_IN_SET(gibbonRole.gibbonRoleID, gibbonPerson.gibbonRoleIDAll))
            LEFT JOIN gibbonStaff ON (gibbonStaff.gibbonPersonID = gibbonPerson.gibbonPersonID)
            LEFT JOIN gibbonStaffUpdate ON (gibbonStaffUpdate.gibbonStaffID = gibbonStaff.gibbonStaffID)
            WHERE gibbonPerson.gibbonPersonID = :gibbonPersonID AND gibbonRole.category = 'Staff'
            ORDER BY gibbonStaffUpdate.timestamp DESC LIMIT 1)
    ");

    $updatesRequiredCount = 0;
    $result = [];

    foreach ($people as $person) {
        $updateStmt->execute([
            'gibbonPersonID' => $person['gibbonPersonID'],
            'gibbonPersonIDSource' => $authUser['personID'],
        ]);
        $rawUpdates = $updateStmt->fetchAll(PDO::FETCH_ASSOC);

        $updatesByType = [];
        foreach ($rawUpdates as $update) {
            $type = $update['type'];
            $lastUpdated = $update['lastUpdated'];

            if (!in_array($type, $requiredUpdatesByType) || empty($cutoffDate)) {
                // A record exists (this loop only runs over rows the query
                // actually returned) — required-tracking is just off for
                // this type. NOT the same as no-record-exists, which is
                // handled separately by $updatesByType simply having no
                // key for that type at all (see the empty-entries case on
                // the Flutter side).
                $status = 'available';
            } elseif (empty($lastUpdated) || $lastUpdated === '0' || $lastUpdated < $cutoffDate) {
                $status = 'required';
                $updatesRequiredCount++;
            } else {
                $status = 'uptodate';
            }

            $updatesByType[$type][] = [
                'id' => $update['id'],
                'name' => $update['name'],
                'lastUpdated' => (empty($lastUpdated) || $lastUpdated === '0') ? null : $lastUpdated,
                'status' => $status,
            ];
        }

        $result[] = [
            'gibbonPersonID' => $person['gibbonPersonID'],
            'preferredName' => $person['preferredName'],
            'surname' => $person['surname'],
            'image_240' => $person['image_240'],
            'updates' => $updatesByType,
        ];
    }

    echo json_encode([
        'success' => true,
        'requiredUpdatesEnabled' => $requiredUpdates,
        'updatesRequiredCount' => $updatesRequiredCount,
        'people' => $result,
    ]);
} catch (Exception $e) {
    error_log('School Connect data_updates.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load data updates.']);
}
