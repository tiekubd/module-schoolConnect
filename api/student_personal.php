<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

// Read-only "Personal" tab — mirrors Gibbon core's own comprehensive
// student-profile breakdown (student_view_details.php, source verified):
// Basic Information, Contact Information, School Information, Background
// Information, System Access, Miscellaneous. This is the fuller superset
// that also covers everything Overview shows (Name, Year Group, Form
// Group, Tutors, Age, Head of Year) — see the earlier scope discussion on
// why we consolidated into one screen instead of building both separately.
//
// Deliberately NOT ported: core's own Tutor 3 (we only ever resolved
// tutor/tutor2 elsewhere in this app), Custom Fields, and the Personal
// Documents list at the end of core's page (a genuinely separate,
// file-attachment feature) — flagged, not silently dropped.

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

    $schoolYearID = $authUser['schoolYearID'];

    $stmt = $pdo->prepare("
        SELECT
            p.title, p.surname, p.firstName, p.preferredName, p.officialName, p.nameInCharacters,
            p.gender, p.dob,
            p.phone1, p.phone1Type, p.phone1CountryCode,
            p.phone2, p.phone2Type, p.phone2CountryCode,
            p.phone3, p.phone3Type, p.phone3CountryCode,
            p.phone4, p.phone4Type, p.phone4CountryCode,
            p.email, p.emailAlternate, p.website,
            p.countryOfBirth, p.ethnicity, p.religion,
            p.languageFirst, p.languageSecond, p.languageThird,
            p.username, p.canLogin, p.lastIPAddress,
            p.transport, p.transportNotes, p.vehicleRegistration, p.lockerNumber,
            p.privacy, p.studentAgreements,
            p.studentID, p.dateStart, p.dateEnd, p.lastSchool, p.nextSchool, p.departureReason,
            house.name AS houseName,
            classOfYear.name AS classOfName,
            yg.name AS yearGroupName,
            fg.name AS formGroupName,
            tutor1.title AS tutor1Title, tutor1.preferredName AS tutor1PreferredName, tutor1.surname AS tutor1Surname,
            tutor2.title AS tutor2Title, tutor2.preferredName AS tutor2PreferredName, tutor2.surname AS tutor2Surname,
            hoy.title AS hoyTitle, hoy.preferredName AS hoyPreferredName, hoy.surname AS hoySurname
        FROM gibbonPerson p
        LEFT JOIN gibbonStudentEnrolment se ON (
            se.gibbonPersonID = p.gibbonPersonID AND se.gibbonSchoolYearID = ?
        )
        LEFT JOIN gibbonYearGroup yg ON yg.gibbonYearGroupID = se.gibbonYearGroupID
        LEFT JOIN gibbonFormGroup fg ON fg.gibbonFormGroupID = se.gibbonFormGroupID
        LEFT JOIN gibbonPerson tutor1 ON tutor1.gibbonPersonID = fg.gibbonPersonIDTutor
        LEFT JOIN gibbonPerson tutor2 ON tutor2.gibbonPersonID = fg.gibbonPersonIDTutor2
        LEFT JOIN gibbonPerson hoy ON hoy.gibbonPersonID = yg.gibbonPersonIDHOY
        LEFT JOIN gibbonHouse house ON house.gibbonHouseID = p.gibbonHouseID
        LEFT JOIN gibbonSchoolYear classOfYear ON classOfYear.gibbonSchoolYearID = p.gibbonSchoolYearIDClassOf
        WHERE p.gibbonPersonID = ?
        LIMIT 1
    ");
    $stmt->execute([$schoolYearID, $personID]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    // Age computed server-side from DOB, same as core's Format::age().
    $age = null;
    if (!empty($data['dob']) && $data['dob'] !== '0000-00-00') {
        $dobDate = new DateTime($data['dob']);
        $age = $dobDate->diff(new DateTime())->y;
    }
    $data['age'] = $age;

    // Privacy / Student Agreements are only shown at all if the school has
    // the relevant setting turned on — matches core's own conditional gate,
    // read live rather than assumed.
    $privacySetting = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'User Admin' AND name = 'privacy' LIMIT 1")->fetchColumn();
    $data['showPrivacy'] = $privacySetting === 'Y';

    $agreementOptions = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'School Admin' AND name = 'studentAgreementOptions' LIMIT 1")->fetchColumn();
    $data['showStudentAgreements'] = !empty($agreementOptions);

    echo json_encode(['success' => true, 'personal' => $data]);

} catch (PDOException $e) {
    error_log('School Connect student_personal.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
