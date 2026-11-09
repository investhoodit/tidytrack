<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
require_once __DIR__ . '/../dp.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
  http_response_code(403);
  exit('Unauthorized');
}

$taskId = (int)($_GET['task_id'] ?? 0);
if ($taskId <= 0) {
  http_response_code(400);
  exit('Invalid task');
}

/* Get stored path from DB */
$stmt = $conn->prepare("SELECT NULLIF(file_upload,'') AS file_upload FROM tasks WHERE task_id = ? LIMIT 1");
$stmt->bind_param("i", $taskId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

$stored = trim((string)($row['file_upload'] ?? ''));
if ($stored === '') {
  http_response_code(404);
  exit('No document found for this task');
}

$stored = str_replace('\\', '/', $stored);
$stored = ltrim($stored, '/');
$stored = str_replace('..', '', $stored);

$tidyRoot = realpath(__DIR__ . '/..'); // .../public_html/tidytracker/tidytrack
$docRoot  = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'); // .../public_html

$try = [];
if ($tidyRoot) $try[] = $tidyRoot . '/' . $stored;
if ($docRoot)  $try[] = $docRoot  . '/' . $stored;

/* Uploads vs uploads */
$storedLower = preg_replace('~^Uploads/~', 'uploads/', $stored);
$storedUpper = preg_replace('~^uploads/~', 'Uploads/', $stored);

if ($tidyRoot) { $try[] = $tidyRoot . '/' . $storedLower; $try[] = $tidyRoot . '/' . $storedUpper; }
if ($docRoot)  { $try[] = $docRoot  . '/' . $storedLower; $try[] = $docRoot  . '/' . $storedUpper; }

/* Find direct match first */
$finalPath = null;
$checked = [];

foreach ($try as $p) {
  if (!$p) continue;
  $p = preg_replace('~/+~', '/', $p);
  if (isset($checked[$p])) continue;
  $checked[$p] = true;

  $rp = realpath($p);
  if ($rp && is_file($rp) && file_exists($rp)) {
    $finalPath = $rp;
    break;
  }
}

/* Fallback: search by filename in likely folders */
if (!$finalPath) {
  $fileName = basename($stored);

  $searchBases = [];
  if ($tidyRoot) $searchBases[] = $tidyRoot;
  if ($docRoot)  $searchBases[] = $docRoot;

  $maxFilesScanned = 30000; // safety cap
  $scanned = 0;

  foreach ($searchBases as $base) {
    if (!$base || !is_dir($base)) continue;

    try {
      $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
      );

      foreach ($it as $file) {
        $scanned++;
        if ($scanned > $maxFilesScanned) break 2;

        if (!$file->isFile()) continue;

        if ($file->getFilename() === $fileName) {
          $finalPath = $file->getPathname();
          break 2;
        }
      }
    } catch (Throwable $e) {
      // ignore unreadable folders
    }
  }
}

if (!$finalPath) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo "File not found\n";
  echo "task_id: {$taskId}\n";
  echo "stored(file_upload): {$stored}\n\n";
  echo "Tried paths:\n- " . implode("\n- ", array_keys($checked)) . "\n\n";
  echo "Fallback search: looked for filename \"" . basename($stored) . "\" inside tidytrack + public_html.\n";
  exit;
}

/* Serve inline */
$mime = mime_content_type($finalPath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($finalPath) . '"');
header('Content-Length: ' . filesize($finalPath));
readfile($finalPath);
exit;
