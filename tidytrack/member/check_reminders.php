<?php
header('Content-Type: application/json');
include '../dp.php';

$userId = $_POST['user_id'] ?? null;
if (!$userId) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

date_default_timezone_set('Africa/Johannesburg');
$currentTime = date('Y-m-d H:i:00');

$stmt = $conn->prepare("
    SELECT r.reminder_id, r.task_id, r.notification_type, t.taskTitle, t.priority, t.dueDate
    FROM reminders r
    JOIN tasks t ON r.task_id = t.task_id
    WHERE r.user_id = ? AND r.reminder_date = ? AND r.is_triggered = 0
    AND (r.notification_type = 'desktop' OR r.notification_type = 'both')
");
$stmt->bind_param("is", $userId, $currentTime);
$stmt->execute();
$result = $stmt->get_result();

$reminders = [];
while ($row = $result->fetch_assoc()) {
    $reminders[] = $row;
    // Mark reminder as triggered
    $updateStmt = $conn->prepare("UPDATE reminders SET is_triggered = 1 WHERE reminder_id = ?");
    $updateStmt->bind_param("i", $row['reminder_id']);
    $updateStmt->execute();
    $updateStmt->close();
}

$stmt->close();
$conn->close();

echo json_encode(['reminders' => $reminders]);
?>