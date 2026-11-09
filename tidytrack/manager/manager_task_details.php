<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
require_once __DIR__ . '/../dp.php';

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level() > 0 && ob_get_length()) { ob_clean(); }

function respond(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
  respond(['ok' => false, 'error' => 'Unauthorized'], 401);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
  respond(['ok' => false, 'error' => 'DB connection not found'], 500);
}

$taskId = (int)($_GET['task_id'] ?? 0);
if ($taskId <= 0) {
  respond(['ok' => false, 'error' => 'Invalid task'], 400);
}

/* base web path: /tidytracker/tidytrack */
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$baseWeb = rtrim(dirname(dirname($scriptPath)), '/');

$stmt = $conn->prepare("
  SELECT
    t.task_id,
    t.taskTitle   AS title,
    t.description AS description,
    t.status      AS status,
    t.dueDate     AS due,

    COALESCE(p.project_name,'') AS project,

    COALESCE(creator.name, CONCAT('User #', t.created_by)) AS created_by,

    COALESCE(member.name, t.assignTo) AS member_name,
    member.email AS member_email,

    NULLIF(t.file_upload,'')  AS submitted_doc_raw,
    NULLIF(t.code_snippet,'') AS submitted_code,
    NULLIF(t.comment,'')      AS daily_summary

  FROM tasks t
  LEFT JOIN projects_x p ON p.project_id = t.project_id
  LEFT JOIN users creator ON creator.user_id = t.created_by
  LEFT JOIN users member
    ON LOWER(TRIM(member.name)) = LOWER(TRIM(t.assignTo))
    OR (t.assignTo REGEXP '^[0-9]+$' AND member.user_id = CAST(t.assignTo AS UNSIGNED))
  WHERE t.task_id = ?
  LIMIT 1
");
$stmt->bind_param("i", $taskId);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
  respond(['ok'=>false,'error'=>'Task not found'], 404);
}

$task = $res->fetch_assoc();
$stmt->close();

/* ✅ Always give a safe view URL if doc exists */
$submittedDocUrl = null;
if (!empty($task['submitted_doc_raw'])) {
  $submittedDocUrl = $baseWeb . "/manager/view_document.php?task_id=" . (int)$taskId;
}

/* Reminder history (if table exists) */
$reminders = [];
$check = $conn->query("SHOW TABLES LIKE 'task_reminders'");
if ($check && $check->num_rows > 0) {
  $r = $conn->prepare("
    SELECT task_id, sent_by, message, sent_at
    FROM task_reminders
    WHERE task_id=?
    ORDER BY sent_at DESC
  ");
  $r->bind_param("i", $taskId);
  $r->execute();
  $rr = $r->get_result();
  while ($row = $rr->fetch_assoc()) $reminders[] = $row;
  $r->close();
}

respond([
  'ok' => true,

  'task_id'        => $task['task_id'],
  'title'          => $task['title'],
  'description'    => $task['description'],
  'status'         => $task['status'],
  'due'            => $task['due'],
  'project'        => $task['project'],
  'created_by'     => $task['created_by'],
  'member_name'    => $task['member_name'],
  'member_email'   => $task['member_email'],

  // ✅ this is what your modal should open
  'submitted_doc'  => $submittedDocUrl,

  'submitted_code' => $task['submitted_code'],
  'daily_summary'  => $task['daily_summary'],

  // nested
  'task' => [
    'task_id'       => $task['task_id'],
    'title'         => $task['title'],
    'description'   => $task['description'],
    'status'        => $task['status'],
    'due'           => $task['due'],
    'project'       => $task['project'],
    'created_by'    => $task['created_by'],
    'member_name'   => $task['member_name'],
    'member_email'  => $task['member_email'],
    'submitted_doc' => $submittedDocUrl,
    'submitted_code'=> $task['submitted_code'],
    'daily_summary' => $task['daily_summary'],
  ],

  'reminders' => $reminders
]);
