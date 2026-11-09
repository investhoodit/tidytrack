<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get JSON input (from fetch body)
$input = json_decode(file_get_contents('php://input'), true);
$sender_id = $input['sender_id'] ?? null;

if (!$sender_id || !is_numeric($sender_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid sender']);
    exit;
}

$sender_id = (int)$sender_id;

// Mark messages as read
$stmt = $conn->prepare("
    UPDATE messages 
    SET is_read = 1 
    WHERE receiver_id = ? 
      AND sender_id = ? 
      AND is_read = 0
");
$stmt->bind_param("ii", $user_id, $sender_id);
$success = $stmt->execute();

echo json_encode(['success' => $success]);

$stmt->close();
$conn->close();
?>