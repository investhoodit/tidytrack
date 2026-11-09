<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) exit(json_encode([]));

$alerts = [];

// New task from leader
$stmt = $conn->prepare("SELECT t.taskTitle, u.name AS leader_name FROM tasks t JOIN users u ON t.created_by = u.user_id WHERE t.assignTo = ? AND t.status = 'pending' AND t.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY t.created_at DESC LIMIT 3");
$stmt->bind_param("s", $_SESSION['name']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $alerts[] = "New task from {$row['leader_name']}: {$row['taskTitle']}";
}
$stmt->close();

// Add more alerts here (meetings, messages, leave approval, etc.)

echo json_encode($alerts);
?>