<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$room = $_GET['room'] ?? null;

if(!$userId || !$room) exit("[]");

$stmt = $conn->prepare("SELECT id, sender_id, signal_data FROM meeting_signals WHERE room_code = ? AND sender_id != ? ORDER BY id ASC");
$stmt->bind_param("si", $room, $userId);
$stmt->execute();
$res = $stmt->get_result();

$signals = [];
while($row = $res->fetch_assoc()){
    $signals[] = $row;
}

$stmt->close();

// Delete after sending so no duplicates
if(!empty($signals)){
    $lastId = end($signals)['id'];
    $del = $conn->prepare("DELETE FROM meeting_signals WHERE id <= ?");
    $del->bind_param("i", $lastId);
    $del->execute();
    $del->close();
}

echo json_encode($signals);
?>

