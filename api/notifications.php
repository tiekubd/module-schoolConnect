<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$action = trim($input['action'] ?? 'list');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);
    $personID = $authUser['personID'];

    // ── LIST notifications ────────────────────────────────────────────────────
    if ($action === 'list') {
        $stmt = $pdo->prepare("
            SELECT
                n.gibbonNotificationID,
                n.status,
                n.count,
                n.text,
                n.actionLink,
                n.timestamp,
                m.name AS moduleName
            FROM gibbonNotification n
            LEFT JOIN gibbonModule m ON m.gibbonModuleID = n.gibbonModuleID
            WHERE n.gibbonPersonID = ?
            ORDER BY
                CASE WHEN n.status = 'New' THEN 0 ELSE 1 END,
                n.timestamp DESC
            LIMIT 50
        ");
        $stmt->execute([$personID]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Unread count for badge
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM gibbonNotification
            WHERE gibbonPersonID = ? AND status = 'New'
        ");
        $countStmt->execute([$personID]);
        $unreadCount = (int)$countStmt->fetchColumn();

        echo json_encode([
            'success'       => true,
            'notifications' => $notifications,
            'unreadCount'   => $unreadCount,
        ]);
        exit;
    }

    // ── ARCHIVE one notification ──────────────────────────────────────────────
    if ($action === 'archive') {
        $notificationID = trim($input['notificationID'] ?? '');
        if (empty($notificationID)) {
            echo json_encode(['success' => false, 'message' => 'notificationID required']);
            exit;
        }
        // Only allow archiving your own notifications
        $stmt = $pdo->prepare("
            UPDATE gibbonNotification
            SET status = 'Archived'
            WHERE gibbonNotificationID = ? AND gibbonPersonID = ?
        ");
        $stmt->execute([$notificationID, $personID]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── ARCHIVE ALL notifications ─────────────────────────────────────────────
    if ($action === 'archive_all') {
        $stmt = $pdo->prepare("
            UPDATE gibbonNotification
            SET status = 'Archived'
            WHERE gibbonPersonID = ? AND status = 'New'
        ");
        $stmt->execute([$personID]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect notifications.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
