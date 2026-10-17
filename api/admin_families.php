<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// Scope note: intentionally NOT building create-new-family, edit family
// address/language/marital-status, the adult<->child relationship-label
// matrix, or delete-family here. Those are rare, detail-heavy, and safer
// done at a desk in Gibbon's own web UI. This covers the operationally
// useful, on-the-go pieces: viewing a family, adjusting an adult's contact
// settings (including childDataAccess — the flag that gates Parent
// Dashboard access elsewhere in this app), editing a child's comment, and
// linking/unlinking an existing person to/from a family.
//
// Also deliberately NOT ported: gibbonFamilyRelationship (an adult's
// relationship label, e.g. "Father", is stored PER ADULT-CHILD PAIR in
// core — a family with multiple children could have a different
// relationship per child). That's a genuinely separate, single-student-
// context feature (Gibbon's own read-only "Family" tab on a student's
// profile, verified against student_view_details.php), not something
// that fits cleanly onto this whole-family admin view. The family-level
// address/language fields and each adult's phone/email/employment fields
// (all read-only display, matching core — those are edited on the
// person's own profile, not this page) ARE ported below.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? '');

// ── Contact priority enforcement ────────────────────────────────────────────
// Faithful port of the logic in Gibbon's family_manage_edit_editAdultProcess.php
// / family_manage_edit_addAdultProcess.php: exactly one adult per family must
// hold contactPriority=1 (and therefore all contact methods 'Y'). Setting an
// adult to priority 1 bumps any other 1/2 up by one; setting one to anything
// else, if no adult currently holds priority 1, forces this one to 1 instead;
// setting one to 2 bumps any existing 2 to 3. Returns the possibly-adjusted
// [contactPriority, contactCall, contactSMS, contactEmail, contactMail].
function gibbonConnectEnforceContactPriority(PDO $pdo, string $familyID, string $personID, string $contactPriority): array {
    if ($contactPriority == 1) {
        $contactCall = $contactSMS = $contactEmail = $contactMail = 'Y';
        $pdo->prepare("
            UPDATE gibbonFamilyAdult SET contactPriority = contactPriority + 1
            WHERE contactPriority < 3 AND gibbonFamilyID = ? AND gibbonPersonID <> ?
        ")->execute([$familyID, $personID]);
    } else {
        $contactCall = $contactSMS = $contactEmail = $contactMail = 'N';
        $check = $pdo->prepare("
            SELECT gibbonFamilyAdultID FROM gibbonFamilyAdult
            WHERE contactPriority = 1 AND gibbonFamilyID = ? AND gibbonPersonID <> ?
        ");
        $check->execute([$familyID, $personID]);
        if ($check->rowCount() < 1) {
            // No one else holds priority 1 — this adult becomes the primary
            // contact whether they asked to be or not, mirroring Gibbon.
            $contactPriority = '1';
            $contactCall = $contactSMS = $contactEmail = $contactMail = 'Y';
        } elseif ($contactPriority == 2) {
            $pdo->prepare("
                UPDATE gibbonFamilyAdult SET contactPriority = 3
                WHERE contactPriority = 2 AND gibbonFamilyID = ? AND gibbonPersonID <> ?
            ")->execute([$familyID, $personID]);
        }
    }
    return [$contactPriority, $contactCall, $contactSMS, $contactEmail, $contactMail];
}

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseServer . ';dbname=' . $databaseName . ';charset=utf8',
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    require_once __DIR__ . '/auth.php';
    $authUser = gibbonConnectRequireAuth($pdo);

    if ($authUser['role'] !== 'Administrator') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not permitted']);
        exit;
    }

    // ── LIST ─────────────────────────────────────────────────────────────────
    if ($action === 'list') {
        $search = trim($input['search'] ?? '');
        $page   = max(1, (int)($input['page'] ?? 1));
        $limit  = min(50, max(1, (int)($input['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $where = "1=1";
        $params = [];
        if ($search !== '') {
            $where .= " AND name LIKE ?";
            $params[] = '%' . $search . '%';
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonFamily WHERE $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT gibbonFamilyID, name, status
            FROM gibbonFamily WHERE $where
            ORDER BY name LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $families = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($families)) {
            $ids = array_column($families, 'gibbonFamilyID');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $adultStmt = $pdo->prepare("
                SELECT fa.gibbonFamilyID, p.title, p.preferredName, p.surname, p.status
                FROM gibbonFamilyAdult fa
                JOIN gibbonPerson p ON p.gibbonPersonID = fa.gibbonPersonID
                WHERE fa.gibbonFamilyID IN ($placeholders)
                ORDER BY fa.contactPriority
            ");
            $adultStmt->execute($ids);
            $adultsByFamily = [];
            foreach ($adultStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $adultsByFamily[$a['gibbonFamilyID']][] = $a;
            }

            $childStmt = $pdo->prepare("
                SELECT fc.gibbonFamilyID, p.preferredName, p.surname, p.status
                FROM gibbonFamilyChild fc
                JOIN gibbonPerson p ON p.gibbonPersonID = fc.gibbonPersonID
                WHERE fc.gibbonFamilyID IN ($placeholders)
                ORDER BY p.surname
            ");
            $childStmt->execute($ids);
            $childrenByFamily = [];
            foreach ($childStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $childrenByFamily[$c['gibbonFamilyID']][] = $c;
            }

            foreach ($families as &$f) {
                $f['adults'] = $adultsByFamily[$f['gibbonFamilyID']] ?? [];
                $f['children'] = $childrenByFamily[$f['gibbonFamilyID']] ?? [];
            }
            unset($f);
        }

        echo json_encode([
            'success' => true, 'families' => $families,
            'total' => $total, 'page' => $page, 'limit' => $limit,
        ]);
        exit;
    }

    // ── GET ONE FAMILY ──────────────────────────────────────────────────────
    if ($action === 'get') {
        $familyID = trim($input['gibbonFamilyID'] ?? '');
        if (empty($familyID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonFamilyID required']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT gibbonFamilyID, name, status, nameAddress, homeAddress,
                   homeAddressDistrict, homeAddressCountry,
                   languageHomePrimary, languageHomeSecondary
            FROM gibbonFamily WHERE gibbonFamilyID = ?
        ");
        $stmt->execute([$familyID]);
        $family = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$family) {
            echo json_encode(['success' => false, 'message' => 'Family not found']);
            exit;
        }

        $adultStmt = $pdo->prepare("
            SELECT p.gibbonPersonID, p.title, p.preferredName, p.surname, p.email, p.emailAlternate,
                   p.image_240, p.status,
                   p.languageFirst, p.languageSecond,
                   p.profession, p.employer, p.jobTitle, p.vehicleRegistration,
                   p.phone1, p.phone1Type, p.phone1CountryCode,
                   p.phone2, p.phone2Type, p.phone2CountryCode,
                   p.phone3, p.phone3Type, p.phone3CountryCode,
                   p.phone4, p.phone4Type, p.phone4CountryCode,
                   fa.comment, fa.childDataAccess, fa.contactPriority,
                   fa.contactCall, fa.contactSMS, fa.contactEmail, fa.contactMail
            FROM gibbonFamilyAdult fa
            JOIN gibbonPerson p ON p.gibbonPersonID = fa.gibbonPersonID
            WHERE fa.gibbonFamilyID = ?
            ORDER BY fa.contactPriority
        ");
        $adultStmt->execute([$familyID]);
        $adults = $adultStmt->fetchAll(PDO::FETCH_ASSOC);

        $childStmt = $pdo->prepare("
            SELECT p.gibbonPersonID, p.preferredName, p.surname, p.image_240, p.status, fc.comment
            FROM gibbonFamilyChild fc
            JOIN gibbonPerson p ON p.gibbonPersonID = fc.gibbonPersonID
            WHERE fc.gibbonFamilyID = ?
            ORDER BY p.surname
        ");
        $childStmt->execute([$familyID]);
        $children = $childStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'family' => $family, 'adults' => $adults, 'children' => $children]);
        exit;
    }

    // ── SEARCH PERSON (for linking to a family) ─────────────────────────────
    if ($action === 'search_person') {
        $q = trim($input['search'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode(['success' => true, 'people' => []]);
            exit;
        }
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("
            SELECT gibbonPersonID, title, preferredName, surname, username, image_240, status
            FROM gibbonPerson
            WHERE status = 'Full'
            AND (preferredName LIKE ? OR surname LIKE ? OR username LIKE ?)
            ORDER BY surname, preferredName
            LIMIT 30
        ");
        $stmt->execute([$like, $like, $like]);
        echo json_encode(['success' => true, 'people' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── EDIT ADULT ───────────────────────────────────────────────────────────
    if ($action === 'edit_adult') {
        $familyID = trim($input['gibbonFamilyID'] ?? '');
        $personID = trim($input['gibbonPersonID'] ?? '');
        $comment = trim($input['comment'] ?? '');
        $childDataAccess = trim($input['childDataAccess'] ?? 'N');
        $contactPriority = trim($input['contactPriority'] ?? '2');

        if (empty($familyID) || empty($personID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonFamilyID and gibbonPersonID required']);
            exit;
        }

        [$contactPriority, $contactCall, $contactSMS, $contactEmail, $contactMail] =
            gibbonConnectEnforceContactPriority($pdo, $familyID, $personID, $contactPriority);

        $pdo->prepare("
            UPDATE gibbonFamilyAdult SET
                comment = ?, childDataAccess = ?, contactPriority = ?,
                contactCall = ?, contactSMS = ?, contactEmail = ?, contactMail = ?
            WHERE gibbonFamilyID = ? AND gibbonPersonID = ?
        ")->execute([$comment, $childDataAccess, $contactPriority, $contactCall, $contactSMS, $contactEmail, $contactMail, $familyID, $personID]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── EDIT CHILD ───────────────────────────────────────────────────────────
    if ($action === 'edit_child') {
        $familyID = trim($input['gibbonFamilyID'] ?? '');
        $personID = trim($input['gibbonPersonID'] ?? '');
        $comment = trim($input['comment'] ?? '');

        if (empty($familyID) || empty($personID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonFamilyID and gibbonPersonID required']);
            exit;
        }

        $pdo->prepare("UPDATE gibbonFamilyChild SET comment = ? WHERE gibbonFamilyID = ? AND gibbonPersonID = ?")
            ->execute([$comment, $familyID, $personID]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── ADD PERSON TO FAMILY ────────────────────────────────────────────────
    if ($action === 'add_person') {
        $familyID = trim($input['gibbonFamilyID'] ?? '');
        $personID = trim($input['gibbonPersonID'] ?? '');
        $type = trim($input['type'] ?? '');
        $comment = trim($input['comment'] ?? '');

        if (empty($familyID) || empty($personID) || !in_array($type, ['adult', 'child'], true)) {
            echo json_encode(['success' => false, 'message' => 'gibbonFamilyID, gibbonPersonID, and a valid type are required']);
            exit;
        }

        // A person can't be both a child and an adult in the same family —
        // matches Gibbon's own duplicate check exactly.
        $check = $pdo->prepare("
            SELECT gibbonPersonID FROM gibbonFamilyChild WHERE gibbonPersonID = ? AND gibbonFamilyID = ?
            UNION
            SELECT gibbonPersonID FROM gibbonFamilyAdult WHERE gibbonPersonID = ? AND gibbonFamilyID = ?
        ");
        $check->execute([$personID, $familyID, $personID, $familyID]);
        if ($check->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'This person is already linked to this family']);
            exit;
        }

        if ($type === 'child') {
            $pdo->prepare("INSERT INTO gibbonFamilyChild SET gibbonFamilyID = ?, gibbonPersonID = ?, comment = ?")
                ->execute([$familyID, $personID, $comment]);
        } else {
            $childDataAccess = trim($input['childDataAccess'] ?? 'Y');
            $contactPriority = trim($input['contactPriority'] ?? '2');

            [$contactPriority, $contactCall, $contactSMS, $contactEmail, $contactMail] =
                gibbonConnectEnforceContactPriority($pdo, $familyID, $personID, $contactPriority);

            $pdo->prepare("
                INSERT INTO gibbonFamilyAdult SET
                    gibbonFamilyID = ?, gibbonPersonID = ?, comment = ?, childDataAccess = ?,
                    contactPriority = ?, contactCall = ?, contactSMS = ?, contactEmail = ?, contactMail = ?
            ")->execute([$familyID, $personID, $comment, $childDataAccess, $contactPriority, $contactCall, $contactSMS, $contactEmail, $contactMail]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ── REMOVE PERSON FROM FAMILY ───────────────────────────────────────────
    if ($action === 'remove_person') {
        $familyID = trim($input['gibbonFamilyID'] ?? '');
        $personID = trim($input['gibbonPersonID'] ?? '');
        $type = trim($input['type'] ?? '');

        if (empty($familyID) || empty($personID) || !in_array($type, ['adult', 'child'], true)) {
            echo json_encode(['success' => false, 'message' => 'gibbonFamilyID, gibbonPersonID, and a valid type are required']);
            exit;
        }

        if ($type === 'child') {
            $pdo->prepare("DELETE FROM gibbonFamilyChild WHERE gibbonPersonID = ? AND gibbonFamilyID = ?")
                ->execute([$personID, $familyID]);
        } else {
            // If removing the primary contact, promote whoever's currently
            // priority 2, matching Gibbon's own delete logic.
            $stmt = $pdo->prepare("SELECT contactPriority FROM gibbonFamilyAdult WHERE gibbonPersonID = ? AND gibbonFamilyID = ?");
            $stmt->execute([$personID, $familyID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['contactPriority'] == 1) {
                $pdo->prepare("
                    UPDATE gibbonFamilyAdult SET contactPriority = 1
                    WHERE contactPriority = 2 AND gibbonFamilyID = ? AND gibbonPersonID <> ? LIMIT 1
                ")->execute([$familyID, $personID]);
            }
            $pdo->prepare("DELETE FROM gibbonFamilyAdult WHERE gibbonPersonID = ? AND gibbonFamilyID = ?")
                ->execute([$personID, $familyID]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect admin_families.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
