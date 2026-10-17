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

$input   = json_decode(file_get_contents('php://input'), true);
$action  = trim($input['action'] ?? '');

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

    // ── STUDENT GRADES: all grades for one student across all classes ──
    if ($action === 'student_grades') {
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
        // student profile (source verified: modules/Students/student_view_details.php).
        // Scoped to the currently active school year only — the real page
        // also lets you pick from any year the student was ever enrolled in
        // (a genuine multi-year history view), deliberately not ported here.
        $gibbonDepartmentID = trim($input['gibbonDepartmentID'] ?? '');
        $gibbonSchoolYearTermID = trim($input['gibbonSchoolYearTermID'] ?? '');
        $type = trim($input['type'] ?? '');

        $params = [$personID, $schoolYearID];
        $filterClauses = '';
        if (!empty($gibbonDepartmentID)) {
            $filterClauses .= ' AND gibbonCourse.gibbonDepartmentID = ?';
            $params[] = $gibbonDepartmentID;
        }
        if (!empty($gibbonSchoolYearTermID)) {
            $filterClauses .= ' AND gibbonMarkbookColumn.gibbonSchoolYearTermID = ?';
            $params[] = $gibbonSchoolYearTermID;
        }
        if (!empty($type)) {
            $filterClauses .= ' AND gibbonMarkbookColumn.type = ?';
            $params[] = $type;
        }

        $stmt = $pdo->prepare("
            SELECT
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
            LEFT JOIN gibbonSchoolYearTerm ON (
                gibbonSchoolYearTerm.gibbonSchoolYearTermID = gibbonMarkbookColumn.gibbonSchoolYearTermID
            )
            WHERE gibbonMarkbookEntry.gibbonPersonIDStudent = ?
            AND gibbonCourse.gibbonSchoolYearID = ?
            AND gibbonMarkbookColumn.complete = 'Y'
            AND gibbonMarkbookColumn.viewableStudents = 'Y'
            $filterClauses
            ORDER BY gibbonCourse.nameShort, gibbonMarkbookColumn.sequenceNumber,
                     gibbonMarkbookColumn.completeDate
        ");
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by course
        $grouped = [];
        foreach ($entries as $e) {
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
        if (!in_array($authUser['role'], ['Administrator', 'Teacher'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not permitted to view this markbook']);
            exit;
        }
        $classID = trim($input['classID'] ?? '');
        if (empty($classID)) {
            echo json_encode(['success' => false, 'message' => 'classID required']);
            exit;
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
