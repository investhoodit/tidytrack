<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? 0;
$room = $_GET['room'] ?? '';

if (!$userId || !preg_match('/^[A-Z0-9]{4,8}$/', $room)) {
    die(json_encode([]));
}

// 1. UPDATE current user's last_seen (Heartbeat)
$stmt = $conn->prepare("UPDATE meeting_rooms SET joined_at = NOW() WHERE room_code = ? AND user_id = ?");
$stmt->bind_param("si", $room, $userId);
$stmt->execute();

// 2. FETCH all users active in the last 10 seconds
$stmt = $conn->prepare("
    SELECT u.user_id AS id, u.name, u.role 
    FROM meeting_rooms r 
    JOIN users u ON r.user_id = u.user_id 
    WHERE r.room_code = ? 
    AND r.joined_at > (NOW() - INTERVAL 10 SECOND)
    ORDER BY r.joined_at ASC
");
$stmt->bind_param("s", $room);
$stmt->execute();
$res = $stmt->get_result();

$users = [];
while ($row = $res->fetch_assoc()) {
    $users[] = $row;
}

header('Content-Type: application/json');
echo json_encode($users);
?>