<?php
/**
 * School Connect API — String Replacements
 *
 * Returns the school's string replacements (System Admin > Manage String
 * Replacements, table gibbonString), so the app can rename its interface
 * text the same way Gibbon does: e.g. "Form Group" -> "Classroom" turns
 * "My Form Group" into "My Classroom".
 *
 * Same query and order as Gibbon core's Locale::setStringReplacementList()
 * (v30): ORDER BY priority DESC, original. The app applies each rule in
 * that order exactly as core's Locale::doStringReplacement() does:
 *   - Partial: replace every occurrence (case-sensitive or not);
 *   - Whole:   replace only when the entire string matches.
 * Like Gibbon, rules apply to interface text only, never to data such as
 * form group or course names.
 *
 * Requires sign-in, like every other endpoint. Contains no personal data.
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
    error_log('School Connect strings.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

gibbonConnectRequireAuth($pdo);

try {
    $stmt = $pdo->query("SELECT original, replacement, mode, caseSensitive
                         FROM gibbonString
                         ORDER BY priority DESC, original");
    echo json_encode(['success' => true, 'strings' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (PDOException $e) {
    error_log('School Connect strings.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
