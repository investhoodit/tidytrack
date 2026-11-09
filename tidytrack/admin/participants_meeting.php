<?php
include '../dp.php';

$room = $_GET['room'] ?? '';
if (!preg_match('/^[A-Z0-9]{4,8}$/', $room)) die(json_encode([]));

$stmt = $conn->prepare("
    SELECT u.user_id AS id, u.name, u.role 
    FROM meeting_rooms r 
    JOIN users u ON r.user_id = u.user_id 
    WHERE r.room_code = ? 
    ORDER BY r.joined_at
");
$stmt->bind_param("s", $room);
$stmt->execute();
$res = $stmt->get_result();

$users = [];
while ($row = $res->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

header('Content-Type: application/json');
echo json_encode($users);
?>