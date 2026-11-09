<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../dp.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

$taskId = (int)($_POST['task_id'] ?? 0);
$message = trim($_POST['message'] ?? '');

if (!$taskId || !$message) {
    echo json_encode(['ok'=>false,'error'=>'Missing data']);
    exit;
}

$stmt = $conn->prepare("
    SELECT t.taskTitle, t.assignTo, u.email 
    FROM tasks t
    LEFT JOIN users u ON u.name = t.assignTo
    WHERE t.task_id = ?
");
$stmt->bind_param("i",$taskId);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo json_encode(['ok'=>false,'error'=>'Task not found']);
    exit;
}

$row = $res->fetch_assoc();
$stmt->close();

$to = $row['email'];
$taskTitle = $row['taskTitle'];
$memberName = $row['assignTo'];
$managerName = $_SESSION['name'];

$subject = "Reminder: $taskTitle";
$body = "Hi $memberName,\n\n"
      . "This is a reminder regarding your task:\n\n"
      . "$taskTitle\n\n"
      . "Message from Manager:\n"
      . "$message\n\n"
      . "Regards,\n$managerName";

$headers = "From: no-reply@investhoodprojects.com";

@mail($to, $subject, $body, $headers);

/* Log reminder */
$conn->query("
CREATE TABLE IF NOT EXISTS task_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT,
    sent_by VARCHAR(255),
    message TEXT,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$ins = $conn->prepare("INSERT INTO task_reminders(task_id, sent_by, message) VALUES(?,?,?)");
$ins->bind_param("iss",$taskId,$managerName,$message);
$ins->execute();
$ins->close();

echo json_encode(['ok'=>true]);
