<?php
session_start();
include 'dp.php'; 

// === CONFIGURATION ===
$client_id = getenv('TIDYTRACK_GOOGLE_CLIENT_ID') ?: '';
$client_secret = getenv('TIDYTRACK_GOOGLE_CLIENT_SECRET') ?: '';
$redirect_uri = getenv('TIDYTRACK_GOOGLE_REDIRECT_URI') ?: '';

if ($client_id === '' || $client_secret === '' || $redirect_uri === '') {
    error_log('TidyTrack Google sign-in configuration is incomplete.');
    http_response_code(500);
    exit('Google sign-in configuration is incomplete.');
}

// --- STEP 1: Verify we have the "code" from Google ---
if (!isset($_GET['code']) || empty($_GET['code'])) {
    header("Location: google.php"); 
    exit();
}

$code = $_GET['code'];

// --- STEP 2: Exchange code for access token ---
$token_url = 'https://oauth2.googleapis.com/token';
$post_data = [
    'code'          => $code,
    'client_id'     => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri'  => $redirect_uri,
    'grant_type'    => 'authorization_code'
];

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$token_response = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($token_response, true);
$access_token = $token_data['access_token'] ?? null;

if (!$access_token) {
    echo "<h1>Google Token Exchange Failed!</h1>";
    exit();
}

// --- STEP 3: Fetch user profile ---
$userinfo_url = 'https://www.googleapis.com/oauth2/v3/userinfo';
$ch = curl_init($userinfo_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
$userinfo_response = curl_exec($ch);
curl_close($ch);

$user_data = json_decode($userinfo_response, true);

if (!isset($user_data['email'])) {
    echo "<h1>Failed to get user info from Google</h1>";
    exit();
}

$email = $user_data['email'];
$name  = $user_data['name'] ?? 'Google User';

// --- STEP 4: Check user in database + isallowed ---
$stmt = $conn->prepare("SELECT user_id, name, role, isallowed, appeal FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();

    // === NEW: Check if user is banned ===
    if ($row['isallowed'] == 0) {
    $_SESSION['ban_message'] = "Your account has been temporarily suspended due to missing daily reports (3+ times in a week).";
    if (!empty($row['appeal'])) {
        $_SESSION['ban_message'] .= "<br><br><strong>Your Previous Appeal:</strong> " . htmlspecialchars($row['appeal']);
    }
    $_SESSION['user_id'] = $row['user_id']; // for appeal submission
    header("Location: account_suspended.php");
    exit();
}

    // === Login Success ===
    $_SESSION['user_id'] = $row['user_id'];
    $_SESSION['name']    = $row['name'];
    $_SESSION['role']    = $row['role'];
    $_SESSION['logged_in'] = true;

    header("Location: index.php");
    exit();
} 
else {
    // New Google user → go to role selection
    $_SESSION['temp_email'] = $email;
    $_SESSION['temp_name']  = $name;
    $_SESSION['google_user'] = true;

    header("Location: select-role.php");
    exit();
}
?>