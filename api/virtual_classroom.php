<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// Generates a Jitsi join URL for a Class (by gibbonCourseClassID, used from
// My Timetable) or a specific Lesson (by gibbonPlannerEntryID, used from
// Planner). Room names are HMAC-derived from gibbonCourseClassID + a
// server-side secret (gibbonSetting scope 'School Connect') — deterministic
// (same class always resolves to the same room, no new table needed) but
// unguessable (meet.jit.si has no access control by default, so the room
// name IS the security boundary).
//
// Access check mirrors the ccp join used everywhere else in School Connect
// (my_timetable.php CLASSES LAYER, planner.php get_lesson/get_classes): the
// caller must hold a non-'Left' gibbonCourseClassPerson row for the class.

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

    // Deliberately NOT using gibbonConnectResolvePersonID/viewPersonID here,
    // unlike most other endpoints in this app. Elsewhere, "view as" lets a
    // Teacher/Admin look at read-only data (a student's attendance,
    // homework, etc) — a legitimate oversight use case. A virtual classroom
    // join is different in kind: it puts the caller INTO a live session
    // under that identity, with that person's moderator/viewer status.
    // There's no legitimate reason to join a call as someone else, so this
    // endpoint always resolves to the actual authenticated caller — closes
    // off a Teacher/Admin generating a join URL as another person for a
    // class they don't themselves belong to.
    $personID = $authUser['personID'];

    if ($action === 'get_join_url') {
        $gibbonCourseClassID = trim($input['gibbonCourseClassID'] ?? '');
        $gibbonPlannerEntryID = trim($input['gibbonPlannerEntryID'] ?? '');

        // Time gate inputs. For a Planner lesson, date/timeStart are looked
        // up from gibbonPlannerEntry below — authoritative, client input for
        // these two is ignored on that path. For My Timetable (class ID
        // given directly, no lesson), there is no single authoritative
        // "today's occurrence" of a class without re-deriving the full
        // Classes Layer grid query — so that path trusts the date/timeStart
        // the client already displays on the card. This is a soft UX gate
        // (stops someone from accidentally joining 40 minutes early), NOT a
        // security boundary — the real security check is the enrollment
        // check below, which is unaffected either way.
        $clientDate = trim($input['date'] ?? '');
        $clientTimeStart = trim($input['timeStart'] ?? '');
        $clientTimeEnd = trim($input['timeEnd'] ?? '');

        // Planner entry given instead of a class? Resolve the class first —
        // same JOIN chain as planner.php's get_lesson. Also pulls the
        // authoritative date/timeStart/timeEnd for the time gate.
        $gateDate = null;
        $gateTimeStart = null;
        $gateTimeEnd = null;
        if (empty($gibbonCourseClassID) && !empty($gibbonPlannerEntryID)) {
            $peStmt = $pdo->prepare("
                SELECT pe.gibbonCourseClassID, pe.date, pe.timeStart, pe.timeEnd
                FROM gibbonPlannerEntry pe
                WHERE pe.gibbonPlannerEntryID = ?
            ");
            $peStmt->execute([$gibbonPlannerEntryID]);
            $peRow = $peStmt->fetch(PDO::FETCH_ASSOC);
            $gibbonCourseClassID = $peRow['gibbonCourseClassID'] ?? '';
            $gateDate = $peRow['date'] ?? null;
            $gateTimeStart = $peRow['timeStart'] ?? null;
            $gateTimeEnd = $peRow['timeEnd'] ?? null;
        } elseif (!empty($clientDate) && !empty($clientTimeStart)) {
            // Timetable path — soft, client-supplied (see note above).
            $gateDate = $clientDate;
            $gateTimeStart = $clientTimeStart;
            $gateTimeEnd = $clientTimeEnd ?: null;
        }

        if (empty($gibbonCourseClassID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonCourseClassID or gibbonPlannerEntryID required']);
            exit;
        }

        // ── Time gate: room opens 10 min before start, closes 10 min after
        // end. Symmetric buffer, easy to explain to a teacher. Skipped
        // entirely if no date/timeStart was available at all (keeps old
        // callers working without the gate rather than breaking them). The
        // close side only applies if timeEnd was actually available —
        // missing timeEnd just means no upper bound, not a hard failure.
        if (!empty($gateDate) && !empty($gateTimeStart)) {
            $classStart = DateTime::createFromFormat('Y-m-d H:i:s', $gateDate . ' ' . $gateTimeStart);
            if ($classStart !== false) {
                $opensAt = (clone $classStart)->modify('-10 minutes');
                $now = new DateTime();
                if ($now < $opensAt) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'The virtual classroom opens 10 minutes before class starts, at ' . $opensAt->format('H:i') . '.',
                        'opensAt' => $opensAt->format('Y-m-d\TH:i:s'),
                    ]);
                    exit;
                }

                if (!empty($gateTimeEnd)) {
                    $classEnd = DateTime::createFromFormat('Y-m-d H:i:s', $gateDate . ' ' . $gateTimeEnd);
                    if ($classEnd !== false) {
                        $closesAt = (clone $classEnd)->modify('+10 minutes');
                        if ($now > $closesAt) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'The virtual classroom closed at ' . $closesAt->format('H:i') . '.',
                                'closedAt' => $closesAt->format('Y-m-d\TH:i:s'),
                            ]);
                            exit;
                        }
                    }
                }
            }
            // If a format didn't parse, fall through without gating rather
            // than blocking a join over a malformed date — enrollment check
            // below remains the real gate in that edge case.
        }

        // ── Access check — identical convention to CLASSES LAYER / get_lesson ──
        $accessStmt = $pdo->prepare("
            SELECT ccp.role
            FROM gibbonCourseClassPerson ccp
            WHERE ccp.gibbonCourseClassID = ? AND ccp.gibbonPersonID = ? AND ccp.role NOT LIKE '%Left%'
            LIMIT 1
        ");
        $accessStmt->execute([$gibbonCourseClassID, $personID]);
        $role = $accessStmt->fetchColumn();

        if ($role === false) {
            echo json_encode(['success' => false, 'message' => 'You are not enrolled in this class']);
            exit;
        }

        $isModerator = stripos($role, 'Teacher') !== false;

        // ── Settings, direct query (no container/gateway in this codebase) ──
        $settingStmt = $pdo->prepare("
            SELECT name, value FROM gibbonSetting
            WHERE scope = 'School Connect' AND name IN ('jitsiServerURL', 'jitsiRoomSecret', 'virtualClassroomEnabled')
        ");
        $settingStmt->execute();
        $settings = [];
        foreach ($settingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['name']] = $row['value'];
        }
        $jitsiServerURL = $settings['jitsiServerURL'] ?? 'https://meet.jit.si';
        $jitsiRoomSecret = $settings['jitsiRoomSecret'] ?? '';

        // Defaults 'N' — same reasoning as admin_settings.php's default:
        // most schools haven't actually configured jitsiRoomSecret, so
        // defaulting to enabled would mean the button appears but every
        // join attempt just fails for them anyway. This is the REAL gate
        // (the client-side check in JoinVirtualClassButton only controls
        // whether the button is shown at all — a disabled school must not
        // be able to get a working join URL even via a direct API call
        // bypassing the UI).
        $virtualClassroomEnabled = ($settings['virtualClassroomEnabled'] ?? 'N') === 'Y';
        if (!$virtualClassroomEnabled) {
            echo json_encode(['success' => false, 'message' => 'The virtual classroom feature is disabled for this school.']);
            exit;
        }

        if (empty($jitsiRoomSecret)) {
            echo json_encode(['success' => false, 'message' => 'Virtual classroom is not configured for this school yet']);
            exit;
        }

        $roomName = 'gc-' . substr(hash_hmac('sha256', 'class_' . $gibbonCourseClassID, $jitsiRoomSecret), 0, 16);

        $personStmt = $pdo->prepare("SELECT preferredName, surname FROM gibbonPerson WHERE gibbonPersonID = ?");
        $personStmt->execute([$personID]);
        $person = $personStmt->fetch(PDO::FETCH_ASSOC);
        $displayName = rawurlencode(trim(($person['preferredName'] ?? '') . ' ' . ($person['surname'] ?? '')));

        $joinURL = rtrim($jitsiServerURL, '/') . '/' . $roomName . '#userInfo.displayName=%22' . $displayName . '%22';

        echo json_encode([
            'success' => true,
            'joinURL' => $joinURL,
            'roomName' => $roomName,
            'isModerator' => $isModerator,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect virtual_classroom.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
