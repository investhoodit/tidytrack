<?php
session_start();
include '../dp.php';

$response = ['success' => false, 'error' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['error'] = 'Unauthorized';
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['user_id'];
$taskId = $_POST['task_id'] ?? '';
$reminderDate = $_POST['reminderDate'] ?? '';

if (empty($taskId) || empty($reminderDate)) {
    $response['error'] = 'Missing required fields';
    echo json_encode($response);
    exit;
}

// Verify task belongs to user
$stmt = $conn->prepare("SELECT task_id FROM tasks WHERE task_id = ? AND assignTo = ?");
$stmt->bind_param("is", $taskId, $_SESSION['name']);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    $response['error'] = 'Invalid task';
    $stmt->close();
    echo json_encode($response);
    exit;
}
$stmt->close();

$stmt = $conn->prepare("INSERT INTO reminder_tasks (task_id, user_id, reminderDate) VALUES (?, ?, ?)");
$stmt->bind_param("iis", $taskId, $userId, $reminderDate);

if ($stmt->execute()) {
    $response['success'] = true;
} else {
    $response['error'] = 'Database error: ' . $conn->error;
}

$stmt->close();
$conn->close();

echo json_encode($response);
?>