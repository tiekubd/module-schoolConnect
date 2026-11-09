<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

// Read-only Emergency Contacts view — mirrors Gibbon core's own
// "Emergency Contacts" tab on a student's profile
// (Students/src/Profile/EmergencyContactsPage.php in v31, formerly
// student_view_details.php; source verified). Deliberately does NOT include
// the "Follow-up Contacts" section (a separate, more complex feature merging
// a configurable staff list, related teachers, and email-opted-in family
// adults) — flagged as its own scope decision, not silently dropped.
//
// Important distinction from student_family.php: this NEVER checks
// contactCall/contactSMS/contactEmail preference flags — core shows every
// phone number unconditionally here, since in a genuine emergency you
// contact everyone regardless of their stated non-emergency preference.
//
// Multi-family students: core lists the adults of EVERY family the student
// belongs to, family by family (families ordered by name). 'adults' is
// still one flat list (existing app builds keep working), now covering all
// families; each adult carries gibbonFamilyID + familyName so the app can
// group them. 'families' gives the same data grouped per family.

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

    // ── Adult Family Members of every family — same per-student relationship
    //    lookup as student_family.php, but phones shown unconditionally
    //    (see note above) ──────────────────────────────────────────────────────
    $familyStmt = $pdo->prepare("
        SELECT f.gibbonFamilyID, f.name FROM gibbonFamilyChild fc
        JOIN gibbonFamily f ON f.gibbonFamilyID = fc.gibbonFamilyID
        WHERE fc.gibbonPersonID = ?
        ORDER BY f.name
    ");
    $familyStmt->execute([$personID]);
    $familyRows = $familyStmt->fetchAll(PDO::FETCH_ASSOC);

    $adultStmt = $pdo->prepare("
        SELECT p.gibbonPersonID, p.title, p.preferredName, p.surname,
               p.phone1, p.phone1Type, p.phone1CountryCode,
               p.phone2, p.phone2Type, p.phone2CountryCode,
               p.phone3, p.phone3Type, p.phone3CountryCode,
               p.phone4, p.phone4Type, p.phone4CountryCode,
               fa.contactPriority, fr.relationship,
               fa.gibbonFamilyID
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

    $adults = [];
    $families = [];
    foreach ($familyRows as $family) {
        $familyID = $family['gibbonFamilyID'];
        $adultStmt->execute([$personID, $familyID, $familyID]);
        $familyAdults = $adultStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($familyAdults as &$a) { $a['familyName'] = $family['name']; }
        unset($a);

        $families[] = [
            'gibbonFamilyID' => $familyID,
            'name'           => $family['name'],
            'adults'         => $familyAdults,
        ];
        $adults = array_merge($adults, $familyAdults);
    }

    echo json_encode([
        'success' => true,
        'adults' => $adults,
        'families' => $families,
        'emergencyContacts' => $emergencyContacts,
    ]);

} catch (PDOException $e) {
    error_log('School Connect student_emergency.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
