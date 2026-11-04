<?php
/**
 * School Connect API — Impersonate User
 *
 * The app's version of Gibbon core's System Admin > Impersonate User
 * (modules/System Admin/impersonateUser.php + impersonateUserProcess.php,
 * v30.0.01). Every request re-checks core's four conditions:
 *
 *   1. the signed-in role has permission for core's Impersonate User action
 *      (same test as isActionAccessible: gibbonPermission for the action
 *      whose URLList includes impersonateUser.php, System Admin module);
 *   2. the signed-in username is listed in the site's config.php:
 *          $allowImpersonateUser = ['username'];
 *      Without that line nobody can impersonate, exactly as in core;
 *   3. the person is acting as their primary role (not a switched role);
 *   4. that primary role is Administrator (gibbonRoleID 001).
 *
 * Differences from core, all in the direction of caution:
 *   - The target gets a separate sign-in token that lasts 2 hours and is
 *     never extended (see auth.php). The admin's own token is untouched,
 *     so the app can return to it ("Return to my account").
 *   - Start and end are written to Gibbon's log (gibbonLog, module School
 *     Connect) with who impersonated whom. Core doesn't log this.
 *   - An impersonation can't start another impersonation.
 *
 * Actions:
 *   check  → {success, allowed, message?}
 *   search → {success, people:[{gibbonPersonID, name, username, role}]}  (search ≥ 2 chars)
 *   start  → {success, token, user, expiresInSeconds}                     (gibbonPersonID)
 *   stop   → {success}   called with the impersonation token itself
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

const GC_IMPERSONATE_SECONDS = 7200; // 2 hours

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim((string) ($input['action'] ?? 'check'));

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('School Connect impersonate.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

// Self-healing, like the module's other tables (CHANGEDB 1.16.09 creates it too).
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `gibbonConnectImpersonation` (
      `token` varchar(64) NOT NULL,
      `gibbonPersonIDImpersonator` varchar(20) NOT NULL,
      `gibbonPersonIDTarget` varchar(20) NOT NULL,
      `created` datetime NOT NULL,
      `expires` datetime NOT NULL,
      PRIMARY KEY (`token`),
      KEY `idx_gibbonConnectImpersonation_expires` (`expires`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

/** Writes one row to Gibbon's own log, the same way core's LogGateway::addLog does. */
function gcImpersonationLog(PDO $pdo, array $authUser, string $title, array $details): void
{
    try {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 15);
        $stmt = $pdo->prepare("INSERT INTO gibbonLog SET
                gibbonSchoolYearID = :gibbonSchoolYearID,
                gibbonModuleID = (SELECT gibbonModuleID FROM gibbonModule WHERE gibbonModule.name = 'School Connect'),
                gibbonPersonID = :gibbonPersonID,
                title = :title,
                serialisedArray = :serialisedArray,
                ip = :ip");
        $stmt->execute([
            'gibbonSchoolYearID' => $authUser['schoolYearID'],
            'gibbonPersonID'     => $details['impersonatorID'],
            'title'              => $title,
            'serialisedArray'    => serialize($details),
            'ip'                 => $ip,
        ]);
    } catch (PDOException $e) {
        // Logging must never block the action itself.
        error_log('School Connect impersonate.php log error: ' . $e->getMessage());
    }
}

/**
 * Core's four conditions, plus "not already impersonating".
 * Returns [allowed, message, username].
 */
