<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
$data = json_decode(file_get_contents('php://input'), true);
$room = $data['room'] ?? '';
$message = trim($data['message'] ?? '');

if (!$userId || !$room || $message === '') {
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $conn->prepare("INSERT INTO meeting_chat (room_code, user_id, message, sent_at) VALUES (?, ?, ?, NOW())");
$stmt->bind_param("sis", $room, $userId, $message);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
?>