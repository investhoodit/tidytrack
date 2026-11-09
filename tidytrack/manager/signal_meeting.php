<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
if(!$userId) exit("ERR");

$data = json_decode(file_get_contents("php://input"), true);
$room = $data['room'] ?? null;
$signalJson = $data['signal'] ?? null;

if(!$room || !$signalJson) exit("ERR");

$signal = json_decode($signalJson, true);
$signal['from'] = $userId; // attach sender id

$finalSignal = json_encode($signal);

$stmt = $conn->prepare("INSERT INTO meeting_signals (room_code, sender_id, signal_data) VALUES (?, ?, ?)");
$stmt->bind_param("sis", $room, $userId, $finalSignal);
$stmt->execute();
$stmt->close();

echo "OK";
?>
