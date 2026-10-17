<?php
/**
 * School Connect API — Admin Settings
 *
 * Generic get/set for gibbonSetting rows under scope='School Connect' —
 * built generic rather than Jitsi-specific, since this is explicitly
 * described as the first of more settings to come. Adding a future
 * setting means adding its name to SETTABLE_NAMES below, not building a
 * new endpoint.
 *
 * ACCESS SPLIT: 'get' is readable by any authenticated role — the
 * settings here (starting with virtualClassroomEnabled) control what
 * ordinary users see elsewhere in the app (e.g. whether the Join Virtual
 * Class button renders at all), so Teachers/Students/Parents legitimately
 * need to read this, not just Administrators. 'set' is Administrator-only.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__.'/auth.php';

header('Content-Type: application/json');

// Whitelist — never let client input choose an arbitrary setting name to
// read/write, even scoped to 'School Connect'. Add new entries here as
// more settings are introduced; each is a plain 'Y'/'N' toggle for now.
const SETTABLE_NAMES = ['virtualClassroomEnabled'];

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('School Connect admin_settings.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? '');

try {
    if ($action === 'get') {
        $placeholders = implode(',', array_fill(0, count(SETTABLE_NAMES), '?'));
        $stmt = $pdo->prepare("SELECT name, value FROM gibbonSetting WHERE scope = 'School Connect' AND name IN ($placeholders)");
        $stmt->execute(SETTABLE_NAMES);

        $settings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['name']] = $row['value'];
        }
        // Defaults for any setting that's never been saved yet.
        // virtualClassroomEnabled defaults 'N': most schools haven't
        // actually configured Jitsi (jitsiRoomSecret unset), so an
        // on-by-default button would just be a dead end that errors on
        // every tap for the majority of schools — better to require an
        // explicit opt-in once a school has confirmed it actually works
        // for them.
        $settings['virtualClassroomEnabled'] = $settings['virtualClassroomEnabled'] ?? 'N';

        echo json_encode(['success' => true, 'settings' => $settings]);
        exit;
    }

    if ($action === 'set') {
        if ($authUser['role'] !== 'Administrator') {
            echo json_encode(['success' => false, 'message' => 'Only Administrator accounts can change settings.']);
            exit;
        }

        $name = trim($input['name'] ?? '');
        $value = trim($input['value'] ?? '');

        if (!in_array($name, SETTABLE_NAMES, true)) {
            echo json_encode(['success' => false, 'message' => 'Unknown setting.']);
            exit;
        }
        if (!in_array($value, ['Y', 'N'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid value.']);
            exit;
        }

        // These rows don't necessarily exist yet for scope='Gibbon
        // Connect' (unlike core Gibbon settings, which are pre-seeded by
        // install SQL) — check-then-insert-or-update rather than
        // assuming an UPDATE will ever match a row.
        $checkStmt = $pdo->prepare("SELECT gibbonSettingID FROM gibbonSetting WHERE scope = 'School Connect' AND name = :name");
        $checkStmt->execute(['name' => $name]);
        $existingID = $checkStmt->fetchColumn();

        if ($existingID) {
            $pdo->prepare("UPDATE gibbonSetting SET value = :value WHERE gibbonSettingID = :id")
                ->execute(['value' => $value, 'id' => $existingID]);
        } else {
            $pdo->prepare("INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('School Connect', :name, :name, '', :value)")
                ->execute(['name' => $name, 'value' => $value]);
        }

        // Auto-provision Jitsi config the first time a school actually
        // opts in — this is what keeps the module's "no database writes
        // until you use a feature" promise true end to end. Without this,
        // turning the toggle on would render a Join button that fails on
        // every tap, since jitsiServerURL/jitsiRoomSecret would still be
        // missing. Only runs once: the check-then-skip below means this
        // never overwrites values a school may have customized since.
        if ($name === 'virtualClassroomEnabled' && $value === 'Y') {
            $jitsiDefaults = [
                'jitsiServerURL'  => 'https://meet.jit.si',
                'jitsiRoomSecret' => bin2hex(random_bytes(24)),
            ];
            foreach ($jitsiDefaults as $defaultName => $defaultValue) {
                $existsStmt = $pdo->prepare("SELECT 1 FROM gibbonSetting WHERE scope = 'School Connect' AND name = :name");
                $existsStmt->execute(['name' => $defaultName]);
                if (!$existsStmt->fetchColumn()) {
                    $pdo->prepare("INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) VALUES ('School Connect', :name, :name, '', :value)")
                        ->execute(['name' => $defaultName, 'value' => $defaultValue]);
                }
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
} catch (Exception $e) {
    error_log('School Connect admin_settings.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not process settings request.']);
}
