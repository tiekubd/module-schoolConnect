<?php
/**
 * School Connect API — Translations
 *
 * Returns the signed-in person's language and its translations, so the
 * app can show its interface in the same language Gibbon uses for them.
 *
 * Which language (same rules as Gibbon core v30):
 *   - the person's Personal Language (gibbonPerson.gibboni18nIDPersonal,
 *     set in Preferences), as AuthenticationAdapter does at login;
 *   - otherwise the school's default (gibboni18n.systemDefault = 'Y'),
 *     as getSystemSettings() / setLanguageSession() do.
 *
 * Where the translations come from (see i18n_mo.php):
 *   1. this module's own file,
 *      modules/School Connect/i18n/<code>/LC_MESSAGES/School Connect.mo,
 *      for text that only exists in the app (wins if both have a string);
 *   2. Gibbon's own file, i18n/<code>/LC_MESSAGES/gibbon.mo, installed by
 *      the school in System Admin > Language Settings.
 * Either file may be missing; the app then keeps its English text for
 * those strings. String Replacements are not applied here: the app does
 * that after translating, in core's order (translate, then replace).
 *
 * Caching: the response carries a 'version' (changes when either file or
 * the language changes). The app sends back the version it already has;
 * if it still matches, only the language details are returned, not the
 * strings, so a normal launch costs a few hundred bytes.
 *
 * Request (JSON body, like every endpoint): version (optional) — the
 * version the app has cached.
 *
 * Response:
 *   success, language {code, name, rtl, dateFormat}, version,
 *   unchanged (true when the cached copy is current) — otherwise also:
 *   strings {msgid: msgstr}, plurals {singular msgid: [forms]},
 *   pluralTable [200 ints] (see gibbonConnectPluralTable).
 *
 * Requires sign-in, like every other endpoint. Contains no personal data.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n_mo.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('School Connect i18n.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

try {
    // ── Which language ────────────────────────────────────────────────────
    $i18n = null;
    $personStmt = $pdo->prepare("SELECT gibboni18nIDPersonal FROM gibbonPerson WHERE gibbonPersonID = ? LIMIT 1");
    $personStmt->execute([$authUser['personID']]);
    $personalID = $personStmt->fetchColumn();
    if (!empty($personalID)) {
        $stmt = $pdo->prepare("SELECT code, name, rtl, dateFormat FROM gibboni18n WHERE gibboni18nID = ? LIMIT 1");
        $stmt->execute([$personalID]);
        $i18n = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($i18n === null) {
        $i18n = $pdo->query("SELECT code, name, rtl, dateFormat FROM gibboni18n WHERE systemDefault = 'Y' LIMIT 1")
                    ->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($i18n === null) {
        $i18n = ['code' => 'en_GB', 'name' => 'English - United Kingdom', 'rtl' => 'N', 'dateFormat' => 'dd/mm/yyyy'];
    }

    $language = [
        'code'       => $i18n['code'],
        'name'       => $i18n['name'],
        'rtl'        => $i18n['rtl'] === 'Y',
        'dateFormat' => $i18n['dateFormat'],
    ];

    // The code becomes part of a file path, so accept only the xx_XX form.
    $code = preg_match('/^[a-z]{2,3}_[A-Z]{2}$/', $i18n['code']) ? $i18n['code'] : '';

    // ── Where the files are ──────────────────────────────────────────────
    $absolutePath = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'absolutePath' LIMIT 1")
                        ->fetchColumn();
    if (empty($absolutePath)) $absolutePath = dirname(__DIR__, 3);
    $absolutePath = rtrim($absolutePath, '/\\');

    $files = $code === '' ? [] : [
        'module' => $absolutePath . '/modules/School Connect/i18n/' . $code . '/LC_MESSAGES/School Connect.mo',
        'core'   => $absolutePath . '/i18n/' . $code . '/LC_MESSAGES/gibbon.mo',
    ];

    // Version: changes whenever the language or either file changes.
    clearstatcache();
    $fingerprint = $code;
    foreach ($files as $path) {
        $fingerprint .= '|' . (is_file($path) ? filemtime($path) . ':' . filesize($path) : '-');
    }
    $version = substr(sha1($fingerprint), 0, 16);

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if ((string) ($input['version'] ?? '') === $version) {
        echo json_encode(['success' => true, 'language' => $language, 'version' => $version, 'unchanged' => true]);
        exit;
    }

    // ── Read and merge: module first, then Gibbon ───────────────────────
    $core   = isset($files['core'])   ? gibbonConnectReadMo($files['core'])   : null;
    $module = isset($files['module']) ? gibbonConnectReadMo($files['module']) : null;

    $strings = array_replace($core['strings'] ?? [], $module['strings'] ?? []);
    $plurals = array_replace($core['plurals'] ?? [], $module['plurals'] ?? []);
    $pluralForms = ($core['pluralForms'] ?? '') !== '' ? $core['pluralForms'] : ($module['pluralForms'] ?? '');
    $plural = gibbonConnectPluralTable($pluralForms);

    $payload = json_encode([
        'success'     => true,
        'language'    => $language,
        'version'     => $version,
        'unchanged'   => false,
        'strings'     => (object) $strings,
        'plurals'     => (object) $plurals,
        'pluralTable' => $plural['table'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    // A full language is roughly 600 KB of JSON, about 180 KB compressed.
    // Compress if the server isn't already doing it and the app accepts it
    // (ob_gzhandler checks the request's Accept-Encoding itself).
    if (!ini_get('zlib.output_compression') && function_exists('ob_gzhandler')) {
        ob_start('ob_gzhandler');
    }
    echo $payload;
} catch (PDOException $e) {
    error_log('School Connect i18n.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
