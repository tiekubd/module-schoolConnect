<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
// personID and role now come from the validated session token below,
// never from the request body — see auth.php.

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);
    $personID = $authUser['personID'];
    $role     = $authUser['role'];

    // CHANGED: was a hardcoded "WHERE status='Current'" lookup.
    $schoolYearID = $authUser['schoolYearID'];
    if (!$schoolYearID) {
        echo json_encode(['success' => false, 'message' => 'No active school year']);
        exit;
    }

    if ($role === 'Administrator') {
        // Admin sees all form groups
        $stmt = $pdo->prepare("
            SELECT 
                gibbonFormGroup.gibbonFormGroupID,
                gibbonFormGroup.name,
                gibbonFormGroup.nameShort,
                gibbonYearGroup.name AS yearGroup,
                CONCAT(tutor.firstName, ' ', tutor.surname) AS tutorName,
                COUNT(gibbonStudentEnrolment.gibbonPersonID) AS studentCount
            FROM gibbonFormGroup
            LEFT JOIN gibbonStudentEnrolment ON (
                gibbonStudentEnrolment.gibbonFormGroupID = gibbonFormGroup.gibbonFormGroupID
                AND gibbonStudentEnrolment.gibbonSchoolYearID = ?
            )
            LEFT JOIN gibbonYearGroup ON (
                gibbonStudentEnrolment.gibbonYearGroupID = gibbonYearGroup.gibbonYearGroupID
            )
            LEFT JOIN gibbonPerson tutor ON (
                tutor.gibbonPersonID = gibbonFormGroup.gibbonPersonIDTutor
            )
            WHERE gibbonFormGroup.gibbonSchoolYearID = ?
            GROUP BY gibbonFormGroup.gibbonFormGroupID
            ORDER BY gibbonFormGroup.nameShort
        ");
        $stmt->execute([$schoolYearID, $schoolYearID]);

    } else {
        // Tutor sees only their own form groups
        $stmt = $pdo->prepare("
            SELECT 
                gibbonFormGroup.gibbonFormGroupID,
                gibbonFormGroup.name,
                gibbonFormGroup.nameShort,
                gibbonYearGroup.name AS yearGroup,
                CONCAT(tutor.firstName, ' ', tutor.surname) AS tutorName,
                COUNT(gibbonStudentEnrolment.gibbonPersonID) AS studentCount
            FROM gibbonFormGroup
            LEFT JOIN gibbonStudentEnrolment ON (
                gibbonStudentEnrolment.gibbonFormGroupID = gibbonFormGroup.gibbonFormGroupID
                AND gibbonStudentEnrolment.gibbonSchoolYearID = ?
            )
            LEFT JOIN gibbonYearGroup ON (
                gibbonStudentEnrolment.gibbonYearGroupID = gibbonYearGroup.gibbonYearGroupID
            )
            LEFT JOIN gibbonPerson tutor ON (
                tutor.gibbonPersonID = gibbonFormGroup.gibbonPersonIDTutor
            )
            WHERE gibbonFormGroup.gibbonSchoolYearID = ?
            AND (
                gibbonFormGroup.gibbonPersonIDTutor = ?
                OR gibbonFormGroup.gibbonPersonIDTutor2 = ?
                OR gibbonFormGroup.gibbonPersonIDTutor3 = ?
            )
            GROUP BY gibbonFormGroup.gibbonFormGroupID
            ORDER BY gibbonFormGroup.nameShort
        ");
        $stmt->execute([$schoolYearID, $schoolYearID, $personID, $personID, $personID]);
    }

    $formGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'       => true,
        'formGroups'    => $formGroups,
        'count'         => count($formGroups),
        'isCurrentYear' => $authUser['isCurrentYear'],
    ]);

} catch (PDOException $e) {
    error_log('School Connect form_groups.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
