<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) exit("ERR");

$data = json_decode(file_get_contents("php://input"), true);
$room = $data['room'] ?? null;
$emoji = $data['emoji'] ?? null;

if (!$room || !$emoji) exit("ERR");

$stmt = $conn->prepare("INSERT INTO meeting_reactions (room_code, user_id, emoji) VALUES (?, ?, ?)");
$stmt->bind_param("sis", $room, $userId, $emoji);
$stmt->execute();
$stmt->close();

echo "OK";
?>