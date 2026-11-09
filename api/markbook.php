<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// Access model — mirrors Gibbon core (source verified against v30/v31):
//
//   The Markbook module's "View Markbook" actions decide what a role sees,
//   NOT the role's name. Highest-precedence action wins, exactly like core's
//   getHighestGroupedAction() / Access::get()->highest():
//
//     View Markbook_allClassesAllData      any class, all columns
//     View Markbook_myClasses              only classes the user takes part in
//     View Markbook_viewMyChildrensClasses columns with viewableParents='Y'
//     View Markbook_myMarks                columns with viewableStudents='Y'
//
//   No Markbook permission at all = no access (core's student profile
//   Markbook tab also requires markbook_view on top of profile access).
//
//   class_markbook mirrors markbook_view_allClassesAllData.php + getClass():
//   only allClassesAllData (any class) or myClasses (classes where the user
//   is enrolled as role='Teacher') may open a whole-class markbook.
//
// NOTE: v31's refactored MarkbookPage uses ->allows('View Markbook_myClasses')
// instead of v30's highest-action comparison. That restricts a role holding
// BOTH allClassesAllData and myClasses to its own classes. We deliberately
// keep v30's highest-action behaviour here; revisit if core confirms the
// v31 behaviour is intended.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true);
$action  = trim($input['action'] ?? '');

/**
 * Highest-precedence "View Markbook" action the given role holds, or null.
 * Same query shape as core's ActionGateway::selectModuleActionsByRole().
 */
