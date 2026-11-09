<?php
include '../dp.php';
include 'mailer.php';

date_default_timezone_set('Africa/Johannesburg');
$currentTime = date('Y-m-d H:i:00');

$stmt = $conn->prepare("
    SELECT r.reminder_id, r.task_id, r.user_id, r.notification_type, t.taskTitle, t.priority, t.dueDate, u.email
    FROM reminders r
    JOIN tasks t ON r.task_id = t.task_id
    JOIN users u ON r.user_id = u.user_id
    WHERE r.reminder_date = ? AND r.is_triggered = 0
    AND (r.notification_type = 'email' OR r.notification_type = 'both')
");
$stmt->bind_param("s", $currentTime);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    sendEmailReminder(
        $row['email'],
        $row['taskTitle'],
        $row['priority'],
        $row['dueDate']
    );

    // Mark reminder as triggered
    $updateStmt = $conn->prepare("UPDATE reminders SET is_triggered = 1 WHERE reminder_id = ?");
    $updateStmt->bind_param("i", $row['reminder_id']);
    $updateStmt->execute();
    $updateStmt->close();
}

$stmt->close();
$conn->close();
?>