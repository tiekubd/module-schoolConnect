<?php
/**
 * School Connect API — Finance: View Invoices
 *
 * Lists invoices for the authenticated Parent's children. Mirrors core's
 * invoices_view.php under the 'View Invoices_myChildren' scope + 
 * InvoiceGateway::selectInvoicesByPersonID() (source verified against
 * GibbonEdu/core).
 *
 * DELIBERATE UX DEVIATION: core requires picking ONE child first (a
 * dropdown, or auto-selected if there's only one) before showing that
 * child's invoices — matching this app's established pattern instead
 * (Reports, Data Updater), all children's invoices are combined into one
 * list, each row showing which student it's for.
 *
 * SCOPING DECISIONS:
 *   - Current school year only, matching this app's existing convention
 *     (no year-switcher, unlike the website's < 2025-2026 > navigator).
 *   - "Pay Now" hands off to the website's existing invoices_payOnline.php
 *     in the device's external browser (via a per-invoice `key` — the
 *     same token-based, login-free auth the email "Pay Now" link uses).
 *     This endpoint never touches PayPal/card details itself — see the
 *     eligibility-check comment below for the full reasoning.
 *   - "Print" (itemized fee breakdown + PDF/share) is deliberately
 *     deferred to its own follow-up — see conversation notes. This
 *     endpoint only returns what the list screen itself shows (core's
 *     own list doesn't include fee line items either — those only
 *     appear via the separate Print view).
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
    error_log('School Connect finance_invoices.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

if ($authUser['role'] !== 'Parent') {
    echo json_encode(['success' => false, 'message' => 'Only Parent accounts can view invoices.']);
    exit;
}

try {
    // Children via childDataAccess='Y' — same pattern used throughout
    // this app (Reports, Data Updater).
    $childrenStmt = $pdo->prepare("
        SELECT DISTINCT child.gibbonPersonID
        FROM gibbonFamilyAdult
        JOIN gibbonFamily ON (gibbonFamily.gibbonFamilyID = gibbonFamilyAdult.gibbonFamilyID)
        JOIN gibbonFamilyChild ON (gibbonFamilyChild.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
        JOIN gibbonPerson AS child ON (gibbonFamilyChild.gibbonPersonID = child.gibbonPersonID)
        WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID
        AND gibbonFamilyAdult.childDataAccess = 'Y' AND child.status = 'Full'
    ");
    $childrenStmt->execute(['gibbonPersonID' => $authUser['personID']]);
    $childrenIDs = $childrenStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($childrenIDs)) {
        echo json_encode(['success' => true, 'currency' => 'USD $', 'invoices' => []]);
        exit;
    }

    $placeholders = [];
    $params = ['gibbonSchoolYearID' => $authUser['schoolYearID'], 'gibbonSchoolYearID2' => $authUser['schoolYearID']];
    foreach ($childrenIDs as $i => $childID) {
        $key = "child$i";
        $placeholders[] = ":$key";
        $params[$key] = $childID;
    }
    $childList = implode(',', $placeholders);

    // Matches selectInvoicesByPersonID() exactly, widened from a single
    // gibbonPersonID to an IN-list across all children — see header
    // comment. NOT status='Pending' excludes draft/unissued invoices,
    // same as core.
    $sql = "
        SELECT
            gibbonFinanceInvoice.gibbonFinanceInvoiceID,
            gibbonPerson.gibbonPersonID,
            surname,
            preferredName,
            gibbonFinanceInvoice.invoiceTo,
            gibbonFinanceInvoice.status,
            gibbonFinanceInvoice.invoiceIssueDate,
            gibbonFinanceInvoice.invoiceDueDate,
            paidDate,
            paidAmount,
            billingScheduleType AS billingSchedule,
            gibbonFinanceBillingSchedule.name AS billingScheduleExtra,
            gibbonFinanceInvoice.notes,
            gibbonFinanceInvoice.key,
            gibbonFormGroup.name AS formGroup
        FROM gibbonFinanceInvoice
        JOIN gibbonFinanceInvoicee ON (gibbonFinanceInvoice.gibbonFinanceInvoiceeID = gibbonFinanceInvoicee.gibbonFinanceInvoiceeID)
        JOIN gibbonPerson ON (gibbonFinanceInvoicee.gibbonPersonID = gibbonPerson.gibbonPersonID)
        LEFT JOIN gibbonFinanceBillingSchedule ON (gibbonFinanceInvoice.gibbonFinanceBillingScheduleID = gibbonFinanceBillingSchedule.gibbonFinanceBillingScheduleID)
        LEFT JOIN gibbonStudentEnrolment ON (gibbonStudentEnrolment.gibbonPersonID = gibbonPerson.gibbonPersonID)
        LEFT JOIN gibbonFormGroup ON (gibbonStudentEnrolment.gibbonFormGroupID = gibbonFormGroup.gibbonFormGroupID)
        WHERE gibbonFinanceInvoice.gibbonSchoolYearID = :gibbonSchoolYearID
        AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearID2
        AND NOT gibbonFinanceInvoice.status = 'Pending'
        AND gibbonFinanceInvoicee.gibbonPersonID IN ($childList)
        ORDER BY invoiceIssueDate, surname, preferredName
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalFeeStmt = $pdo->prepare("SELECT SUM(fee) FROM gibbonFinanceInvoiceFee WHERE gibbonFinanceInvoiceID = :gibbonFinanceInvoiceID");

    // ── Pay Now eligibility — matches invoices_view.php's action-column
    // logic exactly (source verified against GibbonEdu/core): payments
    // must be enabled at both the System and Finance level, the invoice
    // must not already be settled/cancelled, its total must be within
    // any configured threshold, and it must actually have a key (older
    // invoices predating the payment feature may not).
    //
    // We never build/reimplement the PayPal flow ourselves — the app
    // only opens the SAME website URL the email "Pay Now" link uses
    // (invoices_payOnline.php), in the device's external browser. All
    // payment handling stays entirely on Gibbon's existing, already-
    // tested website flow; this app never sees card details or PayPal
    // tokens.
    $enablePayments = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'enablePayments'")->fetchColumn();
    $financeOnlinePaymentEnabled = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'Finance' AND name = 'financeOnlinePaymentEnabled'")->fetchColumn();
    $financeOnlinePaymentThreshold = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'Finance' AND name = 'financeOnlinePaymentThreshold'")->fetchColumn();
    $paymentsPossible = ($enablePayments === 'Y' && $financeOnlinePaymentEnabled === 'Y');

    // Derived from $_SERVER rather than assumed from a config.php global
    // (same pattern login.php already uses for its license check) — this
    // endpoint doesn't know config.php's exact variable names, but every
    // request already carries its own host.
    $schoolUrlForLinks = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

    $invoices = [];
    foreach ($rows as $row) {
        // Matches getInvoiceTotalFee(): SUM of fee line items. Parents
        // never see 'Pending' invoices (excluded above), so the
        // Pending-only fallback-to-live-fee branch in core's version
        // never applies here.
        $totalFeeStmt->execute(['gibbonFinanceInvoiceID' => $row['gibbonFinanceInvoiceID']]);
        $totalFee = $totalFeeStmt->fetchColumn();
        $totalFee = $totalFee !== false ? (float) $totalFee : null;

        // Matches core's status-display logic exactly (Issued-Overdue,
        // Paid-Late).
        $statusDisplay = $row['status'];
        if ($row['status'] === 'Issued' && $row['invoiceDueDate'] < date('Y-m-d')) {
            $statusDisplay = 'Issued - Overdue';
        } elseif ($row['status'] === 'Paid' && !empty($row['paidDate']) && $row['invoiceDueDate'] < $row['paidDate']) {
            $statusDisplay = 'Paid - Late';
        }

        // Same conditions as invoices_view.php's action column: not
        // already settled/cancelled/refunded, within any configured
        // threshold, a real fee amount, and an actual key present.
        $payNowUrl = null;
        if (
            $paymentsPossible
            && !in_array($row['status'], ['Paid', 'Cancelled', 'Refunded'], true)
            && (empty($financeOnlinePaymentThreshold) || (float) $financeOnlinePaymentThreshold >= $totalFee)
            && $totalFee > 0
            && !empty($row['key'])
        ) {
            $payNowUrl = rtrim($schoolUrlForLinks, '/') . '/index.php?q=/modules/Finance/invoices_payOnline.php'
                . '&gibbonFinanceInvoiceID=' . urlencode($row['gibbonFinanceInvoiceID'])
                . '&key=' . urlencode($row['key']);
        }

        $invoices[] = [
            'gibbonFinanceInvoiceID' => $row['gibbonFinanceInvoiceID'],
            'gibbonPersonID' => $row['gibbonPersonID'],
            'studentName' => trim(($row['preferredName'] ?? '') . ' ' . ($row['surname'] ?? '')),
            'formGroup' => $row['formGroup'],
            'invoiceTo' => $row['invoiceTo'],
            'status' => $row['status'],
            'statusDisplay' => $statusDisplay,
            'payNowUrl' => $payNowUrl,
            'billingSchedule' => !empty($row['billingScheduleExtra']) ? $row['billingScheduleExtra'] : $row['billingSchedule'],
            'total' => $totalFee,
            'paidAmount' => $row['paidAmount'] !== null ? (float) $row['paidAmount'] : null,
            'invoiceIssueDate' => $row['invoiceIssueDate'],
            'invoiceDueDate' => $row['invoiceDueDate'],
            'notes' => $row['notes'],
        ];
    }

    echo json_encode([
        'success' => true,
        'currency' => $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'currency'")->fetchColumn() ?: 'USD $',
        'invoices' => $invoices,
    ]);
} catch (Exception $e) {
    error_log('School Connect finance_invoices.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load invoices.']);
}