function gibbonConnectMarkbookHighestAction(PDO $pdo, $roleID): ?string
{
    if (empty($roleID)) {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT gibbonAction.name
        FROM gibbonAction
        JOIN gibbonModule ON (gibbonModule.gibbonModuleID = gibbonAction.gibbonModuleID)
        JOIN gibbonPermission ON (gibbonPermission.gibbonActionID = gibbonAction.gibbonActionID)
        WHERE gibbonModule.name = 'Markbook'
        AND gibbonModule.active = 'Y'
        AND gibbonAction.URLList LIKE '%markbook_view.php%'
        AND gibbonPermission.gibbonRoleID = ?
        ORDER BY gibbonAction.precedence DESC, gibbonAction.gibbonActionID
        LIMIT 1
    ");
    $stmt->execute([$roleID]);
    $name = $stmt->fetchColumn();

    return $name !== false ? $name : null;
}

function gibbonConnectDeny(string $message): void
{
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $message]);
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

    // CHANGED: was a hardcoded "WHERE status='Current'" lookup.
    $schoolYearID = $authUser['schoolYearID'];

    $highestAction = gibbonConnectMarkbookHighestAction($pdo, $authUser['roleID'] ?? '');

    // ── STUDENT GRADES: all grades for one student across all classes ──
    if ($action === 'student_grades') {
        if ($highestAction === null) {
            gibbonConnectDeny('Not permitted to view markbook');
        }

        $personID = gibbonConnectResolvePersonID(
            trim($input['personID'] ?? ''),
            $authUser,
            $pdo
        );
        if (empty($personID)) {
            echo json_encode(['success' => false, 'message' => 'personID required']);
            exit;
        }

        // Optional filters, matching Gibbon core's Markbook tab on the
        // student profile (source verified: Students/src/Profile/MarkbookPage.php,
        // formerly student_view_details.php). Scoped to the currently active
        // school year only — the real page also lets you pick from any year
        // the student was ever enrolled in, deliberately not ported here.
        $gibbonDepartmentID = trim($input['gibbonDepartmentID'] ?? '');
        $gibbonSchoolYearTermID = trim($input['gibbonSchoolYearTermID'] ?? '');
        $type = trim($input['type'] ?? '');

        $joins = '';
        $params = [];

        // myClasses: only classes the viewer takes part in (core joins a
        // second gibbonCourseClassPerson as teacherParticipant, any role).
        if ($highestAction === 'View Markbook_myClasses') {
            $joins = "JOIN gibbonCourseClassPerson AS teacherParticipant ON (
                teacherParticipant.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID
                AND teacherParticipant.gibbonPersonID = ?
            )";
            $params[] = $authUser['personID'];
        }

        $params[] = $personID;
        $params[] = $personID;
        $params[] = $schoolYearID;
        $params[] = date('Y-m-d');

        $filterClauses = '';

        // Column visibility follows the viewer's Markbook action, not the
        // student being viewed: parents see viewableParents columns,
        // students see viewableStudents columns, staff see everything.
        if ($highestAction === 'View Markbook_viewMyChildrensClasses') {
            $filterClauses .= " AND gibbonMarkbookColumn.viewableParents = 'Y'";
        } elseif ($highestAction === 'View Markbook_myMarks') {
            $filterClauses .= " AND gibbonMarkbookColumn.viewableStudents = 'Y'";
        }

        if (!empty($gibbonDepartmentID)) {
            $filterClauses .= ' AND gibbonCourse.gibbonDepartmentID = ?';
            $params[] = $gibbonDepartmentID;
        }

        // Term filter: core matches on completeDate falling inside the
        // term's dates (only when enableGroupByTerm = Y), NOT on the
        // column's gibbonSchoolYearTermID.
        if (!empty($gibbonSchoolYearTermID)) {
            $enableGroupByTerm = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'Markbook' AND name = 'enableGroupByTerm' LIMIT 1")->fetchColumn() === 'Y';
            if ($enableGroupByTerm) {
                $termStmt = $pdo->prepare("SELECT firstDay, lastDay FROM gibbonSchoolYearTerm WHERE gibbonSchoolYearTermID = ? LIMIT 1");
                $termStmt->execute([$gibbonSchoolYearTermID]);
                $term = $termStmt->fetch(PDO::FETCH_ASSOC);
                if ($term) {
                    $filterClauses .= ' AND gibbonMarkbookColumn.completeDate >= ? AND gibbonMarkbookColumn.completeDate <= ?';
                    $params[] = $term['firstDay'];
                    $params[] = $term['lastDay'];
                }
            }
        }
        if (!empty($type)) {
            $filterClauses .= ' AND gibbonMarkbookColumn.type = ?';
            $params[] = $type;
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT
                gibbonCourse.name AS courseName,
                gibbonCourse.nameShort AS courseShort,
                gibbonCourseClass.nameShort AS classShort,
                gibbonMarkbookColumn.gibbonMarkbookColumnID,
                gibbonMarkbookColumn.name AS columnName,
                gibbonMarkbookColumn.type,
                gibbonMarkbookColumn.date,
                gibbonMarkbookColumn.attainment,
                gibbonMarkbookColumn.effort,
                gibbonMarkbookColumn.comment AS commentEnabled,
                gibbonMarkbookColumn.completeDate,
                gibbonMarkbookColumn.sequenceNumber,
                gibbonMarkbookEntry.attainmentValue,
                gibbonMarkbookEntry.attainmentDescriptor,
                gibbonMarkbookEntry.effortValue,
                gibbonMarkbookEntry.effortDescriptor,
                gibbonMarkbookEntry.comment,
                gibbonSchoolYearTerm.name AS termName
            FROM gibbonMarkbookEntry
            JOIN gibbonMarkbookColumn ON (
                gibbonMarkbookColumn.gibbonMarkbookColumnID = gibbonMarkbookEntry.gibbonMarkbookColumnID
            )
            JOIN gibbonCourseClass ON (
                gibbonCourseClass.gibbonCourseClassID = gibbonMarkbookColumn.gibbonCourseClassID
            )
            JOIN gibbonCourse ON (
                gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID
            )
            $joins
            JOIN gibbonCourseClassPerson AS studentParticipant ON (
                studentParticipant.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID
                AND studentParticipant.gibbonPersonID = ?
            )
            LEFT JOIN gibbonSchoolYearTerm ON (
                gibbonSchoolYearTerm.gibbonSchoolYearTermID = gibbonMarkbookColumn.gibbonSchoolYearTermID
            )
            WHERE gibbonMarkbookEntry.gibbonPersonIDStudent = ?
            AND gibbonCourse.gibbonSchoolYearID = ?
            AND gibbonMarkbookColumn.complete = 'Y'
            AND gibbonMarkbookColumn.completeDate <= ?
            $filterClauses
            ORDER BY gibbonCourse.nameShort, gibbonCourseClass.nameShort,
                     gibbonMarkbookColumn.sequenceNumber, gibbonMarkbookColumn.completeDate
        ");
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by course
        $grouped = [];
        foreach ($entries as $e) {
            unset($e['sequenceNumber']);
            $key = $e['courseShort'] . '-' . $e['classShort'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'courseName'  => $e['courseName'],
                    'courseShort' => $e['courseShort'],
                    'classShort'  => $e['classShort'],
                    'entries'     => [],
                ];
            }
            $grouped[$key]['entries'][] = $e;
        }

        echo json_encode([
            'success' => true,
            'courses' => array_values($grouped),
            'total'   => count($entries),
        ]);
        exit;
    }

    // ── GRADES META: filter options for the student_grades screen ─────────
    // Learning Areas, Term, and Type mirror the real filters on Gibbon's
    // student profile Markbook tab (source verified). Term and Type are
    // school-configurable: 'enableGroupByTerm' and 'markbookType' are real
    // gibbonSetting rows in core, queried live rather than assumed, since a
    // school that hasn't configured markbookType shouldn't get a Type
    // filter with nothing meaningful in it. Learning Areas here is scoped
    // to the currently active school year only — see the scope note on
    // student_grades above re: multi-year history not being ported.
    if ($action === 'grades_meta') {
        if ($highestAction === null) {
            gibbonConnectDeny('Not permitted to view markbook');
        }

        $laStmt = $pdo->query("SELECT gibbonDepartmentID, name FROM gibbonDepartment WHERE type = 'Learning Area' ORDER BY name");
        $learningAreas = $laStmt->fetchAll(PDO::FETCH_ASSOC);

        $termStmt = $pdo->prepare("SELECT gibbonSchoolYearTermID, name FROM gibbonSchoolYearTerm WHERE gibbonSchoolYearID = ? ORDER BY sequenceNumber");
        $termStmt->execute([$schoolYearID]);
        $terms = $termStmt->fetchAll(PDO::FETCH_ASSOC);

        $enableGroupByTermStmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'Markbook' AND name = 'enableGroupByTerm' LIMIT 1");
        $enableGroupByTerm = $enableGroupByTermStmt->fetchColumn() === 'Y';

        $typeStmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'Markbook' AND name = 'markbookType' LIMIT 1");
        $typeSetting = $typeStmt->fetchColumn();
        $types = !empty($typeSetting) ? array_map('trim', str_getcsv($typeSetting)) : [];

        echo json_encode([
            'success' => true,
            'learningAreas' => $learningAreas,
            'terms' => $enableGroupByTerm ? $terms : [],
            'showTermFilter' => $enableGroupByTerm,
            'types' => $types,
            'showTypeFilter' => !empty($types),
        ]);
        exit;
    }

    // ── CLASS MARKBOOK: all students' grades for one class ─────────────
    if ($action === 'class_markbook') {
        if ($highestAction !== 'View Markbook_allClassesAllData' && $highestAction !== 'View Markbook_myClasses') {
            gibbonConnectDeny('Not permitted to view this markbook');
        }

        $classID = trim($input['classID'] ?? '');
        if (empty($classID)) {
            echo json_encode(['success' => false, 'message' => 'classID required']);
            exit;
        }

        // Same check as core's getClass(): allClassesAllData may open any
        // class that exists; myClasses only a class they teach.
        if ($highestAction === 'View Markbook_myClasses') {
            $classStmt = $pdo->prepare("
                SELECT gibbonCourseClass.gibbonCourseClassID
                FROM gibbonCourseClass
                JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
                WHERE gibbonCourseClass.gibbonCourseClassID = ?
                AND gibbonCourseClassPerson.gibbonPersonID = ?
                AND gibbonCourseClassPerson.role = 'Teacher'
                LIMIT 1
            ");
            $classStmt->execute([$classID, $authUser['personID']]);
        } else {
            $classStmt = $pdo->prepare("SELECT gibbonCourseClassID FROM gibbonCourseClass WHERE gibbonCourseClassID = ? LIMIT 1");
            $classStmt->execute([$classID]);
        }
        if (!$classStmt->fetch()) {
            gibbonConnectDeny('Not permitted to view this markbook');
        }

        // Get columns for this class
        $colStmt = $pdo->prepare("
            SELECT
                gibbonMarkbookColumn.gibbonMarkbookColumnID,
                gibbonMarkbookColumn.name AS columnName,
                gibbonMarkbookColumn.type,
                gibbonMarkbookColumn.date,
                gibbonMarkbookColumn.completeDate,
                gibbonMarkbookColumn.attainment,
                gibbonMarkbookColumn.effort,
                gibbonMarkbookColumn.attainmentRawMax,
                gibbonSchoolYearTerm.name AS termName
            FROM gibbonMarkbookColumn
            LEFT JOIN gibbonSchoolYearTerm ON (
                gibbonSchoolYearTerm.gibbonSchoolYearTermID = gibbonMarkbookColumn.gibbonSchoolYearTermID
            )
            WHERE gibbonMarkbookColumn.gibbonCourseClassID = ?
            AND gibbonMarkbookColumn.complete = 'Y'
            ORDER BY gibbonMarkbookColumn.sequenceNumber, gibbonMarkbookColumn.completeDate
        ");
        $colStmt->execute([$classID]);
        $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get all entries for this class
        $entryStmt = $pdo->prepare("
            SELECT
                gibbonMarkbookEntry.gibbonPersonIDStudent,
                gibbonPerson.preferredName,
                gibbonPerson.surname,
                gibbonMarkbookEntry.gibbonMarkbookColumnID,
                gibbonMarkbookEntry.attainmentValue,
                gibbonMarkbookEntry.attainmentDescriptor,
                gibbonMarkbookEntry.effortValue,
                gibbonMarkbookEntry.comment
            FROM gibbonMarkbookEntry
            JOIN gibbonMarkbookColumn ON (
                gibbonMarkbookColumn.gibbonMarkbookColumnID = gibbonMarkbookEntry.gibbonMarkbookColumnID
            )
            JOIN gibbonPerson ON (
                gibbonPerson.gibbonPersonID = gibbonMarkbookEntry.gibbonPersonIDStudent
            )
            WHERE gibbonMarkbookColumn.gibbonCourseClassID = ?
            AND gibbonMarkbookColumn.complete = 'Y'
            ORDER BY gibbonPerson.surname, gibbonPerson.preferredName
        ");
        $entryStmt->execute([$classID]);
        $entries = $entryStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build student->column->grade map
        $studentMap = [];
        foreach ($entries as $e) {
            $pid = $e['gibbonPersonIDStudent'];
            $cid = $e['gibbonMarkbookColumnID'];
            if (!isset($studentMap[$pid])) {
                $studentMap[$pid] = [
                    'personID'      => $pid,
                    'preferredName' => $e['preferredName'],
                    'surname'       => $e['surname'],
                    'grades'        => [],
                ];
            }
            $studentMap[$pid]['grades'][$cid] = [
                'attainmentValue'      => $e['attainmentValue'],
                'attainmentDescriptor' => $e['attainmentDescriptor'],
                'effortValue'          => $e['effortValue'],
                'comment'              => $e['comment'],
            ];
        }

        echo json_encode([
            'success'  => true,
            'columns'  => $columns,
            'students' => array_values($studentMap),
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect markbook.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
