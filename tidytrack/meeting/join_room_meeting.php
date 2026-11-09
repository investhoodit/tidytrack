<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) die("ERR");

$data = json_decode(file_get_contents("php://input"), true);
$room = $data['room'] ?? '';

if (!preg_match('/^[A-Z0-9]{4,8}$/', $room)) die("BAD");

$stmt = $conn->prepare("INSERT IGNORE INTO meeting_rooms (room_code, user_id) VALUES (?, ?)");
$stmt->bind_param("si", $room, $userId);
$stmt->execute();
$stmt->close();

echo "OK";
?>