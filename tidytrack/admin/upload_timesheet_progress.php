<?php
// upload_timesheet_progress.php (NO SIDEBAR)
// ✅ Upload Timesheet + Progress report (once per month)
// ✅ Tabs: Submitted | Signed
// ✅ Signed tab only shows items where BOTH signed docs exist
// ✅ Shows mentor fields + download buttons (only when both docs exist)

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

/* Helpers */
function ensureDir($dir){
  if (!is_dir($dir)) mkdir($dir, 0755, true);
}
function monthText($ymd){
  try { return (new DateTime($ymd))->format('F Y'); }
  catch (Throwable $e) { return (string)$ymd; }
}
function validateUpload($file, $maxBytes, $allowedExt){
  if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    return "Please choose a file.";
  }
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
  $origName = $file['name'];
  $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $origName);
  $stored   = time() . '_' . $safeName;

  $absPath = rtrim($absDir,'/') . '/' . $stored;
  $relPath = rtrim($relDir,'/') . '/' . $stored;

  if (!move_uploaded_file($file['tmp_name'], $absPath)) {
    return [false, "Upload failed. Could not save file."];
  }
  return [true, [$origName, $relPath, ($file['type'] ?? ''), (int)$file['size'], $absPath]];
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
function colExists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  $ok = ($r && $r->num_rows > 0);
  if ($r) $r->free();
  return $ok;
}

/* Checks */
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

/* Upload settings */
$maxBytes = 12 * 1024 * 1024;
$allowedExt = ['pdf','doc','docx']; // restrict later

/* Auto month */
$submissionMonth = date('Y-m-01');
$currentMonthText = monthText($submissionMonth);

/* POST upload */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$hasSubmissions || !$hasFiles) {
    $toast = ['type'=>'danger','msg'=>'Missing DB tables. Create document_submissions and submission_files first.'];
  } elseif (!$hasMonthCol) {
    $toast = ['type'=>'danger','msg'=>"Missing column: document_submissions.submission_month. Add it with ALTER TABLE."];
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
          $stmt = $conn->prepare("
            INSERT INTO document_submissions (user_id, role, submission_month, status)
            VALUES (?, ?, ?, 'Pending Manager Review')
          ");
          $stmt->bind_param("iss", $userId, $role, $submissionMonth);
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

          $docType = 'timesheet';
          $stmt->bind_param("issssi", $submissionId, $docType, $orig1, $rel1, $mime1, $size1);
          if (!$stmt->execute()) throw new Exception("Insert timesheet row failed: ".$stmt->error);

          $docType = 'progress_report';
          $stmt->bind_param("issssi", $submissionId, $docType, $orig2, $rel2, $mime2, $size2);
          if (!$stmt->execute()) throw new Exception("Insert progress row failed: ".$stmt->error);

          $stmt->close();

          $conn->commit();
          $toast = ['type'=>'success','msg'=>"Uploaded successfully for $currentMonthText and sent to the manager."];
        } catch (Throwable $ex) {
          $conn->rollback();
          foreach ($savedAbsPaths as $p) @unlink($p);
          $toast = ['type'=>'danger','msg'=>$ex->getMessage()];
        }
      }
    }
  }
}

/* Tabs */
$tab = strtolower(trim($_GET['tab'] ?? 'submitted'));
if (!in_array($tab, ['submitted','signed'], true)) $tab = 'submitted';

