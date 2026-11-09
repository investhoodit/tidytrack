<?php
// upload_timesheet_progress.php (Leader/Member page)
// ✅ Upload Timesheet + Progress report (once per month)
// ✅ Leader selects manager (pulled from DB)
// ✅ Sends email to selected manager
// ✅ Remind manager button (rate-limited)
// ✅ Tabs: Submitted | Signed
// ✅ Signed tab only shows items where BOTH signed docs exist
// ✅ PDF preview before upload

error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

session_start();
require_once __DIR__ . '/../dp.php';

if (!function_exists('esc')) {
  function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* AUTH */
$userId   = (int)($_SESSION['user_id'] ?? 0);
$role     = strtolower(trim($_SESSION['role'] ?? ''));
$userName = $_SESSION['name'] ?? 'User';

$allowedRoles = ['member','leader','admin'];
if (!$userId || !in_array($role, $allowedRoles, true)) {
  http_response_code(403);
  exit('Forbidden');
}

/* ---------------- Helpers ---------------- */
function ensureDir($dir){
  if (!is_dir($dir)) mkdir($dir, 0755, true);
}
function monthText($ymd){
  try { return (new DateTime($ymd))->format('F Y'); }
  catch (Throwable $e) { return (string)$ymd; }
}
function colExists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  $ok = ($r && $r->num_rows > 0);
  if ($r) $r->free();
  return $ok;
}
function uploadErrText(int $code): string {
  return match ($code) {
    UPLOAD_ERR_OK         => "OK",
    UPLOAD_ERR_INI_SIZE   => "File is too large for server settings (upload_max_filesize).",
    UPLOAD_ERR_FORM_SIZE  => "File is too large (form limit).",
    UPLOAD_ERR_PARTIAL    => "File was partially uploaded. Please try again.",
    UPLOAD_ERR_NO_FILE    => "Please choose a file.",
    UPLOAD_ERR_NO_TMP_DIR => "Server missing a temporary folder.",
    UPLOAD_ERR_CANT_WRITE => "Server cannot write the uploaded file to disk.",
    UPLOAD_ERR_EXTENSION  => "Upload blocked by server extension.",
    default               => "Upload error code: $code",
  };
}
function validateUpload($file, $maxBytes, $allowedExt){
  if (!isset($file)) return "Please choose a file.";
  $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($err !== UPLOAD_ERR_OK) return uploadErrText($err);

  if (($file['size'] ?? 0) > $maxBytes) {
    return "File is too large. Max size is 12MB.";
  }
  $name = $file['name'] ?? '';
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) {
    return "Only PDF, DOC, DOCX files are allowed.";
  }
  return null;
}
function storeFile($file, $absDir, $relDir){
  $origName = $file['name'] ?? 'file';
  $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $origName);
  $stored   = time() . '_' . $safeName;

  $absPath = rtrim($absDir,'/') . '/' . $stored;
  $relPath = rtrim($relDir,'/') . '/' . $stored;

  if (!move_uploaded_file($file['tmp_name'], $absPath)) {
    return [false, "Upload failed. Could not save file."];
  }
  return [true, [$origName, $relPath, ($file['type'] ?? ''), (int)($file['size'] ?? 0), $absPath]];
}
function getFilesForSubmission(mysqli $conn, int $sid): array {
  $out = [];
  $stmt = $conn->prepare("
    SELECT doc_type, original_name, stored_path
    FROM submission_files
    WHERE submission_id = ?
    ORDER BY id ASC
  ");
  if(!$stmt) return $out;
  $stmt->bind_param("i", $sid);
  $stmt->execute();
  $res = $stmt->get_result();
  if($res){
    while ($r = $res->fetch_assoc()) $out[] = $r;
  }
  $stmt->close();
  return $out;
}

/* ---------------- DB checks ---------------- */
$toast = null;

$r = $conn->query("SHOW TABLES LIKE 'document_submissions'");
$hasSubmissions = ($r && $r->num_rows > 0);
if ($r) $r->free();

$r = $conn->query("SHOW TABLES LIKE 'submission_files'");
$hasFiles = ($r && $r->num_rows > 0);
if ($r) $r->free();

$hasMonthCol = false;
if ($hasSubmissions) {
  $r = $conn->query("SHOW COLUMNS FROM document_submissions LIKE 'submission_month'");
  $hasMonthCol = ($r && $r->num_rows > 0);
  if ($r) $r->free();
}

/* Optional columns */
$hasMentorOverall  = $hasSubmissions ? colExists($conn,'document_submissions','mentor_overall') : false;
$hasMentorImprove  = $hasSubmissions ? colExists($conn,'document_submissions','mentor_improve') : false;
$hasMentorGeneral  = $hasSubmissions ? colExists($conn,'document_submissions','mentor_general') : false;
$hasMentorAction   = $hasSubmissions ? colExists($conn,'document_submissions','mentor_action') : false;

$hasSignedTimesheet = $hasSubmissions ? colExists($conn,'document_submissions','signed_timesheet_path') : false;
$hasCommentedProg   = $hasSubmissions ? colExists($conn,'document_submissions','commented_progress_path') : false;

$hasManagerId       = $hasSubmissions ? colExists($conn,'document_submissions','manager_id') : false;
$hasRoutedTo        = $hasSubmissions ? colExists($conn,'document_submissions','routed_to') : false;
$hasNotifiedAt      = $hasSubmissions ? colExists($conn,'document_submissions','notified_at') : false;
$hasLastRemindedAt  = $hasSubmissions ? colExists($conn,'document_submissions','last_reminded_at') : false;
$hasReminderCount   = $hasSubmissions ? colExists($conn,'document_submissions','reminder_count') : false;

/* ---------------- Upload settings ---------------- */
$maxBytes = 12 * 1024 * 1024;
$allowedExt = ['pdf','doc','docx'];

/* Auto month */
$submissionMonth = date('Y-m-01');
$currentMonthText = monthText($submissionMonth);

/* ---------------- Fetch managers ---------------- */
$managers = [];
$mgrStmt = $conn->prepare("SELECT user_id, name, email FROM users WHERE LOWER(role)='manager' ORDER BY name ASC");
if ($mgrStmt) {
  $mgrStmt->execute();
  $mgrRes = $mgrStmt->get_result();
  while ($mgrRes && ($row = $mgrRes->fetch_assoc())) $managers[] = $row;
  $mgrStmt->close();
}

/* ---------------- Email sender ---------------- */
function sendManagerEmail(string $to, string $subject, string $body): bool {
  $headers = "From: no-reply@investhoodprojects.com\r\n";
  $headers .= "Reply-To: no-reply@investhoodprojects.com\r\n";
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
  return @mail($to, $subject, $body, $headers);
}

/* ---------------- REMIND ACTION ---------------- */
if (isset($_GET['action']) && $_GET['action'] === 'remind' && isset($_GET['id'])) {
  if (!$hasSubmissions || !$hasMonthCol) {
    $toast = ['type'=>'danger','msg'=>'DB not ready.'];
  } else {
    $sid = (int)$_GET['id'];

    $stmt = $conn->prepare("
      SELECT id, status, submission_month, manager_id, routed_to, last_reminded_at, reminder_count
      FROM document_submissions
      WHERE id=? AND user_id=?
      LIMIT 1
    ");
    $stmt->bind_param("ii", $sid, $userId);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sub) {
      $toast = ['type'=>'danger','msg'=>'Submission not found.'];
    } else {
      $status = $sub['status'] ?? '';
      if ($status === 'Approved') {
        $toast = ['type'=>'danger','msg'=>'This submission is already approved/signed.'];
      } else {
        $managerId = (int)($sub['manager_id'] ?? 0);
        if ($managerId <= 0) {
          $toast = ['type'=>'danger','msg'=>'No manager assigned to this submission.'];
        } else {
          $last = $sub['last_reminded_at'] ?? null;
          $lastTs = $last ? strtotime($last) : 0;
          $canSend = (!$lastTs || (time() - $lastTs) >= (12 * 3600));

          if (!$canSend) {
            $toast = ['type'=>'danger','msg'=>'Reminder already sent recently. Please try again later.'];
          } else {
            $stmt = $conn->prepare("SELECT name, email FROM users WHERE user_id=? AND LOWER(role)='manager' LIMIT 1");
            $stmt->bind_param("i", $managerId);
            $stmt->execute();
            $mgr = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$mgr || empty($mgr['email'])) {
              $toast = ['type'=>'danger','msg'=>'Manager email not found.'];
            } else {
              $monthTxt = monthText($sub['submission_month'] ?? date('Y-m-01'));
              $reviewLink = "https://investhoodprojects.com/tidytracker/tidytrack/manager/manager_timesheet_review.php?submission_id=".$sid;
              $subject = "Reminder: Timesheet submission awaiting signature ($monthTxt)";
              $body =
                "Hello ".($mgr['name'] ?? 'Manager').",\n\n".
                $GLOBALS['userName']." has requested a reminder to sign the timesheet and progress report for $monthTxt.\n\n".
                "Review here:\n$reviewLink\n\nRegards,\nTidyTrack";

              $sent = sendManagerEmail($mgr['email'], $subject, $body);

              if ($sent) {
                if ($hasLastRemindedAt && $hasReminderCount) {
                  $upd = $conn->prepare("UPDATE document_submissions SET last_reminded_at=NOW(), reminder_count=reminder_count+1 WHERE id=? AND user_id=?");
                  $upd->bind_param("ii", $sid, $userId);
                  $upd->execute();
                  $upd->close();
                }
                $toast = ['type'=>'success','msg'=>'Reminder email sent to the manager successfully.'];
              } else {
                $toast = ['type'=>'danger','msg'=>'Reminder email failed to send (server mail not configured).'];
              }
            }
          }
        }
      }
    }
  }
}

