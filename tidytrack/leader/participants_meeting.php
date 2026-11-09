<?php
session_start();
include '../dp.php';

$room = $_GET['room'] ?? null;
if(!$room) exit("[]");

$stmt = $conn->prepare("
SELECT u.user_id AS id, u.name, u.role
FROM meeting_rooms r
JOIN users u ON r.user_id = u.user_id
WHERE r.room_code = ?
ORDER BY r.joined_at ASC
");
$stmt->bind_param("s", $room);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while($row = $res->fetch_assoc()) $data[] = $row;

$stmt->close();
echo json_encode($data);
?>
