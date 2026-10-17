<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config.php';

// School Connect runs as a standalone API module (raw PDO), not inside
// Gibbon's own bootstrap/container — so unlike the web UI's
// user_manage_add.php, we don't have access to Gibbon's UsernameGenerator,
// StaffGateway, StudentGateway, or Alert classes. Where those matter (ENUM
// option lists), this file re-implements a reasonable equivalent directly
// against the database. Password policy is the one exception worth calling
// out: rather than guessing at a fixed rule, this reads the school's ACTUAL
// configured policy straight out of gibbonSetting (scope 'System') — see
// gibbonConnectPasswordPolicy() below — so it enforces exactly what the
// school has configured in Gibbon, not an approximation.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Body is JSON for all actions except 'add'/'edit' with a photo, which use
// multipart/form-data so the image bytes can ride along — for those, field
// values arrive in $_POST and the file in $_FILES['photo'].
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'multipart/form-data') !== false) {
    $input = $_POST;
} else {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$action = trim($input['action'] ?? '');

// ── Small helper: read ENUM options straight off a column's schema ─────────
// Avoids hardcoding lists (gender, title, status, staff type, emergency
// relationship) that are configurable per Gibbon install. Blank ('') values
// are filtered out — Gibbon commonly defines ENUMs like ('','Mr.','Mrs.',...)
// so a NOT NULL column can still represent "unset" (confirmed elsewhere in
// this schema, e.g. gibbonStaff.firstAidQualified = enum('','N','Y')). That
// blank is meaningful at the database level but not a useful *choice* in a
// dropdown — "nothing selected" already covers it — so it's dropped here.
function gibbonConnectEnumValues(PDO $pdo, string $table, string $column): array {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$col || !preg_match("/^enum\\((.*)\\)$/i", $col['Type'], $m)) {
        return [];
    }
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $matches);
    $values = array_map(fn($v) => stripslashes($v), $matches[1]);
    return array_values(array_filter($values, fn($v) => $v !== ''));
}

// ── Small helper: save a base64 (or multipart) photo, resized, and return
// the relative path to store in gibbonPerson.image_240 ─────────────────────
// Deliberately simpler than Gibbon's own FileUploader/PersonPhotoGateway —
// no automatic previous-photo history (that's the deferred "previous
// photos" screen). Saved under uploads/gibbonConnect/ to avoid colliding
// with Gibbon's own upload naming conventions.
function gibbonConnectSavePhoto(string $sourcePath, string $username): ?string {
    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo) return null;

    [$width, $height, $type] = $imageInfo;
    $image = match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
        IMAGETYPE_PNG  => imagecreatefrompng($sourcePath),
        IMAGETYPE_GIF  => imagecreatefromgif($sourcePath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : null,
        default => null,
    };
    if (!$image) return null;

    // Resize so the longest side is 480px, matching Gibbon's own 240-photo
    // convention closely enough for display purposes across the app.
    $maxDim = 480;
    $scale = min(1, $maxDim / max($width, $height));
    $newW = (int) round($width * $scale);
    $newH = (int) round($height * $scale);

    $resized = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);

    $uploadDir = __DIR__ . '/../../../uploads/gibbonConnect';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $safeUsername = preg_replace('/[^a-zA-Z0-9_.-]/', '', $username);
    $filename = $safeUsername . '_240_' . time() . '.jpg';
    $fullPath = $uploadDir . '/' . $filename;

    imagejpeg($resized, $fullPath, 85);
    imagedestroy($image);
    imagedestroy($resized);

    return 'uploads/gibbonConnect/' . $filename;
}

// ── Password policy: read the school's ACTUAL configured policy ────────────
// Gibbon stores this as four settings under scope 'System' in gibbonSetting
// (the same table Gibbon's own System Admin > Password Policy screen writes
// to), rather than hardcoding a fixed rule. This means whatever the school
// has configured in Gibbon is exactly what gets enforced here too — no
// separate/guessed baseline, and no drift if they change it later.
function gibbonConnectPasswordPolicy(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT name, value FROM gibbonSetting
        WHERE scope = 'System'
        AND name IN ('passwordPolicyAlpha', 'passwordPolicyNumeric', 'passwordPolicyNonAlphaNumeric', 'passwordPolicyMinLength')
    ");
    $settings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['name']] = $row['value'];
    }
    return [
        'alpha'       => ($settings['passwordPolicyAlpha'] ?? 'N') === 'Y',
        'numeric'     => ($settings['passwordPolicyNumeric'] ?? 'N') === 'Y',
        'punctuation' => ($settings['passwordPolicyNonAlphaNumeric'] ?? 'N') === 'Y',
        'minLength'   => (int)($settings['passwordPolicyMinLength'] ?? 6),
    ];
}

// Mirrors Gibbon's own doesPasswordMatchPolicy() logic exactly (alpha =
// needs both an uppercase AND lowercase letter; numeric = needs a digit;
// punctuation = needs a non-alphanumeric character OR a space). Returns a
// list of unmet requirements — empty means the password passes.
function gibbonConnectPasswordErrors(string $password, array $policy): array {
    $errors = [];
    if (strlen($password) < $policy['minLength']) {
        $errors[] = "at least {$policy['minLength']} characters";
    }
    if ($policy['alpha'] && (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password))) {
        $errors[] = 'both an uppercase and a lowercase letter';
    }
    if ($policy['numeric'] && !preg_match('/[0-9]/', $password)) {
        $errors[] = 'at least one number';
    }
    if ($policy['punctuation'] && !preg_match('/[^a-zA-Z0-9]/', $password) && strpos($password, ' ') === false) {
        $errors[] = 'at least one punctuation mark or space';
    }
    return $errors;
}

