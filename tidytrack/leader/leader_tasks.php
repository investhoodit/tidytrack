<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include '../dp.php';
include '../track.php';

$userId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$userName = isset($_SESSION['name']) ? $_SESSION['name'] : 'Leader';

if (!$userId) {
    die("❌ User not logged in. Please log in.");
}

$stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$currentRole = $currentUser['role'] ?? 'leader';
$stmt->close();

if (function_exists('log_activity')) {
    log_activity($userId, "Viewed My Tasks (Leader)");
}

/* =========================================================
   NOTIFY OTHER LEADERS/ADMINS WHEN A LEADER SUBMITS A TASK
   (excludes the submitter themselves)
========================================================= */
function notifyLeaderTaskSubmitted($conn, $taskId, $taskTitle, $submitterName, $excludeUserId) {
    $stmt = $conn->prepare("SELECT name, email FROM users WHERE role IN ('leader','admin') AND user_id != ? AND email IS NOT NULL AND email != ''");
    $stmt->bind_param("i", $excludeUserId);
    $stmt->execute();
    $recipients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (empty($recipients)) return;

    $subject = "Task Submitted for Review - $taskTitle";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: no-reply@investhoodprojects.com\r\n";
    foreach ($recipients as $r) {
        $ln = htmlspecialchars($r['name']); $st = htmlspecialchars($taskTitle); $ss = htmlspecialchars($submitterName);
        $body = "<html><body style='font-family:Poppins,sans-serif;background:#f4f6f9;padding:30px;'><div style='max-width:560px;margin:auto;background:white;border-radius:12px;overflow:hidden;'><div style='background:#1d3557;padding:24px 30px;'><h2 style='color:white;margin:0;'>Task Submitted for Review</h2></div><div style='padding:28px 30px;'><p>Hi <strong>$ln</strong>,</p><div style='background:#f0f7ff;border-left:4px solid #1d3557;padding:16px;border-radius:8px;margin:20px 0;'><p style='margin:0;font-weight:700;'>$st</p><p style='margin:8px 0 0;font-size:13px;'>Submitted by: <strong>$ss</strong> (Leader)</p></div><a href='https://investhoodprojects.com/tidytracker/tidytrack/' style='display:inline-block;background:#1d3557;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;'>Go to Task Manager</a></div></div></body></html>";
        mail($r['email'], $subject, $body, $headers);
    }
}

