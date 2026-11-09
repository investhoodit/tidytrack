<?php
session_start();
include '../dp.php';
$data = json_decode(file_get_contents("php://input"), true);
$room = $data['room'] ?? '';
$users = $data['users'] ?? [];
$groups = $data['groups'] ?? [];

if ($users) {
  $placeholders = str_repeat('?,', count($users)-1) . '?';
  $stmt = $conn->prepare("SELECT email FROM users WHERE user_id IN ($placeholders)");
  $stmt->bind_param(str_repeat('i', count($users)), ...$users);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    //mail($row['email'], "Meeting Invite", "Join: https://yourdomain.com/meeting.php (Code: $room)");
  }
}
if ($groups) {
  // Similar for groups: fetch members and notify
}
echo json_encode(["status"=>"sent"]);
?>