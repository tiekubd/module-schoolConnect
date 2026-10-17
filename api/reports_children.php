<?php
/**
 * School Connect API — Reports: My Children
 *
 * Lists the authenticated Parent's children who have childDataAccess='Y',
 * matching core's StudentGateway::selectAnyStudentsByFamilyAdult()
 * (source verified against GibbonEdu/core). This is the "View Reports"
 * landing list — tapping a child leads to reports_byStudent.php.
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
    error_log('School Connect reports_children.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

if ($authUser['role'] !== 'Parent') {
    echo json_encode(['success' => false, 'message' => 'Only Parent accounts can view children\'s reports.']);
    exit;
}

try {
    $sql = "SELECT gibbonPerson.gibbonPersonID, gibbonPerson.title, gibbonPerson.preferredName, gibbonPerson.surname,
                   gibbonPerson.image_240, gibbonYearGroup.nameShort AS yearGroup, gibbonFormGroup.nameShort AS formGroup
            FROM gibbonFamilyAdult
            JOIN gibbonFamilyChild ON (gibbonFamilyChild.gibbonFamilyID = gibbonFamilyAdult.gibbonFamilyID)
            JOIN gibbonPerson ON (gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID)
            LEFT JOIN gibbonStudentEnrolment ON (gibbonStudentEnrolment.gibbonPersonID = gibbonPerson.gibbonPersonID
                AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearID)
            LEFT JOIN gibbonYearGroup ON (gibbonYearGroup.gibbonYearGroupID = gibbonStudentEnrolment.gibbonYearGroupID)
            LEFT JOIN gibbonFormGroup ON (gibbonFormGroup.gibbonFormGroupID = gibbonStudentEnrolment.gibbonFormGroupID)
            WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID
              AND gibbonFamilyAdult.childDataAccess = 'Y'
            GROUP BY gibbonPerson.gibbonPersonID
            ORDER BY gibbonPerson.surname, gibbonPerson.preferredName";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'gibbonSchoolYearID' => $authUser['schoolYearID'],
        'gibbonPersonID' => $authUser['personID'],
    ]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'children' => $children]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Could not load children.']);
}
