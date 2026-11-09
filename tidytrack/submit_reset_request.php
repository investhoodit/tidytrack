<?php
session_start();
require_once 'dp.php';

header('Content-Type: application/json');

$identifier = $_POST['identifier'] ?? '';
$reason = $_POST['reason'] ?? '';
$other_reason = $_POST['other_reason'] ?? '';
$new_password = $_POST['new_password'] ?? '';

if (empty($identifier) || empty($reason) || empty($new_password) || ($reason === 'other' && empty($other_reason))) {
    echo json_encode(['success' => false, 'error' => 'All fields are required']);
    exit;
}

if (strlen($new_password) < 8) {
    echo json_encode(['success' => false, 'error' => 'New password must be at least 8 characters']);
    exit;
}

// Verify user exists (any role)
$stmt = $conn->prepare("SELECT user_id FROM users WHERE name = ? OR email = ?");
$stmt->bind_param('ss', $identifier, $identifier);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// Store reset request
$new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("
    INSERT INTO password_reset_requests (user_id, identifier, reason, other_reason, new_password_hash, request_time, status)
    VALUES (?, ?, ?, ?, ?, NOW(), 'pending')
");
$stmt->bind_param('issss', $user['user_id'], $identifier, $reason, $other_reason, $new_password_hash);
$success = $stmt->execute();
$stmt->close();
$conn->close();

if ($success) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to submit request']);
}