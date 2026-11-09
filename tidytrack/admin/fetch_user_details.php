<?php
session_start();
require_once __DIR__ . '/../dp.php';

// --- AUTH GUARD ---
$userId = $_SESSION['user_id'] ?? null;
$role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed = ['admin'];
if (!$userId || !in_array($role, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID not provided.']);
    exit;
}

$targetUserId = (int)$_GET['id'];
$stmt = $conn->prepare("
    SELECT u.user_id, u.name, u.email, u.phone, u.role, u.status, u.profile_picture, u.department, u.bio,
           r.request_id, r.reason, r.other_reason
    FROM users u
    LEFT JOIN password_reset_requests r ON u.user_id = r.user_id AND r.status = 'pending'
    WHERE u.user_id = ? LIMIT 1
");
$stmt->bind_param("i", $targetUserId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$user) {
    echo json_encode(['error' => 'User not found.']);
    exit;
}

// Add user initial for avatar if no picture exists
$user['initial'] = strtoupper($user['name'][0] ?? 'U');

// Prepare response with reset request details
$response = [
    'user_id' => $user['user_id'],
    'name' => htmlspecialchars($user['name']),
    'email' => htmlspecialchars($user['email']),
    'phone' => $user['phone'] ? htmlspecialchars($user['phone']) : null,
    'role' => htmlspecialchars($user['role']),
    'status' => htmlspecialchars($user['status']),
    'profile_picture' => $user['profile_picture'] ? htmlspecialchars($user['profile_picture']) : null,
    'department' => $user['department'] ? htmlspecialchars($user['department']) : null,
    'bio' => $user['bio'] ? htmlspecialchars($user['bio']) : null,
    'initial' => $user['initial'],
    'reset_request' => !empty($user['request_id']),
    'request_id' => $user['request_id'] ? (int)$user['request_id'] : null,
    'reset_reason' => $user['reason'] ? htmlspecialchars($user['reason']) : null,
    'reset_other_reason' => $user['other_reason'] ? htmlspecialchars($user['other_reason']) : null
];

header('Content-Type: application/json');
echo json_encode($response);