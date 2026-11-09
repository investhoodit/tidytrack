<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$room   = $_GET['room'] ?? null;

if (!$userId || !$room) {
    http_response_code(400);
    exit(json_encode([]));
}

// Fetch ONLY signals specifically meant for ME
$stmt = $conn->prepare("
    SELECT s.*, u.name 
    FROM meeting_signals s 
    JOIN users u ON s.sender_id = u.user_id 
    WHERE s.room_code = ? 
    AND s.sender_id != ? 
    AND (JSON_UNQUOTE(JSON_EXTRACT(s.signal_data, '$.to')) = ? OR JSON_UNQUOTE(JSON_EXTRACT(s.signal_data, '$.to')) IS NULL)
    ORDER BY s.id ASC
");
$stmt->bind_param("sii", $room, $userId, $userId);

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
// Delete ONLY the signals you just read that were meant for you
$conn->query("DELETE FROM meeting_signals WHERE room_code = '$room' AND JSON_UNQUOTE(JSON_EXTRACT(signal_data, '$.to')) = '$userId'");

header('Content-Type: application/json');
echo json_encode($signals);
?>