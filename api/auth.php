<?php
// auth.php — include AFTER cors.php, config.php, and PDO setup in every
// protected endpoint. Call gibbonConnectRequireAuth($pdo) to validate the
// request's Bearer token. On success it returns:
//   ['personID' => ..., 'role' => ..., 'category' => ..., 'roleID' => ...,
//    'hasMultipleRoles' => bool, 'schoolYearID' => ..., 'isCurrentYear' => bool]
// On failure it sends a 401 JSON response and exits — the calling endpoint
// does not need to do anything further in that case.
//
// IMPORTANT: 'role' is the NAME of whichever role the user has SELECTED
// (defaults to their gibbonRoleIDPrimary if they haven't switched). Every
// endpoint that branches on role — permission checks, "Administrator sees
// all classes vs Teacher sees their own" style queries, etc — already reads
// $authUser['role'], so role-switching works everywhere automatically once
// this resolution is correct here. No endpoint-by-endpoint sweep needed,
// unlike school year (which is used to filter query results, not just gate
// access, so each endpoint needed its own change).
//
// 'category' is Gibbon's own fixed classification for the role — 'Staff',
// 'Student', 'Parent', or 'Other' — regardless of what a school names or
// adds as a custom role (e.g. a school's own "Principal" role is still
// category 'Staff'). PREFER THIS over checking 'role' against a literal
// name list wherever the real intent is "any staff member" / "any
// student" / etc, rather than one specific named role — a hardcoded name
// list silently excludes any custom role a school has added.
//
// 'schoolYearID' is the year the user has SELECTED (defaults to the
// Current year if they haven't switched). Every endpoint that used to run:
//   SELECT gibbonSchoolYearID FROM gibbonSchoolYear WHERE status='Current' LIMIT 1
// should use $authUser['schoolYearID'] instead.