/* =========================================================
   INITIAL SUBMIT
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_done'], $_POST['confirm_task_id'])) {
    $taskId = intval($_POST['confirm_task_id']);
    $codeSnippet = trim($_POST['code_snippet']);
    $uploadPath = '';
    if (!empty($_FILES['screenshot']['tmp_name'])) {
        $td = "../member/Uploads/screenshots/"; if (!is_dir($td)) mkdir($td, 0777, true);
        $tf = $td . time() . "_" . basename($_FILES['screenshot']['name']);
        if (move_uploaded_file($_FILES['screenshot']['tmp_name'], $tf)) $uploadPath = $tf;
    }
    $stmt = $conn->prepare("UPDATE tasks SET status='In review', code_snippet=?, file_upload=? WHERE task_id=? AND assignTo=?");
    $stmt->bind_param("ssis", $codeSnippet, $uploadPath, $taskId, $userName); $stmt->execute(); $stmt->close();
    $s2 = $conn->prepare("SELECT taskTitle FROM tasks WHERE task_id=?"); $s2->bind_param("i", $taskId); $s2->execute(); $s2->bind_result($tt); $s2->fetch(); $s2->close();
    notifyLeaderTaskSubmitted($conn, $taskId, $tt, $userName, $userId);
    exit;
}

/* =========================================================
   RE-SUBMIT
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resubmit_task_id'])) {
    $taskId = intval($_POST['resubmit_task_id']);
    $codeSnippet = trim($_POST['code_snippet']);
    $stmt = $conn->prepare("SELECT file_upload FROM tasks WHERE task_id=? AND assignTo=?"); $stmt->bind_param("is", $taskId, $userName); $stmt->execute(); $stmt->bind_result($ef); $stmt->fetch(); $stmt->close();
    $uploadPath = $ef;
    if (!empty($_FILES['screenshot']['tmp_name'])) {
        $td = "../member/Uploads/screenshots/"; if (!is_dir($td)) mkdir($td, 0777, true);
        $tf = $td . time() . "_" . basename($_FILES['screenshot']['name']);
        if (move_uploaded_file($_FILES['screenshot']['tmp_name'], $tf)) $uploadPath = $tf;
    }
    $stmt = $conn->prepare("UPDATE tasks SET code_snippet=?, file_upload=?, status='In review' WHERE task_id=? AND assignTo=? AND LOWER(status)='in review'");
    $stmt->bind_param("ssis", $codeSnippet, $uploadPath, $taskId, $userName); $stmt->execute(); $stmt->close();
    if (function_exists('log_activity')) log_activity($userId, "Re-submitted task #$taskId");
    $s2 = $conn->prepare("SELECT taskTitle FROM tasks WHERE task_id=?"); $s2->bind_param("i", $taskId); $s2->execute(); $s2->bind_result($tt); $s2->fetch(); $s2->close();
    notifyLeaderTaskSubmitted($conn, $taskId, $tt . " (Updated)", $userName, $userId);
    exit;
}

/* =========================================================
   REMINDER
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_reminder'])) {
    $taskId = intval($_POST['task_id']);
    $rdt = $_POST['reminder_date'] . " " . $_POST['reminder_time'] . ":00";
    $nt = $_POST['notification_type'] ?? 'email';
    $stmt = $conn->prepare("INSERT INTO reminders (task_id, user_id, reminder_date, notification_type) VALUES (?,?,?,?)");
    $stmt->bind_param("iiss", $taskId, $userId, $rdt, $nt); $stmt->execute(); $stmt->close();
}

/* =========================================================
   FETCH ALL TASKS ASSIGNED TO THIS LEADER
========================================================= */
$stmt = $conn->prepare("
    SELECT t.task_id, t.taskTitle, t.description, t.assignTo, t.priority, t.role,
           t.dueDate, t.status, t.comment, t.created_at, t.code_snippet, t.file_upload,
           t.overdue_reason, t.overdue_reason_at,
           t.delete_requested, t.delete_reason, t.delete_requested_at, t.delete_approved_at,
           p.project_name
    FROM tasks t
    JOIN projects_x p ON t.project_id = p.project_id
    WHERE t.assignTo = ?
    ORDER BY p.project_name ASC,
             FIELD(t.status,'Completed','Deleted','Delete Requested','In review','In progress'),
             t.dueDate ASC
");
$stmt->bind_param("s", $userName); $stmt->execute();
$allTasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$fiveDaysAgo = date('Y-m-d', strtotime('-5 days'));
$tasksToShow = []; $overdueTasks = []; $deletedTasks = []; $deleteReqTasks = [];

foreach ($allTasks as $task) {
    $s = strtolower(trim($task['status'] ?? 'in progress'));
    if ($s === 'deleted')           { $deletedTasks[]   = $task; continue; }
    if ($s === 'delete requested')  { $deleteReqTasks[] = $task; continue; }
    $isFinished = ($s === 'completed' || $s === 'in review');
    if ($isFinished)                { $tasksToShow[]    = $task; continue; }
    $due = $task['dueDate'] ?? '2099-12-31';
    if ($due < $fiveDaysAgo)        { $overdueTasks[]   = $task; }
    else                            { $tasksToShow[]    = $task; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Tasks - INVESTHOOD_TSM</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

<style>
:root{
  --bg:#f6f8fb; --text:#1f2937; --muted:#6b7280;
  --accent:#1d3557; --accent-600:#162a44; --border:#e5e7eb;
  --surface:#ffffff; --surface-2:#f1f5f9;
  --success:#27ae60; --warning:#f39c12; --danger:#e94560;
  --shadow:0 10px 30px rgba(0,0,0,.12);
}
[data-theme="dark"]{
  --bg:#0b1220; --text:#e5e7eb; --muted:#9ca3af;
  --accent:#60a5fa; --accent-600:#3b82f6; --border:rgba(255,255,255,0.12);
  --surface:#0f172a; --surface-2:#111c33;
  --shadow:0 14px 35px rgba(0,0,0,.45);
  color-scheme: dark;
}
*{box-sizing:border-box;}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:"Poppins",system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
main.page-wrap{max-width:960px;margin:40px auto;padding:0 16px;}

.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap;}
.back-btn{display:inline-flex;align-items:center;gap:8px;text-decoration:none;background:var(--accent);color:#fff;padding:10px 14px;border-radius:10px;font-weight:600;}
.back-btn:hover{background:var(--accent-600);}
.theme-toggle{display:inline-flex;align-items:center;gap:8px;background:var(--surface);color:var(--text);padding:8px 12px;border:1px solid var(--border);border-radius:999px;font-weight:600;cursor:pointer;}
.theme-toggle:hover{background:var(--surface-2);}

.page-title{text-align:center;font-size:clamp(1.6rem,3vw,2rem);font-weight:600;color:var(--accent);margin-bottom:10px;}
.heading-accent{height:4px;width:120px;margin:0 auto 24px;background:linear-gradient(90deg,var(--accent) 0%,#2a9d8f 100%);border-radius:999px;}

.time-bar{background:var(--surface);color:var(--text);padding:14px 22px;border-radius:12px;display:flex;align-items:center;gap:18px;box-shadow:var(--shadow-soft, 0 2px 10px rgba(0,0,0,0.06));margin-bottom:18px;flex-wrap:wrap;}
.time-bar i{color:var(--accent);}
#localTime{font-weight:700;color:var(--accent);letter-spacing:.8px;}
.search-wrap{position:relative;flex:1;min-width:220px;max-width:370px;margin-left:auto;}
.search-wrap i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#bbb;font-size:13px;}
.search-wrap input{width:100%;padding:11px 13px 11px 38px;border-radius:10px;border:1px solid var(--border);outline:none;font-family:inherit;font-size:13px;background:var(--surface);color:var(--text);}

.task-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:12px;}
.task-item{background:var(--surface);color:var(--text);border-radius:12px;padding:18px 20px;display:flex;justify-content:space-between;align-items:center;border-left:5px solid #ccd1d9;box-shadow:0 2px 6px rgba(0,0,0,0.04);transition:all .22s ease;gap:14px;flex-wrap:wrap;}
.task-item:hover{transform:translateX(4px);box-shadow:0 4px 14px rgba(0,0,0,0.08);}
.completed-task{opacity:.8;border-left-color:var(--success)!important;}
.project-header{font-size:1.15rem;font-weight:700;color:var(--accent);margin:28px 0 10px;padding-bottom:7px;border-bottom:2px solid var(--border);}
.section-label{font-weight:700;font-size:11px;letter-spacing:1.2px;text-transform:uppercase;padding:4px 0;margin:18px 0 6px;display:flex;align-items:center;gap:8px;color:var(--muted);}
.section-label::after{content:"";flex:1;height:1px;background:currentColor;opacity:.2;}

.reminder-icon{font-size:17px;cursor:pointer;padding:6px;border-radius:50%;}
.reminder-icon:hover{background:var(--surface-2);}
.reminder-popup{display:none;position:absolute;top:36px;right:0;background:var(--surface);color:var(--text);border:1px solid var(--border);padding:13px;border-radius:10px;box-shadow:var(--shadow);z-index:200;min-width:205px;}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.6);display:flex;justify-content:center;align-items:center;z-index:9999;}
.modal-box{background:var(--surface);color:var(--text);border-radius:16px;padding:26px;width:90%;max-width:540px;box-shadow:var(--shadow);max-height:90vh;overflow-y:auto;}
.modal-box h3{margin:0 0 6px;color:var(--accent);}
.modal-box textarea,.modal-box input[type=file]{width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;resize:vertical;background:var(--surface);color:var(--text);}
.modal-box textarea:focus{outline:none;border-color:var(--accent);}
.btn-row{display:flex;gap:10px;margin-top:16px;}
.btn{padding:10px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;font-family:inherit;}
.btn-primary{background:var(--accent);color:white;}
.btn-success{background:var(--success);color:white;}
.btn-danger{background:var(--danger);color:white;}
.btn-neutral{background:var(--surface-2);color:var(--text);}

.reason-options{display:flex;flex-direction:column;gap:7px;margin:14px 0;}
.reason-option{display:flex;align-items:center;gap:10px;padding:10px 13px;border:2px solid var(--border);border-radius:10px;cursor:pointer;font-size:13px;}
.reason-option:hover,.reason-option.selected{border-color:var(--danger);background:rgba(233,69,96,0.08);color:var(--danger);font-weight:600;}
.reason-option input[type="radio"]{accent-color:var(--danger);width:14px;height:14px;flex-shrink:0;}
.extra-reason{width:100%;padding:10px;border:2px solid var(--border);border-radius:10px;font-family:inherit;font-size:13px;resize:vertical;min-height:65px;margin-top:7px;display:none;background:var(--surface);color:var(--text);}

.collapsible-section{margin-top:22px;border:1px solid var(--border);border-radius:14px;overflow:hidden;background:var(--surface);}
.collapsible-header{display:flex;align-items:center;gap:12px;padding:15px 18px;cursor:pointer;user-select:none;}
.ch-title{flex:1;font-weight:700;font-size:.92rem;}
.ch-count{font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;color:white;}
.ch-arrow{transition:transform .3s ease;}
.ch-arrow.open{transform:rotate(180deg);}
.collapsible-body{display:none;padding:16px;}
.collapsible-body.open{display:block;}

.overdue-card,.del-req-card,.deleted-card{background:var(--surface);color:var(--text);border-radius:10px;padding:15px;margin-bottom:11px;box-shadow:0 1px 4px rgba(0,0,0,0.04);display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}
.overdue-card{border-left:5px solid var(--danger);}
.del-req-card{border-left:5px solid var(--warning);}
.deleted-card{border-left:5px solid #a0aec0;}
.overdue-badge{display:inline-block;background:rgba(233,69,96,0.1);color:var(--danger);font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-top:4px;}
.reason-ok-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(39,174,96,0.1);color:var(--success);font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-top:4px;}
.pending-badge{display:inline-block;background:rgba(243,156,18,0.1);color:#d97706;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-top:4px;}
.deleted-badge{display:inline-block;background:var(--surface-2);color:var(--muted);font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-top:4px;}
.deleted-empty{text-align:center;padding:22px;color:var(--muted);font-size:13px;}
</style>
</head>
<body>
<main class="page-wrap">

  <div class="topbar">
    <!--<a href="leader_index.php" class="back-btn">-->
    <!--  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px"><path d="M15 18l-6-6 6-6"/></svg>-->
    <!--  Back to Dashboard-->
    <!--</a>-->
    <button type="button" class="theme-toggle" id="themeToggle">
      <span id="themeLabel">Dark mode</span>
    </button>
  </div>

  <h1 class="page-title">My Tasks</h1>
  <div class="heading-accent"></div>

  <div class="time-bar">
    <i class="fas fa-clock"></i><span id="localTime">--:--:--</span>
    <i class="fas fa-calendar-alt"></i>
    <span style="font-size:14px;">Track your work, <?= htmlspecialchars($userName) ?></span>
    <div class="search-wrap">
      <i class="fas fa-search"></i>
      <input type="text" id="taskSearch" onkeyup="filterSearch()" placeholder="Search tasks or projects...">
    </div>
  </div>

  <ul class="task-list" id="mainTaskList">
  <?php
  if (count($tasksToShow) > 0):
      $curProj = null; $activeOpen = $reviewOpen = $doneOpen = false;
      foreach ($tasksToShow as $row):
          $st = strtolower(trim($row['status'] ?? 'in progress'));
          $isReview = ($st === 'in review');
          $isDone   = ($st === 'completed');
          $canEdit  = $isReview;
          $borderColor = $isDone ? 'var(--success)' : ($isReview ? 'var(--warning)' : '#3498db');

          if ($curProj !== $row['project_name']) {
              $curProj = $row['project_name'];
              echo "<h3 class='project-header'>📁 " . htmlspecialchars($curProj) . "</h3>";
              $activeOpen = $reviewOpen = $doneOpen = false;
          }
          if (!$isReview && !$isDone && !$activeOpen) { echo "<div class='section-label'>Active / To Do</div>"; $activeOpen = true; }
          if ($isReview && !$reviewOpen) { echo "<div class='section-label'>In Review — Waiting Approval</div>"; $reviewOpen = true; }
          if ($isDone && !$doneOpen) { echo "<div class='section-label'>Completed</div>"; $doneOpen = true; }
  ?>
      <li class="task-item <?= $isDone ? 'completed-task' : '' ?>"
          data-search="<?= strtolower(htmlspecialchars($row['taskTitle'].' '.$row['project_name'])) ?>"
          style="border-left-color:<?= $borderColor ?>;">
          <div style="flex:1;min-width:220px;">
              <div style="font-size:1rem;font-weight:600;margin-bottom:4px;"><?= htmlspecialchars($row['taskTitle']) ?></div>
              <div style="color:var(--muted);font-size:13px;margin-bottom:6px;"><?= nl2br(htmlspecialchars($row['description'])) ?></div>
              <div style="font-size:11px;color:var(--muted);">
                  <i class="fas fa-calendar-alt"></i> Due: <?= htmlspecialchars($row['dueDate'] ?? 'N/A') ?>
                  &nbsp;|&nbsp;<i class="fas fa-user-tag"></i> <?= htmlspecialchars($row['role'] ?? 'N/A') ?>
              </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;position:relative;flex-shrink:0;">
              <?php if ($isReview || $isDone): ?>
                  <button onclick="openViewSubmission(<?= $row['task_id'] ?>)" class="btn" style="background:#8e44ad;color:white;">View Submission</button>
              <?php else: ?>
                  <button onclick="openDonePopup(<?= $row['task_id'] ?>)" class="btn" style="background:#3498db;color:white;">Submit Work</button>
              <?php endif; ?>
              <?php if (!$isDone): ?>
                  <button onclick="openDeleteModal(<?= $row['task_id'] ?>,'<?= addslashes(htmlspecialchars($row['taskTitle'])) ?>')"
                          class="btn" style="background:rgba(233,69,96,0.1);color:var(--danger);" title="Request deletion">
                      <i class="fas fa-trash-alt"></i>
                  </button>
              <?php endif; ?>
              <span class="reminder-icon" onclick="toggleReminder(this)">⏰</span>
              <div class="reminder-popup">
                  <form method="POST">
                      <input type="hidden" name="task_id" value="<?= $row['task_id'] ?>">
                      <label style="font-size:11px;display:block;margin-bottom:3px;">Date:</label>
                      <input type="date" name="reminder_date" required style="width:100%;margin-bottom:7px;padding:4px;border:1px solid var(--border);border-radius:4px;background:var(--surface);color:var(--text);">
                      <label style="font-size:11px;display:block;margin-bottom:3px;">Time:</label>
                      <input type="time" name="reminder_time" required style="width:100%;margin-bottom:7px;padding:4px;border:1px solid var(--border);border-radius:4px;background:var(--surface);color:var(--text);">
                      <label style="font-size:11px;display:flex;align-items:center;gap:5px;margin-bottom:9px;"><input type="checkbox" name="notification_type" value="email"> Email</label>
                      <button type="submit" name="set_reminder" class="btn btn-primary" style="width:100%;">Set Reminder</button>
                  </form>
              </div>
          </div>
      </li>

      <!-- Submit Work Modal -->
      <div id="done-popup-<?= $row['task_id'] ?>" class="modal-overlay" style="display:none;">
          <div class="modal-box">
              <h3>Submit Results</h3>
              <p style="font-size:12px;color:var(--muted);margin-bottom:12px;"><?= htmlspecialchars($row['taskTitle']) ?></p>
              <form method="POST" enctype="multipart/form-data">
                  <input type="hidden" name="confirm_task_id" value="<?= $row['task_id'] ?>">
                  <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Your work:</label>
                  <textarea name="code_snippet" rows="6" required placeholder="Paste your work here..."></textarea>
                  <label style="font-size:12px;font-weight:600;display:block;margin:10px 0 5px;">Attach file (optional):</label>
                  <input type="file" name="screenshot">
                  <div class="btn-row">
                      <button type="submit" name="submit_done" class="btn btn-success" style="flex:1;">✅ Confirm</button>
                      <button type="button" onclick="closeDonePopup(<?= $row['task_id'] ?>)" class="btn btn-neutral" style="flex:1;">Cancel</button>
                  </div>
              </form>
          </div>
      </div>

      <!-- View/Edit Submission Modal -->
      <div id="view-submission-<?= $row['task_id'] ?>" class="modal-overlay" style="display:none;">
          <div class="modal-box" style="max-width:610px;">
              <span onclick="document.getElementById('view-submission-<?= $row['task_id'] ?>').style.display='none'" style="float:right;font-size:1.5rem;cursor:pointer;color:var(--muted);">×</span>
              <h3>Submission: <?= htmlspecialchars($row['taskTitle']) ?></h3>
              <form method="POST" enctype="multipart/form-data" <?= $canEdit ? '' : 'onsubmit="return false;"' ?>>
                  <input type="hidden" name="resubmit_task_id" value="<?= $row['task_id'] ?>">
                  <label style="display:block;margin:12px 0 4px;font-weight:600;font-size:12px;">Your Work:</label>
                  <textarea name="code_snippet" rows="7" <?= $canEdit ? 'required' : 'readonly' ?>><?= htmlspecialchars($row['code_snippet'] ?? '') ?></textarea>
                  <label style="display:block;margin:12px 0 4px;font-weight:600;font-size:12px;">File/Screenshot:</label>
                  <?php if (!empty($row['file_upload']) && file_exists($row['file_upload'])): ?>
                      <?php if (pathinfo($row['file_upload'], PATHINFO_EXTENSION) === 'pdf'): ?>
                          <a href="<?= htmlspecialchars($row['file_upload']) ?>" target="_blank" style="color:#3498db;font-size:13px;">View PDF</a>
                      <?php else: ?>
                          <img src="<?= htmlspecialchars($row['file_upload']) ?>" alt="Screenshot" style="max-width:100%;border-radius:8px;">
                      <?php endif; ?>
                  <?php else: ?><p style="color:var(--muted);font-size:13px;">No file uploaded.</p><?php endif; ?>
                  <?php if ($canEdit): ?>
                      <label style="display:block;margin:10px 0 4px;font-weight:600;font-size:12px;">Replace File (optional):</label>
                      <input type="file" name="screenshot">
                      <div class="btn-row">
                          <button type="submit" name="resubmit_task_id" value="<?= $row['task_id'] ?>" class="btn btn-success" style="flex:1;">Update Submission</button>
                          <button type="button" onclick="document.getElementById('view-submission-<?= $row['task_id'] ?>').style.display='none'" class="btn btn-neutral">Close</button>
                      </div>
                  <?php else: ?>
                      <div class="btn-row"><button type="button" onclick="document.getElementById('view-submission-<?= $row['task_id'] ?>').style.display='none'" class="btn btn-neutral" style="flex:1;">Close</button></div>
                  <?php endif; ?>
              </form>
          </div>
      </div>
  <?php endforeach;
  else: ?>
      <div style="text-align:center;padding:44px;color:var(--muted);">
          <i class="fas fa-tasks" style="font-size:2.6rem;margin-bottom:12px;display:block;"></i>No tasks right now.
      </div>
  <?php endif; ?>
  </ul>

  <!-- OVERDUE ARCHIVE -->
  <?php if (!empty($overdueTasks)): ?>
  <div class="collapsible-section">
      <div class="collapsible-header" onclick="toggleCollapsible('overdueBody','overdueArrow')">
          <span>🗃️</span>
          <span class="ch-title" style="color:var(--danger);">Overdue Task Archive</span>
          <span class="ch-count" style="background:var(--danger);"><?= count($overdueTasks) ?></span>
          <i class="fas fa-chevron-down ch-arrow" id="overdueArrow" style="color:var(--danger);"></i>
      </div>
      <div class="collapsible-body" id="overdueBody">
          <p style="font-size:11px;color:var(--muted);margin:0 0 13px;">5+ days overdue. Submit a reason to move to In Review, or request deletion.</p>
          <?php foreach ($overdueTasks as $oTask):
              $daysOv = (int)((strtotime('today') - strtotime($oTask['dueDate'])) / 86400);
              $hasReason = !empty($oTask['overdue_reason']);
          ?>
          <div class="overdue-card" id="overdue-card-<?= $oTask['task_id'] ?>">
              <div style="flex:1;">
                  <div style="font-weight:600;font-size:.9rem;margin-bottom:2px;"><?= htmlspecialchars($oTask['taskTitle']) ?></div>
                  <div style="font-size:11px;color:var(--muted);"><?= htmlspecialchars($oTask['project_name']) ?> | <?= htmlspecialchars($oTask['role'] ?? 'N/A') ?></div>
                  <span class="overdue-badge">⚠️ <?= $daysOv ?>d overdue — due <?= htmlspecialchars($oTask['dueDate']) ?></span>
                  <?php if ($hasReason): ?><br><span class="reason-ok-badge">✅ Reason submitted — In Review</span><?php endif; ?>
              </div>
              <div style="display:flex;flex-direction:column;gap:7px;flex-shrink:0;">
                  <?php if (!$hasReason): ?>
                      <button class="btn btn-danger" onclick="openOverdueModal(<?= $oTask['task_id'] ?>,'<?= addslashes(htmlspecialchars($oTask['taskTitle'])) ?>')">
                          <i class="fas fa-comment-alt"></i> Explain Why
                      </button>
                  <?php endif; ?>
                  <button class="btn" style="background:rgba(233,69,96,0.1);color:var(--danger);"
                          onclick="openDeleteModal(<?= $oTask['task_id'] ?>,'<?= addslashes(htmlspecialchars($oTask['taskTitle'])) ?>')">
                      <i class="fas fa-trash-alt"></i> Request Delete
                  </button>
              </div>
          </div>
          <?php endforeach; ?>
      </div>
  </div>
  <?php endif; ?>

  <!-- DELETE REQUESTED -->
  <?php if (!empty($deleteReqTasks)): ?>
  <div class="collapsible-section">
      <div class="collapsible-header" onclick="toggleCollapsible('delReqBody','delReqArrow')">
          <span>⏳</span>
          <span class="ch-title" style="color:#d97706;">Deletion Pending — Awaiting Approval</span>
          <span class="ch-count" style="background:#d97706;"><?= count($deleteReqTasks) ?></span>
          <i class="fas fa-chevron-down ch-arrow" id="delReqArrow" style="color:#d97706;"></i>
      </div>
      <div class="collapsible-body" id="delReqBody">
          <?php foreach ($deleteReqTasks as $dt): ?>
          <div class="del-req-card">
              <div style="font-weight:600;font-size:.9rem;margin-bottom:2px;"><?= htmlspecialchars($dt['taskTitle']) ?></div>
              <div style="font-size:11px;color:var(--muted);margin-bottom:4px;"><?= htmlspecialchars($dt['project_name']) ?></div>
              <span class="pending-badge">🕐 Awaiting decision</span>
              <?php if (!empty($dt['delete_reason'])): ?>
                  <div style="margin-top:7px;font-size:11px;color:var(--muted);"><strong>Your reason:</strong> <?= htmlspecialchars($dt['delete_reason']) ?></div>
              <?php endif; ?>
          </div>
          <?php endforeach; ?>
      </div>
  </div>
  <?php endif; ?>

  <!-- DELETED TASKS -->
  <div class="collapsible-section">
      <div class="collapsible-header" onclick="toggleCollapsible('deletedBody','deletedArrow')">
          <span>🗑️</span>
          <span class="ch-title" style="color:var(--muted);">Deleted Tasks</span>
          <span class="ch-count" style="background:#718096;"><?= count($deletedTasks) ?></span>
          <i class="fas fa-chevron-down ch-arrow" id="deletedArrow" style="color:var(--muted);"></i>
      </div>
      <div class="collapsible-body" id="deletedBody">
          <?php if (empty($deletedTasks)): ?>
              <div class="deleted-empty">
                  <i class="fas fa-check-circle" style="font-size:1.8rem;color:var(--success);display:block;margin-bottom:7px;"></i>
                  No deleted tasks — your list is clean! ✨
              </div>
          <?php else: ?>
              <?php foreach ($deletedTasks as $dt): ?>
              <div class="deleted-card">
                  <div style="font-weight:600;font-size:.88rem;color:var(--muted);text-decoration:line-through;margin-bottom:2px;"><?= htmlspecialchars($dt['taskTitle']) ?></div>
                  <div style="font-size:11px;color:var(--muted);margin-bottom:4px;"><?= htmlspecialchars($dt['project_name']) ?></div>
                  <span class="deleted-badge">✅ Deletion approved</span>
                  <?php if (!empty($dt['delete_approved_at'])): ?>
                      <span style="font-size:10px;color:var(--muted);margin-left:6px;"><?= date('d M Y', strtotime($dt['delete_approved_at'])) ?></span>
                  <?php endif; ?>
              </div>
              <?php endforeach; ?>
          <?php endif; ?>
      </div>
  </div>

  <!-- OVERDUE REASON MODAL -->
  <div id="overdueModal" class="modal-overlay" style="display:none;">
      <div class="modal-box">
          <h3 style="color:var(--danger);">🚨 Why wasn't this completed?</h3>
          <p id="overdueModalTaskName" style="font-size:12px;color:var(--muted);margin-bottom:3px;"></p>
          <p style="font-size:11px;color:var(--muted);margin-bottom:0;">Submitting moves this task to <strong>In Review</strong>.</p>
          <div class="reason-options" id="overdueReasonOpts">
              <label class="reason-option"><input type="radio" name="ov_reason" value="too_much_work" onchange="selReason(this,'ov')"> 📚 Too much other work</label>
              <label class="reason-option"><input type="radio" name="ov_reason" value="blocked" onchange="selReason(this,'ov')"> 🚧 Blocked / waiting on someone</label>
              <label class="reason-option"><input type="radio" name="ov_reason" value="unclear" onchange="selReason(this,'ov')"> ❓ Requirements were unclear</label>
              <label class="reason-option"><input type="radio" name="ov_reason" value="personal" onchange="selReason(this,'ov')"> 🏥 Personal / health reasons</label>
              <label class="reason-option"><input type="radio" name="ov_reason" value="forgot" onchange="selReason(this,'ov')"> 😕 Forgot / missed deadline</label>
              <label class="reason-option"><input type="radio" name="ov_reason" value="other" onchange="selReason(this,'ov')"> ✏️ Other (add details below)</label>
          </div>
          <textarea id="ovExtra" class="extra-reason" placeholder="Add more details..."></textarea>
          <div class="btn-row">
              <button class="btn btn-danger" style="flex:1;" onclick="submitOverdueReason()"><i class="fas fa-paper-plane"></i> Submit Explanation</button>
              <button class="btn btn-neutral" style="flex:1;" onclick="closeOvModal()">Cancel</button>
          </div>
      </div>
  </div>

  <!-- DELETE REQUEST MODAL -->
  <div id="deleteModal" class="modal-overlay" style="display:none;">
      <div class="modal-box">
          <h3 style="color:var(--danger);">🗑️ Request Task Deletion</h3>
          <p id="deleteModalTaskName" style="font-size:12px;color:var(--muted);margin-bottom:3px;"></p>
          <div class="reason-options" id="deleteReasonOpts">
              <label class="reason-option"><input type="radio" name="del_reason" value="duplicate" onchange="selReason(this,'del')"> 🔁 Duplicate task</label>
              <label class="reason-option"><input type="radio" name="del_reason" value="no_longer_needed" onchange="selReason(this,'del')"> ❌ No longer needed</label>
              <label class="reason-option"><input type="radio" name="del_reason" value="wrong_assignment" onchange="selReason(this,'del')"> 🔀 Wrong person assigned</label>
              <label class="reason-option"><input type="radio" name="del_reason" value="completed_elsewhere" onchange="selReason(this,'del')"> ✅ Already done elsewhere</label>
              <label class="reason-option"><input type="radio" name="del_reason" value="scope_change" onchange="selReason(this,'del')"> 📋 Project scope changed</label>
              <label class="reason-option"><input type="radio" name="del_reason" value="other" onchange="selReason(this,'del')"> ✏️ Other (add details below)</label>
          </div>
          <textarea id="delExtra" class="extra-reason" placeholder="Add more details..."></textarea>
          <div class="btn-row">
              <button class="btn btn-danger" style="flex:1;" onclick="submitDeleteRequest()"><i class="fas fa-trash-alt"></i> Request Deletion</button>
              <button class="btn btn-neutral" style="flex:1;" onclick="closeDelModal()">Cancel</button>
          </div>
      </div>
  </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
/* THEME */
(function initTheme(){
  const root = document.documentElement;
  const saved = localStorage.getItem("theme");
  const prefersDark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
  root.setAttribute("data-theme", saved ? saved : (prefersDark ? "dark" : "light"));
  updateThemeLabel();
})();
function toggleTheme(){
  const root = document.documentElement;
  const current = root.getAttribute("data-theme") || "light";
  const next = current === "dark" ? "light" : "dark";
  root.setAttribute("data-theme", next);
  localStorage.setItem("theme", next);
  updateThemeLabel();
}
function updateThemeLabel(){
  const label = document.getElementById("themeLabel");
  const current = document.documentElement.getAttribute("data-theme");
  if (label) label.textContent = current === "dark" ? "Light mode" : "Dark mode";
}
document.getElementById("themeToggle").addEventListener("click", toggleTheme);

let curOvTaskId = null, curDelTaskId = null;

document.addEventListener('DOMContentLoaded', () => {
    updateTime(); setInterval(updateTime, 1000);
});
function updateTime() {
    const el = document.getElementById('localTime');
    if (el) el.textContent = new Date().toLocaleTimeString('en-ZA',{hour:'2-digit',minute:'2-digit',second:'2-digit',timeZone:'Africa/Johannesburg'});
}

function toggleCollapsible(bId, aId) {
    document.getElementById(bId).classList.toggle('open');
    document.getElementById(aId).classList.toggle('open');
}

function toggleReminder(icon) {
    const p = icon.nextElementSibling;
    const v = p.style.display === 'block';
    document.querySelectorAll('.reminder-popup').forEach(x => x.style.display='none');
    p.style.display = v ? 'none' : 'block';
}
document.addEventListener('click', e => {
    if (!e.target.classList.contains('reminder-icon') && !e.target.closest('.reminder-popup'))
        document.querySelectorAll('.reminder-popup').forEach(p => p.style.display='none');
});

function openDonePopup(id) { const el=document.getElementById('done-popup-'+id); if(el) el.style.display='flex'; }
function closeDonePopup(id) { const el=document.getElementById('done-popup-'+id); if(el) el.style.display='none'; }
function openViewSubmission(id) { const el=document.getElementById('view-submission-'+id); if(el) el.style.display='flex'; }

document.addEventListener('click', e => {
    if (e.target.closest('button')) return;
    document.querySelectorAll('.modal-overlay').forEach(m => {
        if (m.style.display==='flex') { const b=m.querySelector('.modal-box'); if(b && !b.contains(e.target)) m.style.display='none'; }
    });
});

function selReason(radio, type) {
    const opts = type==='ov' ? '#overdueReasonOpts' : '#deleteReasonOpts';
    const extra = type==='ov' ? 'ovExtra' : 'delExtra';
    document.querySelectorAll(opts+' .reason-option').forEach(o => o.classList.remove('selected'));
    radio.closest('.reason-option').classList.add('selected');
    document.getElementById(extra).style.display = radio.value==='other' ? 'block' : 'none';
}

function openOverdueModal(taskId, taskName) {
    curOvTaskId = taskId;
    document.getElementById('overdueModalTaskName').textContent = '📋 '+taskName;
    document.querySelectorAll('input[name="ov_reason"]').forEach(r => r.checked=false);
    document.querySelectorAll('#overdueReasonOpts .reason-option').forEach(o => o.classList.remove('selected'));
    document.getElementById('ovExtra').style.display='none'; document.getElementById('ovExtra').value='';
    document.getElementById('overdueModal').style.display='flex';
}
function closeOvModal() { document.getElementById('overdueModal').style.display='none'; curOvTaskId=null; }

async function submitOverdueReason() {
    const sel = document.querySelector('input[name="ov_reason"]:checked');
    if (!sel) { Swal.fire({icon:'warning',title:'Select a reason',confirmButtonColor:'#e94560'}); return; }
    const label = sel.closest('.reason-option').textContent.trim();
    const extra = document.getElementById('ovExtra').value.trim();
    const reason = extra ? `${label} — ${extra}` : label;

    const btn = document.querySelector('#overdueModal .btn-danger');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Submitting...';

    const fd = new FormData();
    fd.append('action','submit_overdue_reason'); fd.append('task_id',curOvTaskId); fd.append('reason',reason);

    try {
        const res = await fetch('../member/task_actions.php',{method:'POST',body:fd});
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        closeOvModal();
        await Swal.fire({icon:'success',title:'Explanation Submitted',html:'Task moved to <strong>In Review</strong>.',confirmButtonColor:'#27ae60'});
        location.reload();
    } catch(err) {
        Swal.fire({icon:'error',title:'Error',text:err.message||'Something went wrong.'});
        btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> Submit Explanation';
    }
}

function openDeleteModal(taskId, taskName) {
    curDelTaskId = taskId;
    document.getElementById('deleteModalTaskName').textContent = '🗑️ '+taskName;
    document.querySelectorAll('input[name="del_reason"]').forEach(r => r.checked=false);
    document.querySelectorAll('#deleteReasonOpts .reason-option').forEach(o => o.classList.remove('selected'));
    document.getElementById('delExtra').style.display='none'; document.getElementById('delExtra').value='';
    document.getElementById('deleteModal').style.display='flex';
}
function closeDelModal() { document.getElementById('deleteModal').style.display='none'; curDelTaskId=null; }

async function submitDeleteRequest() {
    const sel = document.querySelector('input[name="del_reason"]:checked');
    if (!sel) { Swal.fire({icon:'warning',title:'Select a reason',confirmButtonColor:'#e94560'}); return; }
    const label = sel.closest('.reason-option').textContent.trim();
    const extra = document.getElementById('delExtra').value.trim();
    const reason = extra ? `${label} — ${extra}` : label;

    const btn = document.querySelector('#deleteModal .btn-danger');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending...';

    const fd = new FormData();
    fd.append('action','request_delete'); fd.append('task_id',curDelTaskId); fd.append('reason',reason);

    try {
        const res = await fetch('../member/task_actions.php',{method:'POST',body:fd});
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        closeDelModal();
        await Swal.fire({icon:'info',title:'Deletion Requested',html:'Sent for approval.',confirmButtonColor:'#1d3557'});
        location.reload();
    } catch(err) {
        Swal.fire({icon:'error',title:'Error',text:err.message||'Something went wrong.'});
        btn.disabled=false; btn.innerHTML='<i class="fas fa-trash-alt"></i> Request Deletion';
    }
}

document.addEventListener('click', e => {
    const btn = e.target.closest('button[type="submit"]');
    if (!btn||!btn.name) return;
    if (btn.name!=='submit_done'&&btn.name!=='resubmit_task_id') return;
    e.preventDefault(); e.stopPropagation();
    const form = btn.closest('form'); if(!form) return;
    const txt = btn.name==='submit_done' ? 'Work submitted!' : 'Submission updated!';
    handleAjaxSubmit(form, btn, txt);
});

async function handleAjaxSubmit(form, btn, txt) {
    const orig = btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Processing...';
    const fd = new FormData(form); fd.append(btn.name, btn.value||'1');
    try {
        const res = await fetch(window.location.href,{method:'POST',body:fd});
        if(!res.ok) throw new Error('Server error');
        await Swal.fire({icon:'success',title:'Done!',text:txt,timer:1500,showConfirmButton:false,toast:true,position:'top-end'});
        location.reload();
    } catch(err) {
        Swal.fire({icon:'error',title:'Error',text:'Something went wrong.'});
        btn.disabled=false; btn.innerHTML=orig;
    }
}

function filterSearch() {
    const inp = document.getElementById('taskSearch').value.toLowerCase();
    document.querySelectorAll('.task-item').forEach(i => { i.style.display=i.getAttribute('data-search').includes(inp)?'flex':'none'; });
    document.querySelectorAll('.project-header,.section-label').forEach(h => { h.style.display=inp.length>0?'none':''; });
}
</script>
</body>
</html>
