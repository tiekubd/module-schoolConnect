<?php
/**
 * School Connect API — Data Updater: Finance (load)
 *
 * Loads a Finance Invoicee's billing preferences. Mirrors core's
 * data_finance.php (source verified against GibbonEdu/core). Flat form,
 * same shape/complexity as Family — no repeating sub-records like
 * Medical.
 *
 * Access is children-only (via gibbonFamilyChild + childDataAccess='Y')
 * — no self or co-adult branch, matching core exactly. Keyed by
 * gibbonFinanceInvoiceeID, not gibbonPersonID.
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
    error_log('School Connect data_finance.php DB connection error: ' . $e->getMessage());
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
    // Access check — children only, matching core exactly. No self or
    // co-adult branch (a Parent isn't billed via their own
    // gibbonFinanceInvoicee record in this flow).
    $accessStmt = $pdo->prepare("
        SELECT gibbonPerson.preferredName, gibbonPerson.surname
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
    $person = $accessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        echo json_encode(['success' => false, 'message' => 'The selected record does not exist, or you do not have access to it.']);
        exit;
    }

    $pendingStmt = $pdo->prepare("SELECT * FROM gibbonFinanceInvoiceeUpdate WHERE gibbonFinanceInvoiceeID = :gibbonFinanceInvoiceeID AND gibbonPersonIDUpdater = :gibbonPersonIDUpdater AND status = 'Pending'");
    $pendingStmt->execute(['gibbonFinanceInvoiceeID' => $gibbonFinanceInvoiceeID, 'gibbonPersonIDUpdater' => $authUser['personID']]);
    $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    if ($pending) {
        $values = $pending;
    } else {
        $liveStmt = $pdo->prepare("SELECT * FROM gibbonFinanceInvoicee WHERE gibbonFinanceInvoiceeID = :gibbonFinanceInvoiceeID");
        $liveStmt->execute(['gibbonFinanceInvoiceeID' => $gibbonFinanceInvoiceeID]);
        $values = $liveStmt->fetch(PDO::FETCH_ASSOC);
    }

    $selectedFeeCategories = !empty($values['gibbonFinanceFeeCategoryIDList'])
        ? explode(',', $values['gibbonFinanceFeeCategoryIDList'])
        : [];

    // Fee categories — 'Other' (ID 0001) is a fixed option core always
    // adds alongside the live category list; category ID 1 is excluded
    // (matches core's `NOT gibbonFinanceFeeCategoryID=1`).
    $feeCategories = $pdo->query("SELECT gibbonFinanceFeeCategoryID, name FROM gibbonFinanceFeeCategory WHERE active = 'Y' AND gibbonFinanceFeeCategoryID != 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $feeCategories[] = ['gibbonFinanceFeeCategoryID' => '0001', 'name' => 'Other'];

    echo json_encode([
        'success' => true,
        'personName' => trim(($person['preferredName'] ?? '') . ' ' . ($person['surname'] ?? '')),
        'hasPendingEdit' => $pending !== false,
        'values' => [
            'invoiceTo' => $values['invoiceTo'] ?? 'Family',
            'companyName' => $values['companyName'] ?? '',
            'companyContact' => $values['companyContact'] ?? '',
            'companyAddress' => $values['companyAddress'] ?? '',
            'companyEmail' => $values['companyEmail'] ?? '',
            'companyCCFamily' => $values['companyCCFamily'] ?? 'N',
            'companyPhone' => $values['companyPhone'] ?? '',
            'companyAll' => $values['companyAll'] ?? 'Y',
        ],
        'selectedFeeCategories' => $selectedFeeCategories,
        'feeCategories' => $feeCategories,
    ]);
} catch (Exception $e) {
    error_log('School Connect data_finance.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load finance data.']);
}
