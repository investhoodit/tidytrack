<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Member';

$today = date('Y-m-d');
$threeDays = date('Y-m-d', strtotime('+3 days'));

$stmt = $conn->prepare("
    SELECT t.task_id, t.taskTitle, t.dueDate, t.priority, t.status, r.reminderDate
    FROM tasks t
    LEFT JOIN reminder_tasks r ON t.task_id = r.task_id AND r.user_id = ?
    WHERE t.assignTo = ? AND t.status != 'completed'
    AND (t.dueDate BETWEEN ? AND ? OR r.reminderDate BETWEEN ? AND ?)
    ORDER BY COALESCE(r.reminderDate, t.dueDate) ASC
");
$stmt->bind_param("isssss", $userId, $userName, $today, $threeDays, $today, $threeDays);
$stmt->execute();
$result = $stmt->get_result();
$tasks = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode($tasks);
?>