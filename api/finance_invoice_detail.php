<?php
/**
 * School Connect API — Finance: Invoice Detail
 *
 * Returns the itemized detail for a single invoice — header info (student,
 * form group, invoice-to, status, schedule, dates, notes) plus fee line
 * items and total. Mirrors core's invoiceContents() (source verified
 * against GibbonEdu/core, modules/Finance/moduleFunctions.php) as clean
 * JSON, rather than that function's hand-assembled HTML string — this
 * app renders its own native detail screen instead of replicating the
 * website's exact print layout.
 *
 * Also includes actual payment/transaction records (gibbonPayment) when
 * the invoice has been paid — the real gateway/transaction ID behind the
 * Paid status already shown on the list screen.
 *
 * SCOPING DECISION: no "Print" handoff to the website here — that page
 * (invoices_view_print.php) requires a real logged-in website session
 * (isActionAccessible(), not a public key like Pay Now's flow), which
 * most app-only parents won't have. Instead, the app screen offers a
 * Share action that sends a plain-text summary — no login required, no
 * dead end.
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
    error_log('School Connect finance_invoice_detail.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

if ($authUser['role'] !== 'Parent') {
    echo json_encode(['success' => false, 'message' => 'Only Parent accounts can view invoices.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$gibbonFinanceInvoiceID = trim($input['gibbonFinanceInvoiceID'] ?? '');

if (empty($gibbonFinanceInvoiceID)) {
    echo json_encode(['success' => false, 'message' => 'gibbonFinanceInvoiceID is required.']);
    exit;
}

try {
    // Access check: this invoice must belong to one of the parent's
    // children (childDataAccess='Y') — same pattern used throughout this
    // app, applied here via a direct join rather than a pre-fetched list
    // since we only need to check one specific invoice.
    $stmt = $pdo->prepare("
        SELECT
            gibbonFinanceInvoice.*,
            gibbonPerson.gibbonPersonID, gibbonPerson.studentID, gibbonPerson.surname, gibbonPerson.preferredName,
            gibbonFormGroup.name AS formGroup,
            gibbonFinanceBillingSchedule.name AS billingScheduleExtra,
            gibbonFinanceInvoicee.companyName, gibbonFinanceInvoicee.companyContact, gibbonFinanceInvoicee.companyAddress, gibbonFinanceInvoicee.companyEmail
        FROM gibbonFinanceInvoice
        JOIN gibbonFinanceInvoicee ON (gibbonFinanceInvoice.gibbonFinanceInvoiceeID = gibbonFinanceInvoicee.gibbonFinanceInvoiceeID)
        JOIN gibbonPerson ON (gibbonFinanceInvoicee.gibbonPersonID = gibbonPerson.gibbonPersonID)
        JOIN gibbonFamilyChild ON (gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID)
        JOIN gibbonFamily ON (gibbonFamily.gibbonFamilyID = gibbonFamilyChild.gibbonFamilyID)
        JOIN gibbonFamilyAdult ON (gibbonFamilyAdult.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
        LEFT JOIN gibbonFinanceBillingSchedule ON (gibbonFinanceInvoice.gibbonFinanceBillingScheduleID = gibbonFinanceBillingSchedule.gibbonFinanceBillingScheduleID)
        LEFT JOIN gibbonStudentEnrolment ON (gibbonStudentEnrolment.gibbonPersonID = gibbonPerson.gibbonPersonID AND gibbonStudentEnrolment.gibbonSchoolYearID = gibbonFinanceInvoice.gibbonSchoolYearID)
        LEFT JOIN gibbonFormGroup ON (gibbonStudentEnrolment.gibbonFormGroupID = gibbonFormGroup.gibbonFormGroupID)
        WHERE gibbonFinanceInvoice.gibbonFinanceInvoiceID = :gibbonFinanceInvoiceID
        AND gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID
        AND gibbonFamilyAdult.childDataAccess = 'Y'
        AND NOT gibbonFinanceInvoice.status = 'Pending'
        LIMIT 1
    ");
    $stmt->execute(['gibbonFinanceInvoiceID' => $gibbonFinanceInvoiceID, 'gibbonPersonID' => $authUser['personID']]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        echo json_encode(['success' => false, 'message' => 'The selected record does not exist, or you do not have access to it.']);
        exit;
    }

    // "Invoice To" / "Receipt To" family contact list — shared structure
    // between both document types (source verified against
    // GibbonEdu/core: invoiceContents() and receiptContents() use the
    // identical query and logic here, just a different section label).
    // Only relevant when billed to Family, not Company.
    $familyContacts = [];
    if ($invoice['invoiceTo'] !== 'Company') {
        $familyStmt = $pdo->prepare("
            SELECT parent.title, parent.surname, parent.preferredName, parent.email,
                   parent.address1, parent.address1District, parent.address1Country,
                   gibbonFamily.homeAddress, gibbonFamily.homeAddressDistrict, gibbonFamily.homeAddressCountry
            FROM gibbonFinanceInvoicee
            JOIN gibbonPerson AS student ON (gibbonFinanceInvoicee.gibbonPersonID = student.gibbonPersonID)
            JOIN gibbonFamilyChild ON (gibbonFamilyChild.gibbonPersonID = student.gibbonPersonID)
            JOIN gibbonFamily ON (gibbonFamilyChild.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
            JOIN gibbonFamilyAdult ON (gibbonFamily.gibbonFamilyID = gibbonFamilyAdult.gibbonFamilyID)
            JOIN gibbonPerson AS parent ON (gibbonFamilyAdult.gibbonPersonID = parent.gibbonPersonID)
            WHERE gibbonFinanceInvoicee.gibbonFinanceInvoiceeID = :gibbonFinanceInvoiceeID
            AND (gibbonFamilyAdult.contactPriority = 1 OR (gibbonFamilyAdult.contactPriority = 2 AND gibbonFamilyAdult.contactEmail = 'Y'))
            AND parent.status = 'Full'
            ORDER BY gibbonFamilyAdult.contactPriority, parent.surname, parent.preferredName
        ");
        $familyStmt->execute(['gibbonFinanceInvoiceeID' => $invoice['gibbonFinanceInvoiceeID']]);
        $familyRows = $familyStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($familyRows as $row) {
            // Same fallback as core: prefer address1 if set, else home address.
            if (!empty($row['address1'])) {
                $address = trim(implode(', ', array_filter([$row['address1'], $row['address1District'], $row['address1Country']])));
            } else {
                $address = trim(implode(', ', array_filter([$row['homeAddress'], $row['homeAddressDistrict'], $row['homeAddressCountry']])));
            }
            $familyContacts[] = [
                'name' => trim(($row['title'] ?? '') . ' ' . ($row['preferredName'] ?? '') . ' ' . ($row['surname'] ?? '')),
                'email' => $row['email'],
                'address' => $address,
            ];
        }
    }

    // Fee line items — matches invoiceContents() exactly: both 'Standard'
    // and 'Ad Hoc' feeType rows, ordered by sequenceNumber. Discounts
    // appear as negative-fee rows within these same two types, not a
    // separate type.
    $feesStmt = $pdo->prepare("
        SELECT gibbonFinanceInvoiceFee.name, gibbonFinanceFeeCategory.name AS category,
               gibbonFinanceInvoiceFee.description, gibbonFinanceInvoiceFee.fee
        FROM gibbonFinanceInvoiceFee
        JOIN gibbonFinanceFeeCategory ON (gibbonFinanceInvoiceFee.gibbonFinanceFeeCategoryID = gibbonFinanceFeeCategory.gibbonFinanceFeeCategoryID)
        WHERE gibbonFinanceInvoiceFee.gibbonFinanceInvoiceID = :gibbonFinanceInvoiceID
        AND gibbonFinanceInvoiceFee.feeType IN ('Standard', 'Ad Hoc')
        ORDER BY gibbonFinanceInvoiceFee.sequenceNumber
    ");
    $feesStmt->execute(['gibbonFinanceInvoiceID' => $gibbonFinanceInvoiceID]);
    $fees = $feesStmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0;
    foreach ($fees as $fee) {
        $total += (float) $fee['fee'];
    }

    // Invoice number — matches core's exact 3-way format setting, with
    // leading zeros stripped the same way.
    $invoiceNumberSetting = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'Finance' AND name = 'invoiceNumber'")->fetchColumn();
    if ($invoiceNumberSetting === 'Person ID + Invoice ID') {
        $invoiceNumber = ltrim($invoice['gibbonPersonID'], '0') . '-' . ltrim($gibbonFinanceInvoiceID, '0');
    } elseif ($invoiceNumberSetting === 'Student ID + Invoice ID') {
        $invoiceNumber = ltrim($invoice['studentID'] ?? '', '0') . '-' . ltrim($gibbonFinanceInvoiceID, '0');
    } else {
        $invoiceNumber = ltrim($gibbonFinanceInvoiceID, '0');
    }

    // Payment records — the actual transaction detail (gateway,
    // transaction ID, exact timestamp) behind the Paid status/date/amount
    // already shown elsewhere. Matches the same gibbonPayment lookup
    // receiptContents() uses (source verified against GibbonEdu/core):
    // foreignTable='gibbonFinanceInvoice', foreignTableID=invoice ID. Can
    // be more than one row for invoices paid in multiple installments.
    $paymentsStmt = $pdo->prepare("
        SELECT amount, type, status, gateway, paymentTransactionID, paymentReceiptID, timestamp
        FROM gibbonPayment
        WHERE foreignTable = 'gibbonFinanceInvoice' AND foreignTableID = :gibbonFinanceInvoiceID
        ORDER BY timestamp
    ");
    $paymentsStmt->execute(['gibbonFinanceInvoiceID' => $gibbonFinanceInvoiceID]);
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'currency' => $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'currency'")->fetchColumn() ?: 'USD $',
        // Matches core's exact rule (source verified against
        // GibbonEdu/core, invoices_view.php): 'Issued' status shows as
        // an Invoice; anything else (Paid, Cancelled, Refunded) shows as
        // a Receipt. Not just a label swap on the website — core renders
        // genuinely different content per type (invoiceContents() vs
        // receiptContents()), but our Payment Details section already
        // covers that difference in practice, since it's naturally empty
        // for an unpaid invoice anyway.
        'documentType' => $invoice['status'] === 'Issued' ? 'invoice' : 'receipt',
        'invoiceNumber' => $invoiceNumber,
        'studentName' => trim(($invoice['preferredName'] ?? '') . ' ' . ($invoice['surname'] ?? '')),
        'formGroup' => $invoice['formGroup'],
        'invoiceTo' => $invoice['invoiceTo'],
        'familyContacts' => $familyContacts,
        'companyName' => $invoice['companyName'],
        'companyContact' => $invoice['companyContact'],
        'companyAddress' => $invoice['companyAddress'],
        'companyEmail' => $invoice['companyEmail'],
        'status' => $invoice['status'],
        'billingSchedule' => !empty($invoice['billingScheduleExtra']) ? $invoice['billingScheduleExtra'] : $invoice['billingScheduleType'],
        'invoiceIssueDate' => $invoice['invoiceIssueDate'],
        'invoiceDueDate' => $invoice['invoiceDueDate'],
        'paidDate' => $invoice['paidDate'],
        'paidAmount' => $invoice['paidAmount'] !== null ? (float) $invoice['paidAmount'] : null,
        'notes' => $invoice['notes'],
        'fees' => array_map(fn($f) => [
            'name' => $f['name'],
            'category' => $f['category'],
            'description' => $f['description'],
            'fee' => (float) $f['fee'],
        ], $fees),
        'total' => $total,
        'payments' => array_map(fn($p) => [
            'amount' => (float) $p['amount'],
            'type' => $p['type'],
            'status' => $p['status'],
            'gateway' => $p['gateway'],
            'paymentTransactionID' => $p['paymentTransactionID'],
            'paymentReceiptID' => $p['paymentReceiptID'],
            'timestamp' => $p['timestamp'],
        ], $payments),
    ]);
} catch (Exception $e) {
    error_log('School Connect finance_invoice_detail.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load invoice detail.']);
}
