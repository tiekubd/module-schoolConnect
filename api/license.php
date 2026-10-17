<?php
/*
School Connect - Mobile API Module
Copyright (C) 2026, High Point Education
*/

// license.php — shared licence check, included by login.php,
// login_google.php and session_check.php (not an endpoint itself).
//
// Asks High Point's licence server whether this school may use School
// Connect, sending ONLY this server's own host name (HTTP_HOST). No user,
// student or session data is ever sent.
//
// A successful "allowed" answer is cached on the school's own server for
// 24 hours (table gibbonConnectLicenseCache), so the licence server is
// contacted at most once a day per school instead of at every sign-in and
// session check. This is what the School Connect privacy policy promises.
//
// Deliberate choices:
//   - Only "allowed" answers are cached. A "not allowed" answer is
//     rechecked every time, so a school that has just been registered or
//     reactivated never waits up to a day to get in.
//   - A cached answer can run up to 24 hours past a trial's end date, and
//     trialDaysLeft can be up to a day stale. Both are acceptable.
//   - Fails OPEN exactly as before: if the licence server is unreachable
//     or anything errors, login is allowed, so a network hiccup never
//     locks out a school mid-class.
//   - Cache problems (e.g. no CREATE privilege) never block the remote
//     check; they only mean the result isn't cached.
//
// Returns [bool $allowed, ?array $licenseData].

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

function gibbonConnectCheckLicense(PDO $pdo): array
{
    $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));

    // ── Cached answer (less than 24 hours old) ───────────────────────────────
    $cacheAvailable = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `gibbonConnectLicenseCache` (
              `schoolHost` varchar(255) NOT NULL,
              `response` text NOT NULL,
              `checkedAt` datetime NOT NULL,
              PRIMARY KEY (`schoolHost`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // NOW() on both sides, so PHP's timezone setting never matters here
        $stmt = $pdo->prepare("
            SELECT response FROM gibbonConnectLicenseCache
            WHERE schoolHost = ? AND checkedAt > (NOW() - INTERVAL 1 DAY)
            LIMIT 1
        ");
        $stmt->execute([$host]);
        $cached = $stmt->fetchColumn();
        if ($cached !== false) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return [true, $decoded];
            }
        }
    } catch (Throwable $e) {
        $cacheAvailable = false;
    }

    // ── Ask the licence server ───────────────────────────────────────────────
    try {
        $licenseUrl = 'https://portal.highpointedu.com/license/check.php?school_url='
                    . urlencode($host);

        if (function_exists('curl_init')) {
            $ch = curl_init($licenseUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $licenseResponse = curl_exec($ch);
            curl_close($ch);
        } elseif (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => ['timeout' => 3]]);
            $licenseResponse = @file_get_contents($licenseUrl, false, $ctx);
        } else {
            $licenseResponse = false;
        }

        if ($licenseResponse === false) {
            return [true, null]; // unreachable: fail open, retry next time
        }

        $licenseData = json_decode($licenseResponse, true);
        if (!is_array($licenseData)) {
            return [true, null]; // unreadable answer: fail open
        }

        if (isset($licenseData['allowed']) && $licenseData['allowed'] === false) {
            return [false, $licenseData]; // never cached, see header
        }

        if ($cacheAvailable) {
            try {
                $encoded = json_encode($licenseData);
                $upsert = $pdo->prepare("
                    INSERT INTO gibbonConnectLicenseCache (schoolHost, response, checkedAt)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE response = ?, checkedAt = NOW()
                ");
                $upsert->execute([$host, $encoded, $encoded]);
            } catch (Throwable $e) {
                // Not cached this time; the next sign-in simply checks again.
            }
        }

        return [true, $licenseData];
    } catch (Throwable $e) {
        return [true, null]; // any unexpected error: fail open
    }
}
