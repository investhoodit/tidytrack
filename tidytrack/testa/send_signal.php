<?php
session_start();
include '../dp.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['room']) || !isset($input['to_user']) || !isset($input['type']) || !isset($input['data'])) {
    http_response_code(400);
    exit;
}

$room_code = $input['room'];
$from_user = $_SESSION['user_id'];
$to_user = $input['to_user'];
$type = $input['type'];
$data = json_encode($input['data']);

// Clean old signals (older than 1 minute)
$conn->query("DELETE FROM meeting_signals WHERE created_at < NOW() - INTERVAL 1 MINUTE");

$stmt = $conn->prepare("INSERT INTO meeting_signals (room_code, from_user, to_user, signal_type, signal_data) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("siiss", $room_code, $from_user, $to_user, $type, $data);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send signal']);
}
$stmt->close();
?>