// ── Build gibbonRoleIDAll safely from client input ─────────────────────────
// Client sends a comma-separated list of selected role IDs (the "All Roles"
// multi-select). The primary role must always be included in that list —
// Gibbon's own permission checks assume it — so this adds it if the client
// somehow omitted it, rather than trusting the client to always include it.
function gibbonConnectBuildRoleIDAll(string $primaryRoleID, array $input): string {
    $raw = trim($input['gibbonRoleIDAll'] ?? '');
    $ids = array_values(array_filter(array_map('trim', explode(',', $raw))));
    if (!in_array($primaryRoleID, $ids, true)) {
        $ids[] = $primaryRoleID;
    }
    return implode(',', $ids);
}

// ── Auto-fill required columns we're not explicitly setting ────────────────
// gibbonPerson has many NOT NULL columns with no default (address, phone,
// language, ethnicity, transport fields, and apparently install-specific
// ones like birthCertificateScan — likely a custom field or another module
// on this school's install). Rather than guessing column names one MySQL
// error at a time, this reads the table's real schema and supplies a safe
// blank for anything NOT NULL/no-default that isn't already being set
// explicitly. $alreadyHandled is the list of column names the caller is
// already providing (so those aren't touched or overridden).
function gibbonConnectAutoFillRequired(PDO $pdo, string $table, array $alreadyHandled): array {
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);

    $sqlParts = [];
    $params = [];
    $i = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $name = $col['COLUMN_NAME'];
        if (in_array($name, $alreadyHandled, true)) continue;
        if ($col['IS_NULLABLE'] === 'YES') continue;
        if ($col['COLUMN_DEFAULT'] !== null) continue;
        if (stripos($col['EXTRA'], 'auto_increment') !== false) continue;

        $type = strtolower($col['DATA_TYPE']);
        if (in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'float', 'double'], true)) {
            $default = 0;
        } elseif ($type === 'date') {
            $default = '1970-01-01';
        } elseif (in_array($type, ['datetime', 'timestamp'], true)) {
            $default = '1970-01-01 00:00:01';
        } else {
            $default = '';
        }

        $placeholder = "autofill_$i";
        $sqlParts[] = "`$name` = :$placeholder";
        $params[$placeholder] = $default;
        $i++;
    }

    return ['sql' => implode(', ', $sqlParts), 'params' => $params];
}

// ── Username generator ──────────────────────────────────────────────────────
// Faithful port of Gibbon\Data\UsernameGenerator (confirmed via the actual
// source file), since that class lives inside Gibbon's own
// container/bootstrap and isn't reachable from this standalone API script.
// Reads the school's real gibbonUsernameFormat rows — role-specific format
// if one lists this role, otherwise whichever row is marked isDefault — and
// resolves [token] / [token:N] / [number] placeholders exactly as Gibbon
// does, including persisting the incremented numeric counter back to
// gibbonUsernameFormat.numericValue when a format uses one.
class GibbonConnectUsernameGenerator {
    const MAX_LENGTH = 20;
    const ILLEGAL_CHARS = " '-";

