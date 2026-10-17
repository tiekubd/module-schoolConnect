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

$input       = json_decode(file_get_contents('php://input'), true);
$formGroupID = trim($input['formGroupID'] ?? '');

if (empty($formGroupID)) {
    echo json_encode(['success' => false, 'message' => 'formGroupID required']);
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

    // CHANGED: was a "WHERE status='Current'" subquery — now a bound param
    // using the session's active year.
    $stmt = $pdo->prepare("
        SELECT
            gibbonPerson.gibbonPersonID,
            gibbonPerson.firstName,
            gibbonPerson.surname,
            gibbonPerson.preferredName,
            gibbonPerson.email,
            gibbonPerson.image_240,
            gibbonStudentEnrolment.rollOrder,
            gibbonYearGroup.name AS yearGroup
        FROM gibbonStudentEnrolment
        JOIN gibbonPerson ON (gibbonPerson.gibbonPersonID = gibbonStudentEnrolment.gibbonPersonID)
        LEFT JOIN gibbonYearGroup ON (gibbonStudentEnrolment.gibbonYearGroupID = gibbonYearGroup.gibbonYearGroupID)
        WHERE gibbonStudentEnrolment.gibbonFormGroupID = ?
        AND gibbonStudentEnrolment.gibbonSchoolYearID = ?
        AND gibbonPerson.status = 'Full'
        ORDER BY gibbonStudentEnrolment.rollOrder, gibbonPerson.surname, gibbonPerson.preferredName
    ");
    $stmt->execute([$formGroupID, $authUser['schoolYearID']]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'students' => $students,
        'count'    => count($students),
    ]);

} catch (PDOException $e) {
    error_log('School Connect form_group_students.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
