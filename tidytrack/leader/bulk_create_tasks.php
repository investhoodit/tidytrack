<?php
// bulk_create_tasks.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
header('Content-Type: application/json');

require_once '../dp.php'; // $conn

$input = json_decode(file_get_contents('php://input'), true);
$project_id = $input['project_id'] ?? null;
$role = $input['role'] ?? null;
$tasks = $input['tasks'] ?? [];

if (!$project_id || !$role || !is_array($tasks) || !count($tasks)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
  exit;
}

$conn->begin_transaction();
try {
  $stmt = $conn->prepare(
    "INSERT INTO tasks (taskTitle, description, assignTo, priority, dueDate, project_id, role)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
  );
  foreach ($tasks as $t) {
    $taskTitle   = trim($t['taskTitle'] ?? '');
    $description = trim($t['description'] ?? '');
    $assignTo    = trim($t['assignTo'] ?? '');
    $priority    = trim($t['priority'] ?? 'normal');
    $dueDate     = trim($t['dueDate'] ?? '');
    if (!$taskTitle || !$description || !$assignTo || !$priority || !$dueDate) {
      throw new Exception("Missing fields in one of the tasks");
    }
    $stmt->bind_param("sssssss", $taskTitle, $description, $assignTo, $priority, $dueDate, $project_id, $role);
    if (!$stmt->execute()) throw new Exception($stmt->error);
  }
  $stmt->close();
  $conn->commit();
  echo json_encode(['ok' => true]);
} catch (Exception $e) {
  $conn->rollback();
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
