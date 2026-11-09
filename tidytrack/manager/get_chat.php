<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$room = $_GET['room'] ?? '';
if (!$room) {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("
    SELECT c.message, c.sent_at, u.name 
    FROM meeting_chat c
    JOIN users u ON c.user_id = u.user_id
    WHERE c.room_code = ?
    ORDER BY c.sent_at ASC
");
$stmt->bind_param("s", $room);
$stmt->execute();
$res = $stmt->get_result();

$chat = [];
while ($row = $res->fetch_assoc()) {
    $chat[] = $row;
}
$stmt->close();

echo json_encode($chat);
?>