<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// ─── Markbook grade entry ────────────────────────────────────────────────────
//
// The app's equivalent of Assess > Markbook > (column) > Enter Data.
// Grade entry only: columns are still created and edited on the website.
//
// Ported from Gibbon core v30.0.02 (source verified):
//   modules/Markbook/markbook_edit_data.php         (what the form shows)
//   modules/Markbook/markbook_edit_dataProcess.php  (what a save writes)
//   modules/Markbook/src/MarkbookGate.php           (who may save)
//   src/UI/Components/Alert.php                     (academic alert refresh)
//
// ACCESS — same as MarkbookGate::canEditMarkbook():
//   1. The current role must hold at least one Markbook action whose URLList
//      includes markbook_edit_data.php (core's Access::get(...)->allows()).
//   2. Then any ONE of:
//      - an "everything" or "across school" action           -> any class
//      - the user teaches the class (role 'Teacher')         -> that class
//      - an "in department" action AND the user is a
//        Coordinator, Assistant Coordinator or Teacher
//        (Curriculum) of the class's department              -> that class
//
//   Both "Edit Markbook_*" and the proposed "Enter Markbook Data_*" actions
//   (forum thread 15560: grade entry separate from column editing) are
//   accepted at each level. Until that change is in core, no school has the
//   Enter actions, so behaviour is exactly core's today. Once it is, a role
//   with only Enter Markbook Data_* can enter grades here, and column
//   editing stays on the website for the roles that hold Edit Markbook_*.
//
// NOT ported (the app shows a note and the website does these):
//   - rubrics, uploaded responses, homework submissions
//   Existing uploaded responses are always kept unchanged.
//
// DIFFERENCES FROM CORE (deliberate, all stricter):
//   - A save only writes students who are currently enrolled in the class
//     with role 'Student' (core trusts the posted gibbonPersonIDs).
//   - A grade that is not on the column's scale is rejected for that
//     student instead of being written without a descriptor.
//   - Students not sent in a save are left untouched (core's form always
//     posts every student, so the outcome is the same for a full save).

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim($input['action'] ?? '');

