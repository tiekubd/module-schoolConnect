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

$input  = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? 'list_children');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);

    if ($authUser['role'] !== 'Parent') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not permitted']);
        exit;
    }

    // ── LIST CHILDREN ───────────────────────────────────────────────────────
    if ($action === 'list_children') {
        // Form group is resolved for the active (possibly switched) school
        // year, same as everywhere else — a child's form group can differ
        // year to year, and may be NULL if they weren't enrolled that year.
        $stmt = $pdo->prepare("
            SELECT
                p.gibbonPersonID,
                p.firstName,
                p.preferredName,
                p.surname,
                p.image_240,
                fg.name      AS formGroup,
                fg.nameShort AS formGroupShort
            FROM gibbonFamilyAdult fa
            JOIN gibbonFamilyChild fc ON fc.gibbonFamilyID = fa.gibbonFamilyID
            JOIN gibbonPerson p ON p.gibbonPersonID = fc.gibbonPersonID
            LEFT JOIN gibbonStudentEnrolment se ON (
                se.gibbonPersonID = p.gibbonPersonID
                AND se.gibbonSchoolYearID = ?
            )
            LEFT JOIN gibbonFormGroup fg ON fg.gibbonFormGroupID = se.gibbonFormGroupID
            WHERE fa.gibbonPersonID = ?
            AND   fa.childDataAccess = 'Y'
            AND   p.status = 'Full'
            ORDER BY p.firstName, p.surname
        ");
        $stmt->execute([$authUser['schoolYearID'], $authUser['personID']]);
        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'       => true,
            'children'      => $children,
            'isCurrentYear' => $authUser['isCurrentYear'],
        ]);
        exit;
    }

    // ── CHILD ACTIVITIES ────────────────────────────────────────────────────
    // Read-only "what is my child enrolled in" list — a faithful port of
    // ONLY the first branch of core's ActivityGateway::queryActivitiesByParticipant
    // (source verified, modules/Activities/src/Tables/ActivitiesViewParent.php),
    // which is a 3-way UNION ALL in core. Deliberately NOT porting the other
    // two branches:
    //   - Activities where the person appears as STAFF — not realistically
    //     relevant to a parent viewing their own child.
    //   - PENDING sign-up categories (activities the child could still choose,
    //     with date-gated eligibility windows and a choice list) — a genuinely
    //     separate, bigger feature (browsing + registering), not a simple
    //     read-only view. Flagged here as deferred, not silently dropped.
    if ($action === 'child_activities') {
        $childPersonID = trim($input['childPersonID'] ?? '');
        if (empty($childPersonID)) {
            echo json_encode(['success' => false, 'message' => 'childPersonID required']);
            exit;
        }

        // Same childDataAccess check as list_children — never trust the
        // client's claim about which child this parent is allowed to view.
        $accessCheck = $pdo->prepare("
            SELECT fa.gibbonPersonID
            FROM gibbonFamilyAdult fa
            JOIN gibbonFamilyChild fc ON fc.gibbonFamilyID = fa.gibbonFamilyID
            WHERE fa.gibbonPersonID = ? AND fc.gibbonPersonID = ?
            AND   fa.childDataAccess = 'Y' LIMIT 1
        ");
        $accessCheck->execute([$authUser['personID'], $childPersonID]);
        if (!$accessCheck->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not permitted to view this child']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT
                gibbonActivity.gibbonActivityID,
                gibbonActivity.name,
                gibbonActivity.type,
                gibbonActivityStudent.status,
                gibbonActivityCategory.name AS category
            FROM gibbonActivityStudent
            JOIN gibbonActivity ON gibbonActivity.gibbonActivityID = gibbonActivityStudent.gibbonActivityID
            LEFT JOIN gibbonActivityCategory ON (
                gibbonActivityCategory.gibbonActivityCategoryID = gibbonActivity.gibbonActivityCategoryID
            )
            WHERE gibbonActivity.gibbonSchoolYearID = ?
            AND   gibbonActivityStudent.gibbonPersonID = ?
            AND   gibbonActivity.active = 'Y'
            AND (
                gibbonActivityCategory.gibbonActivityCategoryID IS NULL
                OR (
                    CURRENT_TIMESTAMP >= gibbonActivityCategory.accessEnrolmentDate
                    AND CURRENT_TIMESTAMP >= gibbonActivityCategory.viewableDate
                    AND gibbonActivityCategory.active = 'Y'
                )
            )
            ORDER BY gibbonActivityCategory.sequenceNumber, gibbonActivity.name
        ");
        $stmt->execute([$authUser['schoolYearID'], $childPersonID]);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Day/time slots — NOT part of core's own ActivitiesViewParent (it
        // has no join to gibbonActivitySlot at all, confirmed against
        // source), so this is a deliberate addition beyond core, not a
        // fidelity port. Reuses the same table our MyTimetableScreen
        // Activities layer already relies on. Fetched as a separate query
        // rather than GROUP_CONCAT in the main query, since an activity can
        // have multiple slots (e.g. Monday AND Wednesday) and building that
        // into a single delimited string is more fragile than just grouping
        // in PHP.
        if (!empty($activities)) {
            $activityIDs = array_column($activities, 'gibbonActivityID');
            $placeholders = implode(',', array_fill(0, count($activityIDs), '?'));
            $slotStmt = $pdo->prepare("
                SELECT
                    gibbonActivitySlot.gibbonActivityID,
                    gibbonDaysOfWeek.name AS dayName,
                    gibbonActivitySlot.timeStart,
                    gibbonActivitySlot.timeEnd,
                    gibbonActivitySlot.locationExternal,
                    gibbonSpace.name AS space
                FROM gibbonActivitySlot
                JOIN gibbonDaysOfWeek ON gibbonDaysOfWeek.gibbonDaysOfWeekID = gibbonActivitySlot.gibbonDaysOfWeekID
                LEFT JOIN gibbonSpace ON gibbonSpace.gibbonSpaceID = gibbonActivitySlot.gibbonSpaceID
                WHERE gibbonActivitySlot.gibbonActivityID IN ($placeholders)
                ORDER BY gibbonDaysOfWeek.sequenceNumber, gibbonActivitySlot.timeStart
            ");
            $slotStmt->execute($activityIDs);
            $slots = $slotStmt->fetchAll(PDO::FETCH_ASSOC);

            $slotsByActivity = [];
            foreach ($slots as $slot) {
                $slotsByActivity[$slot['gibbonActivityID']][] = [
                    'day'       => $slot['dayName'],
                    'timeStart' => $slot['timeStart'],
                    'timeEnd'   => $slot['timeEnd'],
                    'location'  => $slot['space'] ?: $slot['locationExternal'],
                ];
            }
            foreach ($activities as &$activity) {
                $activity['slots'] = $slotsByActivity[$activity['gibbonActivityID']] ?? [];
            }
            unset($activity);
        }

        echo json_encode(['success' => true, 'activities' => $activities]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect parent_dashboard.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
