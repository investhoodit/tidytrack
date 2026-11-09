<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$room = $_GET['room'] ?? null;

if (!$userId || !$room) {
    http_response_code(400);
    exit(json_encode([]));
}

$stmt = $conn->prepare("
    SELECT r.emoji, r.user_id 
    FROM meeting_reactions r 
    WHERE r.room_code = ? 
    ORDER BY r.id ASC
");
$stmt->bind_param("s", $room);
$stmt->execute();
$result = $stmt->get_result();

$reactions = [];
while ($row = $result->fetch_assoc()) {
    $reactions[] = ['emoji' => $row['emoji'], 'user_id' => $row['user_id']];
}
$stmt->close();

// Delete after reading
$conn->query("DELETE FROM meeting_reactions WHERE room_code = '" . $conn->real_escape_string($room) . "'");

header('Content-Type: application/json');
echo json_encode($reactions);
?>