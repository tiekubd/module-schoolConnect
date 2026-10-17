<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

// Read-only Emergency Contacts view — mirrors Gibbon core's own
// "Emergency Contacts" tab on a student's profile (student_view_details.php,
// source verified). Deliberately does NOT include the "Follow-up Contacts"
// section (a separate, more complex feature merging a configurable staff
// list, related teachers, and email-opted-in family adults) — flagged as
// its own scope decision, not silently dropped.
//
// Important distinction from student_family.php: this NEVER checks
// contactCall/contactSMS/contactEmail preference flags — core shows every
// phone number unconditionally here, since in a genuine emergency you
// contact everyone regardless of their stated non-emergency preference.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true);
$personID = trim($input['personID'] ?? '');

if (empty($personID)) {
    echo json_encode(['success' => false, 'message' => 'personID required']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);
    $role     = $authUser['role'];

    // ── Access control — same pattern as student_attendance.php / student_family.php ──
    if (in_array($role, ['Administrator', 'Teacher', 'Support Staff'], true)) {
        // can view anyone
    } elseif ($role === 'Parent') {
        $parentCheck = $pdo->prepare("
            SELECT fa.gibbonPersonID
            FROM gibbonFamilyAdult fa
            JOIN gibbonFamilyChild fc ON fc.gibbonFamilyID = fa.gibbonFamilyID
            WHERE fa.gibbonPersonID = ? AND fc.gibbonPersonID = ?
            AND   fa.childDataAccess = 'Y' LIMIT 1
        ");
        $parentCheck->execute([$authUser['personID'], $personID]);
        if (!$parentCheck->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not permitted to view this student']);
            exit;
        }
    } else {
        $personID = $authUser['personID'];
    }

    // ── The student's own emergency1/2 fields ────────────────────────────────
    $studentStmt = $pdo->prepare("
        SELECT emergency1Name, emergency1Number1, emergency1Number2, emergency1Relationship,
               emergency2Name, emergency2Number1, emergency2Number2, emergency2Relationship
        FROM gibbonPerson WHERE gibbonPersonID = ? LIMIT 1
    ");
    $studentStmt->execute([$personID]);
    $emergencyContacts = $studentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$emergencyContacts) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    // ── Adult Family Members — same per-student relationship lookup as
    //    student_family.php, but phones shown unconditionally (see note above) ──
    $familyStmt = $pdo->prepare("
        SELECT f.gibbonFamilyID FROM gibbonFamilyChild fc
        JOIN gibbonFamily f ON f.gibbonFamilyID = fc.gibbonFamilyID
        WHERE fc.gibbonPersonID = ? LIMIT 1
    ");
    $familyStmt->execute([$personID]);
    $family = $familyStmt->fetch(PDO::FETCH_ASSOC);

    $adults = [];
    if ($family) {
        $familyID = $family['gibbonFamilyID'];
        $adultStmt = $pdo->prepare("
            SELECT p.gibbonPersonID, p.title, p.preferredName, p.surname,
                   p.phone1, p.phone1Type, p.phone1CountryCode,
                   p.phone2, p.phone2Type, p.phone2CountryCode,
                   p.phone3, p.phone3Type, p.phone3CountryCode,
                   p.phone4, p.phone4Type, p.phone4CountryCode,
                   fa.contactPriority, fr.relationship
            FROM gibbonFamilyAdult fa
            JOIN gibbonPerson p ON p.gibbonPersonID = fa.gibbonPersonID
            LEFT JOIN gibbonFamilyRelationship fr ON (
                fr.gibbonPersonID1 = fa.gibbonPersonID
                AND fr.gibbonPersonID2 = ?
                AND fr.gibbonFamilyID = ?
            )
            WHERE fa.gibbonFamilyID = ?
            ORDER BY fa.contactPriority, p.surname, p.preferredName
        ");
        $adultStmt->execute([$personID, $familyID, $familyID]);
        $adults = $adultStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'adults' => $adults,
        'emergencyContacts' => $emergencyContacts,
    ]);

} catch (PDOException $e) {
    error_log('School Connect student_emergency.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