/* ---------------- POST upload ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$hasSubmissions || !$hasFiles) {
    $toast = ['type'=>'danger','msg'=>'Missing DB tables. Create document_submissions and submission_files first.'];
  } elseif (!$hasMonthCol) {
    $toast = ['type'=>'danger','msg'=>"Missing column: document_submissions.submission_month. Add it with ALTER TABLE."];
  } else {
    $managerId = (int)($_POST['manager_id'] ?? 0);
    if ($managerId <= 0) {
      $toast = ['type'=>'danger','msg'=>'Please select a manager.'];
    } else {
      $stmt = $conn->prepare("SELECT user_id, name, email FROM users WHERE user_id=? AND LOWER(role)='manager' LIMIT 1");
      $stmt->bind_param("i", $managerId);
      $stmt->execute();
      $mgr = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$mgr) {
        $toast = ['type'=>'danger','msg'=>'Selected manager not found.'];
      } else {
        $e1 = validateUpload($_FILES['timesheet'] ?? null, $maxBytes, $allowedExt);
        $e2 = validateUpload($_FILES['progress_report'] ?? null, $maxBytes, $allowedExt);

        if ($e1) {
          $toast = ['type'=>'danger','msg'=>"Timesheet: $e1"];
        } elseif ($e2) {
          $toast = ['type'=>'danger','msg'=>"Progress report: $e2"];
        } else {
          $stmt = $conn->prepare("SELECT COUNT(*) FROM document_submissions WHERE user_id=? AND submission_month=?");
          $stmt->bind_param("is", $userId, $submissionMonth);
          $stmt->execute();
          $stmt->bind_result($cnt);
          $stmt->fetch();
          $stmt->close();

          if ((int)$cnt > 0) {
            $toast = ['type'=>'danger','msg'=>"You already submitted for $currentMonthText."];
          } else {
            $absDir = __DIR__ . '/../Uploads/documents/' . $role . '/' . $userId;
            $relDir = 'Uploads/documents/' . $role . '/' . $userId;
            ensureDir($absDir);

            $conn->begin_transaction();
            $savedAbsPaths = [];

            try {
              $status = 'Pending Manager Review';
              $routedTo = $mgr['name'] ?? 'Manager';

              $stmt = $conn->prepare("
                INSERT INTO document_submissions (user_id, role, submission_month, status, manager_id, routed_to".($hasNotifiedAt ? ", notified_at" : "").")
                VALUES (?, ?, ?, ?, ?, ?".($hasNotifiedAt ? ", NOW()" : "").")
              ");
              if (!$stmt) throw new Exception("Insert submission prepare failed: ".$conn->error);

              $stmt->bind_param("isssis", $userId, $role, $submissionMonth, $status, $managerId, $routedTo);
              if (!$stmt->execute()) throw new Exception("Insert submission failed: ".$stmt->error);

              $submissionId = $stmt->insert_id;
              $stmt->close();

              [$ok1, $data1] = storeFile($_FILES['timesheet'], $absDir, $relDir);
              if (!$ok1) throw new Exception("Timesheet: $data1");
              [$orig1, $rel1, $mime1, $size1, $abs1] = $data1;
              $savedAbsPaths[] = $abs1;

              [$ok2, $data2] = storeFile($_FILES['progress_report'], $absDir, $relDir);
              if (!$ok2) throw new Exception("Progress report: $data2");
              [$orig2, $rel2, $mime2, $size2, $abs2] = $data2;
              $savedAbsPaths[] = $abs2;

              $stmt = $conn->prepare("
                INSERT INTO submission_files (submission_id, doc_type, original_name, stored_path, mime_type, file_size)
                VALUES (?, ?, ?, ?, ?, ?)
              ");
              if (!$stmt) throw new Exception("Prepare insert files failed: ".$conn->error);

              $docType = 'timesheet';
              $stmt->bind_param("issssi", $submissionId, $docType, $orig1, $rel1, $mime1, $size1);
              if (!$stmt->execute()) throw new Exception("Insert timesheet row failed: ".$stmt->error);

              $docType = 'progress_report';
              $stmt->bind_param("issssi", $submissionId, $docType, $orig2, $rel2, $mime2, $size2);
              if (!$stmt->execute()) throw new Exception("Insert progress row failed: ".$stmt->error);

              $stmt->close();

              $monthTxt = $currentMonthText;
              $reviewLink = "https://investhoodprojects.com/tidytracker/tidytrack/manager/manager_timesheet_review.php?submission_id=".$submissionId;
              $subject = "Timesheet submission awaiting signature ($monthTxt)";
              $body =
                "Hello ".($mgr['name'] ?? 'Manager').",\n\n".
                $userName." submitted a timesheet and progress report for $monthTxt.\n\n".
                "Review here:\n$reviewLink\n\nRegards,\nTidyTrack";

              $sent = !empty($mgr['email']) ? sendManagerEmail($mgr['email'], $subject, $body) : false;

              $conn->commit();

              if ($sent) {
                $toast = ['type'=>'success','msg'=>"Uploaded successfully for $monthTxt and emailed to {$mgr['name']}."];
              } else {
                $toast = ['type'=>'success','msg'=>"Uploaded successfully for $monthTxt. (Email not sent — server mail not configured)."];
              }

            } catch (Throwable $ex) {
              $conn->rollback();
              foreach ($savedAbsPaths as $p) @unlink($p);
              $toast = ['type'=>'danger','msg'=>$ex->getMessage()];
            }
          }
        }
      }
    }
  }
}

/* Tabs */
$tab = strtolower(trim($_GET['tab'] ?? 'submitted'));
if (!in_array($tab, ['submitted','signed'], true)) $tab = 'submitted';

