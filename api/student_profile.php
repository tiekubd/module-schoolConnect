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

    $requestedPersonID = $personID;
    $personID = gibbonConnectResolvePersonID(
        $requestedPersonID,
        $authUser,
        $pdo
    );

    // resolvePersonID() never returns false — on an unauthorized request
    // it silently falls back to the caller's own personID. Left
    // unchecked, that means an unauthorized request (e.g. a Student
    // requesting another student's profile) would quietly succeed with
    // the caller's OWN data instead of an access-denied error — a
    // confusing failure mode to debug, even though it's not a data leak
    // (see reports_byStudent.php for the fuller explanation of this
    // pattern). $requestedPersonID is always non-empty here (checked
    // above), so no empty-as-self case to exclude, unlike Reports. Cast
    // to int before comparing — gibbonConnectSession.gibbonPersonID and
    // gibbonPerson.gibbonPersonID (ZEROFILL) can render differently as
    // strings for the same person.
    if ((int) $personID !== (int) $requestedPersonID) {
        echo json_encode(['success' => false, 'message' => 'You do not have access to this student\'s profile.']);
        exit;
    }

    // CHANGED: was a hardcoded "WHERE status='Current'" lookup.
    $schoolYearID = $authUser['schoolYearID'];

    // Get student basic info
    $stmt = $pdo->prepare("
        SELECT
            gibbonPerson.gibbonPersonID,
            gibbonPerson.firstName,
            gibbonPerson.preferredName,
            gibbonPerson.surname,
            gibbonPerson.image_240,
            gibbonPerson.email,
            gibbonPerson.studentID,
            gibbonPerson.dob,
            gibbonYearGroup.name AS yearGroup,
            gibbonYearGroup.nameShort AS yearGroupShort,
            gibbonFormGroup.name AS formGroup,
            gibbonFormGroup.nameShort AS formGroupShort,
            CONCAT(tutor.preferredName, ' ', tutor.surname) AS tutorName,
            CONCAT(tutor2.preferredName, ' ', tutor2.surname) AS tutorName2
        FROM gibbonPerson
        JOIN gibbonStudentEnrolment ON (
            gibbonStudentEnrolment.gibbonPersonID = gibbonPerson.gibbonPersonID
            AND gibbonStudentEnrolment.gibbonSchoolYearID = ?
        )
        JOIN gibbonYearGroup ON (
            gibbonStudentEnrolment.gibbonYearGroupID = gibbonYearGroup.gibbonYearGroupID
        )
        JOIN gibbonFormGroup ON (
            gibbonStudentEnrolment.gibbonFormGroupID = gibbonFormGroup.gibbonFormGroupID
        )
        LEFT JOIN gibbonPerson tutor ON (
            tutor.gibbonPersonID = gibbonFormGroup.gibbonPersonIDTutor
        )
        LEFT JOIN gibbonPerson tutor2 ON (
            tutor2.gibbonPersonID = gibbonFormGroup.gibbonPersonIDTutor2
        )
        WHERE gibbonPerson.gibbonPersonID = ?
        AND gibbonPerson.status = 'Full'
        LIMIT 1
    ");
    $stmt->execute([$schoolYearID, $personID]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        // Student may not be enrolled in the selected year (e.g. they
        // joined the school after that year, or left before it) — this is
        // expected/normal when browsing a non-current year, not a server error.
        echo json_encode(['success' => false, 'message' => 'Student not found in this school year']);
        exit;
    }

    // Get student's classes
    $classStmt = $pdo->prepare("
        SELECT
            gibbonCourse.name AS courseName,
            gibbonCourse.nameShort AS courseShort,
            gibbonCourseClass.nameShort AS classShort,
            CONCAT(teacher.preferredName, ' ', teacher.surname) AS teacherName
        FROM gibbonCourseClassPerson
        JOIN gibbonCourseClass ON (
            gibbonCourseClass.gibbonCourseClassID = gibbonCourseClassPerson.gibbonCourseClassID
        )
        JOIN gibbonCourse ON (
            gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID
        )
        LEFT JOIN gibbonCourseClassPerson tcp ON (
            tcp.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID
            AND tcp.role = 'Teacher'
        )
        LEFT JOIN gibbonPerson teacher ON (teacher.gibbonPersonID = tcp.gibbonPersonID)
        WHERE gibbonCourseClassPerson.gibbonPersonID = ?
        AND gibbonCourseClassPerson.role = 'Student'
        AND gibbonCourse.gibbonSchoolYearID = ?
        GROUP BY gibbonCourseClass.gibbonCourseClassID
        ORDER BY gibbonCourse.nameShort, gibbonCourseClass.nameShort
    ");
    $classStmt->execute([$personID, $schoolYearID]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get attendance summary for the selected year
    $attendStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT CASE WHEN direction='In' THEN date END) AS presentDays,
            COUNT(DISTINCT CASE WHEN direction='Out' THEN date END) AS absentDays
        FROM gibbonAttendanceLogPerson
        WHERE gibbonPersonID = ?
        AND date >= (SELECT firstDay FROM gibbonSchoolYear WHERE gibbonSchoolYearID = ?)
        AND date <= (SELECT lastDay FROM gibbonSchoolYear WHERE gibbonSchoolYearID = ?)
        -- 'Person' included alongside 'Form Group': confirmed via a live
        -- test that when School Admin > Attendance Settings has
        -- recordFirstClassAsSchool enabled, a first-period Class
        -- attendance record gets a second, separate row written with
        -- context='Person' (same gibbonCourseClassID) representing the
        -- promoted school-wide entry — not a second 'Form Group' row as
        -- might be assumed. Without this, a school using that setting
        -- (e.g. ISU) shows almost all days as 'No data' here despite
        -- Gibbon's own reports correctly showing full attendance.
        AND context IN ('Form Group', 'Person')
    ");
    $attendStmt->execute([$personID, $schoolYearID, $schoolYearID]);
    $attendance = $attendStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'       => true,
        'student'       => $student,
        'classes'       => $classes,
        'attendance'    => $attendance,
        'isCurrentYear' => $authUser['isCurrentYear'],
    ]);

} catch (PDOException $e) {
    error_log('School Connect student_profile.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
