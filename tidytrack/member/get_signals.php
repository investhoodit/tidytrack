<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$room   = $_GET['room'] ?? null;

if (!$userId || !$room) {
    http_response_code(400);
    exit(json_encode([]));
}

// Fetch all signals NOT sent by current user
$stmt = $conn->prepare("
    SELECT s.*, u.name 
    FROM meeting_signals s 
    JOIN users u ON s.sender_id = u.user_id 
    WHERE s.room_code = ? AND s.sender_id != ? 
    ORDER BY s.id ASC
");
$stmt->bind_param("si", $room, $userId);
$stmt->execute();
$result = $stmt->get_result();

$signals = [];
while ($row = $result->fetch_assoc()) {
    $signalData = json_decode($row['signal_data'], true);
    if ($signalData) {
        $signals[] = [
            'from' => $row['sender_id'],
            'name' => $row['name'],
            'type' => $signalData['type'] ?? null,
            'sdp'  => $signalData['sdp'] ?? null,
            'candidate' => $signalData['candidate'] ?? null,
            'to'   => $signalData['to'] ?? null
        ];
    }
}
$stmt->close();

// Delete signals after reading (prevents duplicates)
$conn->query("DELETE FROM meeting_signals WHERE room_code = '" . $conn->real_escape_string($room) . "' AND sender_id != " . (int)$userId);

header('Content-Type: application/json');
echo json_encode($signals);
?>