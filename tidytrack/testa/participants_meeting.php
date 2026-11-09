<?php
session_start();
include '../dp.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$room_code = $_GET['room'] ?? '';

$stmt = $conn->prepare("
    SELECT u.user_id as id, u.name, u.role 
    FROM meeting_participants mp 
    JOIN users u ON mp.user_id = u.user_id 
    WHERE mp.room_code = ?
");
$stmt->bind_param("s", $room_code);
$stmt->execute();
$result = $stmt->get_result();

$participants = [];
while ($row = $result->fetch_assoc()) {
    $participants[] = $row;
}

echo json_encode($participants);
?>