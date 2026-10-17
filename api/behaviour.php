<?php
/**
 * School Connect API — Behaviour
 *
 * action=get_config  — returns this school's configured Positive/Negative
 *   descriptors and severity levels (School Admin > Behaviour Settings),
 *   so the picker shows each school's real categories rather than
 *   anything hardcoded.
 * action=add_record  — inserts a new gibbonBehaviour row. Matches the
 *   field set Gibbon core's own data importer uses (resources/imports/
 *   behaviour.yml, source verified) — gibbonSchoolYearID, gibbonPersonID,
 *   date, type, descriptor, level, comment, gibbonPersonIDCreator,
 *   timestamp.
 *
 * SCOPING DECISIONS:
 *   - Create-only in this version — no edit/delete, no listing/history
 *     view. This is purely the "log it in the moment" mobile use case;
 *     managing existing records stays on the website for now.
 *   - No student-enrolment restriction on who a Teacher can log a record
 *     for — behaviour incidents commonly happen outside any class context
 *     (corridor, break, bus), unlike Attendance/Markbook which are
 *     inherently class-scoped. Any Teacher/Administrator can log a
 *     record for any student in the school.
 *   - Permission: Administrator + Teacher only — matches Gibbon core's
 *     own default permission grants for "Manage Behaviour Records_my"
 *     (source verified: defaultPermissionSupport='N'), NOT the broader
 *     Administrator/Teacher/Support Staff list used by student_search.php
 *     for a different feature.
 *   - Type is restricted to 'Positive' or 'Negative' — 'Observation' (a
 *     newer, less universally-configured type) is deliberately left out
 *     of this version.
 *   - Does NOT replicate core's form-tutor notification on Negative
 *     records (a side effect of core's own controller, not something a
 *     direct-SQL insert gets automatically) — a known, deliberate gap in
 *     this version, not a silent drop.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('School Connect behaviour.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

if (!in_array($authUser['role'], ['Administrator', 'Teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not permitted to log behaviour records']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? '');

// Comma-separated, per school (School Admin > Behaviour Settings) — one
// school's actual live data confirmed a stray leading comma on at least
// one of these settings, so every entry is trimmed and blanks dropped
// regardless of which setting it came from, rather than assuming only
// one of the three needs that handling.
function parseCommaList(?string $value): array {
    if (empty($value)) return [];
    return array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
}

try {
    if ($action === 'get_config') {
        $stmt = $pdo->prepare("
            SELECT name, value FROM gibbonSetting
            WHERE scope = 'Behaviour' AND name IN ('positiveDescriptors', 'negativeDescriptors', 'levels')
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // name => value

        echo json_encode([
            'success' => true,
            'positiveDescriptors' => parseCommaList($rows['positiveDescriptors'] ?? null),
            'negativeDescriptors' => parseCommaList($rows['negativeDescriptors'] ?? null),
            'levels' => parseCommaList($rows['levels'] ?? null),
        ]);
        exit;
    }

    if ($action === 'add_record') {
        $gibbonPersonID = trim($input['gibbonPersonID'] ?? '');
        $date = trim($input['date'] ?? date('Y-m-d'));
        $type = trim($input['type'] ?? '');
        $descriptor = trim($input['descriptor'] ?? '');
        $level = trim($input['level'] ?? '');
        $comment = trim($input['comment'] ?? '');
        $followup = trim($input['followup'] ?? '');

        if (empty($gibbonPersonID)) {
            echo json_encode(['success' => false, 'message' => 'A student is required.']);
            exit;
        }
        if (!in_array($type, ['Positive', 'Negative'], true)) {
            echo json_encode(['success' => false, 'message' => 'Type must be Positive or Negative.']);
            exit;
        }

        // Confirm the student actually exists and is a current, active
        // person — same defensive pattern used elsewhere in this app
        // (login.php etc.) rather than trusting the client-supplied ID
        // blindly.
        $checkStmt = $pdo->prepare("
            SELECT gibbonPersonID FROM gibbonPerson
            WHERE gibbonPersonID = ? AND status = 'Full' LIMIT 1
        ");
        $checkStmt->execute([$gibbonPersonID]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'The selected student could not be found.']);
            exit;
        }

        // followup is NOT NULL with no default on this table (distinct
        // from the separate gibbonBehaviourFollowUp child table) —
        // discovered via a live SQLSTATE error on first real save, not
        // documented anywhere we could find beforehand. '' is the
        // fallback for an empty submission, matching Gibbon's own
        // convention for optional NOT NULL text columns.
        $insertStmt = $pdo->prepare("
            INSERT INTO gibbonBehaviour
                (gibbonSchoolYearID, gibbonPersonID, date, type, descriptor, level, comment, followup, gibbonPersonIDCreator, timestamp)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $insertStmt->execute([
            $authUser['schoolYearID'],
            $gibbonPersonID,
            $date,
            $type,
            $descriptor !== '' ? $descriptor : null,
            $level !== '' ? $level : null,
            $comment !== '' ? $comment : null,
            $followup,
            $authUser['personID'],
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);

} catch (PDOException $e) {
    error_log('School Connect behaviour.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
