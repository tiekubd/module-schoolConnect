<?php
/*
School Connect - Mobile API Module
Copyright (C) 2026, High Point Education
*/

// timezone.php — makes the API keep time the way Gibbon does.
//
// Gibbon runs in the school's own timezone (System Admin > System Settings
// > Timezone, stored as gibbonSetting System/timezone): core sets PHP's
// timezone from it (Gibbon\Core -> Locale::setTimezone) and writes
// timestamps such as gibbonAttendanceLogFormGroup.timestampTaken with
// PHP's date('Y-m-d H:i:s').
//
// Before 1.16.05 the API wrote those same columns with MySQL NOW(), which
// is the database server's clock, often UTC. On a school in Houston an
// attendance register taken at 20:09 was recorded as 02:09 the next
// morning, and "today" could be the wrong day. gibbonConnectApplyTimezone()
// fixes that for every endpoint at once:
//   - PHP's timezone is set to the school's (date(), strtotime());
//   - this MySQL connection's time_zone is set to the school's current UTC
//     offset, so NOW() and CURDATE() return the school's local time too.
// Called by gibbonConnectRequireAuth() (auth.php) and by login.php /
// login_google.php, which don't go through it. Never throws: if the
// setting is missing or invalid, the server's defaults stay as they were.

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

function gibbonConnectApplyTimezone(PDO $pdo): void
{
    static $applied = [];
    $key = spl_object_id($pdo);
    if (isset($applied[$key])) {
        return;
    }
    $applied[$key] = true;

    try {
        $stmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'timezone' LIMIT 1");
        $tz = trim((string) $stmt->fetchColumn());
        if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) {
            return;
        }
        date_default_timezone_set($tz);
        // Offset for this moment, e.g. "-06:00"; daylight saving is handled
        // because it's worked out fresh on every request.
        $offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
        $pdo->exec("SET time_zone = " . $pdo->quote($offset));
    } catch (Throwable $e) {
        // Keep the server defaults rather than failing the request.
    }
}
