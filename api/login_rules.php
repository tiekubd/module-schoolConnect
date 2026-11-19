<?php
/*
School Connect - Mobile API Module
Copyright (C) 2026, High Point Education
*/

// login_rules.php — Gibbon core's sign-in rules for the app's sign-ins,
// shared by login.php (username/email + password) and login_google.php.
// Not an endpoint itself.
//
// Follows Gibbon core v30.0.03 (src/Auth/Adapter/AuthenticationAdapter.php,
// DefaultAdapter.php, login.php):
//
//   - A wrong password adds 1 to gibbonPerson.failCount and records
//     lastFailIPAddress / lastFailTimestamp. At 3 the sign-in is refused as
//     "Too many failed logins", and the organisation administrator (plus
//     anyone listening to User Admin > "Login - Failed") is notified, once,
//     when the count reaches exactly 3.
//   - With failCount at 3 or more, even the right password is refused (and
//     the count still goes up). There is no time-out: a password reset (the
//     website's Forgot Password, or User Admin > Change Password) sets
//     failCount back to 0. Website and app attempts share the one count.
//   - Also refused, as core does: Can Login = N on the person, Can Login =
//     N on their primary role, and maintenance mode for anyone whose
//     primary role isn't Administrator.
//   - A successful sign-in sets failCount to 0 and records lastIPAddress /
//     lastTimestamp.
//   - Every attempt is written to System Admin > View Logs with core's own
//     titles ("Login - Success", "Login - Failed", "OAuth Login - Failed")
//     and reasons, under the School Connect module, with method
//     "School Connect" or "School Connect (Google)".
//
// One rule of our own: an account with two-step verification (MFA) set up
// (gibbonPerson.mfaSecret) is refused, because the app can't ask for the
// code yet, and signing in without it would bypass MFA. Core v30/v31 has
// MFA switched off in Preferences, so this should affect nobody today.
//
// Differences from core, on purpose:
//   - The administrator notification is on-screen only; core also emails it
//     to those who receive notification emails. The app's API doesn't load
//     Gibbon's mailer.
//   - Messages are short English strings for the app, not core's HTML.

// The time in the school's own timezone (System > Timezone), as core's
// date() gives, since this API doesn't load Gibbon's bootstrap.
function gcSchoolNow(PDO $pdo): string
{
    try {
        $tz = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'timezone'")->fetchColumn();
        $zone = new DateTimeZone($tz ?: date_default_timezone_get());
    } catch (Throwable $e) {
        $zone = new DateTimeZone(date_default_timezone_get());
    }
    return (new DateTime('now', $zone))->format('Y-m-d H:i:s');
}

function gcClientIP(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * One row in View Logs, as core's login.php writes it
 * (LogGateway::addLog with username, method and reason).
 */
function gcLogLoginAttempt(PDO $pdo, ?string $personID, string $title, string $username, string $method, string $reason = ''): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO gibbonLog SET
                gibbonSchoolYearID = (SELECT gibbonSchoolYearID FROM gibbonSchoolYear WHERE status = 'Current' LIMIT 1),
                gibbonModuleID = (SELECT gibbonModuleID FROM gibbonModule WHERE name = 'School Connect'),
                gibbonPersonID = :gibbonPersonID,
                title = :title,
                serialisedArray = :serialisedArray,
                ip = :ip");
        $stmt->execute([
            'gibbonPersonID'  => $personID ?: null,
            'title'           => $title,
            'serialisedArray' => serialize(['username' => $username, 'method' => $method, 'reason' => $reason]),
            'ip'              => substr(gcClientIP(), 0, 15),
        ]);
    } catch (Throwable $e) {
        // Logging must never block a sign-in.
        error_log('School Connect login log error: ' . $e->getMessage());
    }
}

/**
 * Core's notifySystemAdmin(): an on-screen notification to the
 * organisation administrator and to listeners of User Admin > Login -
 * Failed, if that event is active. Like core, an identical unread
 * notification has its count raised instead of being repeated.
 */