function gibbonConnectEntryDeny(string $message): void
{
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function gibbonConnectSetting(PDO $pdo, string $scope, string $name): string
{
    $stmt = $pdo->prepare("SELECT value FROM gibbonSetting WHERE scope = ? AND name = ? LIMIT 1");
    $stmt->execute([$scope, $name]);
    $value = $stmt->fetchColumn();

    return $value === false ? '' : (string) $value;
}

/**
 * Names of the Markbook actions this role holds for markbook_edit_data.php.
 * Same query as core's ActionGateway::selectModuleActionsByRole().
 */
function gibbonConnectEntryActions(PDO $pdo, $roleID): array
{
    if (empty($roleID)) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT gibbonAction.name
        FROM gibbonAction
        JOIN gibbonModule ON (gibbonModule.gibbonModuleID = gibbonAction.gibbonModuleID)
        JOIN gibbonPermission ON (gibbonPermission.gibbonActionID = gibbonAction.gibbonActionID)
        WHERE gibbonAction.URLList LIKE '%markbook_edit_data.php%'
        AND gibbonPermission.gibbonRoleID = ?
        AND gibbonModule.name = 'Markbook'
        AND gibbonModule.active = 'Y'
    ");
    $stmt->execute([$roleID]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * MarkbookGate::canEditMarkbook(), for a person and role instead of a session.
 */
function gibbonConnectCanEnterMarkbook(PDO $pdo, array $actions, $personID, $classID): bool
{
    if ($classID === '' || empty($actions)) {
        return false;
    }

    $classStmt = $pdo->prepare("
        SELECT gibbonCourse.gibbonDepartmentID
        FROM gibbonCourseClass
        JOIN gibbonCourse ON (gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID)
        WHERE gibbonCourseClass.gibbonCourseClassID = ?
        LIMIT 1
    ");
    $classStmt->execute([$classID]);
    $class = $classStmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        return false;
    }

    $wholeSchool = [
        'Edit Markbook_everything', 'Edit Markbook_multipleClassesAcrossSchool', 'Manage Weightings_everything',
        'Enter Markbook Data_everything', 'Enter Markbook Data_multipleClassesAcrossSchool',
    ];
    if (array_intersect($wholeSchool, $actions)) {
        return true;
    }

    $teacherStmt = $pdo->prepare("
        SELECT 1 FROM gibbonCourseClassPerson
        WHERE gibbonCourseClassID = ? AND gibbonPersonID = ? AND role = 'Teacher'
        LIMIT 1
    ");
    $teacherStmt->execute([$classID, $personID]);
    if ($teacherStmt->fetchColumn()) {
        return true;
    }

    $department = ['Edit Markbook_multipleClassesInDepartment', 'Enter Markbook Data_multipleClassesInDepartment'];
    if (!array_intersect($department, $actions) || empty($class['gibbonDepartmentID'])) {
        return false;
    }

    $deptStmt = $pdo->prepare("
        SELECT 1 FROM gibbonDepartmentStaff
        WHERE gibbonDepartmentID = ? AND gibbonPersonID = ?
        AND role IN ('Coordinator', 'Assistant Coordinator', 'Teacher (Curriculum)')
        LIMIT 1
    ");
    $deptStmt->execute([$class['gibbonDepartmentID'], $personID]);

    return (bool) $deptStmt->fetchColumn();
}

/** The column, only if it belongs to the class (MarkbookGate::fetchColumn). */
function gibbonConnectEntryColumn(PDO $pdo, $columnID, $classID): ?array
{
    $stmt = $pdo->prepare("
        SELECT gibbonMarkbookColumn.*, gibbonUnit.name AS unitName,
            attainmentScale.name AS scaleNameAttainment, attainmentScale.usage AS usageAttainment,
            attainmentScale.lowestAcceptable AS lowestAcceptableAttainment,
            effortScale.name AS scaleNameEffort, effortScale.usage AS usageEffort,
            effortScale.lowestAcceptable AS lowestAcceptableEffort,
            gibbonCourseClass.gibbonScaleIDTarget
        FROM gibbonMarkbookColumn
        JOIN gibbonCourseClass ON (gibbonCourseClass.gibbonCourseClassID = gibbonMarkbookColumn.gibbonCourseClassID)
        LEFT JOIN gibbonUnit ON (gibbonUnit.gibbonUnitID = gibbonMarkbookColumn.gibbonUnitID)
        LEFT JOIN gibbonScale AS attainmentScale ON (attainmentScale.gibbonScaleID = gibbonMarkbookColumn.gibbonScaleIDAttainment)
        LEFT JOIN gibbonScale AS effortScale ON (effortScale.gibbonScaleID = gibbonMarkbookColumn.gibbonScaleIDEffort)
        WHERE gibbonMarkbookColumn.gibbonMarkbookColumnID = ?
        AND gibbonMarkbookColumn.gibbonCourseClassID = ?
        LIMIT 1
    ");
    $stmt->execute([$columnID, $classID]);
    $column = $stmt->fetch(PDO::FETCH_ASSOC);

    return $column ?: null;
}

/** Grades of a scale in core's dropdown order (createSelectGradeScaleGrade). */
function gibbonConnectScaleGrades(PDO $pdo, $scaleID): array
{
    if (empty($scaleID)) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT value, descriptor, sequenceNumber, isDefault
        FROM gibbonScaleGrade WHERE gibbonScaleID = ? ORDER BY sequenceNumber
    ");
    $stmt->execute([$scaleID]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Students on the entry form — same query and filters as core's form. */
function gibbonConnectEntryStudents(PDO $pdo, $classID, $columnID, $schoolYearID): array
{
    $stmt = $pdo->prepare("
        SELECT gibbonPerson.gibbonPersonID, gibbonPerson.title, gibbonPerson.surname, gibbonPerson.preferredName,
            gibbonStudentEnrolment.rollOrder, gibbonScaleGrade.value AS targetScaleGrade,
            gibbonMarkbookEntry.modifiedAssessment, gibbonMarkbookEntry.attainmentValue,
            gibbonMarkbookEntry.attainmentValueRaw, gibbonMarkbookEntry.attainmentDescriptor,
            gibbonMarkbookEntry.attainmentConcern, gibbonMarkbookEntry.effortValue,
            gibbonMarkbookEntry.effortDescriptor, gibbonMarkbookEntry.effortConcern,
            gibbonMarkbookEntry.comment, gibbonMarkbookEntry.response
        FROM gibbonCourseClassPerson
        JOIN gibbonCourseClass ON (gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
        JOIN gibbonPerson ON (gibbonCourseClassPerson.gibbonPersonID = gibbonPerson.gibbonPersonID)
        JOIN gibbonStudentEnrolment ON (gibbonStudentEnrolment.gibbonPersonID = gibbonCourseClassPerson.gibbonPersonID)
        LEFT JOIN gibbonMarkbookEntry ON (gibbonMarkbookEntry.gibbonMarkbookColumnID = :columnID AND gibbonMarkbookEntry.gibbonPersonIDStudent = gibbonCourseClassPerson.gibbonPersonID)
        LEFT JOIN gibbonMarkbookTarget ON (gibbonMarkbookTarget.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID AND gibbonMarkbookTarget.gibbonPersonIDStudent = gibbonPerson.gibbonPersonID)
        LEFT JOIN gibbonScaleGrade ON (gibbonMarkbookTarget.gibbonScaleGradeID = gibbonScaleGrade.gibbonScaleGradeID)
        WHERE gibbonStudentEnrolment.gibbonSchoolYearID = :schoolYearID
        AND gibbonCourseClassPerson.gibbonCourseClassID = :classID
        AND gibbonPerson.status = 'Full' AND gibbonCourseClassPerson.role = 'Student'
        AND (gibbonPerson.dateStart IS NULL OR gibbonPerson.dateStart <= :today)
        AND (gibbonPerson.dateEnd IS NULL OR gibbonPerson.dateEnd >= :today)
        ORDER BY gibbonPerson.surname, gibbonPerson.preferredName
    ");
    $stmt->execute([
        'columnID' => $columnID,
        'schoolYearID' => $schoolYearID,
        'classID' => $classID,
        'today' => date('Y-m-d'),
    ]);

    // One row per student, first row wins — what core gets from
    // FETCH_GROUP|FETCH_UNIQUE on gibbonPersonID.
    $students = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $students[$row['gibbonPersonID']] = $students[$row['gibbonPersonID']] ?? $row;
    }

    return array_values($students);
}

/**
 * Port of Alert::recalculateAlerts() for the Academic type only — the one
 * alert a markbook save can change. Same thresholds, levels and 60-day window.
 */
function gibbonConnectRecalculateAcademicAlert(PDO $pdo, $schoolYearID, $studentID): void
{
    $typeStmt = $pdo->query("SELECT * FROM gibbonAlertType WHERE name = 'Academic' LIMIT 1");
    $type = $typeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$type || $type['active'] != 'Y' || $type['automatic'] != 'Y') {
        return; // core: isAutomaticAlertActive('Academic') — nothing is recalculated
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM gibbonMarkbookEntry
        JOIN gibbonMarkbookColumn ON (gibbonMarkbookEntry.gibbonMarkbookColumnID = gibbonMarkbookColumn.gibbonMarkbookColumnID)
        JOIN gibbonCourseClass ON (gibbonMarkbookColumn.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
        JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID)
        WHERE gibbonCourse.gibbonSchoolYearID = :schoolYearID
        AND gibbonMarkbookEntry.gibbonPersonIDStudent = :studentID
        AND (gibbonMarkbookEntry.attainmentConcern = 'Y' OR gibbonMarkbookEntry.effortConcern = 'Y')
        AND gibbonMarkbookColumn.complete = 'Y'
        AND gibbonMarkbookColumn.completeDate <= :today
        AND gibbonMarkbookColumn.completeDate > :since
    ");
    $countStmt->execute([
        'schoolYearID' => $schoolYearID,
        'studentID' => $studentID,
        'today' => date('Y-m-d'),
        'since' => date('Y-m-d', time() - (24 * 60 * 60 * 60)),
    ]);
    $count = (int) $countStmt->fetchColumn();

    $level = null;
    if (!empty($type['thresholdHigh']) && !empty($type['thresholdMed']) && !empty($type['thresholdLow'])) {
        if ($count >= $type['thresholdHigh']) {
            $level = ['id' => 1, 'level' => 'High'];
        } elseif ($count >= $type['thresholdMed']) {
            $level = ['id' => 2, 'level' => 'Medium'];
        } elseif ($count >= $type['thresholdLow']) {
            $level = ['id' => 3, 'level' => 'Low'];
        }
    }

    $existingStmt = $pdo->prepare("
        SELECT gibbonAlert.gibbonAlertID FROM gibbonAlert
        JOIN gibbonAlertType ON (gibbonAlertType.gibbonAlertTypeID = gibbonAlert.gibbonAlertTypeID)
        WHERE gibbonAlert.gibbonSchoolYearID = ? AND gibbonAlert.gibbonPersonID = ?
        AND gibbonAlert.context = 'Automatic' AND gibbonAlertType.name = 'Academic'
        LIMIT 1
    ");
    $existingStmt->execute([$schoolYearID, $studentID]);
    $existingID = $existingStmt->fetchColumn();

    $values = [
        'gibbonAlertTypeID' => $type['gibbonAlertTypeID'],
        'gibbonAlertLevelID' => $level['id'] ?? null,
        'type' => $type['name'],
        'level' => $level['level'] ?? null,
    ];

    if ($existingID && $level) {
        $pdo->prepare("
            UPDATE gibbonAlert SET gibbonAlertTypeID = :gibbonAlertTypeID, gibbonAlertLevelID = :gibbonAlertLevelID,
                context = 'Automatic', status = 'Approved', type = :type, level = :level
            WHERE gibbonAlertID = :gibbonAlertID
        ")->execute($values + ['gibbonAlertID' => $existingID]);
    } elseif ($existingID && !$level) {
        $pdo->prepare("DELETE FROM gibbonAlert WHERE gibbonAlertID = ?")->execute([$existingID]);
    } elseif (!$existingID && $level) {
        $pdo->prepare("
            INSERT INTO gibbonAlert SET gibbonAlertTypeID = :gibbonAlertTypeID, gibbonAlertLevelID = :gibbonAlertLevelID,
                context = 'Automatic', status = 'Approved', type = :type, level = :level,
                gibbonSchoolYearID = :gibbonSchoolYearID, gibbonPersonID = :gibbonPersonID
        ")->execute($values + ['gibbonSchoolYearID' => $schoolYearID, 'gibbonPersonID' => $studentID]);
    }
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

    $schoolYearID = $authUser['schoolYearID'];
    $personID = $authUser['personID'];
    $entryActions = gibbonConnectEntryActions($pdo, $authUser['roleID'] ?? '');

    $classID = trim((string) ($input['classID'] ?? ''));

    // ── ENTRY COLUMNS: can this user enter grades here, and in which columns ──
    // Every column of the class, marked or not (core's markbook_view lists
    // unmarked columns to editors too). The app calls this when a teacher
    // opens a class, and shows "Enter grades" only when canEnter is true.
    if ($action === 'entry_columns') {
        if ($classID === '') {
            echo json_encode(['success' => false, 'message' => 'classID required']);
            exit;
        }
        if (!gibbonConnectCanEnterMarkbook($pdo, $entryActions, $personID, $classID)) {
            echo json_encode(['success' => true, 'canEnter' => false, 'columns' => []]);
            exit;
        }

        $enableGroupByTerm = gibbonConnectSetting($pdo, 'Markbook', 'enableGroupByTerm') === 'Y';

        $stmt = $pdo->prepare("
            SELECT gibbonMarkbookColumn.gibbonMarkbookColumnID, gibbonMarkbookColumn.name, gibbonMarkbookColumn.type,
                gibbonMarkbookColumn.description, gibbonMarkbookColumn.date, gibbonMarkbookColumn.complete,
                gibbonMarkbookColumn.completeDate, gibbonSchoolYearTerm.name AS termName,
                (SELECT COUNT(*) FROM gibbonMarkbookEntry
                    WHERE gibbonMarkbookEntry.gibbonMarkbookColumnID = gibbonMarkbookColumn.gibbonMarkbookColumnID
                    AND ((gibbonMarkbookEntry.attainmentValue IS NOT NULL AND gibbonMarkbookEntry.attainmentValue <> '')
                        OR (gibbonMarkbookEntry.effortValue IS NOT NULL AND gibbonMarkbookEntry.effortValue <> '')
                        OR (gibbonMarkbookEntry.comment IS NOT NULL AND gibbonMarkbookEntry.comment <> ''))
                ) AS enteredCount
            FROM gibbonMarkbookColumn
            LEFT JOIN gibbonSchoolYearTerm ON (gibbonSchoolYearTerm.gibbonSchoolYearTermID = gibbonMarkbookColumn.gibbonSchoolYearTermID)
            WHERE gibbonMarkbookColumn.gibbonCourseClassID = ?
            ORDER BY gibbonMarkbookColumn.sequenceNumber, gibbonMarkbookColumn.date, gibbonMarkbookColumn.gibbonMarkbookColumnID
        ");
        $stmt->execute([$classID]);
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$enableGroupByTerm) {
            foreach ($columns as &$c) {
                $c['termName'] = null;
            }
            unset($c);
        }

        echo json_encode(['success' => true, 'canEnter' => true, 'columns' => $columns]);
        exit;
    }

    $columnID = trim((string) ($input['columnID'] ?? ''));
    if ($action === 'entry_column' || $action === 'entry_save') {
        if ($classID === '' || $columnID === '') {
            echo json_encode(['success' => false, 'message' => 'classID and columnID required']);
            exit;
        }
        if (!gibbonConnectCanEnterMarkbook($pdo, $entryActions, $personID, $classID)) {
            gibbonConnectEntryDeny('Not permitted to enter grades for this class');
        }
        $column = gibbonConnectEntryColumn($pdo, $columnID, $classID);
        if (!$column) {
            gibbonConnectEntryDeny('The selected column does not exist, or you do not have access to it.');
        }
    }

    // Settings both actions use — read once, same names as core.
    $enableEffort = gibbonConnectSetting($pdo, 'Markbook', 'enableEffort');
    $enableRubrics = gibbonConnectSetting($pdo, 'Markbook', 'enableRubrics');
    $enableRawAttainment = gibbonConnectSetting($pdo, 'Markbook', 'enableRawAttainment');
    $enableModifiedAssessment = gibbonConnectSetting($pdo, 'Markbook', 'enableModifiedAssessment');
    $personalisedWarnings = gibbonConnectSetting($pdo, 'Markbook', 'personalisedWarnings');

    // ── ENTRY COLUMN: everything the entry form shows for one column ──────
    if ($action === 'entry_column') {
        $hasAttainment = $column['attainment'] == 'Y';
        $hasRawAttainment = $hasAttainment && $column['attainmentRaw'] == 'Y' && !empty($column['attainmentRawMax']) && $enableRawAttainment == 'Y';
        // Core's form shows the effort dropdown whenever the column has effort
        // on (the process then ignores it if enableEffort is off). Show it
        // only when it will actually be saved.
        $hasEffort = $column['effort'] == 'Y' && $enableEffort == 'Y';
        $hasComment = $column['comment'] == 'Y';

        $attainmentName = gibbonConnectSetting($pdo, 'Markbook', 'attainmentAlternativeName');
        $attainmentAbbrev = gibbonConnectSetting($pdo, 'Markbook', 'attainmentAlternativeNameAbrev');
        $effortName = gibbonConnectSetting($pdo, 'Markbook', 'effortAlternativeName');
        $effortAbbrev = gibbonConnectSetting($pdo, 'Markbook', 'effortAlternativeNameAbrev');

        $students = gibbonConnectEntryStudents($pdo, $classID, $columnID, $schoolYearID);

        // Modified assessment: core shows the checkbox for a student with an
        // Individual Needs descriptor now, or one already marked before.
        $inStudents = [];
        if ($enableModifiedAssessment == 'Y' && !empty($students)) {
            $ids = array_column($students, 'gibbonPersonID');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $inStmt = $pdo->prepare("SELECT DISTINCT gibbonPersonID FROM gibbonINPersonDescriptor WHERE gibbonPersonID IN ($placeholders)");
            $inStmt->execute($ids);
            $inStudents = array_flip(array_map('strval', $inStmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        $hasTarget = !empty($column['gibbonScaleIDTarget']);
        $rows = [];
        foreach ($students as $s) {
            $rows[] = [
                'personID' => $s['gibbonPersonID'],
                'title' => $s['title'],
                'preferredName' => $s['preferredName'],
                'surname' => $s['surname'],
                'rollOrder' => $s['rollOrder'],
                'target' => $hasTarget ? $s['targetScaleGrade'] : null,
                'modifiedAssessmentEligible' => $enableModifiedAssessment == 'Y'
                    && (isset($inStudents[(string) $s['gibbonPersonID']]) || $s['modifiedAssessment'] !== null),
                'modifiedAssessment' => $s['modifiedAssessment'],
                'attainmentValue' => $s['attainmentValue'],
                'attainmentValueRaw' => $s['attainmentValueRaw'],
                'attainmentDescriptor' => $s['attainmentDescriptor'],
                'attainmentConcern' => $s['attainmentConcern'],
                'effortValue' => $s['effortValue'],
                'effortDescriptor' => $s['effortDescriptor'],
                'effortConcern' => $s['effortConcern'],
                'comment' => $s['comment'],
                'hasResponse' => !empty($s['response']),
            ];
        }

        echo json_encode([
            'success' => true,
            'column' => [
                'columnID' => $column['gibbonMarkbookColumnID'],
                'name' => $column['name'],
                'description' => $column['description'],
                'type' => $column['type'],
                'unitName' => $column['unitName'],
                'date' => $column['date'],
                'complete' => $column['complete'],
                'completeDate' => $column['completeDate'],
                'viewableStudents' => $column['viewableStudents'],
                'viewableParents' => $column['viewableParents'],
            ],
            'attainment' => !$hasAttainment ? null : [
                'name' => ($attainmentName !== '' && $attainmentAbbrev !== '') ? $attainmentName : 'Attainment',
                'abbrev' => ($attainmentName !== '' && $attainmentAbbrev !== '') ? $attainmentAbbrev : 'Att',
                'scaleName' => $column['scaleNameAttainment'],
                'usage' => $column['usageAttainment'],
                'grades' => gibbonConnectScaleGrades($pdo, $column['gibbonScaleIDAttainment']),
                'raw' => $hasRawAttainment,
                'rawMax' => $hasRawAttainment ? (float) $column['attainmentRawMax'] : null,
                // Core's script turns a raw mark into a percentage grade only
                // for scales whose name contains "percent".
                'percentScale' => $hasRawAttainment && stripos((string) $column['scaleNameAttainment'], 'percent') !== false,
                'rubric' => !empty($column['gibbonRubricIDAttainment']) && $enableRubrics == 'Y',
            ],
            'effort' => !$hasEffort ? null : [
                'name' => ($effortName !== '' && $effortAbbrev !== '') ? $effortName : 'Effort',
                'abbrev' => ($effortName !== '' && $effortAbbrev !== '') ? $effortAbbrev : 'Eff',
                'scaleName' => $column['scaleNameEffort'],
                'usage' => $column['usageEffort'],
                'grades' => gibbonConnectScaleGrades($pdo, $column['gibbonScaleIDEffort']),
                'rubric' => !empty($column['gibbonRubricIDEffort']) && $enableRubrics == 'Y',
            ],
            'comment' => $hasComment,
            'uploadedResponse' => $column['uploadedResponse'] == 'Y',
            'modifiedAssessment' => $enableModifiedAssessment == 'Y',
            'hasTarget' => $hasTarget,
            'students' => $rows,
        ]);
        exit;
    }

    // ── ENTRY SAVE: port of markbook_edit_dataProcess.php ────────────────
    // Input:
    //   classID, columnID
    //   completeDate  'YYYY-MM-DD' marks the column complete from that date
    //                 (it goes live then); '' or null = not complete. Always
    //                 sent by the app, prefilled with the current value.
    //   entries       [{personID, attainmentValue, attainmentValueRaw,
    //                   effortValue, comment, modifiedAssessment}]
    //                 A key left out keeps that field's saved value.
    if ($action === 'entry_save') {
        $entries = $input['entries'] ?? [];
        if (!is_array($entries)) {
            echo json_encode(['success' => false, 'message' => 'entries must be a list']);
            exit;
        }

        // Column live date — checked before anything is written. Same as
        // core: a date marks the column complete, an empty value un-marks
        // it. If the app leaves completeDate out altogether, the column's
        // current state is kept (core's form always sends it).
        $completeDate = array_key_exists('completeDate', $input)
            ? trim((string) ($input['completeDate'] ?? ''))
            : (string) ($column['completeDate'] ?? '');
        if ($completeDate === '') {
            $completeDate = null;
            $complete = 'N';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $completeDate) && strtotime($completeDate) !== false) {
            $complete = 'Y';
        } else {
            echo json_encode(['success' => false, 'message' => 'completeDate must be YYYY-MM-DD']);
            exit;
        }

        $attainment = $column['attainment'];
        $gibbonScaleIDAttainment = $column['gibbonScaleIDAttainment'] ?? '';
        if ($enableEffort != 'Y') {
            $effort = 'N';
            $gibbonScaleIDEffort = '';
        } else {
            $effort = $column['effort'] ?? '';
            $gibbonScaleIDEffort = $column['gibbonScaleIDEffort'] ?? '';
        }
        $comment = $column['comment'] ?? '';
        $hasRawAttainment = $attainment == 'Y' && $column['attainmentRaw'] == 'Y' && !empty($column['attainmentRawMax']) && $enableRawAttainment == 'Y';
        $gibbonScaleIDTarget = $column['gibbonScaleIDTarget'] ?? '';
        $lowestAcceptableAttainment = $column['lowestAcceptableAttainment'] ?? '';
        $lowestAcceptableEffort = $column['lowestAcceptableEffort'] ?? '';

        // Grade lookups by value, for descriptor / sequence (core queries
        // gibbonScaleGrade per student; one load per scale gives the same rows).
        $attainmentGrades = [];
        foreach (gibbonConnectScaleGrades($pdo, $gibbonScaleIDAttainment) as $g) {
            $attainmentGrades[(string) $g['value']] = $g;
        }
        $effortGrades = [];
        foreach (gibbonConnectScaleGrades($pdo, $gibbonScaleIDEffort) as $g) {
            $effortGrades[(string) $g['value']] = $g;
        }

        // Only students on the entry form may be written.
        $allowed = [];
        foreach (gibbonConnectEntryStudents($pdo, $classID, $columnID, $schoolYearID) as $s) {
            $allowed[(int) $s['gibbonPersonID']] = $s;
        }

        $inStudents = [];
        if ($enableModifiedAssessment == 'Y' && !empty($allowed)) {
            $ids = array_keys($allowed);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $inStmt = $pdo->prepare("SELECT DISTINCT gibbonPersonID FROM gibbonINPersonDescriptor WHERE gibbonPersonID IN ($placeholders)");
            $inStmt->execute($ids);
            $inStudents = array_flip(array_map('intval', $inStmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        $targetStmt = $pdo->prepare("
            SELECT gibbonScaleGrade.value, gibbonScaleGrade.sequenceNumber
            FROM gibbonMarkbookTarget
            JOIN gibbonScaleGrade ON (gibbonMarkbookTarget.gibbonScaleGradeID = gibbonScaleGrade.gibbonScaleGradeID)
            WHERE gibbonMarkbookTarget.gibbonCourseClassID = ? AND gibbonMarkbookTarget.gibbonPersonIDStudent = ?
        ");
        $existingStmt = $pdo->prepare("SELECT * FROM gibbonMarkbookEntry WHERE gibbonMarkbookColumnID = ? AND gibbonPersonIDStudent = ? LIMIT 1");
        $insertStmt = $pdo->prepare("
            INSERT INTO gibbonMarkbookEntry SET gibbonMarkbookColumnID = :gibbonMarkbookColumnID, gibbonPersonIDStudent = :gibbonPersonIDStudent,
                modifiedAssessment = :modifiedAssessment, attainmentValue = :attainmentValue, attainmentValueRaw = :attainmentValueRaw,
                attainmentDescriptor = :attainmentDescriptor, attainmentConcern = :attainmentConcern, effortValue = :effortValue,
                effortDescriptor = :effortDescriptor, effortConcern = :effortConcern, comment = :comment,
                gibbonPersonIDLastEdit = :gibbonPersonIDLastEdit, response = :attachment
        ");
        $updateStmt = $pdo->prepare("
            UPDATE gibbonMarkbookEntry SET modifiedAssessment = :modifiedAssessment, attainmentValue = :attainmentValue,
                attainmentValueRaw = :attainmentValueRaw, attainmentDescriptor = :attainmentDescriptor,
                attainmentConcern = :attainmentConcern, effortValue = :effortValue, effortDescriptor = :effortDescriptor,
                effortConcern = :effortConcern, comment = :comment, gibbonPersonIDLastEdit = :gibbonPersonIDLastEdit,
                response = :attachment
            WHERE gibbonMarkbookEntryID = :gibbonMarkbookEntryID
        ");

        $saved = 0;
        $failed = [];
        $savedStudentIDs = [];

        foreach ($entries as $entryIn) {
            $studentID = (int) ($entryIn['personID'] ?? 0);
            if (!isset($allowed[$studentID])) {
                $failed[] = ['personID' => (string) ($entryIn['personID'] ?? ''), 'message' => 'Not a student in this class'];
                continue;
            }
            $current = $allowed[$studentID];

            $existingStmt->execute([$columnID, $studentID]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Field from the app, or the saved value when the app left it out.
            $field = function (string $key) use ($entryIn, $existing) {
                if (array_key_exists($key, $entryIn)) {
                    return $entryIn[$key] === null ? '' : trim((string) $entryIn[$key]);
                }
                return $existing[$key] ?? '';
            };

            // Modified assessment
            if ($enableModifiedAssessment != 'Y') {
                $modifiedAssessment = null;
            } elseif (isset($inStudents[$studentID]) || $current['modifiedAssessment'] !== null) {
                if (array_key_exists('modifiedAssessment', $entryIn)) {
                    $v = $entryIn['modifiedAssessment'];
                    $modifiedAssessment = ($v === true || $v === 'Y' || $v === 1 || $v === '1') ? 'Y' : 'N';
                } else {
                    $modifiedAssessment = $existing['modifiedAssessment'] ?? 'N';
                }
            } else {
                $modifiedAssessment = null; // core: checkbox not shown -> NULL
            }

            // Attainment
            $attainmentDescriptor = null;
            $attainmentConcern = null;
            if ($attainment == 'N') {
                $attainmentValue = null;
                $attainmentValueRaw = null;
            } elseif ($gibbonScaleIDAttainment == '') {
                $attainmentValue = '';
                $attainmentValueRaw = '';
                $attainmentDescriptor = '';
                $attainmentConcern = '';
            } else {
                $attainmentValue = $field('attainmentValue');
                // Core posts the saved raw mark back as a hidden field when
                // raw marks are off, so the stored value is kept.
                $attainmentValueRaw = $hasRawAttainment ? $field('attainmentValueRaw') : ($existing['attainmentValueRaw'] ?? null);
                if ($hasRawAttainment && $attainmentValueRaw !== '' && !is_numeric($attainmentValueRaw)) {
                    $failed[] = ['personID' => (string) ($entryIn['personID'] ?? ''), 'message' => 'Mark must be a number'];
                    continue;
                }
                if ($attainmentValue !== '' && !isset($attainmentGrades[$attainmentValue])) {
                    $failed[] = ['personID' => (string) ($entryIn['personID'] ?? ''), 'message' => 'Grade is not on the scale'];
                    continue;
                }
            }

            // Effort
            $effortDescriptor = null;
            $effortConcern = null;
            if ($effort == 'N') {
                $effortValue = null;
            } elseif ($gibbonScaleIDEffort == '') {
                $effortValue = '';
                $effortDescriptor = '';
                $effortConcern = '';
            } else {
                $effortValue = $field('effortValue');
                if ($effortValue !== '' && !isset($effortGrades[$effortValue])) {
                    $failed[] = ['personID' => (string) ($entryIn['personID'] ?? ''), 'message' => 'Effort is not on the scale'];
                    continue;
                }
            }

            // Comment
            $commentValue = $comment != 'Y' ? null : $field('comment');

            // SET AND CALCULATE FOR ATTAINMENT
            if ($attainment == 'Y' && $gibbonScaleIDAttainment != '') {
                $targetStmt->execute([$classID, $studentID]);
                $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC);

                if ($personalisedWarnings == 'Y' && count($targets) == 1 && $attainmentValue != '' && $gibbonScaleIDAttainment == $gibbonScaleIDTarget) {
                    // With personal warnings
                    $attainmentConcern = 'N';
                    $attainmentDescriptor = '';
                    $grade = $attainmentGrades[$attainmentValue];
                    if ($grade['sequenceNumber'] > $targets[0]['sequenceNumber']) {
                        $attainmentConcern = 'Y';
                        $attainmentDescriptor = sprintf('Below personalised target of %1$s', $targets[0]['value']);
                    } else {
                        $attainmentConcern = 'P';
                        $attainmentDescriptor = sprintf('Equal to or above personalised target of %1$s', $targets[0]['value']);
                    }
                } else {
                    // Without personal warnings
                    $attainmentConcern = 'N';
                    $attainmentDescriptor = '';
                    if ($attainmentValue != '') {
                        $grade = $attainmentGrades[$attainmentValue];
                        $attainmentDescriptor = $grade['descriptor'];
                        if ($lowestAcceptableAttainment != '' && $grade['sequenceNumber'] != '' && $grade['sequenceNumber'] > $lowestAcceptableAttainment) {
                            $attainmentConcern = 'Y';
                        }
                    }
                }
            }

            // SET AND CALCULATE FOR EFFORT
            if ($effort == 'Y' && $gibbonScaleIDEffort != '') {
                $effortConcern = 'N';
                $effortDescriptor = '';
                if ($effortValue != '') {
                    $grade = $effortGrades[$effortValue];
                    $effortDescriptor = $grade['descriptor'];
                    if ($lowestAcceptableEffort != '' && $grade['sequenceNumber'] != '' && $grade['sequenceNumber'] > $lowestAcceptableEffort) {
                        $effortConcern = 'Y';
                    }
                }
            }

            $data = [
                'modifiedAssessment' => $modifiedAssessment,
                'attainmentValue' => $attainmentValue,
                'attainmentValueRaw' => $attainmentValueRaw,
                'attainmentDescriptor' => $attainmentDescriptor,
                'attainmentConcern' => $attainmentConcern,
                'effortValue' => $effortValue,
                'effortDescriptor' => $effortDescriptor,
                'effortConcern' => $effortConcern,
                'comment' => $commentValue,
                'gibbonPersonIDLastEdit' => $personID,
                // Uploaded responses are not handled in the app: keep what's there.
                'attachment' => $existing['response'] ?? null,
            ];

            if (empty($existing)) {
                $insertStmt->execute($data + ['gibbonMarkbookColumnID' => $columnID, 'gibbonPersonIDStudent' => $studentID]);
            } else {
                $updateStmt->execute($data + ['gibbonMarkbookEntryID' => $existing['gibbonMarkbookEntryID']]);
            }
            $saved++;
            $savedStudentIDs[] = $studentID;
        }

        // Update column
        $pdo->prepare("UPDATE gibbonMarkbookColumn SET completeDate = ?, complete = ? WHERE gibbonMarkbookColumnID = ?")
            ->execute([$completeDate, $complete, $columnID]);

        // ALERTS: core recalculates after each student; done after the column
        // update here so a change to the live date is counted too.
        foreach ($savedStudentIDs as $studentID) {
            gibbonConnectRecalculateAcademicAlert($pdo, $schoolYearID, $studentID);
        }

        echo json_encode([
            'success' => empty($failed),
            'saved' => $saved,
            'failed' => $failed,
            'complete' => $complete,
            'completeDate' => $completeDate,
            'message' => empty($failed)
                ? 'Your request was completed successfully.'
                : 'Your request was successful, but some data was not properly saved.',
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect markbook_entry.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
