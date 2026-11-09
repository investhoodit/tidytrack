<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
if(!$userId) exit("ERR");

$data = json_decode(file_get_contents("php://input"),true);
$room = $data['room'] ?? null;
if(!$room) exit("ERR");

$stmt = $conn->prepare("SELECT id FROM meeting_rooms WHERE room_code = ? AND user_id = ?");
$stmt->bind_param("si", $room, $userId);
$stmt->execute();
$stmt->store_result();
if($stmt->num_rows == 0){
    $stmt = $conn->prepare("INSERT INTO meeting_rooms (room_code, user_id) VALUES (?, ?)");
    $stmt->bind_param("si", $room, $userId);
    $stmt->execute();
}
$stmt->close();
echo "OK";

?>