function gcNotifyTooManyFailedLogins(PDO $pdo, array $user): void
{
    try {
        $event = $pdo->query("SELECT gibbonNotificationEvent.gibbonNotificationEventID, gibbonNotificationEvent.active
                FROM gibbonNotificationEvent
                JOIN gibbonModule ON (gibbonNotificationEvent.moduleName = gibbonModule.name)
                WHERE gibbonNotificationEvent.moduleName = 'User Admin'
                AND gibbonNotificationEvent.event = 'Login - Failed'
                AND gibbonModule.active = 'Y'")->fetch(PDO::FETCH_ASSOC);
        if (!$event || $event['active'] === 'N') {
            return;
        }

        $recipients = [];
        $admin = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'organisationAdministrator'")->fetchColumn();
        if (!empty($admin)) {
            $recipients[] = (int) $admin;
        }
        $listeners = $pdo->prepare("SELECT DISTINCT gibbonPerson.gibbonPersonID FROM gibbonNotificationListener
                JOIN gibbonPerson ON (gibbonNotificationListener.gibbonPersonID = gibbonPerson.gibbonPersonID)
                WHERE gibbonNotificationEventID = :eventID
                AND gibbonPerson.status = 'Full'
                AND (gibbonPerson.dateStart IS NULL OR gibbonPerson.dateStart <= :today)
                AND (gibbonPerson.dateEnd IS NULL OR gibbonPerson.dateEnd >= :today)
                AND scopeType = 'All'");
        $listeners->execute(['eventID' => $event['gibbonNotificationEventID'], 'today' => substr(gcSchoolNow($pdo), 0, 10)]);
        foreach ($listeners->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $recipients[] = (int) $id;
        }
        $recipients = array_unique($recipients);
        if (!$recipients) {
            return;
        }

        $text = 'Someone at the IP address ' . gcClientIP() . ' failed to login to account '
              . $user['username'] . ' 3 times in a row.';
        $link = '/index.php?q=/modules/User Admin/user_manage.php&search=' . $user['username'];

        $find = $pdo->prepare("SELECT gibbonNotificationID, count FROM gibbonNotification
                WHERE gibbonPersonID = :p AND text = :t AND actionLink = :l AND status = 'New'
                AND gibbonModuleID = (SELECT gibbonModuleID FROM gibbonModule WHERE name = 'User Admin')
                LIMIT 1");
        $bump = $pdo->prepare("UPDATE gibbonNotification SET count = :c, timestamp = NOW() WHERE gibbonNotificationID = :id");
        $add = $pdo->prepare("INSERT INTO gibbonNotification SET gibbonPersonID = :p,
                gibbonModuleID = (SELECT gibbonModuleID FROM gibbonModule WHERE name = 'User Admin'),
                text = :t, actionLink = :l, timestamp = NOW()");
        foreach ($recipients as $personID) {
            $find->execute(['p' => $personID, 't' => $text, 'l' => $link]);
            $row = $find->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $bump->execute(['c' => (int) $row['count'] + 1, 'id' => $row['gibbonNotificationID']]);
            } else {
                $add->execute(['p' => $personID, 't' => $text, 'l' => $link]);
            }
        }
    } catch (Throwable $e) {
        error_log('School Connect login notification error: ' . $e->getMessage());
    }
}

/** Core's updateFailCount(). Returns the new count. */
function gcRecordFailedLogin(PDO $pdo, array $user): int
{
    $count = (int) ($user['failCount'] ?? 0) + 1;
    $stmt = $pdo->prepare("UPDATE gibbonPerson SET lastFailIPAddress = ?, lastFailTimestamp = ?, failCount = ? WHERE gibbonPersonID = ?");
    $stmt->execute([gcClientIP(), gcSchoolNow($pdo), $count, $user['gibbonPersonID']]);
    if ($count === 3) {
        gcNotifyTooManyFailedLogins($pdo, $user);
    }
    return $count;
}

/** Core's updateUserData(), the parts that apply: last sign-in, count reset. */
function gcRecordSuccessfulLogin(PDO $pdo, array $user): void
{
    $stmt = $pdo->prepare("UPDATE gibbonPerson SET lastIPAddress = ?, lastTimestamp = ?, failCount = 0 WHERE gibbonPersonID = ?");
    $stmt->execute([gcClientIP(), gcSchoolNow($pdo), $user['gibbonPersonID']]);
}

/**
 * Core's verifyUserAccess(), plus the MFA refusal. Runs after the person
 * is identified (and, for a password sign-in, after the password is
 * right). Returns null if they may sign in, or [message, log reason].
 * A refusal for too many failed logins also raises the count, as core does.
 */
function gcCheckLoginAccess(PDO $pdo, array $user): ?array
{
    $role = null;
    if (!empty($user['gibbonRoleIDPrimary'])) {
        $stmt = $pdo->prepare("SELECT name, canLoginRole FROM gibbonRole WHERE gibbonRoleID = ?");
        $stmt->execute([$user['gibbonRoleIDPrimary']]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$role || empty($user['gibbonRoleIDAll'])) {
        return ['A server error occurred. Please try again later.', 'Database login error'];
    }

    if (($user['canLogin'] ?? '') !== 'Y') {
        return ['Login not permitted for this account', 'Not permitted to login'];
    }

    if (!empty($role['canLoginRole']) && $role['canLoginRole'] !== 'Y') {
        return ['Login not permitted for your role', 'Not permitted to login using primary role'];
    }

    $maintenance = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System Admin' AND name = 'maintenanceMode'")->fetchColumn();
    if ($maintenance === 'Y' && $role['name'] !== 'Administrator') {
        // Core doesn't log this one.
        return ['Cannot login during maintenance mode.', null];
    }

    if ((int) ($user['failCount'] ?? 0) >= 3) {
        gcRecordFailedLogin($pdo, $user);
        return [gcTooManyFailedLoginsMessage(), 'Too many failed logins'];
    }

    if (!empty($user['mfaSecret'])) {
        return ['This account uses two-step verification, which the app does not support yet. Please sign in on the school website.',
                'Two-step verification is not supported in the app'];
    }

    return null;
}

function gcTooManyFailedLoginsMessage(): string
{
    return 'Too many failed logins: please reset your password on the school website (Forgot Password), or ask your school\'s Gibbon administrator.';
}
