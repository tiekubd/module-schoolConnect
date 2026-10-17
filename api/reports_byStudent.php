<?php
/**
 * School Connect API — Reports: By Student
 *
 * Lists one child's Final, parent-viewable report archive entries,
 * scoped to the current school year (matches this app's existing
 * convention — see Family/Attendance/Grades — no multi-year history yet).
 * Mirrors core's ReportArchiveEntryGateway::queryArchiveByStudent() +
 * applyArchiveAccessLogic() (source verified against GibbonEdu/core).
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
    error_log('School Connect reports_byStudent.php DB connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}

$authUser = gibbonConnectRequireAuth($pdo);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
// An empty personID is a legitimate self-view (e.g. a Student viewing
// their own reports) — see the resolvePersonID comment below for how
// that's handled. Only actually-provided-but-unauthorized IDs get
// rejected.
$requestedPersonID = $input['personID'] ?? '';

// $pdo is required here — per this project's established convention, it's
// what lets gibbonConnectResolvePersonID() verify a Parent's
// childDataAccess link to the requested student, not just staff roles.
// Always call with all 4 arguments (see markbook.php bug in handoff notes).
//
// NOTE: this function never returns false — on an unauthorized request it
// silently falls back to $authUser['personID']. So the correct check is
// whether the resolved ID still matches what was actually requested — but
// only when a specific personID was actually requested. An EMPTY
// requestedPersonID is a legitimate self-view (e.g. a Student viewing
// their own reports), and resolvePersonID() correctly returns their real
// ID in that case — which will never equal the empty string it started
// as, so that case must be excluded from the mismatch check below.
// Cast to int before comparing — gibbonConnectSession.gibbonPersonID and
// gibbonPerson.gibbonPersonID (ZEROFILL) can render differently as
// strings (e.g. "2746" vs "0000002746") for the same person; a strict
// string comparison would wrongly treat this as a mismatch.
$personID = gibbonConnectResolvePersonID($requestedPersonID, $authUser, $pdo);

if (!empty($requestedPersonID) && (int) $personID !== (int) $requestedPersonID) {
    echo json_encode(['success' => false, 'message' => 'You do not have access to this student\'s reports.']);
    exit;
}

// Mirrors core's applyArchiveAccessLogic() role branching (source
// verified against GibbonEdu/core) — which visibility flag applies
// depends on who's asking, not just who's being viewed. A report might be
// viewableStudents='Y' but viewableParents='N', or vice versa.
$viewableColumn = match ($authUser['category']) {
    'Staff' => 'viewableStaff',
    'Student' => 'viewableStudents',
    'Parent' => 'viewableParents',
    default => 'viewableOther',
};

try {
    $sql = "SELECT gibbonReportArchiveEntry.gibbonReportArchiveEntryID, gibbonReportArchiveEntry.status,
                   gibbonReportArchiveEntry.timestampModified, gibbonReportArchiveEntry.timestampAccessed,
                   gibbonReportArchiveEntry.reportIdentifier, gibbonReport.name AS reportName,
                   gibbonSchoolYear.name AS schoolYear, gibbonYearGroup.nameShort AS yearGroup,
                   gibbonFormGroup.nameShort AS formGroup
            FROM gibbonReportArchiveEntry
            JOIN gibbonReportArchive ON (gibbonReportArchive.gibbonReportArchiveID = gibbonReportArchiveEntry.gibbonReportArchiveID)
            JOIN gibbonSchoolYear ON (gibbonSchoolYear.gibbonSchoolYearID = gibbonReportArchiveEntry.gibbonSchoolYearID)
            LEFT JOIN gibbonReport ON (gibbonReport.gibbonReportID = gibbonReportArchiveEntry.gibbonReportID)
            LEFT JOIN gibbonYearGroup ON (gibbonYearGroup.gibbonYearGroupID = gibbonReportArchiveEntry.gibbonYearGroupID)
            LEFT JOIN gibbonFormGroup ON (gibbonFormGroup.gibbonFormGroupID = gibbonReportArchiveEntry.gibbonFormGroupID)
            WHERE gibbonReportArchiveEntry.type = 'Single'
              AND gibbonReportArchiveEntry.gibbonPersonID = :gibbonPersonID
              AND gibbonReportArchiveEntry.status = 'Final'
              AND gibbonReportArchive.$viewableColumn = 'Y'
              AND gibbonReportArchiveEntry.gibbonSchoolYearID = :gibbonSchoolYearID
              AND (gibbonReport.gibbonReportID IS NULL OR gibbonReport.accessDate IS NULL OR gibbonReport.accessDate <= :now)
            ORDER BY gibbonReportArchiveEntry.timestampModified DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'gibbonPersonID' => $personID,
        'gibbonSchoolYearID' => $authUser['schoolYearID'],
        'now' => date('Y-m-d H:i:s'),
    ]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reports as &$report) {
        $report['isRead'] = !empty($report['timestampAccessed']);
    }
    unset($report);

    echo json_encode(['success' => true, 'reports' => $reports]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Could not load reports.']);
}
