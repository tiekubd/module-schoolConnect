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

    // Attendance menu's By Form Group (scope 'attendance', module 1.16.23+):
    // as Gibbon core v30's Attendance By Form Group, which may take any
    // group with "Attendance By Form Group_all" and only the groups the
    // person tutors with "_myGroups" (attendance_take_byFormGroupProcess.php);
    // groups that take attendance. Without the scope (browsing Form Groups)
    // nothing changes: Administrators every group, others their own.
    $attendanceScope = (($input['scope'] ?? '') === 'attendance');
    $listAll = $role === 'Administrator';
    $fgAccess = 'all';
    if ($attendanceScope) {
        $fgAccess = gibbonConnectAttendanceAccess($pdo, $authUser)['formGroup'];
        $listAll = $fgAccess === 'all';
    }

    if ($listAll) {
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

    if ($attendanceScope) {
        $takesAttendance = array_flip($pdo->query("SELECT gibbonFormGroupID FROM gibbonFormGroup WHERE attendance = 'Y'")
            ->fetchAll(PDO::FETCH_COLUMN));
        $formGroups = $fgAccess === ''
            ? []
            : array_values(array_filter($formGroups, fn($g) => isset($takesAttendance[$g['gibbonFormGroupID']])));
    }

    echo json_encode([
        'success'       => true,
        'allFormGroups' => $listAll,
        'formGroups'    => $formGroups,
        'count'         => count($formGroups),
        'isCurrentYear' => $authUser['isCurrentYear'],
    ]);

} catch (PDOException $e) {
    error_log('School Connect form_groups.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