function gibbonConnectRequireAuth(PDO $pdo): array {
    // Defensive, self-healing table creation — see login.php for the full
    // rationale. Included here too as defense-in-depth: every legitimate
    // call path requires login.php to have run first (no token exists
    // without it), but this guards against the table being missing for
    // any other reason on a given request.
    static $tableChecked = false;
    if (!$tableChecked) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `gibbonConnectSession` (
              `gibbonConnectSessionID` int(11) NOT NULL AUTO_INCREMENT,
              `token` varchar(64) NOT NULL,
              `gibbonPersonID` varchar(20) NOT NULL,
              `role` varchar(50) NOT NULL,
              `selectedSchoolYearID` int(10) unsigned DEFAULT NULL,
              `selectedRoleID` varchar(20) DEFAULT NULL,
              `created` datetime NOT NULL,
              `expires` datetime NOT NULL,
              `lastUsed` datetime NOT NULL,
              PRIMARY KEY (`gibbonConnectSessionID`),
              UNIQUE KEY `token` (`token`),
              KEY `idx_gibbonConnectSession_token` (`token`),
              KEY `idx_gibbonConnectSession_expires` (`expires`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $tableChecked = true;
    }

    // Apply the school's configured timezone (gibbonSetting: System/timezone)
    // before any date/time logic runs. Without this, PHP's date()/time()
    // calls fall back to the server's raw default (often UTC), which can
    // silently disagree with both the school's actual local time and
    // whatever timezone the requesting device is in — this is what caused
    // an out-of-term date to briefly show as "today" during Timetable
    // testing. Every endpoint calls this function first, so setting it
    // here applies it everywhere with no per-endpoint changes needed.
    static $timezoneSet = false;
    if (!$timezoneSet) {
        $tzStmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'timezone' LIMIT 1");
        $timezone = $tzStmt->fetchColumn();
        date_default_timezone_set(!empty($timezone) ? $timezone : 'UTC');
        $timezoneSet = true;
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];

    // getallheaders() key casing can vary by server/SAPI, so check both.
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    // Fallback for servers that strip the Authorization header from
    // getallheaders() (some Apache/PHP-FPM configs do this) but still
    // expose it via $_SERVER.
    if (empty($authHeader) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Missing or invalid Authorization header']);
        exit;
    }
    $token = $m[1];

    $stmt = $pdo->prepare("
        SELECT gibbonPersonID, role, expires, selectedSchoolYearID, selectedRoleID
        FROM gibbonConnectSession
        WHERE token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session || strtotime($session['expires']) < time()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Session expired, please log in again']);
        exit;
    }

    // Sliding expiry: extend session on activity so active users are never
    // logged out mid-use, while idle/stolen tokens still die eventually.
    $newExpires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $update = $pdo->prepare("UPDATE gibbonConnectSession SET lastUsed = NOW(), expires = ? WHERE token = ?");
    $update->execute([$newExpires, $token]);

    // ── Resolve the active school year ──────────────────────────────────────
    $currentYearStmt = $pdo->query("
        SELECT gibbonSchoolYearID, name
        FROM gibbonSchoolYear WHERE status = 'Current' LIMIT 1
    ");
    $currentYear = $currentYearStmt->fetch(PDO::FETCH_ASSOC);
    $currentYearID = $currentYear['gibbonSchoolYearID'] ?? null;

    $activeYearID = $session['selectedSchoolYearID'] ?: $currentYearID;
    $isCurrentYear = ($activeYearID == $currentYearID);

    // Guard against a stale/deleted selected year (e.g. year was removed
    // from Gibbon after the user switched to it) — fall back to Current
    // rather than silently querying with an invalid ID everywhere.
    if (!$isCurrentYear) {
        $checkStmt = $pdo->prepare("SELECT gibbonSchoolYearID FROM gibbonSchoolYear WHERE gibbonSchoolYearID = ? LIMIT 1");
        $checkStmt->execute([$activeYearID]);
        if (!$checkStmt->fetch()) {
            $activeYearID = $currentYearID;
            $isCurrentYear = true;
        }
    }

    // ── Resolve the active role ───────────────────────────────────────────────
    $personStmt = $pdo->prepare("
        SELECT gibbonRoleIDPrimary, gibbonRoleIDAll
        FROM gibbonPerson WHERE gibbonPersonID = ? LIMIT 1
    ");
    $personStmt->execute([$session['gibbonPersonID']]);
    $personRow = $personStmt->fetch(PDO::FETCH_ASSOC);

    $primaryRoleID = $personRow['gibbonRoleIDPrimary'] ?? null;
    $allRoleIDsRaw = !empty($personRow['gibbonRoleIDAll']) ? $personRow['gibbonRoleIDAll'] : $primaryRoleID;
    $availableRoleIDs = array_values(array_filter(array_map('trim', explode(',', (string)$allRoleIDsRaw))));
    if (empty($availableRoleIDs) && $primaryRoleID) {
        $availableRoleIDs = [$primaryRoleID];
    }

    // Use the selected role only if it's still one this person actually
    // holds (guards against a role being removed from their account after
    // they switched to it) — otherwise fall back to their primary role.
    // Cast to int before comparing — gibbonConnectSession.selectedRoleID
    // (plain INT) and gibbonRole.gibbonRoleID (ZEROFILL, reached here via
    // gibbonPerson.gibbonRoleIDAll) can render differently as strings for
    // the same role (e.g. "1" vs "001"). A strict string in_array() would
    // wrongly reject a legitimate role switch and silently fall back to
    // the primary role on every request — the switch would appear to work
    // in the UI but never actually take effect server-side.
    $activeRoleID = $session['selectedRoleID'];
    $availableRoleIDsAsInts = array_map('intval', $availableRoleIDs);
    if (!$activeRoleID || !in_array((int) $activeRoleID, $availableRoleIDsAsInts, true)) {
        $activeRoleID = $primaryRoleID;
    }

    // Resolve the role ID to its name AND category.
    //
    // 'role' (name) is what every existing endpoint branches on
    // ('Administrator', 'Teacher', etc) — a school-editable label that can
    // be renamed or duplicated with a custom name (e.g. a "Principal" role
    // a school added themselves). Kept exactly as before for backward
    // compatibility.
    //
    // 'category' is new: Gibbon's own underlying classification for the
    // role — 'Staff', 'Student', 'Parent', or 'Other' — fixed regardless
    // of what a school names or adds as a custom role. This is what
    // Gibbon core itself actually keys real permission logic on (see
    // e.g. student_search.php's own "All Students" filter, which already
    // joins on gibbonRole.category = 'Student' rather than a name list).
    //
    // A hardcoded check like in_array($authUser['role'], ['Administrator',
    // 'Teacher', 'Support Staff']) silently excludes any custom-named
    // staff role (a real bug we hit: a school's "Principal" role, which
    // Gibbon itself correctly treats as category 'Staff', failed every
    // endpoint that checked the literal name list instead). New checks —
    // and existing ones as they get revisited — should prefer
    // $authUser['category'] === 'Staff' over an ever-growing, always-
    // incomplete list of literal role names.
    $roleName = $session['role']; // fallback: whatever was recorded at login
    $roleCategory = null;
    if ($activeRoleID) {
        $roleStmt = $pdo->prepare("SELECT name, category FROM gibbonRole WHERE gibbonRoleID = ? LIMIT 1");
        $roleStmt->execute([$activeRoleID]);
        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($roleRow) {
            $roleName = $roleRow['name'];
            $roleCategory = $roleRow['category'];
        }
    }

    return [
        'personID'         => $session['gibbonPersonID'],
        'role'             => $roleName,
        'category'         => $roleCategory,
        'roleID'           => $activeRoleID,
        'hasMultipleRoles' => count($availableRoleIDs) > 1,
        'schoolYearID'     => $activeYearID,
        'isCurrentYear'    => $isCurrentYear,
    ];
}

// Convenience helper: call this when an endpoint allows a request to act on
// a *different* person's data (e.g. a teacher viewing a student profile).
// Any Staff member (category, not a literal role-name list — see auth.php
// header) can access another person's data; anyone else falls back to
// their own, except a Parent viewing their own genuinely linked child
// (see below).
//
// Usage:
//   $authUser = gibbonConnectRequireAuth($pdo);
//   $targetPersonID = gibbonConnectResolvePersonID($input['personID'] ?? null, $authUser, $pdo);
//
// CHANGED: previously took an explicit $rolesAllowedToViewOthers array
// from each caller. Checked across all 8 real call sites in this codebase
// (homework_calendar.php, markbook.php, my_timetable.php, planner.php,
// reports_byStudent.php, reports_download.php, student_profile.php,
// timetable.php) — every single one passed the identical literal list,
// ['Administrator', 'Teacher', 'Support Staff'], with zero exceptions.
// That parameter never actually expressed anything narrower than "any
// Staff member" in practice, so it's been replaced with a direct category
// check here instead — fixing, in one place, the same bug we found in
// student_search.php/staff_directory.php: a school's custom-named staff
// role (e.g. "Principal") failing a literal name-list check even though
// Gibbon itself correctly classifies it as category 'Staff'.
//
// Pass $pdo to also allow a Parent to view their own child's data, verified
// against gibbonFamilyAdult/gibbonFamilyChild (childDataAccess='Y') rather
// than a blanket category check — a Parent should only ever see their own
// children, never "any student".
function gibbonConnectResolvePersonID(?string $requestedPersonID, array $authUser, ?PDO $pdo = null): string {
    if (empty($requestedPersonID)) {
        return $authUser['personID'];
    }
    if ($authUser['category'] === 'Staff') {
        return $requestedPersonID;
    }
    if ($authUser['category'] === 'Parent' && $pdo !== null) {
        $check = $pdo->prepare("
            SELECT fa.gibbonPersonID
            FROM gibbonFamilyAdult fa
            JOIN gibbonFamilyChild fc ON fc.gibbonFamilyID = fa.gibbonFamilyID
            WHERE fa.gibbonPersonID = ? AND fc.gibbonPersonID = ?
            AND fa.childDataAccess = 'Y' LIMIT 1
        ");
        $check->execute([$authUser['personID'], $requestedPersonID]);
        if ($check->fetch()) {
            return $requestedPersonID;
        }
    }
    return $authUser['personID'];
}
