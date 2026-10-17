<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// Deliberately trimmed v1 scope (see conversation): reads lesson plans only.
// NOT ported: homework display/submission workflow (its own gibbonPlannerEntryHomework
// table, drafts/versions — a separate pass), the live attendance-taking form
// embedded in Gibbon's own planner_view_full.php (redundant — this app already
// has a full, dedicated Attendance feature), Guests, Teacher's Notes, Custom Fields,
// Year Overview / school-closure-aware date view.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? '');

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

    // Same resolver already used for My Timetable — Admin/Teacher/Support Staff
    // can view lessons for any class/person; a Parent only for their own
    // genuinely linked child (verified via gibbonFamilyAdult/gibbonFamilyChild).
    $personID = gibbonConnectResolvePersonID(
        trim($input['viewPersonID'] ?? '') ?: null,
        $authUser,
        $pdo
    );
    $viewingRole = $authUser['role'];

    // ── LIST CLASSES (for the "Choose a Class" picker) ──────────────────────
    if ($action === 'get_classes') {
        $stmt = $pdo->prepare("
            SELECT cc.gibbonCourseClassID, c.nameShort AS courseNameShort, cc.nameShort AS classNameShort
            FROM gibbonCourseClassPerson ccp
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = ccp.gibbonCourseClassID
            JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            WHERE ccp.gibbonPersonID = ? AND ccp.role NOT LIKE '%Left%' AND c.gibbonSchoolYearID = ?
            ORDER BY c.nameShort, cc.nameShort
        ");
        $stmt->execute([$personID, $schoolYearID]);
        echo json_encode(['success' => true, 'classes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── LIST LESSONS — by class or by date ───────────────────────────────────
    if ($action === 'get_lessons') {
        $gibbonCourseClassID = trim($input['gibbonCourseClassID'] ?? '');
        $date = trim($input['date'] ?? '');
        $viewBy = !empty($gibbonCourseClassID) ? 'class' : 'date';
        if ($viewBy === 'date' && empty($date)) $date = date('Y-m-d');

        // A Student/Parent only ever sees lessons explicitly shared with
        // that audience — Admin/Teacher/Support Staff see everything,
        // matching Gibbon's own viewableStudents/viewableParents gate.
        $visibilityClause = '';
        if ($viewingRole === 'Student') $visibilityClause = "AND pe.viewableStudents = 'Y'";
        if ($viewingRole === 'Parent') $visibilityClause = "AND pe.viewableParents = 'Y'";

        $params = [$personID];
        if ($viewBy === 'class') {
            $classClause = "AND pe.gibbonCourseClassID = ?";
            $params[] = $gibbonCourseClassID;
            $dateClause = '';
            $order = "ORDER BY pe.date DESC, pe.timeStart DESC";
        } else {
            $classClause = '';
            $dateClause = "AND pe.date = ?";
            $params[] = $date;
            $order = "ORDER BY pe.timeStart ASC";
        }

        $stmt = $pdo->prepare("
            SELECT pe.gibbonPlannerEntryID, pe.name AS lesson, pe.date, pe.timeStart, pe.timeEnd,
                   pe.viewableStudents, pe.viewableParents,
                   c.nameShort AS courseNameShort, cc.nameShort AS classNameShort,
                   u.name AS unitName
            FROM gibbonPlannerEntry pe
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = pe.gibbonCourseClassID
            JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            JOIN gibbonCourseClassPerson ccp ON ccp.gibbonCourseClassID = pe.gibbonCourseClassID AND ccp.gibbonPersonID = ?
            LEFT JOIN gibbonUnit u ON u.gibbonUnitID = pe.gibbonUnitID
            WHERE ccp.role NOT LIKE '%Left%'
            $classClause $dateClause $visibilityClause
            $order
        ");
        $stmt->execute($params);
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'lessons' => $lessons, 'viewBy' => $viewBy, 'date' => $date]);
        exit;
    }

    // ── SINGLE LESSON — details + Smart Blocks ───────────────────────────────
    if ($action === 'get_lesson') {
        $gibbonPlannerEntryID = trim($input['gibbonPlannerEntryID'] ?? '');
        if (empty($gibbonPlannerEntryID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonPlannerEntryID required']);
            exit;
        }

        $visibilityClause = '';
        if ($viewingRole === 'Student') $visibilityClause = "AND pe.viewableStudents = 'Y'";
        if ($viewingRole === 'Parent') $visibilityClause = "AND pe.viewableParents = 'Y'";

        $stmt = $pdo->prepare("
            SELECT pe.gibbonPlannerEntryID, pe.name AS lesson, pe.date, pe.timeStart, pe.timeEnd,
                   pe.summary, pe.description, ccp.role AS myRole,
                   pe.homework, pe.homeworkDueDateTime, pe.homeworkDetails,
                   c.nameShort AS courseNameShort, cc.nameShort AS classNameShort,
                   u.name AS unitName
            FROM gibbonPlannerEntry pe
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = pe.gibbonCourseClassID
            JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            JOIN gibbonCourseClassPerson ccp ON ccp.gibbonCourseClassID = pe.gibbonCourseClassID AND ccp.gibbonPersonID = ?
            LEFT JOIN gibbonUnit u ON u.gibbonUnitID = pe.gibbonUnitID
            WHERE pe.gibbonPlannerEntryID = ? AND ccp.role NOT LIKE '%Left%'
            $visibilityClause
            LIMIT 1
        ");
        $stmt->execute([$personID, $gibbonPlannerEntryID]);
        $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lesson) {
            echo json_encode(['success' => false, 'message' => 'Lesson not found, or not accessible']);
            exit;
        }

        $blockStmt = $pdo->prepare("
            SELECT title, type, length, contents
            FROM gibbonUnitClassBlock
            WHERE gibbonPlannerEntryID = ?
            ORDER BY sequenceNumber
        ");
        $blockStmt->execute([$gibbonPlannerEntryID]);
        $blocks = $blockStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'lesson' => $lesson, 'blocks' => $blocks]);
        exit;
    }

    // ── UPCOMING HOMEWORK / DUE DATES ────────────────────────────────────────
    // Faithful port of the real PlannerEntryGateway::selectUpcomingHomeworkByStudent()
    // logic, verified directly against a live install before building this:
    //   - homework = 'Y' on the entry
    //   - viewableStudents='Y' (or viewableParents for a Parent viewer) UNLESS
    //     the viewer's own role in that class is 'Teacher' (a teacher always
    //     sees their own assigned homework regardless of that flag)
    //   - homeworkDueDateTime still in the future
    //   - the LESSON's own date/time must already be in the past — Gibbon
    //     deliberately doesn't surface homework as "upcoming" until the class
    //     that assigned it has actually happened
    //   - enrollment window covers both the lesson date and the due date
    // Deliberately NOT ported: the online-submission-status UNION branches
    // (gibbonPlannerEntryHomework/gibbonPlannerEntryStudentTracker) — that's
    // the homework submission workflow itself, already deferred separately.
    if ($action === 'get_upcoming_homework') {
        $today = date('Y-m-d');
        $now = date('H:i:s');
        $todayTime = date('Y-m-d H:i:s');
        $viewableColumn = $viewingRole === 'Parent' ? 'viewableParents' : 'viewableStudents';

        $stmt = $pdo->prepare("
            SELECT pe.gibbonPlannerEntryID, pe.name AS lesson,
                   c.nameShort AS courseNameShort, cc.nameShort AS classNameShort,
                   pe.homeworkDueDateTime
            FROM gibbonPlannerEntry pe
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = pe.gibbonCourseClassID
            JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            JOIN gibbonCourseClassPerson ccp ON ccp.gibbonCourseClassID = pe.gibbonCourseClassID AND ccp.gibbonPersonID = ?
            WHERE c.gibbonSchoolYearID = ?
            AND pe.homework = 'Y'
            AND (ccp.role = 'Teacher' OR (ccp.role = 'Student' AND pe.$viewableColumn = 'Y'))
            AND pe.homeworkDueDateTime > ?
            AND ((pe.date < ?) OR (pe.date = ? AND pe.timeEnd <= ?))
            AND (ccp.dateEnrolled IS NULL OR ccp.dateEnrolled <= pe.date)
            AND (ccp.dateUnenrolled IS NULL OR ccp.dateUnenrolled > pe.homeworkDueDateTime)
            ORDER BY pe.homeworkDueDateTime ASC
            LIMIT 10
        ");
        $stmt->execute([$personID, $schoolYearID, $todayTime, $today, $today, $now]);
        echo json_encode(['success' => true, 'homework' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── TODAY'S LESSONS (dashboard widget) ───────────────────────────────────
    // Same visibility rules as get_lessons, hardcoded to today's date, plus
    // homework/summary status and — for staff viewers — whether attendance
    // has been taken for that class today (reusing the same
    // gibbonAttendanceLogPerson check already built for attendance_history.php).
    // Simplification: checks "was attendance taken for this class today" at
    // all, not per-specific-period — fine for the common case of a class
    // meeting once a day, but won't distinguish multiple same-day periods.
    if ($action === 'get_today_lessons') {
        $today = date('Y-m-d');
        $isStaff = in_array($viewingRole, ['Administrator', 'Teacher', 'Support Staff']);

        $visibilityClause = '';
        if ($viewingRole === 'Student') $visibilityClause = "AND pe.viewableStudents = 'Y'";
        if ($viewingRole === 'Parent') $visibilityClause = "AND pe.viewableParents = 'Y'";

        $stmt = $pdo->prepare("
            SELECT pe.gibbonPlannerEntryID, pe.name AS lesson, pe.timeStart, pe.timeEnd,
                   pe.summary, pe.homework, pe.homeworkSubmission, pe.homeworkCrowdAssess,
                   c.nameShort AS courseNameShort, cc.nameShort AS classNameShort,
                   cc.gibbonCourseClassID, u.name AS unitName
            FROM gibbonPlannerEntry pe
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = pe.gibbonCourseClassID
            JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            JOIN gibbonCourseClassPerson ccp ON ccp.gibbonCourseClassID = pe.gibbonCourseClassID AND ccp.gibbonPersonID = ?
            LEFT JOIN gibbonUnit u ON u.gibbonUnitID = pe.gibbonUnitID
            WHERE pe.date = ? AND ccp.role NOT LIKE '%Left%'
            $visibilityClause
            ORDER BY pe.timeStart ASC
        ");
        $stmt->execute([$personID, $today]);
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Homework status — faithful port of the real LessonTable.php column
        // logic (source verified directly): "Yes: Teacher Recorded" shows
        // whenever homework='Y' — it's not one of several alternatives, it's
        // the base state. "+ Submission" and "+ Crowd Assessment" are
        // separate additive lines layered on top when those flags are also
        // 'Y', not different labels. NOT ported: a per-student personal
        // homework tracker ("Yes: Student Recorded" / myHomeworkDueDateTime)
        // — that's a genuinely separate feature (students can record their
        // own reminders independent of what the teacher assigned), left out
        // deliberately rather than missed.
        foreach ($lessons as &$l) {
            if (($l['homework'] ?? 'N') !== 'Y') {
                $l['homeworkStatus'] = null;
            } else {
                $status = 'Yes: Teacher Recorded';
                if (($l['homeworkSubmission'] ?? '') === 'Y') {
                    $status .= ' +Submission';
                    if (($l['homeworkCrowdAssess'] ?? '') === 'Y') {
                        $status .= ' +Crowd Assessment';
                    }
                }
                $l['homeworkStatus'] = $status;
            }
        }
        unset($l);

        if ($isStaff && !empty($lessons)) {
            $classIDs = array_unique(array_column($lessons, 'gibbonCourseClassID'));
            $placeholders = implode(',', array_fill(0, count($classIDs), '?'));
            $attStmt = $pdo->prepare("
                SELECT DISTINCT gibbonCourseClassID FROM gibbonAttendanceLogPerson
                WHERE context = 'Class' AND date = ? AND gibbonCourseClassID IN ($placeholders)
            ");
            $attStmt->execute(array_merge([$today], array_values($classIDs)));
            $takenClassIDs = array_column($attStmt->fetchAll(PDO::FETCH_ASSOC), 'gibbonCourseClassID');
            foreach ($lessons as &$l) {
                $l['attendanceTaken'] = in_array($l['gibbonCourseClassID'], $takenClassIDs);
            }
            unset($l);
        }

        echo json_encode(['success' => true, 'lessons' => $lessons, 'isStaff' => $isStaff]);
        exit;
    }

    // ── STUDENT RECORDED HOMEWORK — get ──────────────────────────────────────
    // Reads a student's own personal homework note for a lesson, from
    // gibbonPlannerEntryStudentHomework — a separate table/feature from the
    // teacher-assigned pe.homework flag (get_today_lessons/get_upcoming_homework),
    // deliberately deferred there and built here as its own pass. $personID is
    // already resolved above via gibbonConnectResolvePersonID, so a Parent can
    // read their own linked child's record; only the child themselves can write.
    if ($action === 'get_my_homework') {
        $gibbonPlannerEntryID = trim($input['gibbonPlannerEntryID'] ?? '');
        if (empty($gibbonPlannerEntryID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonPlannerEntryID required']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT homeworkDueDateTime, homeworkDetails, homeworkComplete
            FROM gibbonPlannerEntryStudentHomework
            WHERE gibbonPlannerEntryID = ? AND gibbonPersonID = ?
        ");
        $stmt->execute([$gibbonPlannerEntryID, $personID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 1) {
            echo json_encode(['success' => false, 'message' => 'Duplicate homework records found — please contact your school admin']);
            exit;
        }

        if (empty($rows)) {
            echo json_encode(['success' => true, 'homework' => 'N', 'homeworkDueDateTime' => null, 'homeworkDetails' => '', 'homeworkComplete' => null]);
        } else {
            echo json_encode(array_merge(['success' => true, 'homework' => 'Y'], $rows[0]));
        }
        exit;
    }

    // ── STUDENT RECORDED HOMEWORK — save ─────────────────────────────────────
    // Write path is deliberately self-only: Gibbon core has no admin/teacher/
    // parent write path for this feature (planner_view_full_myHomeworkProcess.php
    // only ever acts on the logged-in student), so this ignores viewPersonID
    // entirely and always acts on $authUser['personID'] — a Parent or staff
    // token can never write into someone else's record even if they send a
    // different gibbonPersonID. homework='N' deletes any existing row.
    // homework='Y' updates if a row exists, else inserts. homeworkDueDateTime
    // seconds forced to :59 and time defaults to 21:00 when omitted, matching
    // core's own logic exactly (verified against planner_view_full.php).
    if ($action === 'save_my_homework') {
        $gibbonPlannerEntryID = trim($input['gibbonPlannerEntryID'] ?? '');
        $homework = trim($input['homework'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $selfPersonID = $authUser['personID'];

        if (empty($gibbonPlannerEntryID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonPlannerEntryID required']);
            exit;
        }

        // Confirm the caller is actually enrolled as a Student in this lesson's
        // class, mirroring core's own guard in planner_view_full_myHomeworkProcess.php.
        $checkStmt = $pdo->prepare("
            SELECT pe.gibbonPlannerEntryID
            FROM gibbonPlannerEntry pe
            JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = pe.gibbonCourseClassID
            JOIN gibbonCourseClassPerson ccp ON ccp.gibbonCourseClassID = cc.gibbonCourseClassID
            WHERE pe.gibbonPlannerEntryID = ? AND ccp.gibbonPersonID = ? AND ccp.role = 'Student'
        ");
        $checkStmt->execute([$gibbonPlannerEntryID, $selfPersonID]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'You are not enrolled as a student in this lesson']);
            exit;
        }

        if ($homework === 'N') {
            $stmt = $pdo->prepare("DELETE FROM gibbonPlannerEntryStudentHomework WHERE gibbonPlannerEntryID = ? AND gibbonPersonID = ?");
            $stmt->execute([$gibbonPlannerEntryID, $selfPersonID]);
            echo json_encode(['success' => true, 'action' => 'deleted']);
            exit;
        }

        $homeworkDueDate = trim($input['homeworkDueDate'] ?? '');
        $homeworkDetails = trim($input['homeworkDetails'] ?? '');
        $timeInput = trim($input['homeworkDueDateTime'] ?? '');

        if (empty($homeworkDueDate) || empty($homeworkDetails)) {
            echo json_encode(['success' => false, 'message' => 'homeworkDueDate and homeworkDetails are required']);
            exit;
        }

        $timePart = !empty($timeInput) ? $timeInput . ':59' : '21:00:59';
        $homeworkDueDateTime = $homeworkDueDate . ' ' . $timePart;

        $existStmt = $pdo->prepare("SELECT gibbonPlannerEntryStudentHomeworkID FROM gibbonPlannerEntryStudentHomework WHERE gibbonPlannerEntryID = ? AND gibbonPersonID = ?");
        $existStmt->execute([$gibbonPlannerEntryID, $selfPersonID]);
        $existing = $existStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($existing) > 1) {
            echo json_encode(['success' => false, 'message' => 'Duplicate homework records found — please contact your school admin']);
            exit;
        }

        if (!empty($existing)) {
            $stmt = $pdo->prepare("UPDATE gibbonPlannerEntryStudentHomework SET homeworkDueDateTime = ?, homeworkDetails = ? WHERE gibbonPlannerEntryID = ? AND gibbonPersonID = ?");
            $stmt->execute([$homeworkDueDateTime, $homeworkDetails, $gibbonPlannerEntryID, $selfPersonID]);
            $actionTaken = 'updated';
        } else {
            $stmt = $pdo->prepare("INSERT INTO gibbonPlannerEntryStudentHomework (gibbonPlannerEntryID, gibbonPersonID, homeworkDueDateTime, homeworkDetails) VALUES (?, ?, ?, ?)");
            $stmt->execute([$gibbonPlannerEntryID, $selfPersonID, $homeworkDueDateTime, $homeworkDetails]);
            $actionTaken = 'created';
        }

        echo json_encode(['success' => true, 'action' => $actionTaken, 'homeworkDueDateTime' => $homeworkDueDateTime]);
        exit;
    }

    // ── STUDENT RECORDED HOMEWORK — mark complete ────────────────────────────
    // Faithful port of the 'studentRecorded' branch of planner_deadlinesAjax.php
    // (source verified directly — core's own quick-toggle action, separate from
    // its main save form). Self-only, same as save_my_homework. Update-only,
    // not insert: marking something "complete" only makes sense once the
    // student has already created their own record via save_my_homework —
    // homeworkDetails and homeworkDueDateTime are NOT NULL columns with no
    // default, so there's nothing sensible to insert if no row exists yet.
    // Existence is checked with a separate SELECT rather than relying on the
    // UPDATE's rowCount(), since rowCount() reports rows CHANGED, not rows
    // MATCHED — toggling to the same value twice would otherwise look
    // identical to "no record found".
    if ($action === 'set_homework_complete') {
        $gibbonPlannerEntryID = trim($input['gibbonPlannerEntryID'] ?? '');
        $complete = trim($input['complete'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $selfPersonID = $authUser['personID'];

        if (empty($gibbonPlannerEntryID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonPlannerEntryID required']);
            exit;
        }

        $checkStmt = $pdo->prepare("SELECT gibbonPlannerEntryStudentHomeworkID FROM gibbonPlannerEntryStudentHomework WHERE gibbonPlannerEntryID = ? AND gibbonPersonID = ?");
        $checkStmt->execute([$gibbonPlannerEntryID, $selfPersonID]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'No homework record found to update']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE gibbonPlannerEntryStudentHomework SET homeworkComplete = ? WHERE gibbonPlannerEntryID = ? AND gibbonPersonID = ?");
        $stmt->execute([$complete, $gibbonPlannerEntryID, $selfPersonID]);

        echo json_encode(['success' => true, 'homeworkComplete' => $complete]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect planner.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