    private PDO $pdo;
    private array $tokens = [];
    private string $defaultValue = 'user';
    private string $defaultFormat = '[preferredName:1][surname]';
    private int $loopCount = 0;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        mb_internal_encoding('utf-8');
    }

    public function addToken(string $name, string $value): void {
        $this->tokens[$name] = ['type' => 'string', 'value' => mb_strtolower($value)];
    }

    public function addNumericToken(string $name, $value, int $size, int $increment, ?callable $callback = null): void {
        $this->tokens[$name] = [
            'type' => 'numeric', 'value' => intval($value), 'size' => $size,
            'increment' => $increment, 'callback' => $callback,
        ];
    }

    public function getToken(string $name) {
        return $this->tokens[$name] ?? false;
    }

    public function generateByRole(string $gibbonRoleID): string {
        $usernameFormat = '';

        // Mirrors Gibbon's own SQL exactly: prefer a format whose
        // gibbonRoleIDList contains this role; fall back to the row marked
        // isDefault='Y' if no role-specific format matches.
        $stmt = $this->pdo->prepare("
            SELECT guf.*, gr.name AS roleName
            FROM gibbonUsernameFormat guf
            JOIN gibbonRole gr ON FIND_IN_SET(gr.gibbonRoleID, guf.gibbonRoleIDList)
            WHERE gr.gibbonRoleID = ? OR guf.isDefault = 'Y'
            ORDER BY FIND_IN_SET(?, guf.gibbonRoleIDList) DESC
            LIMIT 1
        ");
        $stmt->execute([$gibbonRoleID, $gibbonRoleID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $usernameFormat = $row['format'];
            $this->defaultValue = strtolower($row['roleName']);

            if ($row['isNumeric'] === 'Y') {
                $pdo = $this->pdo;
                $formatID = $row['gibbonUsernameFormatID'];
                $callback = function ($number) use ($pdo, $formatID) {
                    $u = $pdo->prepare("UPDATE gibbonUsernameFormat SET numericValue = ? WHERE gibbonUsernameFormatID = ?");
                    $u->execute([$number, $formatID]);
                };
                $this->addNumericToken('number', $row['numericValue'], $row['numericSize'], $row['numericIncrement'], $callback);
            }
        }

        return $this->generate($usernameFormat);
    }

    public function generate(string $format): string {
        $username = !empty($format) ? $format : $this->defaultFormat;

        if (++$this->loopCount >= 1000) {
            return $this->defaultValue . time();
        }

        preg_match_all('/[\[]+([^\]]*)[\]]+/u', $format, $formatTokens);

        if (!empty($formatTokens[1])) {
            foreach ($formatTokens[1] as $fullToken) {
                $parts = explode(':', $fullToken);
                $name = $parts[0];
                $length = $parts[1] ?? false;

                $token = $this->getToken($name);
                if (empty($token)) continue;

                if ($token['type'] === 'numeric') {
                    $value = $this->incrementNumericToken($name);
                } else {
                    $value = $length ? mb_substr($token['value'], 0, (int)$length) : $token['value'];
                }

                $username = str_replace('[' . $fullToken . ']', $value, $username);
            }
        }

        $username = str_replace(str_split(self::ILLEGAL_CHARS), '', $username);
        $username = mb_substr($username, 0, self::MAX_LENGTH);

        if (empty($username)) {
            $format = '[defaultValue][number]';
            $this->addToken('defaultValue', $this->defaultValue);
            $this->addNumericToken('number', 0, 3, 1);
            return $this->generate($format);
        }

        if (!$this->isUsernameUnique($username)) {
            if (stripos($format, '[number]') === false) {
                $format .= '[number]';
            }
            if ($this->getToken('number') === false) {
                $this->addNumericToken('number', 0, 1, 1);
            }
            return $this->generate($format);
        }

        return $username;
    }

    public function isUsernameUnique(string $username): bool {
        $stmt = $this->pdo->prepare("SELECT gibbonPersonID FROM gibbonPerson WHERE username = ? OR username = LOWER(?)");
        $stmt->execute([$username, $username]);
        return $stmt->rowCount() === 0;
    }

    private function incrementNumericToken(string $name): string {
        $number = $this->getToken($name);
        if (empty($number) || $number['type'] !== 'numeric') {
            throw new \InvalidArgumentException();
        }
        $number['value'] = str_pad((string)(intval($number['value']) + $number['increment']), $number['size'], '0', STR_PAD_LEFT);
        if (!empty($number['callback']) && is_callable($number['callback'])) {
            call_user_func($number['callback'], $number['value']);
        }
        $this->tokens[$name] = $number;
        return $number['value'];
    }
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

    // User management is Administrator-only — matches Gibbon's own User
    // Admin module access control. Respects the currently ACTIVE (possibly
    // switched) role, so a Teacher/Admin dual-role account loses access
    // here the moment they switch away from Administrator.
    if ($authUser['role'] !== 'Administrator') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not permitted']);
        exit;
    }

    // ── GENERATE USERNAME ───────────────────────────────────────────────────
    // Uses the school's actual configured username formats (gibbonUsernameFormat),
    // matching what the "Generate" button does in Gibbon's own Add User form.
    if ($action === 'generate_username') {
        $firstName = trim($input['firstName'] ?? '');
        $preferredName = trim($input['preferredName'] ?? '');
        $surname = trim($input['surname'] ?? '');
        $gibbonRoleID = trim($input['gibbonRoleIDPrimary'] ?? '');

        if (empty($preferredName) || empty($firstName) || empty($surname) || empty($gibbonRoleID)) {
            echo json_encode(['success' => false, 'message' => 'First name, surname, preferred name, and role are needed to generate a username']);
            exit;
        }

        $generator = new GibbonConnectUsernameGenerator($pdo);
        $generator->addToken('preferredName', $preferredName);
        $generator->addToken('firstName', $firstName);
        $generator->addToken('surname', $surname);

        echo json_encode(['success' => true, 'username' => $generator->generateByRole($gibbonRoleID)]);
        exit;
    }

    // ── CHECK USERNAME ──────────────────────────────────────────────────────
    // Live availability check as the person types — matches Gibbon's own
    // web UI behavior. excludePersonID lets edit mode check "is this
    // username available, ignoring the user's own current row".
    if ($action === 'check_username') {
        $username = trim($input['username'] ?? '');
        $excludePersonID = trim($input['excludePersonID'] ?? '');
        if ($username === '') {
            echo json_encode(['success' => true, 'available' => null]);
            exit;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonPerson WHERE username = ? AND gibbonPersonID <> ?");
        $stmt->execute([$username, $excludePersonID]);
        echo json_encode(['success' => true, 'available' => $stmt->fetchColumn() == 0]);
        exit;
    }

    // ── META: dropdown options for the Add/Edit form ──────────────────────────
    if ($action === 'meta') {
        // gibbonRole has no 'active' column — every row is selectable.
        $roleStmt = $pdo->query("SELECT gibbonRoleID, name, category FROM gibbonRole ORDER BY name");
        $roles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

        $ygStmt = $pdo->query("SELECT gibbonYearGroupID, name FROM gibbonYearGroup ORDER BY sequenceNumber");
        $yearGroups = $ygStmt->fetchAll(PDO::FETCH_ASSOC);

        $fgStmt = $pdo->prepare("SELECT gibbonFormGroupID, name FROM gibbonFormGroup WHERE gibbonSchoolYearID = ? ORDER BY name");
        $fgStmt->execute([$authUser['schoolYearID']]);
        $formGroups = $fgStmt->fetchAll(PDO::FETCH_ASSOC);

        // CHANGED: title, gender, and emergency relationship are NOT sourced
        // from the database ENUM in Gibbon's own web form — they're
        // hardcoded arrays in Gibbon\Forms\FormFactory (createSelectTitle,
        // createSelectGender, createSelectEmergencyRelationship). The ENUM
        // read was a plausible-looking guess that happened to mostly work
        // for title (the column's ENUM values happen to match) but was
        // silently wrong for gender (DB stores 'M'/'F' short codes; Gibbon's
        // form displays 'Male'/'Female' via this hardcoded array, not the
        // raw column value). Using the literal same lists Gibbon uses.
        echo json_encode([
            'success'                 => true,
            'roles'                   => $roles,
            'yearGroups'              => $yearGroups,
            'formGroups'              => $formGroups,
            'titles'                  => ['Ms.', 'Miss', 'Mr.', 'Mrs.', 'Dr.', 'Prof.'],
            'genders'                 => [
                ['value' => 'F', 'label' => 'Female'],
                ['value' => 'M', 'label' => 'Male'],
                ['value' => 'Other', 'label' => 'Other'],
                ['value' => 'Unspecified', 'label' => 'Unspecified'],
            ],
            'statuses'                => gibbonConnectEnumValues($pdo, 'gibbonPerson', 'status'),
            'emergencyRelationships'  => [
                'Parent', 'Spouse', 'Offspring', 'Guardian', 'Grandmother', 'Grandfather',
                'Sibling', 'Friend', 'Other Relation', 'Doctor', 'Other',
            ],
            // Confirmed directly: this school uses exactly two staff
            // categories, not an open free-text field — same treatment as
            // Title/Gender/Emergency Relationship above.
            'staffTypes'              => ['Teaching', 'Support'],
            'passwordPolicy'          => gibbonConnectPasswordPolicy($pdo),
        ]);
        exit;
    }

    // ── LIST ─────────────────────────────────────────────────────────────────
    if ($action === 'list') {
        $search = trim($input['search'] ?? '');
        $page   = max(1, (int)($input['page'] ?? 1));
        $limit  = min(50, max(1, (int)($input['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        // Filters mirror Gibbon core's own SharedUserLogic::getSharedUserFilterRules()
        // exactly (source verified) — role filters by gibbonRole.category
        // (Student/Parent/Staff, an umbrella grouping distinct from the
        // specific role name — 'Staff' covers Administrator/Teacher/Support
        // Staff together), status by gibbonPerson.status (Full/Left/Expected),
        // date by dateStart/dateEnd relative to today. No default is applied
        // here — every filter is opt-in, matching what was actually asked for.
        $role   = trim($input['role'] ?? '');   // student | parent | staff
        $status = trim($input['status'] ?? ''); // full | left | expected
        $date   = trim($input['date'] ?? '');   // starting | ended

        $where  = "1=1";
        $params = [];
        if ($search !== '') {
            $where .= " AND (p.preferredName LIKE ? OR p.surname LIKE ? OR p.username LIKE ?
                        OR p.email LIKE ? OR p.studentID LIKE ?)";
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }
        if (!empty($role)) {
            $where .= " AND r.category = ?";
            $params[] = ucfirst($role);
        }
        if (!empty($status)) {
            $where .= " AND p.status = ?";
            $params[] = ucfirst($status);
        }
        if ($date === 'starting') {
            $where .= " AND p.dateStart IS NOT NULL AND p.dateStart >= ?";
            $params[] = date('Y-m-d');
        } elseif ($date === 'ended') {
            $where .= " AND p.dateEnd IS NOT NULL AND p.dateEnd <= ?";
            $params[] = date('Y-m-d');
        }

        // Both queries now need the gibbonRole join, since a role filter
        // can apply to either — previously COUNT didn't join it at all,
        // which only worked because no filter ever referenced it.
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM gibbonPerson p
            LEFT JOIN gibbonRole r ON r.gibbonRoleID = p.gibbonRoleIDPrimary
            WHERE $where
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                p.gibbonPersonID, p.title, p.preferredName, p.surname,
                p.username, p.email, p.status, p.image_240,
                r.name AS roleName, r.category AS roleCategory
            FROM gibbonPerson p
            LEFT JOIN gibbonRole r ON r.gibbonRoleID = p.gibbonRoleIDPrimary
            WHERE $where
            ORDER BY p.surname, p.preferredName
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'users'   => $users,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
        ]);
        exit;
    }

    // ── GET ONE ──────────────────────────────────────────────────────────────
    if ($action === 'get') {
        $personID = trim($input['gibbonPersonID'] ?? '');
        if (empty($personID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonPersonID required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM gibbonPerson WHERE gibbonPersonID = ? LIMIT 1");
        $stmt->execute([$personID]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        unset($person['passwordStrong'], $person['passwordStrongSalt']);

        $staffStmt = $pdo->prepare("SELECT jobTitle, type FROM gibbonStaff WHERE gibbonPersonID = ? LIMIT 1");
        $staffStmt->execute([$personID]);
        $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);

        // Enrolment for the ACTIVE (possibly switched) school year — editing
        // a student's year/form group applies to whichever year the admin
        // is currently viewing, consistent with the rest of the app.
        $enrolStmt = $pdo->prepare("
            SELECT gibbonYearGroupID, gibbonFormGroupID, rollOrder
            FROM gibbonStudentEnrolment
            WHERE gibbonPersonID = ? AND gibbonSchoolYearID = ? LIMIT 1
        ");
        $enrolStmt->execute([$personID, $authUser['schoolYearID']]);
        $enrolment = $enrolStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'   => true,
            'person'    => $person,
            'staff'     => $staff ?: null,
            'enrolment' => $enrolment ?: null,
        ]);
        exit;
    }

    // ── ADD ──────────────────────────────────────────────────────────────────
    if ($action === 'add') {
        $title          = trim($input['title'] ?? '');
        $firstName      = trim($input['firstName'] ?? '');
        $surname        = trim($input['surname'] ?? '');
        $preferredName  = trim($input['preferredName'] ?? '');
        $officialName   = trim($input['officialName'] ?? '');
        $nameInCharacters = trim($input['nameInCharacters'] ?? '');
        $gender         = trim($input['gender'] ?? '');
        $gibbonRoleID   = trim($input['gibbonRoleIDPrimary'] ?? '');
        $gibbonRoleIDAll = gibbonConnectBuildRoleIDAll($gibbonRoleID, $input);
        $username       = trim($input['username'] ?? '');
        $password       = $input['password'] ?? '';
        $status         = trim($input['status'] ?? 'Full');
        $canLogin       = trim($input['canLogin'] ?? 'Y');
        $passwordForceReset = trim($input['passwordForceReset'] ?? 'N');
        $email          = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $dob            = !empty($input['dob']) ? trim($input['dob']) : null;

        if (empty($firstName) || empty($surname) || empty($preferredName) || empty($officialName) || empty($gender)
            || empty($gibbonRoleID) || empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        $passwordErrors = gibbonConnectPasswordErrors($password, gibbonConnectPasswordPolicy($pdo));
        if (!empty($passwordErrors)) {
            echo json_encode(['success' => false, 'message' => 'Password must contain ' . implode(', ', $passwordErrors)]);
            exit;
        }

        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonPerson WHERE username = ?");
        $dupStmt->execute([$username]);
        if ($dupStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'That username is already taken']);
            exit;
        }
        if (!empty($email)) {
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonPerson WHERE email = ?");
            $dupStmt->execute([$email]);
            if ($dupStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'That email is already in use']);
                exit;
            }
        }

        $salt = bin2hex(random_bytes(16));
        $passwordStrong = hash('sha256', $salt . $password);

        $pdo->beginTransaction();
        try {
            $explicitColumns = [
                'title', 'firstName', 'surname', 'preferredName', 'officialName', 'nameInCharacters', 'gender',
                'gibbonRoleIDPrimary', 'gibbonRoleIDAll', 'username', 'passwordStrong', 'passwordStrongSalt',
                'status', 'canLogin', 'passwordForceReset', 'email', 'dob',
                'emergency1Name', 'emergency1Number1', 'emergency1Number2', 'emergency1Relationship',
                'emergency2Name', 'emergency2Number1', 'emergency2Number2', 'emergency2Relationship',
            ];
            $autoFill = gibbonConnectAutoFillRequired($pdo, 'gibbonPerson', $explicitColumns);

            $insert = $pdo->prepare("
                INSERT INTO gibbonPerson SET
                    title = :title, firstName = :firstName, surname = :surname,
                    preferredName = :preferredName, officialName = :officialName,
                    nameInCharacters = :nameInCharacters, gender = :gender,
                    gibbonRoleIDPrimary = :roleID, gibbonRoleIDAll = :roleIDAll,
                    username = :username, passwordStrong = :passwordStrong,
                    passwordStrongSalt = :salt, status = :status, canLogin = :canLogin,
                    passwordForceReset = :passwordForceReset,
                    email = :email, dob = :dob,
                    emergency1Name = :e1n, emergency1Number1 = :e1p1, emergency1Number2 = :e1p2,
                    emergency1Relationship = :e1r,
                    emergency2Name = :e2n, emergency2Number1 = :e2p1, emergency2Number2 = :e2p2,
                    emergency2Relationship = :e2r
                    " . (!empty($autoFill['sql']) ? ', ' . $autoFill['sql'] : '') . "
            ");
            $insert->execute(array_merge([
                'title' => $title, 'firstName' => $firstName, 'surname' => $surname,
                'preferredName' => $preferredName, 'officialName' => $officialName,
                'nameInCharacters' => $nameInCharacters, 'gender' => $gender,
                'roleID' => $gibbonRoleID, 'roleIDAll' => $gibbonRoleIDAll,
                'username' => $username, 'passwordStrong' => $passwordStrong, 'salt' => $salt,
                'status' => $status, 'canLogin' => $canLogin, 'passwordForceReset' => $passwordForceReset,
                'email' => $email, 'dob' => $dob,
                'e1n' => trim($input['emergency1Name'] ?? ''), 'e1p1' => trim($input['emergency1Number1'] ?? ''),
                'e1p2' => trim($input['emergency1Number2'] ?? ''), 'e1r' => trim($input['emergency1Relationship'] ?? ''),
                'e2n' => trim($input['emergency2Name'] ?? ''), 'e2p1' => trim($input['emergency2Number1'] ?? ''),
                'e2p2' => trim($input['emergency2Number2'] ?? ''), 'e2r' => trim($input['emergency2Relationship'] ?? ''),
            ], $autoFill['params']));
            $personID = str_pad($pdo->lastInsertId(), 10, '0', STR_PAD_LEFT);

            // Best-effort status log — matches Gibbon's own "Created" entry.
            // Wrapped so a missing/renamed table on older installs can't
            // break the actual user creation.
            try {
                $log = $pdo->prepare("
                    INSERT INTO gibbonUserStatusLog (gibbonPersonID, statusOld, statusNew, reason, gibbonPersonIDModified, timestamp)
                    VALUES (?, ?, ?, 'Created', ?, NOW())
                ");
                $log->execute([$personID, $status, $status, $authUser['personID']]);
            } catch (PDOException $e) { /* non-fatal */ }

            // Staff sub-record
            if (($input['staffRecord'] ?? 'N') === 'Y') {
                $staffInsert = $pdo->prepare("
                    INSERT INTO gibbonStaff (gibbonPersonID, jobTitle, type) VALUES (?, ?, ?)
                ");
                $staffInsert->execute([
                    $personID, trim($input['jobTitle'] ?? ''), trim($input['staffType'] ?? ''),
                ]);
            }

            // Student sub-record — enrolled in whichever year the admin is
            // currently viewing (see 'get' action comment above).
            if (($input['studentRecord'] ?? 'N') === 'Y') {
                $studentIDValue = trim($input['studentID'] ?? '');
                if (!empty($studentIDValue)) {
                    $sidCheck = $pdo->prepare("SELECT COUNT(*) FROM gibbonPerson WHERE studentID = ? AND gibbonPersonID <> ?");
                    $sidCheck->execute([$studentIDValue, $personID]);
                    if ($sidCheck->fetchColumn() > 0) {
                        throw new Exception('That Student ID is already in use');
                    }
                    $pdo->prepare("UPDATE gibbonPerson SET studentID = ? WHERE gibbonPersonID = ?")
                        ->execute([$studentIDValue, $personID]);
                }

                $enrolInsert = $pdo->prepare("
                    INSERT INTO gibbonStudentEnrolment
                        (gibbonPersonID, gibbonSchoolYearID, gibbonYearGroupID, gibbonFormGroupID)
                    VALUES (?, ?, ?, ?)
                ");
                $enrolInsert->execute([
                    $personID, $authUser['schoolYearID'],
                    trim($input['gibbonYearGroupID'] ?? ''), trim($input['gibbonFormGroupID'] ?? ''),
                ]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Could not create user']);
            exit;
        }

        // Photo — handled outside the transaction since it's filesystem,
        // not DB; a failure here shouldn't undo the user record.
        if (!empty($_FILES['photo']['tmp_name'])) {
            $relativePath = gibbonConnectSavePhoto($_FILES['photo']['tmp_name'], $username);
            if ($relativePath) {
                $pdo->prepare("UPDATE gibbonPerson SET image_240 = ? WHERE gibbonPersonID = ?")
                    ->execute([$relativePath, $personID]);
            }
        }

        echo json_encode(['success' => true, 'gibbonPersonID' => $personID]);
        exit;
    }

    // ── EDIT ─────────────────────────────────────────────────────────────────
    if ($action === 'edit') {
        $personID = trim($input['gibbonPersonID'] ?? '');
        if (empty($personID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonPersonID required']);
            exit;
        }

        $existsStmt = $pdo->prepare("SELECT username, status FROM gibbonPerson WHERE gibbonPersonID = ? LIMIT 1");
        $existsStmt->execute([$personID]);
        $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }

        $title         = trim($input['title'] ?? '');
        $firstName     = trim($input['firstName'] ?? '');
        $surname       = trim($input['surname'] ?? '');
        $preferredName = trim($input['preferredName'] ?? '');
        $officialName  = trim($input['officialName'] ?? '');
        $nameInCharacters = trim($input['nameInCharacters'] ?? '');
        $gender        = trim($input['gender'] ?? '');
        $gibbonRoleID  = trim($input['gibbonRoleIDPrimary'] ?? '');
        $gibbonRoleIDAll = gibbonConnectBuildRoleIDAll($gibbonRoleID, $input);
        $username      = trim($input['username'] ?? '');
        $status        = trim($input['status'] ?? 'Full');
        $canLogin      = trim($input['canLogin'] ?? 'Y');
        $passwordForceReset = trim($input['passwordForceReset'] ?? 'N');
        $email         = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $dob           = !empty($input['dob']) ? trim($input['dob']) : null;

        if (empty($firstName) || empty($surname) || empty($preferredName) || empty($officialName) || empty($gender)
            || empty($gibbonRoleID) || empty($username)) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }

        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonPerson WHERE username = ? AND gibbonPersonID <> ?");
        $dupStmt->execute([$username, $personID]);
        if ($dupStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'That username is already taken']);
            exit;
        }
        if (!empty($email)) {
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonPerson WHERE email = ? AND gibbonPersonID <> ?");
            $dupStmt->execute([$email, $personID]);
            if ($dupStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'That email is already in use']);
                exit;
            }
        }

        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare("
                UPDATE gibbonPerson SET
                    title = :title, firstName = :firstName, surname = :surname,
                    preferredName = :preferredName, officialName = :officialName,
                    nameInCharacters = :nameInCharacters, gender = :gender,
                    gibbonRoleIDPrimary = :roleID, gibbonRoleIDAll = :roleIDAll,
                    username = :username, status = :status, canLogin = :canLogin,
                    passwordForceReset = :passwordForceReset, email = :email, dob = :dob,
                    emergency1Name = :e1n, emergency1Number1 = :e1p1, emergency1Number2 = :e1p2,
                    emergency1Relationship = :e1r,
                    emergency2Name = :e2n, emergency2Number1 = :e2p1, emergency2Number2 = :e2p2,
                    emergency2Relationship = :e2r
                WHERE gibbonPersonID = :personID
            ");
            $update->execute([
                'title' => $title, 'firstName' => $firstName, 'surname' => $surname,
                'preferredName' => $preferredName, 'officialName' => $officialName,
                'nameInCharacters' => $nameInCharacters, 'gender' => $gender,
                'roleID' => $gibbonRoleID, 'roleIDAll' => $gibbonRoleIDAll,
                'username' => $username, 'status' => $status, 'canLogin' => $canLogin,
                'passwordForceReset' => $passwordForceReset, 'email' => $email, 'dob' => $dob,
                'e1n' => trim($input['emergency1Name'] ?? ''), 'e1p1' => trim($input['emergency1Number1'] ?? ''),
                'e1p2' => trim($input['emergency1Number2'] ?? ''), 'e1r' => trim($input['emergency1Relationship'] ?? ''),
                'e2n' => trim($input['emergency2Name'] ?? ''), 'e2p1' => trim($input['emergency2Number1'] ?? ''),
                'e2p2' => trim($input['emergency2Number2'] ?? ''), 'e2r' => trim($input['emergency2Relationship'] ?? ''),
                'personID' => $personID,
            ]);

            if ($existing['status'] !== $status) {
                try {
                    $log = $pdo->prepare("
                        INSERT INTO gibbonUserStatusLog (gibbonPersonID, statusOld, statusNew, reason, gibbonPersonIDModified, timestamp)
                        VALUES (?, ?, ?, 'Edited via School Connect', ?, NOW())
                    ");
                    $log->execute([$personID, $existing['status'], $status, $authUser['personID']]);
                } catch (PDOException $e) { /* non-fatal */ }
            }

            // Staff sub-record — upsert
            if (($input['staffRecord'] ?? 'N') === 'Y') {
                $checkStaff = $pdo->prepare("SELECT gibbonPersonID FROM gibbonStaff WHERE gibbonPersonID = ? LIMIT 1");
                $checkStaff->execute([$personID]);
                if ($checkStaff->fetch()) {
                    $pdo->prepare("UPDATE gibbonStaff SET jobTitle = ?, type = ? WHERE gibbonPersonID = ?")
                        ->execute([trim($input['jobTitle'] ?? ''), trim($input['staffType'] ?? ''), $personID]);
                } else {
                    $pdo->prepare("INSERT INTO gibbonStaff (gibbonPersonID, jobTitle, type) VALUES (?, ?, ?)")
                        ->execute([$personID, trim($input['jobTitle'] ?? ''), trim($input['staffType'] ?? '')]);
                }
            }

            // Student sub-record — upsert for the ACTIVE school year
            if (($input['studentRecord'] ?? 'N') === 'Y') {
                $studentIDValue = trim($input['studentID'] ?? '');
                if (!empty($studentIDValue)) {
                    $sidCheck = $pdo->prepare("SELECT COUNT(*) FROM gibbonPerson WHERE studentID = ? AND gibbonPersonID <> ?");
                    $sidCheck->execute([$studentIDValue, $personID]);
                    if ($sidCheck->fetchColumn() > 0) {
                        throw new Exception('That Student ID is already in use');
                    }
                    $pdo->prepare("UPDATE gibbonPerson SET studentID = ? WHERE gibbonPersonID = ?")
                        ->execute([$studentIDValue, $personID]);
                }

                $checkEnrol = $pdo->prepare("
                    SELECT gibbonStudentEnrolmentID FROM gibbonStudentEnrolment
                    WHERE gibbonPersonID = ? AND gibbonSchoolYearID = ? LIMIT 1
                ");
                $checkEnrol->execute([$personID, $authUser['schoolYearID']]);
                $enrolRow = $checkEnrol->fetch(PDO::FETCH_ASSOC);

                if ($enrolRow) {
                    $pdo->prepare("
                        UPDATE gibbonStudentEnrolment SET gibbonYearGroupID = ?, gibbonFormGroupID = ?
                        WHERE gibbonStudentEnrolmentID = ?
                    ")->execute([
                        trim($input['gibbonYearGroupID'] ?? ''), trim($input['gibbonFormGroupID'] ?? ''),
                        $enrolRow['gibbonStudentEnrolmentID'],
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO gibbonStudentEnrolment
                            (gibbonPersonID, gibbonSchoolYearID, gibbonYearGroupID, gibbonFormGroupID)
                        VALUES (?, ?, ?, ?)
                    ")->execute([
                        $personID, $authUser['schoolYearID'],
                        trim($input['gibbonYearGroupID'] ?? ''), trim($input['gibbonFormGroupID'] ?? ''),
                    ]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Could not update user']);
            exit;
        }

        if (!empty($_FILES['photo']['tmp_name'])) {
            $relativePath = gibbonConnectSavePhoto($_FILES['photo']['tmp_name'], $username);
            if ($relativePath) {
                $pdo->prepare("UPDATE gibbonPerson SET image_240 = ? WHERE gibbonPersonID = ?")
                    ->execute([$relativePath, $personID]);
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ── DELETE ───────────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $personID = trim($input['gibbonPersonID'] ?? '');
        if (empty($personID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonPersonID required']);
            exit;
        }
        if ($personID === $authUser['personID']) {
            echo json_encode(['success' => false, 'message' => 'You cannot delete your own account']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM gibbonPerson WHERE gibbonPersonID = ?");
        $stmt->execute([$personID]);

        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit;
    }

    // ── RESET PASSWORD ───────────────────────────────────────────────────────
    if ($action === 'reset_password') {
        $personID    = trim($input['gibbonPersonID'] ?? '');
        $newPassword = $input['newPassword'] ?? '';
        $forceReset  = trim($input['forceReset'] ?? 'N');

        if (empty($personID) || empty($newPassword)) {
            echo json_encode(['success' => false, 'message' => 'gibbonPersonID and newPassword required']);
            exit;
        }
        $passwordErrors = gibbonConnectPasswordErrors($newPassword, gibbonConnectPasswordPolicy($pdo));
        if (!empty($passwordErrors)) {
            echo json_encode(['success' => false, 'message' => 'Password must contain ' . implode(', ', $passwordErrors)]);
            exit;
        }

        $salt = bin2hex(random_bytes(16));
        $passwordStrong = hash('sha256', $salt . $newPassword);

        $stmt = $pdo->prepare("
            UPDATE gibbonPerson
            SET passwordStrong = ?, passwordStrongSalt = ?, passwordForceReset = ?, failCount = 0
            WHERE gibbonPersonID = ?
        ");
        $stmt->execute([$passwordStrong, $salt, $forceReset, $personID]);

        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit;
    }

    // ── ACTION: login_troubleshoot ─────────────────────────────────────────
    // Faithful port of the "Login Troubleshooting" panel in Gibbon core's
    // user_manage_password.php (source verified) — same five checks, same
    // failCount < 3 lockout threshold, same canLoginRole column on
    // gibbonRole (a role-level flag, distinct from gibbonPerson's own
    // canLogin — Gibbon deliberately uses different column names for these
    // two different things).
    if ($action === 'login_troubleshoot') {
        $personID = trim($input['gibbonPersonID'] ?? '');
        if (empty($personID)) {
            echo json_encode(['success' => false, 'message' => 'gibbonPersonID required']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT status, failCount, canLogin, email, lastTimestamp, lastIPAddress, gibbonRoleIDPrimary
            FROM gibbonPerson WHERE gibbonPersonID = ? LIMIT 1
        ");
        $stmt->execute([$personID]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }

        $roleStmt = $pdo->prepare("SELECT name, canLoginRole FROM gibbonRole WHERE gibbonRoleID = ? LIMIT 1");
        $roleStmt->execute([$person['gibbonRoleIDPrimary']]);
        $role = $roleStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => '', 'canLoginRole' => 'N'];

        // Empty email has nothing to collide with — treat as trivially unique,
        // matching the practical intent of the check rather than flagging
        // every blank-email user as a false duplicate of every other one.
        $emailUnique = true;
        if (!empty($person['email'])) {
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM gibbonPerson WHERE email = ? AND gibbonPersonID <> ?");
            $dupStmt->execute([$person['email'], $personID]);
            $emailUnique = $dupStmt->fetchColumn() == 0;
        }

        echo json_encode([
            'success' => true,
            'status' => $person['status'],
            'statusOk' => $person['status'] === 'Full',
            'failCount' => (int)$person['failCount'],
            'failCountOk' => (int)$person['failCount'] < 3,
            'canLoginUser' => $person['canLogin'] === 'Y',
            'roleName' => $role['name'],
            'canLoginRole' => $role['canLoginRole'] === 'Y',
            'email' => $person['email'],
            'emailUnique' => $emailUnique,
            'lastTimestamp' => $person['lastTimestamp'],
            'lastIPAddress' => $person['lastIPAddress'],
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('School Connect admin_users.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
}
?>
