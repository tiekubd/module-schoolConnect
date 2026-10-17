<?php
/**
 * School Connect API — Messenger: Message Wall
 *
 * Returns messages targeted at the authenticated person for a given date,
 * mirroring core's MessengerGateway::getMessages('array', $date) (source
 * verified against GibbonEdu/core) — a message appears on a given day if
 * that date falls between its messageWall_dateStart/messageWall_dateEnd.
 * Generic for any role — which branches apply depends on which role
 * categories this person actually holds (Staff/Student/Parent), resolved
 * per-request, same as core.
 *
 * SCOPING DECISION: core's targeting engine has 12 real targeting
 * branches across the gibbonMessengerTarget type enum (2 more values,
 * Applicants and Mailing List, exist in the schema but aren't used by
 * getMessages() at all — likely email-only/pre-enrollment delivery, not
 * shown on the Wall). This endpoint now covers 9 of those 12:
 *   1. Broadcast — Role, Role Category
 *   2. Direct — Individuals
 *   3. Group (in the "collection of people" sense) — Year Group, Form
 *      Group, Course, Class, Activity, Group (gibbonGroup/
 *      gibbonGroupPerson — an admin-curated arbitrary list, distinct
 *      from Year/Form Group's enrollment-derived membership)
 *      — each with staff/student/parent variants matching core exactly.
 * DEFERRED: Transport, Houses, Attendance targeting. Also deferred
 * entirely: composing, replying, canned responses — all stay
 * website-only. The "Post Owner" branch (messages the viewer authored)
 * is skipped since this app never lets anyone author a message.
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
    error_log('School Connect messenger_wall.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

// No role gate — Message Wall is generic for any role, matching core's
// actual behavior. Which targeting branches apply depends on which role
// categories this person holds (resolved below), not a single check here.

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$date = trim($input['date'] ?? '') ?: date('Y-m-d');

// Basic sanity check on the date format — avoid feeding a malformed value
// into the BETWEEN comparison below.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

try {
    // Update read-tracking, matching core's behavior on every wall view.
    $updateReadStmt = $pdo->prepare("UPDATE gibbonPerson SET messengerLastRead = NOW() WHERE gibbonPersonID = :gibbonPersonID");
    $updateReadStmt->execute(['gibbonPersonID' => $authUser['personID']]);

    // ── This person's roles and role categories (ALL roles they hold,
    // not just the currently active one — core's targeting checks
    // against every role a person has, since a message could target a
    // role they're not currently "acting as"). ──
    $roleStmt = $pdo->prepare("
        SELECT gibbonRole.gibbonRoleID, gibbonRole.category
        FROM gibbonRole
        JOIN gibbonPerson ON (FIND_IN_SET(gibbonRole.gibbonRoleID, gibbonPerson.gibbonRoleIDAll))
        WHERE gibbonPerson.gibbonPersonID = :gibbonPersonID
    ");
    $roleStmt->execute(['gibbonPersonID' => $authUser['personID']]);
    $roles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

    $roleIDs = array_column($roles, 'gibbonRoleID');
    $roleCategories = array_values(array_unique(array_column($roles, 'category')));

    // ── Children (via childDataAccess='Y'), same pattern used throughout
    // this app for Reports/Data Updater. ──
    $childrenStmt = $pdo->prepare("
        SELECT DISTINCT child.gibbonPersonID
        FROM gibbonFamilyAdult
        JOIN gibbonFamily ON (gibbonFamily.gibbonFamilyID = gibbonFamilyAdult.gibbonFamilyID)
        JOIN gibbonFamilyChild ON (gibbonFamilyChild.gibbonFamilyID = gibbonFamily.gibbonFamilyID)
        JOIN gibbonPerson AS child ON (gibbonFamilyChild.gibbonPersonID = child.gibbonPersonID)
        WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID
        AND gibbonFamilyAdult.childDataAccess = 'Y' AND child.status = 'Full'
    ");
    $childrenStmt->execute(['gibbonPersonID' => $authUser['personID']]);
    $childrenIDs = $childrenStmt->fetchAll(PDO::FETCH_COLUMN);

    $dateWhere = "(:date BETWEEN gibbonMessenger.messageWall_dateStart AND gibbonMessenger.messageWall_dateEnd)";
    $unionParts = [];
    $params = ['date' => $date];

    // ── Broadcast: Role ──────────────────────────────────────────────────
    if (!empty($roleIDs)) {
        $placeholders = [];
        foreach ($roleIDs as $i => $roleID) {
            $key = "role$i";
            $placeholders[] = ":$key";
            $params[$key] = $roleID;
        }
        $unionParts[] = "
            (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    CONCAT('Role: ', gibbonRole.name) AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             JOIN gibbonRole ON (gibbonMessengerTarget.id = gibbonRole.gibbonRoleID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Role' AND $dateWhere
             AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
        ";
    }

    // ── Broadcast: Role Category ─────────────────────────────────────────
    if (!empty($roleCategories)) {
        $placeholders = [];
        foreach ($roleCategories as $i => $category) {
            $key = "roleCategory$i";
            $placeholders[] = ":$key";
            $params[$key] = $category;
        }
        $unionParts[] = "
            (SELECT DISTINCT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    CONCAT('Role Category: ', gibbonMessengerTarget.id) AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Role Category' AND $dateWhere
             AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
        ";
    }

    // ── Direct: Individuals ──────────────────────────────────────────────
    $params['gibbonPersonIDSelf'] = $authUser['personID'];
    $unionParts[] = "
        (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                'Individual: You' AS source
         FROM gibbonMessenger
         JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
         JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
         JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
         WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Individuals' AND $dateWhere
         AND gibbonMessengerTarget.id = :gibbonPersonIDSelf)
    ";

    // ── Children's group: Year Group / Form Group (parents='Y') ─────────
    if (!empty($childrenIDs)) {
        $childPlaceholders = [];
        foreach ($childrenIDs as $i => $childID) {
            $key = "child$i";
            $childPlaceholders[] = ":$key";
            $params[$key] = $childID;
        }
        $childList = implode(',', $childPlaceholders);
        $params['gibbonSchoolYearIDYear'] = $authUser['schoolYearID'];
        $params['gibbonSchoolYearIDForm'] = $authUser['schoolYearID'];

        $unionParts[] = "
            (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    CONCAT('Year Group: ', gibbonYearGroup.nameShort) AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             JOIN gibbonStudentEnrolment ON (gibbonMessengerTarget.id = gibbonStudentEnrolment.gibbonYearGroupID)
             JOIN gibbonYearGroup ON (gibbonStudentEnrolment.gibbonYearGroupID = gibbonYearGroup.gibbonYearGroupID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Year Group' AND $dateWhere
             AND gibbonMessengerTarget.parents = 'Y'
             AND gibbonStudentEnrolment.gibbonPersonID IN ($childList)
             AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearIDYear)
        ";
        $unionParts[] = "
            (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    CONCAT('Form Group: ', gibbonFormGroup.nameShort) AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             JOIN gibbonStudentEnrolment ON (gibbonMessengerTarget.id = gibbonStudentEnrolment.gibbonFormGroupID)
             JOIN gibbonFormGroup ON (gibbonStudentEnrolment.gibbonFormGroupID = gibbonFormGroup.gibbonFormGroupID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Form Group' AND $dateWhere
             AND gibbonMessengerTarget.parents = 'Y'
             AND gibbonStudentEnrolment.gibbonPersonID IN ($childList)
             AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearIDForm)
        ";
    }

    // ── Children's group, Student's own path: Year Group / Form Group
    // (via their own enrollment, students='Y') ──────────────────────────
    if (in_array('Student', $roleCategories, true)) {
        $params['gibbonSchoolYearIDStudentYear'] = $authUser['schoolYearID'];
        $params['gibbonPersonIDStudentYear'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    CONCAT('Year Group: ', gibbonYearGroup.nameShort) AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             JOIN gibbonStudentEnrolment ON (gibbonMessengerTarget.id = gibbonStudentEnrolment.gibbonYearGroupID)
             JOIN gibbonYearGroup ON (gibbonStudentEnrolment.gibbonYearGroupID = gibbonYearGroup.gibbonYearGroupID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Year Group' AND $dateWhere
             AND gibbonMessengerTarget.students = 'Y'
             AND gibbonStudentEnrolment.gibbonPersonID = :gibbonPersonIDStudentYear
             AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearIDStudentYear)
        ";

        $params['gibbonSchoolYearIDStudentForm'] = $authUser['schoolYearID'];
        $params['gibbonPersonIDStudentForm'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    CONCAT('Form Group: ', gibbonFormGroup.nameShort) AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             JOIN gibbonStudentEnrolment ON (gibbonMessengerTarget.id = gibbonStudentEnrolment.gibbonFormGroupID)
             JOIN gibbonFormGroup ON (gibbonStudentEnrolment.gibbonFormGroupID = gibbonFormGroup.gibbonFormGroupID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Form Group' AND $dateWhere
             AND gibbonMessengerTarget.students = 'Y'
             AND gibbonStudentEnrolment.gibbonPersonID = :gibbonPersonIDStudentForm
             AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearIDStudentForm)
        ";
    }

    // ── Children's group, Staff/tutor path: Year Group (via courses
    // taught, or via tutoring a form group in that year) / Form Group
    // (via tutoring), staff='Y' ──────────────────────────────────────────
    if (in_array('Staff', $roleCategories, true)) {
        $params['gibbonSchoolYearIDStaffYear1'] = $authUser['schoolYearID'];
        $params['gibbonPersonIDStaffYear1'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT DISTINCT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    'Year Group (courses taught)' AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             JOIN gibbonCourse ON (FIND_IN_SET(gibbonMessengerTarget.id, gibbonCourse.gibbonYearGroupIDList))
             JOIN gibbonCourseClass ON (gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID)
             JOIN gibbonCourseClassPerson ON (gibbonCourseClass.gibbonCourseClassID = gibbonCourseClassPerson.gibbonCourseClassID)
             JOIN gibbonStaff ON (gibbonCourseClassPerson.gibbonPersonID = gibbonStaff.gibbonPersonID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Year Group' AND gibbonMessengerTarget.staff = 'Y'
             AND $dateWhere
             AND gibbonStaff.gibbonPersonID = :gibbonPersonIDStaffYear1
             AND gibbonCourse.gibbonSchoolYearID = :gibbonSchoolYearIDStaffYear1)
        ";

        $params['gibbonSchoolYearIDStaffYear2'] = $authUser['schoolYearID'];
        $params['gibbonPersonIDStaffYear2'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT DISTINCT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    'Year Group (tutor)' AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             JOIN gibbonYearGroup ON (gibbonYearGroup.gibbonYearGroupID = gibbonMessengerTarget.id)
             JOIN gibbonStudentEnrolment ON (gibbonStudentEnrolment.gibbonYearGroupID = gibbonYearGroup.gibbonYearGroupID)
             JOIN gibbonFormGroup ON (gibbonFormGroup.gibbonFormGroupID = gibbonStudentEnrolment.gibbonFormGroupID)
             JOIN gibbonStaff ON (gibbonFormGroup.gibbonPersonIDTutor = gibbonStaff.gibbonPersonID OR gibbonFormGroup.gibbonPersonIDTutor2 = gibbonStaff.gibbonPersonID OR gibbonFormGroup.gibbonPersonIDTutor3 = gibbonStaff.gibbonPersonID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Year Group' AND gibbonMessengerTarget.staff = 'Y'
             AND $dateWhere
             AND gibbonStaff.gibbonPersonID = :gibbonPersonIDStaffYear2
             AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearIDStaffYear2)
        ";

        // Staff's own tutored form groups — pre-fetched, same IN-list
        // pattern used elsewhere in this file (e.g. children).
        $tutorStmt = $pdo->prepare("
            SELECT gibbonFormGroupID FROM gibbonFormGroup
            WHERE gibbonSchoolYearID = :gibbonSchoolYearID
            AND (gibbonPersonIDTutor = :gibbonPersonID1 OR gibbonPersonIDTutor2 = :gibbonPersonID2 OR gibbonPersonIDTutor3 = :gibbonPersonID3)
        ");
        $tutorStmt->execute([
            'gibbonSchoolYearID' => $authUser['schoolYearID'],
            'gibbonPersonID1' => $authUser['personID'],
            'gibbonPersonID2' => $authUser['personID'],
            'gibbonPersonID3' => $authUser['personID'],
        ]);
        $tutoredFormGroupIDs = $tutorStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($tutoredFormGroupIDs)) {
            $formPlaceholders = [];
            foreach ($tutoredFormGroupIDs as $i => $formGroupID) {
                $key = "tutoredForm$i";
                $formPlaceholders[] = ":$key";
                $params[$key] = $formGroupID;
            }
            $unionParts[] = "
                (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                        CONCAT('Form Group: ', gibbonFormGroup.nameShort) AS source
                 FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
                 JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
                 JOIN gibbonFormGroup ON (gibbonMessengerTarget.id = gibbonFormGroup.gibbonFormGroupID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Form Group' AND gibbonMessengerTarget.staff = 'Y'
                 AND $dateWhere
                 AND gibbonMessengerTarget.id IN (" . implode(',', $formPlaceholders) . "))
            ";
        }
    }

    // ── Course / Class: own teaching or enrollment. Core's own query
    // doesn't filter by role name, just excludes '- Left' statuses — so
    // the same ID list applies whether the person holds Staff or Student
    // category (both branches get added if relevant, covering the rare
    // case of someone holding both). ──────────────────────────────────
    $ownCourseStmt = $pdo->prepare("
        SELECT DISTINCT gibbonCourse.gibbonCourseID
        FROM gibbonCourse
        JOIN gibbonCourseClass ON (gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID)
        JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
        WHERE gibbonCourse.gibbonSchoolYearID = :gibbonSchoolYearID AND gibbonCourseClassPerson.gibbonPersonID = :gibbonPersonID
        AND gibbonCourseClassPerson.role NOT LIKE '%- Left'
    ");
    $ownCourseStmt->execute(['gibbonSchoolYearID' => $authUser['schoolYearID'], 'gibbonPersonID' => $authUser['personID']]);
    $ownCourseIDs = $ownCourseStmt->fetchAll(PDO::FETCH_COLUMN);

    $ownClassStmt = $pdo->prepare("
        SELECT DISTINCT gibbonCourseClass.gibbonCourseClassID
        FROM gibbonCourse
        JOIN gibbonCourseClass ON (gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID)
        JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
        WHERE gibbonCourse.gibbonSchoolYearID = :gibbonSchoolYearID AND gibbonCourseClassPerson.gibbonPersonID = :gibbonPersonID
        AND gibbonCourseClassPerson.role NOT LIKE '%- Left'
    ");
    $ownClassStmt->execute(['gibbonSchoolYearID' => $authUser['schoolYearID'], 'gibbonPersonID' => $authUser['personID']]);
    $ownClassIDs = $ownClassStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach (['Staff' => 'staff', 'Student' => 'students'] as $cat => $flag) {
        if (!empty($ownCourseIDs) && in_array($cat, $roleCategories, true)) {
            $placeholders = [];
            foreach ($ownCourseIDs as $i => $courseID) {
                $key = "course{$flag}{$i}";
                $placeholders[] = ":$key";
                $params[$key] = $courseID;
            }
            $unionParts[] = "
                (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                        CONCAT('Course: ', gibbonCourse.nameShort) AS source
                 FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
                 JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
                 JOIN gibbonCourse ON (gibbonMessengerTarget.id = gibbonCourse.gibbonCourseID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Course' AND gibbonMessengerTarget.$flag = 'Y'
                 AND $dateWhere AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
            ";
        }
        if (!empty($ownClassIDs) && in_array($cat, $roleCategories, true)) {
            $placeholders = [];
            foreach ($ownClassIDs as $i => $classID) {
                $key = "class{$flag}{$i}";
                $placeholders[] = ":$key";
                $params[$key] = $classID;
            }
            $unionParts[] = "
                (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                        CONCAT('Class: ', gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS source
                 FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
                 JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
                 JOIN gibbonCourseClass ON (gibbonMessengerTarget.id = gibbonCourseClass.gibbonCourseClassID)
                 JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Class' AND gibbonMessengerTarget.$flag = 'Y'
                 AND $dateWhere AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
            ";
        }
    }

    // Parent variant — via children's course/class enrollment.
    if (!empty($childrenIDs)) {
        $childPlaceholdersForCourse = [];
        foreach ($childrenIDs as $i => $childID) {
            $key = "coursechild$i";
            $childPlaceholdersForCourse[] = ":$key";
        }
        $childListForCourse = implode(',', $childPlaceholdersForCourse);
        $params['gibbonSchoolYearIDChildCourse'] = $authUser['schoolYearID'];

        $childCourseStmt = $pdo->prepare("
            SELECT DISTINCT gibbonCourse.gibbonCourseID
            FROM gibbonCourse
            JOIN gibbonCourseClass ON (gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID)
            JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
            WHERE gibbonCourse.gibbonSchoolYearID = :gibbonSchoolYearID AND gibbonCourseClassPerson.gibbonPersonID IN ($childListForCourse)
            AND gibbonCourseClassPerson.role NOT LIKE '%- Left'
        ");
        $childCourseStmt->execute(array_merge(['gibbonSchoolYearID' => $authUser['schoolYearID']], array_combine(array_map(fn($k) => ltrim($k, ':'), $childPlaceholdersForCourse), $childrenIDs)));
        $childCourseIDs = $childCourseStmt->fetchAll(PDO::FETCH_COLUMN);

        $childClassStmt = $pdo->prepare("
            SELECT DISTINCT gibbonCourseClass.gibbonCourseClassID
            FROM gibbonCourse
            JOIN gibbonCourseClass ON (gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID)
            JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
            WHERE gibbonCourse.gibbonSchoolYearID = :gibbonSchoolYearID AND gibbonCourseClassPerson.gibbonPersonID IN ($childListForCourse)
            AND gibbonCourseClassPerson.role NOT LIKE '%- Left'
        ");
        $childClassStmt->execute(array_merge(['gibbonSchoolYearID' => $authUser['schoolYearID']], array_combine(array_map(fn($k) => ltrim($k, ':'), $childPlaceholdersForCourse), $childrenIDs)));
        $childClassIDs = $childClassStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($childCourseIDs)) {
            $placeholders = [];
            foreach ($childCourseIDs as $i => $courseID) {
                $key = "coursepar$i";
                $placeholders[] = ":$key";
                $params[$key] = $courseID;
            }
            $unionParts[] = "
                (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                        CONCAT('Course: ', gibbonCourse.nameShort) AS source
                 FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
                 JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
                 JOIN gibbonCourse ON (gibbonMessengerTarget.id = gibbonCourse.gibbonCourseID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Course' AND gibbonMessengerTarget.parents = 'Y'
                 AND $dateWhere AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
            ";
        }
        if (!empty($childClassIDs)) {
            $placeholders = [];
            foreach ($childClassIDs as $i => $classID) {
                $key = "classpar$i";
                $placeholders[] = ":$key";
                $params[$key] = $classID;
            }
            $unionParts[] = "
                (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                        CONCAT('Class: ', gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS source
                 FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
                 JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
                 JOIN gibbonCourseClass ON (gibbonMessengerTarget.id = gibbonCourseClass.gibbonCourseClassID)
                 JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Class' AND gibbonMessengerTarget.parents = 'Y'
                 AND $dateWhere AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
            ";
        }
    }

    // ── Activity: staff coordinating it / student accepted into it / a
    // child accepted into it (parent). Staff and Student use different
    // source tables (gibbonActivityStaff vs gibbonActivityStudent), so
    // unlike Course/Class these need separate prefetches. ──────────────
    if (in_array('Staff', $roleCategories, true)) {
        $activityStaffStmt = $pdo->prepare("
            SELECT gibbonActivity.gibbonActivityID FROM gibbonActivity
            JOIN gibbonActivityStaff ON (gibbonActivityStaff.gibbonActivityID = gibbonActivity.gibbonActivityID)
            WHERE gibbonActivity.gibbonSchoolYearID = :gibbonSchoolYearID AND gibbonActivityStaff.gibbonPersonID = :gibbonPersonID
        ");
        $activityStaffStmt->execute(['gibbonSchoolYearID' => $authUser['schoolYearID'], 'gibbonPersonID' => $authUser['personID']]);
        $activityStaffIDs = $activityStaffStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($activityStaffIDs)) {
            $placeholders = [];
            foreach ($activityStaffIDs as $i => $activityID) {
                $key = "actstaff$i";
                $placeholders[] = ":$key";
                $params[$key] = $activityID;
            }
            $unionParts[] = "
                (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                        CONCAT('Activity: ', gibbonActivity.name) AS source
                 FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
                 JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
                 JOIN gibbonActivity ON (gibbonMessengerTarget.id = gibbonActivity.gibbonActivityID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Activity' AND gibbonMessengerTarget.staff = 'Y'
                 AND $dateWhere AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
            ";
        }
    }

    if (in_array('Student', $roleCategories, true)) {
        $activityStudentStmt = $pdo->prepare("
            SELECT gibbonActivity.gibbonActivityID FROM gibbonActivity
            JOIN gibbonActivityStudent ON (gibbonActivityStudent.gibbonActivityID = gibbonActivity.gibbonActivityID)
            WHERE gibbonActivity.gibbonSchoolYearID = :gibbonSchoolYearID AND gibbonActivityStudent.gibbonPersonID = :gibbonPersonID
            AND gibbonActivityStudent.status = 'Accepted'
        ");
        $activityStudentStmt->execute(['gibbonSchoolYearID' => $authUser['schoolYearID'], 'gibbonPersonID' => $authUser['personID']]);
        $activityStudentIDs = $activityStudentStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($activityStudentIDs)) {
            $placeholders = [];
            foreach ($activityStudentIDs as $i => $activityID) {
                $key = "actstudent$i";
                $placeholders[] = ":$key";
                $params[$key] = $activityID;
            }
            $unionParts[] = "
                (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                        CONCAT('Activity: ', gibbonActivity.name) AS source
                 FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
                 JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
                 JOIN gibbonActivity ON (gibbonMessengerTarget.id = gibbonActivity.gibbonActivityID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Activity' AND gibbonMessengerTarget.students = 'Y'
                 AND $dateWhere AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
            ";
        }
    }

    if (!empty($childrenIDs)) {
        $childPlaceholdersForActivity = [];
        foreach ($childrenIDs as $i => $childID) {
            $key = "actchild$i";
            $childPlaceholdersForActivity[] = ":$key";
        }
        $childListForActivity = implode(',', $childPlaceholdersForActivity);
        $params['gibbonSchoolYearIDChildActivity'] = $authUser['schoolYearID'];

        $activityParentStmt = $pdo->prepare("
            SELECT DISTINCT gibbonActivity.gibbonActivityID FROM gibbonActivity
            JOIN gibbonActivityStudent ON (gibbonActivityStudent.gibbonActivityID = gibbonActivity.gibbonActivityID)
            WHERE gibbonActivity.gibbonSchoolYearID = :gibbonSchoolYearID AND gibbonActivityStudent.gibbonPersonID IN ($childListForActivity)
            AND gibbonActivityStudent.status = 'Accepted'
        ");
        $activityParentStmt->execute(array_merge(['gibbonSchoolYearID' => $authUser['schoolYearID']], array_combine(array_map(fn($k) => ltrim($k, ':'), $childPlaceholdersForActivity), $childrenIDs)));
        $activityParentIDs = $activityParentStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($activityParentIDs)) {
            $placeholders = [];
            foreach ($activityParentIDs as $i => $activityID) {
                $key = "actpar$i";
                $placeholders[] = ":$key";
                $params[$key] = $activityID;
            }
            $unionParts[] = "
                (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                        CONCAT('Activity: ', gibbonActivity.name) AS source
                 FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
                 JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
                 JOIN gibbonActivity ON (gibbonMessengerTarget.id = gibbonActivity.gibbonActivityID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Activity' AND gibbonMessengerTarget.parents = 'Y'
                 AND $dateWhere AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
            ";
        }
    }

    // ── Group: admin-curated arbitrary group membership (gibbonGroup/
    // gibbonGroupPerson) — not derived from enrollment at all, so this is
    // a direct membership JOIN rather than a prefetched ID list. ────────
    if (in_array('Staff', $roleCategories, true)) {
        $params['gibbonPersonIDGroupStaff'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    CONCAT(gibbonGroup.name, ' Group') AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             JOIN gibbonGroup ON (gibbonMessengerTarget.id = gibbonGroup.gibbonGroupID)
             JOIN gibbonGroupPerson ON (gibbonGroup.gibbonGroupID = gibbonGroupPerson.gibbonGroupID)
             WHERE gibbonGroupPerson.gibbonPersonID = :gibbonPersonIDGroupStaff
             AND gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Group' AND gibbonMessengerTarget.staff = 'Y'
             AND $dateWhere)
        ";
    }
    if (in_array('Student', $roleCategories, true)) {
        $params['gibbonPersonIDGroupStudent'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    CONCAT(gibbonGroup.name, ' Group') AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             JOIN gibbonGroup ON (gibbonMessengerTarget.id = gibbonGroup.gibbonGroupID)
             JOIN gibbonGroupPerson ON (gibbonGroup.gibbonGroupID = gibbonGroupPerson.gibbonGroupID)
             WHERE gibbonGroupPerson.gibbonPersonID = :gibbonPersonIDGroupStudent
             AND gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Group' AND gibbonMessengerTarget.students = 'Y'
             AND $dateWhere)
        ";
    }
    if (!empty($childrenIDs)) {
        $childPlaceholdersForGroup = [];
        foreach ($childrenIDs as $i => $childID) {
            $key = "groupchild$i";
            $childPlaceholdersForGroup[] = ":$key";
            $params[$key] = $childID;
        }
        $params['gibbonPersonIDGroupParent'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT gibbonMessenger.*, author.title, author.surname, author.preferredName, authorRole.category AS authorCategory, author.image_240,
                    CONCAT(gibbonGroup.name, ' Group') AS source
             FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonPerson AS author ON (gibbonMessenger.gibbonPersonID = author.gibbonPersonID)
             JOIN gibbonRole AS authorRole ON (author.gibbonRoleIDPrimary = authorRole.gibbonRoleID)
             JOIN gibbonGroup ON (gibbonMessengerTarget.id = gibbonGroup.gibbonGroupID)
             JOIN gibbonGroupPerson ON (gibbonGroup.gibbonGroupID = gibbonGroupPerson.gibbonGroupID)
             WHERE (gibbonGroupPerson.gibbonPersonID = :gibbonPersonIDGroupParent OR gibbonGroupPerson.gibbonPersonID IN (" . implode(',', $childPlaceholdersForGroup) . "))
             AND gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Group' AND gibbonMessengerTarget.parents = 'Y'
             AND $dateWhere)
        ";
    }

    $sql = implode(' UNION ', $unionParts) . ' ORDER BY messageWallPin DESC, timestamp DESC, gibbonMessengerID, source';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Dedupe by gibbonMessengerID, merging "source" labels when the same
    // message matched more than one targeting branch — matches core's
    // array_reduce grouping exactly.
    $messages = [];
    foreach ($rows as $row) {
        $id = $row['gibbonMessengerID'];
        if (isset($messages[$id])) {
            $messages[$id]['sources'][] = $row['source'];
        } else {
            $messages[$id] = [
                'gibbonMessengerID' => $id,
                'subject' => $row['subject'],
                'body' => $row['body'],
                'timestamp' => $row['timestamp'],
                'messageWallPin' => $row['messageWallPin'] === 'Y',
                'authorTitle' => $row['title'],
                'authorSurname' => $row['surname'],
                'authorPreferredName' => $row['preferredName'],
                'authorCategory' => $row['authorCategory'],
                'authorImage' => $row['image_240'],
                'sources' => [$row['source']],
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'date' => $date,
        'messages' => array_values($messages),
    ]);
} catch (Exception $e) {
    error_log('School Connect messenger_wall.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load messages.']);
}