$statusWhere = "";
if ($tab === 'submitted') {
  $statusWhere = " AND status <> 'Approved'";
} else {
  $statusWhere = " AND status = 'Approved'
                   AND signed_timesheet_path IS NOT NULL AND signed_timesheet_path <> ''
                   AND commented_progress_path IS NOT NULL AND commented_progress_path <> ''";
}

/* Fetch submissions */
$subs = [];
if ($hasSubmissions && $hasFiles && $hasMonthCol) {

  $selectExtra = "";
  if ($hasMentorOverall)   $selectExtra .= ", mentor_overall";
  if ($hasMentorImprove)   $selectExtra .= ", mentor_improve";
  if ($hasMentorGeneral)   $selectExtra .= ", mentor_general";
  if ($hasMentorAction)    $selectExtra .= ", mentor_action";
  if ($hasSignedTimesheet) $selectExtra .= ", signed_timesheet_path";
  if ($hasCommentedProg)   $selectExtra .= ", commented_progress_path";
  if ($hasManagerId)       $selectExtra .= ", manager_id";
  if ($hasRoutedTo)        $selectExtra .= ", routed_to";
  if ($hasLastRemindedAt)  $selectExtra .= ", last_reminded_at";
  if ($hasReminderCount)   $selectExtra .= ", reminder_count";

  $stmt = $conn->prepare("
    SELECT id, submission_month, status, manager_comment, created_at
    $selectExtra
    FROM document_submissions
    WHERE user_id = ?
    $statusWhere
    ORDER BY submission_month DESC, id DESC
    LIMIT 50
  ");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res) {
    while ($row = $res->fetch_assoc()) $subs[] = $row;
  }
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Upload Timesheet & Progress Report</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <style>
    body { margin:0; font-family:'Poppins', sans-serif; background:#f6f7fb; color:#0f172a; }
    .wrap { max-width:1100px; margin:0 auto; padding:28px; }
    .card { background:#fff; border:1px solid #eee; border-radius:16px; box-shadow:0 8px 24px rgba(0,0,0,.06); padding:18px; }

    .title { font-size:22px; font-weight:700; color:#0f172a; display:flex; gap:10px; align-items:center; }
    .muted { color:#6b7280; font-size:14px; }
    .btn { background:#1d3557; color:#fff; padding:10px 14px; border-radius:12px; border:none; cursor:pointer; font-weight:700; }
    .btn:disabled { opacity:.6; cursor:not-allowed; }
    .btn-light { background:#fff; color:#1d3557; border:1px solid #e5e7eb; padding:10px 14px; border-radius:12px; font-weight:700; cursor:pointer; }

    table { width:100%; border-collapse:collapse; }
    th, td { padding:12px; border-bottom:1px solid #eee; text-align:left; font-size:14px; vertical-align:top; }
    th { color:#0f172a; font-weight:700; background:#fafafa; }
    td { color:#0f172a; }

    .pill { display:inline-block; padding:6px 10px; border-radius:999px; font-size:12px; font-weight:700; background:#fff7ed; color:#9a3412; }
    .filelink { color:#1d3557; text-decoration:underline; }
    .hint { background:#eff6ff; color:#1e40af; padding:10px 12px; border-radius:12px; margin-top:12px; }
    .dlbtn { display:inline-block; margin-top:6px; padding:8px 10px; border-radius:12px; background:#0f766e; color:#fff; text-decoration:none; font-weight:700; font-size:13px; }
    .dlbtn2 { display:inline-block; margin-top:6px; padding:8px 10px; border-radius:12px; background:#1d3557; color:#fff; text-decoration:none; font-weight:700; font-size:13px; }
    .box { background:#fafafa; border:1px solid #eee; border-radius:14px; padding:12px; margin-top:8px; color:#0f172a; }
    .subttl { font-weight:800; color:#1d3557; margin-bottom:6px; }

    .tabs { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
    .tab { padding:10px 12px; border-radius:999px; border:1px solid #e5e7eb; background:#fff; color:#1d3557; font-weight:800; text-decoration:none; font-size:13px; }
    .tab.active { background:#1d3557; color:#fff; border-color:#1d3557; }

    .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    @media (max-width:900px){ .grid2 { grid-template-columns:1fr; } }

    iframe.preview { width:100%; height:260px; border:1px solid #eee; border-radius:14px; margin-top:10px; background:#fff; }

    .row-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .remind { display:inline-flex; align-items:center; gap:8px; padding:8px 10px; border-radius:12px; border:1px solid #e5e7eb; background:#fff; color:#111827; font-weight:800; text-decoration:none; font-size:13px; }
    .remind:hover { background:#f9fafb; }

    label { color:#6b7280; }

    body.dark { background:#0f172a; color:#e2e8f0; }
    body.dark .card { background:#1e293b; border-color:#334155; }
    body.dark .box  { background:#0f172a; border-color:#334155; color:#e2e8f0; }
    body.dark .title  { color:#f1f5f9; }
    body.dark .muted  { color:#94a3b8; }
    body.dark .subttl { color:#7dd3fc; }
    body.dark label   { color:#94a3b8; }
    body.dark b       { color:#f1f5f9; }
    body.dark p       { color:#cbd5e1; }
    body.dark table th { background:#1e293b; color:#f1f5f9; border-bottom-color:#334155; }
    body.dark table td { color:#e2e8f0; border-bottom-color:#334155; }
    body.dark table tr:hover td { background:#1e3a5f22; }
    body.dark .pill { background:#292524; color:#fdba74; }
    body.dark .filelink { color:#7dd3fc; }
    body.dark .btn       { background:#3b82f6; color:#fff; }
    body.dark .btn-light { background:#1e293b; color:#e2e8f0; border-color:#475569; }
    body.dark .remind    { background:#1e293b; color:#e2e8f0; border-color:#475569; }
    body.dark .remind:hover { background:#334155; }
    body.dark .dlbtn  { background:#0f766e; color:#fff; }
    body.dark .dlbtn2 { background:#3b82f6; color:#fff; }
    body.dark .tab        { background:#1e293b; color:#93c5fd; border-color:#334155; }
    body.dark .tab.active { background:#3b82f6; color:#fff; border-color:#3b82f6; }
    body.dark input,
    body.dark select { background:#0f172a; color:#f1f5f9; border-color:#475569; }
    body.dark input::placeholder { color:#64748b; }
    body.dark .hint { background:#1e3a5f; color:#93c5fd; }
    body.dark .toast-success { background:#064e3b; color:#6ee7b7; }
    body.dark .toast-danger  { background:#450a0a; color:#fca5a5; }
    body.dark iframe.preview { background:#0f172a; border-color:#475569; }
    body.dark .notice-warn   { background:#292524; color:#fdba74; }
    body.dark .notice-empty  { background:#1e293b; color:#cbd5e1; }
    body.dark span.muted { color:#94a3b8; }
  </style>
</head>
<body>

<div class="wrap">

  <div style="margin-top:0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <a class="btn-light" href="leader_index.php" style="text-decoration:none;">
      <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
    </a>
    <button id="darkToggle" class="btn-light">
      <i class="fa-solid fa-moon"></i>
    </button>
  </div>

  <div class="card" style="margin-top:14px;">
    <div class="title"><i class="fa-solid fa-file-arrow-up"></i> Upload Timesheet &amp; Progress Report</div>
    <p class="muted" style="margin-top:6px;">
      Submit <b>once per month</b>. Current month: <b><?php echo esc($currentMonthText); ?></b>
    </p>

    <div class="hint"></div>

    <?php if ($toast): ?>
      <div style="margin-top:14px; padding:12px; border-radius:12px; font-weight:700;"
           class="<?php echo $toast['type']==='success' ? 'toast-success' : 'toast-danger'; ?>"
           style="background:<?php echo $toast['type']==='success' ? '#ecfdf5' : '#fef2f2'; ?>;
                  color:<?php echo $toast['type']==='success' ? '#065f46' : '#991b1b'; ?>;">
        <?php echo esc($toast['msg']); ?>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" style="margin-top:16px;" id="uploadForm">

      <div>
        <label style="display:block; margin-bottom:6px; font-size:14px;">
          Select Manager to Sign <span style="color:#ef4444">*</span>
        </label>
        <select name="manager_id" id="manager_id" required
          style="border-radius:12px; padding:10px; width:100%; border:1px solid #ddd;">
          <option value="">-- Choose a manager --</option>
          <?php foreach($managers as $m): ?>
            <option value="<?php echo (int)$m['user_id']; ?>">
              <?php echo esc($m['name']); ?> (<?php echo esc($m['email']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!$managers): ?>
          <div class="muted" style="margin-top:6px;color:#b45309;">
            No managers found in the system. Ensure users.role = "manager".
          </div>
        <?php endif; ?>
      </div>

      <div class="grid2" style="margin-top:14px;">
        <div>
          <label style="display:block; margin-bottom:6px; font-size:14px;">Timesheet (required)</label>
          <input type="file" name="timesheet" id="timesheet" required accept=".pdf,.doc,.docx"
                 style="border-radius:12px; padding:10px; width:100%; border:1px solid #ddd;">
          <div class="muted" id="tsName" style="margin-top:6px;">No file selected</div>
          <iframe id="tsPreview" class="preview" style="display:none;"></iframe>
        </div>

        <div>
          <label style="display:block; margin-bottom:6px; font-size:14px;">Progress Report (required)</label>
          <input type="file" name="progress_report" id="progress_report" required accept=".pdf,.doc,.docx"
                 style="border-radius:12px; padding:10px; width:100%; border:1px solid #ddd;">
          <div class="muted" id="prName" style="margin-top:6px;">No file selected</div>
          <iframe id="prPreview" class="preview" style="display:none;"></iframe>
        </div>
      </div>

      <div class="muted" style="margin-top:10px;">Max 12MB per file.</div>

      <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <button class="btn" type="submit" id="submitBtn" disabled>
          <i class="fa-solid fa-upload"></i> Upload &amp; Send
        </button>
        <span class="muted" id="readyHint">Select a manager + choose both files to enable upload.</span>
      </div>
    </form>
  </div>

  <div class="card" style="margin-top:18px;">
    <div class="title" style="font-size:18px;"><i class="fa-solid fa-folder-open"></i> My Submissions</div>
    <p class="muted" style="margin-top:6px;">Submitted shows pending items. Signed shows only fully signed documents.</p>

    <div class="tabs">
      <a class="tab <?php echo $tab==='submitted'?'active':''; ?>" href="?tab=submitted">Submitted</a>
      <a class="tab <?php echo $tab==='signed'?'active':''; ?>" href="?tab=signed">Signed</a>
    </div>

    <?php if (!$hasSubmissions || !$hasFiles || !$hasMonthCol): ?>
      <div class="notice-warn" style="margin-top:14px; padding:12px; border-radius:12px; background:#fff7ed; color:#9a3412; font-weight:700;">
        DB not ready. Ensure tables exist + <b>document_submissions.submission_month</b>.
      </div>
    <?php elseif (!$subs): ?>
      <div class="notice-empty" style="margin-top:14px; padding:12px; border-radius:12px; background:#f3f4f6; color:#374151;">
        No items in this tab yet.
      </div>
    <?php else: ?>
      <div style="overflow:auto; margin-top:12px;">
        <table>
          <thead>
            <tr>
              <th>Month</th>
              <th>Files</th>
              <th>Status</th>
              <th>Manager</th>
              <th>Mentor Feedback</th>
              <th>Signed Docs</th>
              <th>Submitted</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($subs as $s): ?>
              <?php
                $files = getFilesForSubmission($conn, (int)$s['id']);
                $signedTS = $s['signed_timesheet_path'] ?? '';
                $commPR   = $s['commented_progress_path'] ?? '';
                $isFullySigned = (($s['status'] ?? '') === 'Approved' && $signedTS !== '' && $commPR !== '');

                $mgrName = $s['routed_to'] ?? '—';
                $lastReminded = $s['last_reminded_at'] ?? '';
                $remCount = (int)($s['reminder_count'] ?? 0);

                $canRemind = true;
                if ($lastReminded) {
                  $lastTs = strtotime($lastReminded);
                  if ($lastTs && (time() - $lastTs) < (12 * 3600)) $canRemind = false;
                }
                $isPending = (($s['status'] ?? '') !== 'Approved');
              ?>
              <tr>
                <td><b><?php echo esc(monthText($s['submission_month'])); ?></b></td>

                <td>
                  <?php foreach ($files as $f): ?>
                    <?php $label = ($f['doc_type']==='timesheet') ? 'Timesheet' : 'Progress Report'; ?>
                    <div style="margin-bottom:10px;">
                      <b><?php echo esc($label); ?>:</b><br/>
                      <a class="filelink" href="<?php echo esc('../' . ltrim($f['stored_path'], '/')); ?>" target="_blank" rel="noopener">
                        <?php echo esc($f['original_name']); ?>
                      </a>
                      <div style="margin-top:4px;">
                        <a class="filelink" style="font-weight:700;" href="<?php echo esc('../' . ltrim($f['stored_path'], '/')); ?>" target="_blank" rel="noopener">
                          Preview
                        </a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </td>

                <td>
                  <span class="pill"><?php echo esc($s['status']); ?></span>
                  <?php if ($isPending && $hasManagerId && (int)($s['manager_id'] ?? 0) > 0): ?>
                    <div style="margin-top:10px;" class="row-actions">
                      <?php if ($canRemind): ?>
                        <a class="remind"
                           href="?tab=<?php echo esc($tab); ?>&action=remind&id=<?php echo (int)$s['id']; ?>"
                           onclick="return confirm('Send a reminder email to the manager now?');">
                          <i class="fa-solid fa-bell"></i> Remind Manager
                        </a>
                      <?php else: ?>
                        <span class="muted" style="font-weight:700;">
                          Reminder sent recently (try later)
                        </span>
                      <?php endif; ?>

                      <span class="muted">
                        Reminders: <b><?php echo (int)$remCount; ?></b>
                        <?php if ($lastReminded): ?>
                          &bull; Last: <?php echo esc($lastReminded); ?>
                        <?php endif; ?>
                      </span>
                    </div>
                  <?php endif; ?>
                </td>

                <td><?php echo esc($mgrName); ?></td>

                <td style="min-width:260px;">
                  <?php
                    $hasAnyMentor =
                      (!empty($s['mentor_overall'] ?? '') ||
                       !empty($s['mentor_improve'] ?? '') ||
                       !empty($s['mentor_general'] ?? '') ||
                       !empty($s['mentor_action'] ?? ''));

                    if ($hasAnyMentor):
                  ?>
                    <div class="box">
                      <?php if (!empty($s['mentor_overall'] ?? '')): ?>
                        <div class="subttl">Overall feedback</div>
                        <div><?php echo nl2br(esc($s['mentor_overall'])); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($s['mentor_improve'] ?? '')): ?>
                        <div class="subttl" style="margin-top:10px;">Areas to improve</div>
                        <div><?php echo nl2br(esc($s['mentor_improve'])); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($s['mentor_general'] ?? '')): ?>
                        <div class="subttl" style="margin-top:10px;">General comments</div>
                        <div><?php echo nl2br(esc($s['mentor_general'])); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($s['mentor_action'] ?? '')): ?>
                        <div class="subttl" style="margin-top:10px;">Action plan</div>
                        <div><?php echo nl2br(esc($s['mentor_action'])); ?></div>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span class="muted"><?php echo esc($s['manager_comment'] ?? 'No feedback yet.'); ?></span>
                  <?php endif; ?>
                </td>

                <td style="min-width:220px;">
                  <?php if ($isFullySigned): ?>
                    <div>
                      <a class="dlbtn" target="_blank" rel="noopener"
                         href="<?php echo esc('../' . ltrim($signedTS, '/')); ?>">
                        <i class="fa-solid fa-file-signature"></i> Signed Timesheet
                      </a>
                    </div>
                    <div>
                      <a class="dlbtn2" target="_blank" rel="noopener"
                         href="<?php echo esc('../' . ltrim($commPR, '/')); ?>">
                        <i class="fa-solid fa-file-lines"></i> Commented Progress
                      </a>
                    </div>
                  <?php else: ?>
                    <span class="muted">Available after manager signs both documents</span>
                  <?php endif; ?>
                </td>

                <td><?php echo esc($s['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
  function pretty(file){
    if(!file) return "No file selected";
    const mb = (file.size / (1024*1024)).toFixed(2);
    return `${file.name} (${mb} MB)`;
  }

  function setPreview(input, nameEl, frameEl){
    const f = input.files && input.files[0];
    if(!f){
      nameEl.textContent = "No file selected";
      frameEl.style.display = "none";
      frameEl.src = "";
      return;
    }
    nameEl.textContent = pretty(f);
    const ext = (f.name.split('.').pop() || '').toLowerCase();
    if(ext === 'pdf'){
      frameEl.src = URL.createObjectURL(f);
      frameEl.style.display = "block";
    } else {
      frameEl.style.display = "none";
      frameEl.src = "";
    }
  }

  const ts  = document.getElementById('timesheet');
  const pr  = document.getElementById('progress_report');
  const mgr = document.getElementById('manager_id');

  const tsName    = document.getElementById('tsName');
  const prName    = document.getElementById('prName');
  const tsPreview = document.getElementById('tsPreview');
  const prPreview = document.getElementById('prPreview');
  const submitBtn = document.getElementById('submitBtn');
  const readyHint = document.getElementById('readyHint');

  function updateReady(){
    const ok = (mgr.value !== '') && (ts.files && ts.files.length) && (pr.files && pr.files.length);
    submitBtn.disabled = !ok;
    readyHint.textContent = ok ? "Ready to upload ✅" : "Select a manager + choose both files to enable upload.";
  }

  ts?.addEventListener("change",  () => { setPreview(ts, tsName, tsPreview); updateReady(); });
  pr?.addEventListener("change",  () => { setPreview(pr, prName, prPreview); updateReady(); });
  mgr?.addEventListener("change", updateReady);
  updateReady();
</script>

<script>
  const toggle = document.getElementById("darkToggle");

  toggle.addEventListener("click", () => {
    document.body.classList.toggle("dark");
    if(document.body.classList.contains("dark")){
      localStorage.setItem("darkMode","on");
      toggle.innerHTML = '<i class="fa-solid fa-sun"></i>';
    } else {
      localStorage.setItem("darkMode","off");
      toggle.innerHTML = '<i class="fa-solid fa-moon"></i>';
    }
  });

  if(localStorage.getItem("darkMode") === "on"){
    document.body.classList.add("dark");
    toggle.innerHTML = '<i class="fa-solid fa-sun"></i>';
  }
</script>

</body>
</html>