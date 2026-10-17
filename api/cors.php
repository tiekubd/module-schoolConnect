<?php
// cors.php — include at the top of every API file
// Allows Flutter web (localhost) and any other origin to call the API

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Browsers send a preflight OPTIONS request before POST — handle it here
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
