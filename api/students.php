<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$classID = trim($input['classID'] ?? '');

if (empty($classID)) {
    echo json_encode(['success' => false, 'message' => 'classID required']);
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

    $stmt = $pdo->prepare("
        SELECT 
            gibbonPerson.gibbonPersonID,
            gibbonPerson.firstName,
            gibbonPerson.surname,
            gibbonPerson.preferredName,
            gibbonPerson.email,
            gibbonPerson.image_240,
            gibbonYearGroup.name AS yearGroup,
            gibbonFormGroup.name AS formGroup,
            gibbonCourseClassPerson.role
        FROM gibbonCourseClassPerson
        JOIN gibbonPerson ON (gibbonCourseClassPerson.gibbonPersonID = gibbonPerson.gibbonPersonID)
        LEFT JOIN gibbonStudentEnrolment ON (
            gibbonStudentEnrolment.gibbonPersonID = gibbonPerson.gibbonPersonID
            AND gibbonStudentEnrolment.gibbonSchoolYearID = (
                SELECT gibbonSchoolYearID FROM gibbonSchoolYear WHERE status='Current' LIMIT 1
            )
        )
        LEFT JOIN gibbonYearGroup ON (gibbonStudentEnrolment.gibbonYearGroupID = gibbonYearGroup.gibbonYearGroupID)
        LEFT JOIN gibbonFormGroup ON (gibbonStudentEnrolment.gibbonFormGroupID = gibbonFormGroup.gibbonFormGroupID)
        WHERE gibbonCourseClassPerson.gibbonCourseClassID = CAST(? AS UNSIGNED)
        AND gibbonCourseClassPerson.role = 'Student'
        AND gibbonPerson.status = 'Full'
        ORDER BY gibbonPerson.surname, gibbonPerson.preferredName
    ");
    $stmt->execute([$classID]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'students' => $students,
        'count'    => count($students),
    ]);

} catch (PDOException $e) {
    error_log('School Connect students.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>