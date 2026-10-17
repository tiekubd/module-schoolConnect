<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

// Read-only, per-student Family view — mirrors Gibbon core's own "Family"
// tab on a student's profile (student_view_details.php, source verified),
// NOT the same feature as admin_families.php/FamilyDetailScreen (that's a
// separate, Admin-only family MANAGEMENT tool with real write actions —
// this file is deliberately read-only and has a broader, multi-role
// audience, same access model as student_attendance.php).

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

    // ── Access control — identical pattern to student_attendance.php ────────
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

    // Only staff see the family's internal comment fields — matches the
    // spirit of core's finer-grained permission categories
    // (fullEditAllNotes/full/fullNoNotes), simplified to our app's role
    // model: Parent/Student never see these regardless of who's viewing.
    $canSeeComments = in_array($role, ['Administrator', 'Teacher', 'Support Staff'], true);

    // ── Find this student's family ───────────────────────────────────────────
    $familyStmt = $pdo->prepare("
        SELECT f.gibbonFamilyID, f.name, f.status, f.nameAddress, f.homeAddress,
               f.homeAddressDistrict, f.homeAddressCountry,
               f.languageHomePrimary, f.languageHomeSecondary
        FROM gibbonFamilyChild fc
        JOIN gibbonFamily f ON f.gibbonFamilyID = fc.gibbonFamilyID
        WHERE fc.gibbonPersonID = ?
        LIMIT 1
    ");
    $familyStmt->execute([$personID]);
    $family = $familyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$family) {
        echo json_encode(['success' => true, 'family' => null, 'adults' => [], 'siblings' => []]);
        exit;
    }

    $familyID = $family['gibbonFamilyID'];

    // ── Adults, with per-STUDENT relationship (core: gibbonFamilyRelationship
    // is keyed per adult-child pair, since one adult can have a different
    // relationship to different children in the same family) ───────────────
    $adultStmt = $pdo->prepare("
        SELECT p.gibbonPersonID, p.title, p.preferredName, p.surname, p.image_240, p.status,
               p.email, p.emailAlternate, p.languageFirst, p.languageSecond,
               p.profession, p.employer, p.jobTitle, p.vehicleRegistration,
               p.phone1, p.phone1Type, p.phone1CountryCode,
               p.phone2, p.phone2Type, p.phone2CountryCode,
               p.phone3, p.phone3Type, p.phone3CountryCode,
               p.phone4, p.phone4Type, p.phone4CountryCode,
               fa.comment, fa.contactPriority,
               fa.contactCall, fa.contactSMS, fa.contactEmail, fa.contactMail,
               fr.relationship
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

    if (!$canSeeComments) {
        foreach ($adults as &$a) { $a['comment'] = null; }
        unset($a);
    }

    // ── Siblings — other children in the same family ────────────────────────
    $siblingStmt = $pdo->prepare("
        SELECT p.gibbonPersonID, p.preferredName, p.surname, p.image_240, p.status
        FROM gibbonFamilyChild fc
        JOIN gibbonPerson p ON p.gibbonPersonID = fc.gibbonPersonID
        WHERE fc.gibbonFamilyID = ? AND fc.gibbonPersonID != ?
        ORDER BY p.surname, p.preferredName
    ");
    $siblingStmt->execute([$familyID, $personID]);
    $siblings = $siblingStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'   => true,
        'family'    => $family,
        'adults'    => $adults,
        'siblings'  => $siblings,
    ]);

} catch (PDOException $e) {
    error_log('School Connect student_family.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
