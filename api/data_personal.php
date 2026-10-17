<?php
/**
 * School Connect API — Data Updater: Personal (load)
 *
 * Loads a person's editable Personal Data fields. Mirrors core's
 * data_personal.php (source verified against GibbonEdu/core) for the
 * ~34 core fields (Basic Info, Contact Info, Background, Emergency
 * Contacts, Employment, Privacy Options).
 *
 * DELIBERATELY NOT REPLICATED (agreed scope for this pass):
 *   - Personal Documents (file uploads) — separate feature.
 *   - Custom Fields (CustomFieldHandler) — separate school-extensible
 *     fields subsystem.
 *   - "Matching Address" cross-family sync when saving.
 *   - Multi-role-category field-visibility "greatest priority" merge —
 *     simplified to just the target person's PRIMARY role category. Core
 *     merges settings across all of a person's role categories when they
 *     hold more than one; this covers the common single-role case.
 *
 * FIELD VISIBILITY: driven by gibbonSetting('User Admin',
 * 'personalDataUpdaterRequiredFields'), a PHP-serialized array keyed by
 * role category → field name → 'required'|'readonly'|'hidden'|''. This is
 * unserialized here (allowed_classes disabled — only a plain nested array
 * is ever expected, this blocks PHP object-injection via a corrupted
 * setting value) and applied per-field, since getting this wrong could
 * expose a field a school deliberately hid.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__.'/auth.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('School Connect data_personal.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

// No role gate here — core's actual permission model allows ANY
// authenticated role to self-edit their own Personal Data (source
// verified against GibbonEdu/core: data_personal.php's access check
// includes an explicit self-shortcut with no role restriction).
// Family-based access (editing a child/co-adult's data) is naturally
// still restricted to whoever actually has childDataAccess family
// links, which in practice will only be Parents.

$input = json_decode(file_get_contents('php://input'), true) ?? [];
// Empty gibbonPersonID = self-view, same convention as Reports/Grades/
// Staff Data — resolved to the caller's own ID by the access check below.
$targetPersonID = trim($input['gibbonPersonID'] ?? '') ?: $authUser['personID'];

// The 34 core fields this pass covers (Personal Documents / Custom Fields
// excluded — see header comment).
const PERSONAL_FIELDS = [
    'title', 'surname', 'firstName', 'preferredName', 'officialName', 'nameInCharacters', 'dob',
    'email', 'emailAlternate',
    'address1', 'address1District', 'address1Country',
    'address2', 'address2District', 'address2Country',
    'phone1Type', 'phone1CountryCode', 'phone1',
    'phone2Type', 'phone2CountryCode', 'phone2',
    'phone3Type', 'phone3CountryCode', 'phone3',
    'phone4Type', 'phone4CountryCode', 'phone4',
    'languageFirst', 'languageSecond', 'languageThird',
    'countryOfBirth', 'ethnicity', 'religion',
    'emergency1Name', 'emergency1Number1', 'emergency1Number2', 'emergency1Relationship',
    'emergency2Name', 'emergency2Number1', 'emergency2Number2', 'emergency2Relationship',
    'profession', 'employer', 'jobTitle', 'vehicleRegistration',
];

try {
    // Access check — self OR a member (child/co-adult) of any family
    // where this Parent has childDataAccess='Y'. Matches core exactly
    // (source verified) — same rule this app's "My Data Updates" landing
    // list already uses, just re-expressed as a single membership check
    // rather than a full list.
    // Cast to int before comparing — gibbonConnectSession.gibbonPersonID
    // and gibbonPerson.gibbonPersonID (ZEROFILL) can render differently
    // as strings (e.g. "2746" vs "0000002746") even though they're the
    // same person; a strict string comparison would wrongly treat this
    // as a different person and fall through to the family-access check.
    $isSelf = ((int) $targetPersonID === (int) $authUser['personID']);
    $hasAccess = $isSelf;

    if (!$hasAccess) {
        $accessStmt = $pdo->prepare("
            SELECT 1
            FROM gibbonFamilyAdult
            JOIN gibbonFamily ON (gibbonFamily.gibbonFamilyID = gibbonFamilyAdult.gibbonFamilyID)
            WHERE gibbonFamilyAdult.gibbonPersonID = :gibbonPersonID AND gibbonFamilyAdult.childDataAccess = 'Y'
            AND gibbonFamily.gibbonFamilyID IN (
                SELECT gibbonFamilyID FROM gibbonFamilyChild WHERE gibbonPersonID = :targetPersonID1
                UNION
                SELECT gibbonFamilyID FROM gibbonFamilyAdult WHERE gibbonPersonID = :targetPersonID2
            )
            LIMIT 1
        ");
        $accessStmt->execute([
            'gibbonPersonID' => $authUser['personID'],
            'targetPersonID1' => $targetPersonID,
            'targetPersonID2' => $targetPersonID,
        ]);
        $hasAccess = (bool) $accessStmt->fetchColumn();
    }

    if (!$hasAccess) {
        echo json_encode(['success' => false, 'message' => 'The selected record does not exist, or you do not have access to it.']);
        exit;
    }

    // Target person's name + primary role category (drives field visibility).
    $personStmt = $pdo->prepare("
        SELECT gibbonPerson.preferredName, gibbonPerson.surname, gibbonRole.category
        FROM gibbonPerson
        LEFT JOIN gibbonRole ON (gibbonRole.gibbonRoleID = gibbonPerson.gibbonRoleIDPrimary)
        WHERE gibbonPerson.gibbonPersonID = :gibbonPersonID AND gibbonPerson.status = 'Full'
    ");
    $personStmt->execute(['gibbonPersonID' => $targetPersonID]);
    $personRow = $personStmt->fetch(PDO::FETCH_ASSOC);

    if (!$personRow) {
        echo json_encode(['success' => false, 'message' => 'The specified record cannot be found.']);
        exit;
    }

    $roleCategory = $personRow['category'] ?? 'Other';

    // ── Field visibility ──────────────────────────────────────────────────
    $requiredFieldsSetting = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'User Admin' AND name = 'personalDataUpdaterRequiredFields'")->fetchColumn();
    $requiredFieldsByCategory = [];
    if (!empty($requiredFieldsSetting)) {
        $unserialized = @unserialize($requiredFieldsSetting, ['allowed_classes' => false]);
        if (is_array($unserialized)) {
            $requiredFieldsByCategory = $unserialized;
        }
    }
    $requiredFieldsForRole = $requiredFieldsByCategory[$roleCategory] ?? [];

    $fieldStates = [];
    foreach (PERSONAL_FIELDS as $field) {
        $setting = $requiredFieldsForRole[$field] ?? '';
        // Empty/unset = shown and optional, matching core's isVisible():
        // "empty($requiredFields[$name]) || $requiredFields[$name] != 'hidden'"
        $fieldStates[$field] = in_array($setting, ['required', 'readonly', 'hidden'], true) ? $setting : 'shown';
    }

    // ── Existing pending edit, or current live data ─────────────────────────
    $pendingStmt = $pdo->prepare("SELECT * FROM gibbonPersonUpdate WHERE gibbonPersonID = :gibbonPersonID AND gibbonPersonIDUpdater = :gibbonPersonIDUpdater AND status = 'Pending'");
    $pendingStmt->execute(['gibbonPersonID' => $targetPersonID, 'gibbonPersonIDUpdater' => $authUser['personID']]);
    $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    if ($pending) {
        $values = $pending;
    } else {
        $liveStmt = $pdo->prepare("SELECT * FROM gibbonPerson WHERE gibbonPersonID = :gibbonPersonID");
        $liveStmt->execute(['gibbonPersonID' => $targetPersonID]);
        $values = $liveStmt->fetch(PDO::FETCH_ASSOC);
    }

    $responseValues = [];
    foreach (PERSONAL_FIELDS as $field) {
        $responseValues[$field] = $values[$field] ?? '';
    }

    // ── Ethnicity / Religion: dropdown if the school configured a list,
    // free-text if not (matches core's fromString()-vs-textfield fallback) ──
    $ethnicitiesSetting = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'User Admin' AND name = 'ethnicity'")->fetchColumn();
    $religionsSetting = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'User Admin' AND name = 'religions'")->fetchColumn();
    $ethnicities = !empty($ethnicitiesSetting) ? array_values(array_filter(array_map('trim', explode(',', $ethnicitiesSetting)))) : null;
    $religions = !empty($religionsSetting) ? array_values(array_filter(array_map('trim', explode(',', $religionsSetting)))) : null;

    // ── Privacy Options — a whole section gated by its own visibility flag ──
    $privacyEnabled = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'User Admin' AND name = 'privacyOptionVisibility'")->fetchColumn() === 'Y';
    $privacyOptionsRaw = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'User Admin' AND name = 'privacyOptions'")->fetchColumn();
    $privacyOptions = $privacyEnabled && !empty($privacyOptionsRaw)
        ? array_values(array_filter(array_map('trim', explode(',', $privacyOptionsRaw))))
        : [];
    $currentPrivacy = !empty($values['privacy']) ? array_map('trim', explode(',', $values['privacy'])) : [];

    $countries = $pdo->query("SELECT printable_name FROM gibbonCountry ORDER BY printable_name")->fetchAll(PDO::FETCH_COLUMN);
    $languages = $pdo->query("SELECT name FROM gibbonLanguage ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'personName' => trim(($personRow['preferredName'] ?? '') . ' ' . ($personRow['surname'] ?? '')),
        'hasPendingEdit' => $pending !== false,
        'fields' => $fieldStates,
        'values' => $responseValues,
        'countries' => $countries,
        'languages' => $languages,
        'ethnicities' => $ethnicities,
        'religions' => $religions,
        'privacyEnabled' => $privacyEnabled && !empty($privacyOptions),
        'privacyOptions' => $privacyOptions,
        'currentPrivacy' => $currentPrivacy,
    ]);
} catch (Exception $e) {
    error_log('School Connect data_personal.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load personal data.']);
}