/* Signed tab must show ONLY fully completed (both docs exist) */
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

  <script src="https://cdn.tailwindcss.com"> </script>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <style>
    body { margin:0; font-family:'Poppins', sans-serif; background:#f6f7fb; }
    .wrap { max-width: 1100px; margin: 0 auto; padding: 28px; }
    .card{ background:#fff; border:1px solid #eee; border-radius:14px; box-shadow:0 6px 18px rgba(0,0,0,.05); padding:18px; }
    .title{ font-size:22px; font-weight:600; color:#1d3557; }
    .muted{ color:#6b7280; font-size:14px; }
    .btn{ background:#1d3557; color:#fff; padding:10px 14px; border-radius:10px; border:none; cursor:pointer; font-weight:600; }
    table{ width:100%; border-collapse:collapse; }
    th, td{ padding:12px; border-bottom:1px solid #eee; text-align:left; font-size:14px; vertical-align: top; }
    th{ color:#1d3557; font-weight:600; background:#fafafa; }
    .pill{ display:inline-block; padding:6px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#fff7ed; color:#9a3412; }
    .filelink{ color:#1d3557; text-decoration: underline; }
    .hint { background:#eff6ff; color:#1e40af; padding:10px 12px; border-radius:10px; margin-top:12px; }
    .dlbtn{ display:inline-block; margin-top:6px; padding:8px 10px; border-radius:10px; background:#0f766e; color:#fff; text-decoration:none; font-weight:600; font-size:13px; }
    .dlbtn2{ display:inline-block; margin-top:6px; padding:8px 10px; border-radius:10px; background:#1d3557; color:#fff; text-decoration:none; font-weight:600; font-size:13px; }
    .box { background:#fafafa; border:1px solid #eee; border-radius:12px; padding:10px; margin-top:8px; }
    .subttl{ font-weight:700; color:#1d3557; margin-bottom:6px; }

    .tabs{ display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
    .tab{
      padding:10px 12px; border-radius:999px; border:1px solid #e5e7eb;
      background:#fff; color:#1d3557; font-weight:700; text-decoration:none; font-size:13px;
    }
    .tab.active{ background:#1d3557; color:#fff; border-color:#1d3557; }
    
    /* Tooltip */
.tip {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  margin-left: 6px;
  border-radius: 999px;
  background: #e5e7eb;
  color: #111827;
  font-size: 12px;
  cursor: help;
  user-select: none;
}
.tip:hover::after {
  content: attr(data-tip);
  position: absolute;
  left: 50%;
  top: 130%;
  transform: translateX(-50%);
  background: rgba(17,24,39,0.95);
  color: #fff;
  padding: 10px 12px;
  border-radius: 10px;
  width: 260px;
  font-size: 12px;
  line-height: 1.35;
  z-index: 9999;
  box-shadow: 0 12px 30px rgba(0,0,0,.25);
}
.tip:hover::before{
  content:"";
  position:absolute;
  left:50%;
  top:118%;
  transform: translateX(-50%);
  border: 7px solid transparent;
  border-bottom-color: rgba(17,24,39,0.95);
}
/* ============================
   DARK MODE SYSTEM
============================ */

:root{
  --bg:#f6f7fb;
  --card:#ffffff;
  --text:#111827;
  --border:#e5e7eb;
  --accent:#1d3557;
  --muted:#6b7280;
}

[data-theme="dark"]{
  --bg:#0f172a;
  --card:#1e293b;
  --text:#e2e8f0;
  --border:#334155;
  --accent:#60a5fa;
  --muted:#94a3b8;
}

/* Apply Variables */

body{
  background:var(--bg);
  color:var(--text);
  transition:0.3s ease;
}

.card{
  background:var(--card) !important;
  border:1px solid var(--border) !important;
}

.muted{
  color:var(--muted) !important;
}

.title{
  color:var(--accent) !important;
}

table{
  width:100%;
}

th{
  background:var(--card) !important;
  color:var(--accent) !important;
  border-bottom:1px solid var(--border);
}

td{
  color:var(--text);
  border-bottom:1px solid var(--border);
}

.tab{
  background:var(--card);
  color:var(--text);
  border:1px solid var(--border);
}

.tab.active{
  background:var(--accent);
  color:white;
}

.pill{
  background:var(--border);
  color:var(--accent);
}

.box{
  background:var(--card);
  border:1px solid var(--border);
}

input[type=file]{
  background:var(--card);
  color:var(--text);
  border:1px solid var(--border);
}

    
  </style>
</head>
<body>

<div class="wrap">
    
   <div style="
margin-top:4px;
display:flex;
justify-content:space-between;
align-items:center;
padding-bottom:6px;
">

<!-- LEFT → Back to Dashboard -->
<a class="btn" href="admin_index.php" style="text-decoration:none;">
<i class="fa-solid fa-arrow-left"></i>
<span style="margin-left:6px;">Back to Dashboard</span>
</a>

<!-- RIGHT → Dark Mode Icon Only -->
<button id="themeToggle"
class="btn"
style="
width:44px;
height:40px;
display:flex;
align-items:center;
justify-content:center;
padding:0;
">

<i class="fa-solid fa-moon"></i>

</button>

</div>

  <div class="card">
    <div class="title">Upload Timesheet & Progress Report</div>
    <p class="muted" style="margin-top:6px;">
      You submit <b>once per month</b>. Current submission month: <b><?php echo esc($currentMonthText); ?></b>
    </p>

    <div class="hint">
      <i class="fa-solid fa-circle-info"></i>
      Month is detected automatically based on the date you submit.
    </div>

    <?php if ($toast): ?>
      <div style="margin-top:14px; padding:12px; border-radius:10px;
        background: <?php echo $toast['type']==='success' ? '#ecfdf5' : '#fef2f2'; ?>;
        color: <?php echo $toast['type']==='success' ? '#065f46' : '#991b1b'; ?>;">
        <?php echo esc($toast['msg']); ?>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" style="margin-top:16px;">
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
        <div>
          <label class="muted" style="display:block; margin-bottom:6px;">Timesheet (required)</label>
          <input type="file" name="timesheet" required accept=".pdf,.doc,.docx"
                 style="border-radius:10px; padding:10px; width:100%; border:1px solid #ddd;">
        </div>

        <div>
          <label class="muted" style="display:block; margin-bottom:6px;">Progress Report (required)</label>
          <input type="file" name="progress_report" required accept=".pdf,.doc,.docx"
                 style="border-radius:10px; padding:10px; width:100%; border:1px solid #ddd;">
        </div>
      </div>

      <div class="muted" style="margin-top:10px;">Max 12MB per file.</div>

      <div style="margin-top:14px;">
        <button class="btn" type="submit"><i class="fa-solid fa-upload"></i> Upload & Send</button>
      </div>
    </form>
  </div>

  <div class="card" style="margin-top:18px;">
    <div class="title" style="font-size:18px;">My Submissions</div>
    <p class="muted" style="margin-top:6px;">Use toggles to switch between Submitted and Signed.</p>

    <div class="tabs">
      <a class="tab <?php echo $tab==='submitted'?'active':''; ?>" href="?tab=submitted">Submitted</a>
      <a class="tab <?php echo $tab==='signed'?'active':''; ?>" href="?tab=signed">Signed</a>
    </div>

    <?php if (!$hasSubmissions || !$hasFiles || !$hasMonthCol): ?>
      <div style="margin-top:14px; padding:12px; border-radius:10px; background:#fff7ed; color:#9a3412;">
        DB not ready. Ensure tables exist + <b>document_submissions.submission_month</b>.
      </div>
    <?php elseif (!$subs): ?>
      <div style="margin-top:14px; padding:12px; border-radius:10px; background:#f3f4f6; color:#374151;">
        No items in this tab yet.
      </div>
    <?php else: ?>
      <div style="overflow:auto; margin-top:12px;">
        <table>
          <thead>
            <tr>
              <th>Submission Month</th>
              <th>Files</th>
              <th>Status</th>
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
              ?>
              <tr>
                <td><b><?php echo esc(monthText($s['submission_month'])); ?></b></td>

                <td>
                  <?php foreach ($files as $f): ?>
                    <?php $label = ($f['doc_type']==='timesheet') ? 'Timesheet' : 'Progress Report'; ?>
                    <div style="margin-bottom:6px;">
                      <b><?php echo esc($label); ?>:</b>
                      <a class="filelink" href="<?php echo esc('../' . ltrim($f['stored_path'], '/')); ?>" target="_blank" rel="noopener">
                        <?php echo esc($f['original_name']); ?>
                      </a>
                    </div>
                  <?php endforeach; ?>
                </td>

                <td><span class="pill"><?php echo esc($s['status']); ?></span></td>

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
  <script>
document.addEventListener("DOMContentLoaded",()=>{

    const root = document.documentElement;
    const toggle = document.getElementById("themeToggle");

    function loadTheme(){
        return localStorage.getItem("theme") || "light";
    }

    function applyTheme(theme){

        root.setAttribute("data-theme",theme);
        localStorage.setItem("theme",theme);

        if(toggle){
            toggle.innerHTML =
                theme==="dark"
                ? '<i class="fa-solid fa-sun"></i>'
                : '<i class="fa-solid fa-moon"></i>';
        }
    }

    if(toggle){
        toggle.addEventListener("click",()=>{
            const current = loadTheme();
            applyTheme(current==="dark" ? "light" : "dark");
        });
    }

    applyTheme(loadTheme());
});
</script>

</div>
</body>
</html>
