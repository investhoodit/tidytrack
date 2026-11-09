<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../dp.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
  http_response_code(403);
  exit('Forbidden');
}

$taskId = (int)($_GET['task_id'] ?? 0);
if (!$taskId) {
  http_response_code(400);
  exit('Invalid task id');
}

$stmt = $conn->prepare("SELECT file_upload, taskTitle FROM tasks WHERE task_id = ? LIMIT 1");
$stmt->bind_param("i", $taskId);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
  http_response_code(404);
  exit('Task not found');
}
$row = $res->fetch_assoc();
$stmt->close();

$rel = trim((string)($row['file_upload'] ?? ''));
$title = trim((string)($row['taskTitle'] ?? 'task'));

if ($rel === '') {
  http_response_code(404);
  exit('No file uploaded for this task');
}

/* Remove any leading ./ or / so we can safely join paths */
$relClean = ltrim($rel, "/\\");
$relClean = preg_replace('~^\./~', '', $relClean);

/* Try multiple possible upload directories (case sensitive servers!) */
$candidates = [
  realpath(__DIR__ . '/../' . $relClean),                // as-is from tidytrack root
  realpath(__DIR__ . '/../Uploads/' . basename($relClean)),
  realpath(__DIR__ . '/../uploads/' . basename($relClean)),
  realpath(__DIR__ . '/../Uploads/' . $relClean),
  realpath(__DIR__ . '/../uploads/' . $relClean),
];

$filePath = null;
foreach ($candidates as $c) {
  if ($c && is_file($c)) { $filePath = $c; break; }
}

if (!$filePath) {
  http_response_code(404);
  exit('File not found on server. Saved path: ' . htmlspecialchars($rel, ENT_QUOTES));
}

$filename = basename($filePath);
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

$mime = 'application/octet-stream';
$mimeMap = [
  'pdf' => 'application/pdf',
  'png' => 'image/png',
  'jpg' => 'image/jpeg',
  'jpeg'=> 'image/jpeg',
  'doc' => 'application/msword',
  'docx'=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
if (isset($mimeMap[$ext])) $mime = $mimeMap[$ext];

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filePath));

readfile($filePath);
exit;
