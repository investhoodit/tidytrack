<?php
session_start();
header('Content-Type: application/json');
error_reporting(0);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$room_code = $input['room'] ?? '';

// For testing, just return success
echo json_encode(['success' => true, 'room' => $room_code]);
?>