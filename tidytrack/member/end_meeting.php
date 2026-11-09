<?php
session_start();
include '../dp.php';

$data = json_decode(file_get_contents('php://input'), true);
$room = $data['room'] ?? '';

if ($room) {
    $conn->query("DELETE FROM meeting_rooms WHERE room_code = '$room'");
    $conn->query("DELETE FROM meeting_chat WHERE room_code = '$room'");
}
?>