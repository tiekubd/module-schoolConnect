<?php
/**
 * School Connect API — Messenger: Message Wall count
 *
 * Lightweight companion to messenger_wall.php, for the app-bar badge.
 * Returns only a count of today's messages — matches core's own badge
 * logic exactly: index.php caches getMessages('array') (today, no date
 * param) each session and the header badge shows count() of that array
 * (source verified against GibbonEdu/core, src/UI/Components/Header.php
 * getStatusTray() + resources/templates/tray.twig.html).
 *
 * DELIBERATELY SEPARATE FROM messenger_wall.php, not a shared call: that
 * endpoint updates gibbonPerson.messengerLastRead (read-tracking) on
 * every call. If this badge check reused it directly, just loading the
 * dashboard would silently mark messages "read" before the user ever
 * opened the wall — this endpoint never touches that column.
 *
 * Duplicates the same targeting UNION query as messenger_wall.php rather
 * than sharing code (this app's endpoints are self-contained files, not
 * a shared library) — if the targeting logic changes there, mirror the
 * change here too.
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
    error_log('School Connect messenger_wall_count.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);
$date = date('Y-m-d'); // always today, matching core's badge (not date-navigable)

try {
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

    if (!empty($roleIDs)) {
        $placeholders = [];
        foreach ($roleIDs as $i => $roleID) {
            $key = "role$i";
            $placeholders[] = ":$key";
            $params[$key] = $roleID;
        }
        $unionParts[] = "
            (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Role' AND $dateWhere
             AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
        ";
    }

    if (!empty($roleCategories)) {
        $placeholders = [];
        foreach ($roleCategories as $i => $category) {
            $key = "roleCategory$i";
            $placeholders[] = ":$key";
            $params[$key] = $category;
        }
        $unionParts[] = "
            (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Role Category' AND $dateWhere
             AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
        ";
    }

    $params['gibbonPersonIDSelf'] = $authUser['personID'];
    $unionParts[] = "
        (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
         JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
         WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Individuals' AND $dateWhere
         AND gibbonMessengerTarget.id = :gibbonPersonIDSelf)
    ";

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
            (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonStudentEnrolment ON (gibbonMessengerTarget.id = gibbonStudentEnrolment.gibbonYearGroupID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Year Group' AND $dateWhere
             AND gibbonMessengerTarget.parents = 'Y'
             AND gibbonStudentEnrolment.gibbonPersonID IN ($childList)
             AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearIDYear)
        ";
        $unionParts[] = "
            (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonStudentEnrolment ON (gibbonMessengerTarget.id = gibbonStudentEnrolment.gibbonFormGroupID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Form Group' AND $dateWhere
             AND gibbonMessengerTarget.parents = 'Y'
             AND gibbonStudentEnrolment.gibbonPersonID IN ($childList)
             AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearIDForm)
        ";
    }

    if (in_array('Student', $roleCategories, true)) {
        $params['gibbonSchoolYearIDStudentYear'] = $authUser['schoolYearID'];
        $params['gibbonPersonIDStudentYear'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonStudentEnrolment ON (gibbonMessengerTarget.id = gibbonStudentEnrolment.gibbonYearGroupID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Year Group' AND $dateWhere
             AND gibbonMessengerTarget.students = 'Y'
             AND gibbonStudentEnrolment.gibbonPersonID = :gibbonPersonIDStudentYear
             AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearIDStudentYear)
        ";
        $params['gibbonSchoolYearIDStudentForm'] = $authUser['schoolYearID'];
        $params['gibbonPersonIDStudentForm'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonStudentEnrolment ON (gibbonMessengerTarget.id = gibbonStudentEnrolment.gibbonFormGroupID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Form Group' AND $dateWhere
             AND gibbonMessengerTarget.students = 'Y'
             AND gibbonStudentEnrolment.gibbonPersonID = :gibbonPersonIDStudentForm
             AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearIDStudentForm)
        ";
    }

    if (in_array('Staff', $roleCategories, true)) {
        $params['gibbonSchoolYearIDStaffYear1'] = $authUser['schoolYearID'];
        $params['gibbonPersonIDStaffYear1'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT DISTINCT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
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
            (SELECT DISTINCT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonYearGroup ON (gibbonYearGroup.gibbonYearGroupID = gibbonMessengerTarget.id)
             JOIN gibbonStudentEnrolment ON (gibbonStudentEnrolment.gibbonYearGroupID = gibbonYearGroup.gibbonYearGroupID)
             JOIN gibbonFormGroup ON (gibbonFormGroup.gibbonFormGroupID = gibbonStudentEnrolment.gibbonFormGroupID)
             JOIN gibbonStaff ON (gibbonFormGroup.gibbonPersonIDTutor = gibbonStaff.gibbonPersonID OR gibbonFormGroup.gibbonPersonIDTutor2 = gibbonStaff.gibbonPersonID OR gibbonFormGroup.gibbonPersonIDTutor3 = gibbonStaff.gibbonPersonID)
             WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Year Group' AND gibbonMessengerTarget.staff = 'Y'
             AND $dateWhere
             AND gibbonStaff.gibbonPersonID = :gibbonPersonIDStaffYear2
             AND gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearIDStaffYear2)
        ";

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
                (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Form Group' AND gibbonMessengerTarget.staff = 'Y'
                 AND $dateWhere
                 AND gibbonMessengerTarget.id IN (" . implode(',', $formPlaceholders) . "))
            ";
        }
    }

    // ── Course / Class / Activity / Group — same logic as
    // messenger_wall.php, minimal SELECT since only a count is needed.
    // See that file for the full explanation of each branch. ────────────
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
                (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
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
                (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Class' AND gibbonMessengerTarget.$flag = 'Y'
                 AND $dateWhere AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
            ";
        }
    }

    if (!empty($childrenIDs)) {
        $childPlaceholdersForCourse = [];
        foreach ($childrenIDs as $i => $childID) {
            $key = "coursechild$i";
            $childPlaceholdersForCourse[] = ":$key";
        }
        $childListForCourse = implode(',', $childPlaceholdersForCourse);

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
                (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
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
                (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Class' AND gibbonMessengerTarget.parents = 'Y'
                 AND $dateWhere AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
            ";
        }
    }

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
                (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
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
                (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
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
                (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
                 JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
                 WHERE gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Activity' AND gibbonMessengerTarget.parents = 'Y'
                 AND $dateWhere AND gibbonMessengerTarget.id IN (" . implode(',', $placeholders) . "))
            ";
        }
    }

    if (in_array('Staff', $roleCategories, true)) {
        $params['gibbonPersonIDGroupStaff'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonGroupPerson ON (gibbonMessengerTarget.id = gibbonGroupPerson.gibbonGroupID)
             WHERE gibbonGroupPerson.gibbonPersonID = :gibbonPersonIDGroupStaff
             AND gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Group' AND gibbonMessengerTarget.staff = 'Y'
             AND $dateWhere)
        ";
    }
    if (in_array('Student', $roleCategories, true)) {
        $params['gibbonPersonIDGroupStudent'] = $authUser['personID'];
        $unionParts[] = "
            (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonGroupPerson ON (gibbonMessengerTarget.id = gibbonGroupPerson.gibbonGroupID)
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
            (SELECT gibbonMessenger.gibbonMessengerID FROM gibbonMessenger
             JOIN gibbonMessengerTarget ON (gibbonMessengerTarget.gibbonMessengerID = gibbonMessenger.gibbonMessengerID)
             JOIN gibbonGroupPerson ON (gibbonMessengerTarget.id = gibbonGroupPerson.gibbonGroupID)
             WHERE (gibbonGroupPerson.gibbonPersonID = :gibbonPersonIDGroupParent OR gibbonGroupPerson.gibbonPersonID IN (" . implode(',', $childPlaceholdersForGroup) . "))
             AND gibbonMessenger.status = 'Sent' AND gibbonMessengerTarget.type = 'Group' AND gibbonMessengerTarget.parents = 'Y'
             AND $dateWhere)
        ";
    }

    $sql = "SELECT COUNT(DISTINCT gibbonMessengerID) FROM (" . implode(' UNION ', $unionParts) . ") AS combined";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = (int) $stmt->fetchColumn();

    echo json_encode(['success' => true, 'count' => $count]);
} catch (Exception $e) {
    error_log('School Connect messenger_wall_count.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load message count.']);
}
