<?php
session_start();
include '../dp.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['room']) || !isset($_GET['user'])) {
    http_response_code(400);
    exit;
}

$room_code = $_GET['room'];
$user_id = $_GET['user'];

// Get signals for this user
$stmt = $conn->prepare("SELECT * FROM meeting_signals WHERE room_code = ? AND to_user = ? ORDER BY created_at ASC");
$stmt->bind_param("si", $room_code, $user_id);
$stmt->execute();
$result = $stmt->get_result();

$signals = [];
while ($row = $result->fetch_assoc()) {
    $signals[] = [
        'from_user' => $row['from_user'],
        'type' => $row['signal_type'],
        'data' => json_decode($row['signal_data'], true)
    ];
}

// Delete retrieved signals
$stmt = $conn->prepare("DELETE FROM meeting_signals WHERE room_code = ? AND to_user = ?");
$stmt->bind_param("si", $room_code, $user_id);
$stmt->execute();
$stmt->close();

echo json_encode($signals);
?>