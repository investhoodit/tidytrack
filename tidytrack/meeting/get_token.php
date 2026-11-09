<?php
session_start();
header('Content-Type: application/json');

// 1. Database Connection
// Check if the path is correct. If your file is in /meeting/, and dp.php is in the folder above:
if (file_exists('../dp.php')) {
    require_once '../dp.php';
} else {
    echo json_encode(['error' => 'Database connection file (dp.php) not found at ../dp.php']);
    exit;
}

// 2. Safety Check: Check if the settings table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'meeting_settings'");
if ($tableCheck->num_rows == 0) {
    // Table is missing! Let's return a safe token but log the error
    error_log("Meeting settings table missing. Allowing connection anyway.");
} else {
    // Table exists, check minutes
    $res = $conn->query("SELECT monthly_balance, used_this_month FROM meeting_settings WHERE id = 1");
    $settings = $res->fetch_assoc();

    if ($settings && $settings['used_this_month'] >= $settings['monthly_balance']) {
        http_response_code(403);
        echo json_encode(['error' => 'Monthly limit reached.']);
        exit;
    }
}

// 3. Token Generation
$API_KEY = getenv('TIDYTRACK_LIVEKIT_API_KEY') ?: '';
$API_SECRET = getenv('TIDYTRACK_LIVEKIT_API_SECRET') ?: '';

if ($API_KEY === '' || $API_SECRET === '') {
    error_log('TidyTrack LiveKit configuration is incomplete.');
    http_response_code(500);
    echo json_encode(['error' => 'Meeting service is not configured.']);
    exit;
}

$room = $_GET['room'] ?? 'Lobby';
$participant = $_SESSION['name'] ?? 'User_' . rand(100, 999);

function generateLiveKitToken($key, $secret, $room, $identity) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode([
        'exp' => time() + 3600,
        'iss' => $key,
        'sub' => $identity,
        'video' => [
            'roomJoin' => true,
            'room' => $room,
            'canPublish' => true,
            'canSubscribe' => true,
            'roomCreate' => true
        ],
        'name' => $identity
    ]);

    $bH = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $bP = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    $sig = hash_hmac('sha256', "$bH.$bP", $secret, true);
    $bS = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($sig));
    return "$bH.$bP.$bS";
}

try {
    $token = generateLiveKitToken($API_KEY, $API_SECRET, $room, $participant);
    echo json_encode(['token' => $token]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}