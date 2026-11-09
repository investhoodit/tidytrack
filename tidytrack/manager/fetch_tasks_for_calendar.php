<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Member';

$stmt = $conn->prepare("
    SELECT t.taskTitle, t.dueDate, t.priority, t.status, r.reminderDate
    FROM tasks t
    LEFT JOIN reminder_tasks r ON t.task_id = r.task_id AND r.user_id = ?
    WHERE t.assignTo = ?
");
$stmt->bind_param("is", $userId, $userName);
$stmt->execute();
$result = $stmt->get_result();
$tasks = [];
while ($row = $result->fetch_assoc()) {
    $tasks[] = $row;
}
$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode($tasks);
?>