<?php
/**
 * School Connect API — Reports: Download / View
 *
 * Streams a single report PDF as raw bytes (Content-Type: application/pdf)
 * on success, or a normal {'success':false,'message':...} JSON body on
 * failure — the Flutter side must check the response Content-Type header
 * to tell these apart, since this is the one endpoint in the module that
 * doesn't always return JSON.
 *
 * Re-verifies access server-side (status, viewableParents, childDataAccess,
 * AND that the archive entry actually belongs to the resolved student)
 * rather than trusting the app already filtered the list — mirrors core's
 * own archive_byStudent_download.php re-check pattern (source verified).
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__.'/auth.php';

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('School Connect reports_download.php DB connection error: ' . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
// An empty personID is a legitimate self-view (e.g. a Student viewing
// their own report) — see reports_byStudent.php for the full explanation.
$requestedPersonID = $input['personID'] ?? '';
$archiveEntryID = $input['archiveEntryID'] ?? '';
$action = $input['action'] ?? 'view'; // 'view' (inline) or 'download' (attachment)

if (empty($archiveEntryID)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'archiveEntryID is required.']);
    exit;
}

$personID = gibbonConnectResolvePersonID($requestedPersonID, $authUser, $pdo);

// resolvePersonID never returns false — it falls back to the caller's own
// ID on an unauthorized request. Compare resolved vs requested to detect
// that fallback, but only when a specific personID was actually requested
// (see reports_byStudent.php for the full explanation of the empty case
// and the int-cast fix for ZEROFILL string-formatting mismatches).
if (!empty($requestedPersonID) && (int) $personID !== (int) $requestedPersonID) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You do not have access to this student\'s reports.']);
    exit;
}

// Mirrors core's applyArchiveAccessLogic() role branching — see
// reports_byStudent.php for the full explanation.
$viewableColumn = match ($authUser['category']) {
    'Staff' => 'viewableStaff',
    'Student' => 'viewableStudents',
    'Parent' => 'viewableParents',
    default => 'viewableOther',
};

try {
    $sql = "SELECT gibbonReportArchiveEntry.gibbonPersonID, gibbonReportArchiveEntry.filePath,
                   gibbonReportArchiveEntry.status, gibbonReportArchive.path, gibbonReportArchive.$viewableColumn AS isViewable,
                   gibbonReport.accessDate
            FROM gibbonReportArchiveEntry
            JOIN gibbonReportArchive ON (gibbonReportArchive.gibbonReportArchiveID = gibbonReportArchiveEntry.gibbonReportArchiveID)
            LEFT JOIN gibbonReport ON (gibbonReport.gibbonReportID = gibbonReportArchiveEntry.gibbonReportID)
            WHERE gibbonReportArchiveEntry.gibbonReportArchiveEntryID = :gibbonReportArchiveEntryID
              AND gibbonReportArchiveEntry.type = 'Single'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['gibbonReportArchiveEntryID' => $archiveEntryID]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');

    if (empty($entry)) {
        echo json_encode(['success' => false, 'message' => 'Report not found.']);
        exit;
    }

    // Re-check the report actually belongs to the authorized student —
    // don't trust archiveEntryID alone just because resolvePersonID passed.
    if ($entry['gibbonPersonID'] != $personID) {
        echo json_encode(['success' => false, 'message' => 'You do not have access to this report.']);
        exit;
    }

    if ($entry['status'] !== 'Final' || $entry['isViewable'] !== 'Y') {
        echo json_encode(['success' => false, 'message' => 'This report is not yet available.']);
        exit;
    }

    if (!empty($entry['accessDate']) && strtotime($entry['accessDate']) > time()) {
        echo json_encode(['success' => false, 'message' => 'This report is not yet available.']);
        exit;
    }

    // absolutePath isn't a config.php constant — it's stored in
    // gibbonSetting (scope='System', name='absolutePath'), confirmed
    // against Gibbon core's install SQL. Same lookup pattern auth.php
    // already uses for the timezone setting.
    $pathStmt = $pdo->query("SELECT value FROM gibbonSetting WHERE scope = 'System' AND name = 'absolutePath' LIMIT 1");
    $absolutePath = $pathStmt->fetchColumn();

    if (empty($absolutePath)) {
        echo json_encode(['success' => false, 'message' => 'Server configuration error.']);
        exit;
    }

    $filePath = realpath(rtrim($absolutePath, '/') . '/' . $entry['path'] . '/' . $entry['filePath']);

    if ($filePath === false || !file_exists($filePath)) {
        echo json_encode(['success' => false, 'message' => 'Report file is missing on the server.']);
        exit;
    }

    // Log parent access, same as core does, so school admins still see
    // an accurate "Read" status against this archive entry.
    $update = $pdo->prepare("UPDATE gibbonReportArchiveEntry
                              SET gibbonPersonIDAccessed = :accessedBy, timestampAccessed = :now
                              WHERE gibbonReportArchiveEntryID = :id");
    $update->execute([
        'accessedBy' => $authUser['personID'],
        'now' => date('Y-m-d H:i:s'),
        'id' => $archiveEntryID,
    ]);

    $disposition = $action === 'download' ? 'attachment' : 'inline';

    // Overwrite the JSON Content-Type set above — this response is binary.
    header('Content-Type: application/pdf');
    header('Content-Disposition: '.$disposition.'; filename="report.pdf"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Could not load report file.']);
}
