<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$sender_id = $_SESSION['user_id'] ?? null;
$receiver_id = $_POST['receiver_id'] ?? null;
$message = trim($_POST['message'] ?? '');

if (!$sender_id || !$receiver_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid user']);
    exit;
}

// Allow empty message only if there's a file attachment (not implemented yet)
if ($message === '') {
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, timestamp) VALUES (?, ?, ?, NOW())");
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("iis", $sender_id, $receiver_id, $message);
$executed = $stmt->execute();

if ($executed) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Insert failed: ' . $stmt->error]);
}

$stmt->close();