function gcImpersonationAllowed(PDO $pdo, array $authUser, $allowList): array
{
    $denied = 'You do not have access to this action.';

    if (!empty($authUser['isImpersonation'])) {
        return [false, 'Return to your own account before impersonating someone else.', null];
    }

    $person = $pdo->prepare("SELECT username, gibbonRoleIDPrimary FROM gibbonPerson WHERE gibbonPersonID = ? LIMIT 1");
    $person->execute([$authUser['personID']]);
    $me = $person->fetch(PDO::FETCH_ASSOC);
    if (!$me) return [false, $denied, null];

    // 1. Permission for core's Impersonate User action, for the role in use.
    $perm = $pdo->prepare("
        SELECT 1
        FROM gibbonAction
        JOIN gibbonModule ON (gibbonModule.gibbonModuleID = gibbonAction.gibbonModuleID)
        JOIN gibbonPermission ON (gibbonPermission.gibbonActionID = gibbonAction.gibbonActionID)
        WHERE gibbonModule.name = 'System Admin'
          AND gibbonModule.active = 'Y'
          AND gibbonAction.URLList LIKE '%impersonateUser.php%'
          AND gibbonPermission.gibbonRoleID = ?
        LIMIT 1
    ");
    $perm->execute([$authUser['roleID']]);
    if (!$perm->fetchColumn()) return [false, $denied, $me['username']];

    // 2. Manually enabled in config.php for this username.
    if (empty($allowList) || !is_array($allowList) || !in_array($me['username'], $allowList, true)) {
        return [false, 'Access to this action must be manually enabled in the configuration file.', $me['username']];
    }

    // 3. Acting as the primary role.
    if ((int) $me['gibbonRoleIDPrimary'] !== (int) $authUser['roleID']) {
        return [false, $denied, $me['username']];
    }

    // 4. That primary role is Administrator, role 001.
    $role = $pdo->prepare("SELECT gibbonRoleID, name FROM gibbonRole WHERE gibbonRoleID = ? LIMIT 1");
    $role->execute([$me['gibbonRoleIDPrimary']]);
    $primary = $role->fetch(PDO::FETCH_ASSOC);
    if (!$primary || $primary['name'] !== 'Administrator' || (int) $primary['gibbonRoleID'] !== 1) {
        return [false, $denied, $me['username']];
    }

    return [true, null, $me['username']];
}

$allowImpersonateUserList = $allowImpersonateUser ?? [];

try {
    // ── STOP: end the impersonation this token belongs to ────────────────────
    if ($action === 'stop') {
        $token = $authUser['token'];
        $row = $pdo->prepare("SELECT gibbonPersonIDImpersonator, gibbonPersonIDTarget FROM gibbonConnectImpersonation WHERE token = ? LIMIT 1");
        $row->execute([$token]);
        $imp = $row->fetch(PDO::FETCH_ASSOC);
        if (!$imp) {
            echo json_encode(['success' => false, 'message' => 'This is not an impersonation session.']);
            exit;
        }
        $pdo->prepare("DELETE FROM gibbonConnectSession WHERE token = ?")->execute([$token]);
        $pdo->prepare("DELETE FROM gibbonConnectImpersonation WHERE token = ?")->execute([$token]);
        gcImpersonationLog($pdo, $authUser, 'Impersonate User - Ended', [
            'impersonatorID' => $imp['gibbonPersonIDImpersonator'],
            'targetID'       => $imp['gibbonPersonIDTarget'],
            'via'            => 'School Connect app',
        ]);
        echo json_encode(['success' => true]);
        exit;
    }

    [$allowed, $message] = gcImpersonationAllowed($pdo, $authUser, $allowImpersonateUserList);

    if ($action === 'check') {
        echo json_encode(['success' => true, 'allowed' => $allowed, 'message' => $message]);
        exit;
    }

    if (!$allowed) {
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }

    // ── SEARCH ───────────────────────────────────────────────────────────────
    if ($action === 'search') {
        $search = trim((string) ($input['search'] ?? ''));
        if (mb_strlen($search) < 2) {
            echo json_encode(['success' => true, 'people' => []]);
            exit;
        }
        $like = '%' . $search . '%';
        $stmt = $pdo->prepare("
            SELECT gibbonPerson.gibbonPersonID, gibbonPerson.title, gibbonPerson.preferredName,
                   gibbonPerson.surname, gibbonPerson.username, gibbonRole.name AS roleName
            FROM gibbonPerson
            LEFT JOIN gibbonRole ON (gibbonRole.gibbonRoleID = gibbonPerson.gibbonRoleIDPrimary)
            WHERE gibbonPerson.status = 'Full'
              AND gibbonPerson.gibbonPersonID <> :me
              AND (gibbonPerson.preferredName LIKE :s1 OR gibbonPerson.surname LIKE :s2
                   OR gibbonPerson.firstName LIKE :s3 OR gibbonPerson.username LIKE :s4
                   OR CONCAT(gibbonPerson.preferredName, ' ', gibbonPerson.surname) LIKE :s5)
            ORDER BY gibbonPerson.surname, gibbonPerson.preferredName
            LIMIT 30
        ");
        $stmt->execute(['me' => $authUser['personID'], 's1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $like]);
        $people = array_map(function ($p) {
            return [
                'gibbonPersonID' => $p['gibbonPersonID'],
                'name'           => trim($p['preferredName'] . ' ' . $p['surname']),
                'username'       => $p['username'],
                'role'           => $p['roleName'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['success' => true, 'people' => $people]);
        exit;
    }

    // ── START ────────────────────────────────────────────────────────────────
    if ($action === 'start') {
        $targetID = trim((string) ($input['gibbonPersonID'] ?? ''));
        $stmt = $pdo->prepare("
            SELECT gibbonPerson.gibbonPersonID, gibbonPerson.firstName, gibbonPerson.surname,
                   gibbonPerson.preferredName, gibbonPerson.email, gibbonPerson.username,
                   gibbonPerson.image_240, gibbonRole.name AS roleName, gibbonRole.category AS roleCategory
            FROM gibbonPerson
            LEFT JOIN gibbonRole ON (gibbonRole.gibbonRoleID = gibbonPerson.gibbonRoleIDPrimary)
            WHERE gibbonPerson.gibbonPersonID = ?
            LIMIT 1
        ");
        $stmt->execute([$targetID]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($targetID === '' || !$target) {
            echo json_encode(['success' => false, 'message' => 'That user could not be found.']);
            exit;
        }
        if ((string) $target['gibbonPersonID'] === (string) $authUser['personID']) {
            echo json_encode(['success' => false, 'message' => 'You are already signed in as this user.']);
            exit;
        }

        // School-local time throughout (auth.php set PHP's timezone from
        // gibbonSetting), never MySQL NOW(): the database server's clock can
        // be in a different timezone, and auth.php compares in PHP time.
        $token   = bin2hex(random_bytes(32));
        $now     = gibbonConnectNow();
        $expires = date('Y-m-d H:i:s', time() + GC_IMPERSONATE_SECONDS);
        $roleName = $target['roleName'] ?? 'Unknown';

        // Same columns login.php writes, so every endpoint treats this
        // session exactly like the target signing in themselves.
        $pdo->prepare("
            INSERT INTO gibbonConnectSession (token, gibbonPersonID, role, created, expires, lastUsed)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$token, $target['gibbonPersonID'], $roleName, $now, $expires, $now]);

        $pdo->prepare("
            INSERT INTO gibbonConnectImpersonation (token, gibbonPersonIDImpersonator, gibbonPersonIDTarget, created, expires)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$token, $authUser['personID'], $target['gibbonPersonID'], $now, $expires]);

        // Tidy up finished impersonations (both tables) while we're here.
        $pdo->prepare("DELETE s FROM gibbonConnectSession s JOIN gibbonConnectImpersonation i ON (i.token = s.token) WHERE i.expires < ?")->execute([$now]);
        $pdo->prepare("DELETE FROM gibbonConnectImpersonation WHERE expires < ?")->execute([$now]);

        gcImpersonationLog($pdo, $authUser, 'Impersonate User - Started', [
            'impersonatorID' => $authUser['personID'],
            'targetID'       => $target['gibbonPersonID'],
            'via'            => 'School Connect app',
            'expires'        => $expires,
        ]);

        echo json_encode([
            'success'          => true,
            'token'            => $token,
            'expiresInSeconds' => GC_IMPERSONATE_SECONDS,
            // Same shape as login.php's 'user'.
            'user'             => [
                'id'        => $target['gibbonPersonID'],
                'firstName' => $target['firstName'],
                'lastName'  => $target['surname'],
                'email'     => $target['email'],
                'username'  => $target['username'],
                'role'      => $roleName,
                'category'  => $target['roleCategory'],
                'image_240' => $target['image_240'],
            ],
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
} catch (PDOException $e) {
    error_log('School Connect impersonate.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
