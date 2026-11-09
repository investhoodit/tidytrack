<?php
session_start();
include '../dp.php';
include '../track.php'; 

$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$userName = isset($_SESSION['name']) ? $_SESSION['name'] : 'Member';
$userInitial = strtoupper($userName[0]);

if (!$userId) {
    die("Unauthorized. Please log in.");
}

$stmt = $conn->prepare("SELECT profile_picture, status, role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$currentProfilePicture = $currentUser['profile_picture'] ?? null;
$currentStatus = $currentUser['status'] ?? 'offline';
$currentRole = $currentUser['role'] ?? 'Member';
$stmt = $conn->prepare("
    SELECT DISTINCT p.project_id, p.project_name 
    FROM projects_x p
    JOIN tasks t ON t.project_id = p.project_id
    WHERE t.assignTo = ?
");
$stmt->bind_param("s", $userName);
$stmt->execute();
$myProjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

log_activity($userId, "Viewed My Tasks");

function notifyLeaderTaskSubmitted($conn, $taskId, $taskTitle, $submitterName) {
    $stmt = $conn->prepare("SELECT name, email FROM users WHERE role IN ('Leader','Admin','leader','admin') AND email IS NOT NULL AND email != ''");
    $stmt->execute();
    $leaders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (empty($leaders)) return;
    $subject = "Task Submitted for Review - $taskTitle";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: no-reply@investhoodprojects.com\r\n";
    foreach ($leaders as $l) {
        $ln = htmlspecialchars($l['name']); $st = htmlspecialchars($taskTitle); $ss = htmlspecialchars($submitterName);
        $body = "<html><body style='font-family:Poppins,sans-serif;background:#f4f6f9;padding:30px;'><div style='max-width:560px;margin:auto;background:white;border-radius:12px;overflow:hidden;'><div style='background:#1d3557;padding:24px 30px;'><h2 style='color:white;margin:0;'>Task Submitted for Review</h2></div><div style='padding:28px 30px;'><p>Hi <strong>$ln</strong>,</p><div style='background:#f0f7ff;border-left:4px solid #1d3557;padding:16px;border-radius:8px;margin:20px 0;'><p style='margin:0;font-weight:700;'>$st</p><p style='margin:8px 0 0;font-size:13px;'>Submitted by: <strong>$ss</strong></p></div><a href='https://investhoodprojects.com/tidytracker/tidytrack/' style='display:inline-block;background:#1d3557;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;'>Go to Task Manager</a></div></div></body></html>";
        mail($l['email'], $subject, $body, $headers);
    }
}

// Initial task submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_done'], $_POST['confirm_task_id'])) {
    $taskId = intval($_POST['confirm_task_id']);
    $codeSnippet = trim($_POST['code_snippet']);
    $uploadPath = '';
    if (!empty($_FILES['screenshot']['tmp_name'])) {
        $td = "Uploads/screenshots/"; if (!is_dir($td)) mkdir($td, 0777, true);
        $tf = $td . time() . "_" . basename($_FILES['screenshot']['name']);
        if (move_uploaded_file($_FILES['screenshot']['tmp_name'], $tf)) $uploadPath = $tf;
    }
    $stmt = $conn->prepare("UPDATE tasks SET status='In review', code_snippet=?, file_upload=? WHERE task_id=? AND assignTo=?");
    $stmt->bind_param("ssis", $codeSnippet, $uploadPath, $taskId, $userName); $stmt->execute(); $stmt->close();
    $s2 = $conn->prepare("SELECT taskTitle FROM tasks WHERE task_id=?"); $s2->bind_param("i", $taskId); $s2->execute(); $s2->bind_result($tt); $s2->fetch(); $s2->close();
    notifyLeaderTaskSubmitted($conn, $taskId, $tt, $userName);
    exit;
}

// Re-submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resubmit_task_id'])) {
    $taskId = intval($_POST['resubmit_task_id']);
    $codeSnippet = trim($_POST['code_snippet']);
    $stmt = $conn->prepare("SELECT file_upload FROM tasks WHERE task_id=? AND assignTo=?"); $stmt->bind_param("is", $taskId, $userName); $stmt->execute(); $stmt->bind_result($ef); $stmt->fetch(); $stmt->close();
    $uploadPath = $ef;
    if (!empty($_FILES['screenshot']['tmp_name'])) {
        $td = "Uploads/screenshots/"; if (!is_dir($td)) mkdir($td, 0777, true);
        $tf = $td . time() . "_" . basename($_FILES['screenshot']['name']);
        if (move_uploaded_file($_FILES['screenshot']['tmp_name'], $tf)) $uploadPath = $tf;
    }
    $stmt = $conn->prepare("UPDATE tasks SET code_snippet=?, file_upload=?, status='In review' WHERE task_id=? AND assignTo=? AND LOWER(status)='in review'");
    $stmt->bind_param("ssis", $codeSnippet, $uploadPath, $taskId, $userName); $stmt->execute(); $stmt->close();
    log_activity($userId, "Re-submitted task #$taskId");
    $s2 = $conn->prepare("SELECT taskTitle FROM tasks WHERE task_id=?"); $s2->bind_param("i", $taskId); $s2->execute(); $s2->bind_result($tt); $s2->fetch(); $s2->close();
    notifyLeaderTaskSubmitted($conn, $taskId, $tt . " (Updated)", $userName);
    exit;
}

//for cretan your own task 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_own_task'])) {
    $title   = trim($_POST['taskTitle']);
    $desc    = trim($_POST['description']);
    $due     = $_POST['dueDate'];
    $projId  = intval($_POST['project_id']);

    $stmt = $conn->prepare("INSERT INTO tasks (taskTitle, description, assignTo, dueDate, status, project_id, role, created_at) VALUES (?,?,?,?,'In progress',?,?,NOW())");
    $stmt->bind_param("ssssis", $title, $desc, $userName, $due, $projId, $currentRole);
    $stmt->execute();
    $stmt->close();

      log_activity($userId, "Added own task: $title");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Reminder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_reminder'])) {
    $taskId = intval($_POST['task_id']);
    $rdt = $_POST['reminder_date'] . " " . $_POST['reminder_time'] . ":00";
    $nt = $_POST['notification_type'];
    $stmt = $conn->prepare("INSERT INTO reminders (task_id, user_id, reminder_date, notification_type) VALUES (?,?,?,?)");
    $stmt->bind_param("iiss", $taskId, $userId, $rdt, $nt); $stmt->execute(); $stmt->close();
}

// Fetch all tasks
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

$status_info = [
    'online'=>['name'=>'Online'],'out_of_office'=>['name'=>'Out of Office'],'sick'=>['name'=>'Sick'],
    'focusing'=>['name'=>'Focusing'],'in_meeting'=>['name'=>'In Meeting'],'offline'=>['name'=>'Offline'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Tasks - INVESTHOOD_TSM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
    <style>
    
    /* ================== DARK MODE MAIN CONTENT TEXT ================== */
body.dark-mode .dashboard-container,
body.dark-mode .dashboard-container * {
    color: #ffffff !important;   /* Make all text white */
    font-weight: 600 !important; /* Bold enough to be readable */
}

/* Placeholders in inputs/textareas */
body.dark-mode .dashboard-container ::placeholder {
    color: #ccc !important;
    font-weight: 500 !important;
}

/* Links */
body.dark-mode .dashboard-container a {
    color: #1d9bf0 !important;
    text-decoration: underline !important;
}

/* Smaller text like due dates, roles */
body.dark-mode .dashboard-container div[style*="color:#555"],
body.dark-mode .dashboard-container div[style*="color:#aaa"],
body.dark-mode .dashboard-container p {
    color: #ffffff !important;
    font-weight: 600 !important;
}

/* Modals and textareas in main content */
body.dark-mode .dashboard-container .modal-box,
body.dark-mode .dashboard-container .modal-box textarea,
body.dark-mode .dashboard-container .modal-box input,
body.dark-mode .dashboard-container .modal-box select {
    color: #ffffff !important;
    font-weight: 600 !important;
}
    
    /* 🔥 FORCE FULL DARK MODE (REMOVE ALL WHITE BACKGROUNDS) */
body.dark-mode,
body.dark-mode * {
    background-color: transparent;
}

/* Restore proper dark backgrounds using variables */
body.dark-mode {
    background: #121212 !important;
}

body.dark-mode .sidebar,
body.dark-mode .task-item,
body.dark-mode .time-bar,
body.dark-mode .modal-box,
body.dark-mode .collapsible-section,
body.dark-mode .collapsible-header,
body.dark-mode .overdue-card,
body.dark-mode .del-req-card,
body.dark-mode .deleted-card,
body.dark-mode .reminder-popup {
    background: #1e1e1e !important;
    color: #f1f1f1 !important;
}

/* Fix ALL light cards & sections */
body.dark-mode .project-header,
body.dark-mode .section-label {
    background: transparent !important;
    color: #ccc !important;
}

/* Fix buttons that are still white */
body.dark-mode button {
    background-color: #2a2a2a !important;
    color: #fff !important;
    border: 1px solid #444 !important;
}

/* Fix special light backgrounds */
body.dark-mode .btn-neutral {
    background: #2a2a2a !important;
    color: #ccc !important;
}

body.dark-mode .reason-option {
    background: #1a1a1a !important;
    border-color: #333 !important;
    color: #ccc !important;
}

/* Kill gradient light headers */
body.dark-mode .collapsible-header[style*="linear-gradient"] {
    background: #1e1e1e !important;
}

/* Fix textareas & inputs */
body.dark-mode textarea,
body.dark-mode input,
body.dark-mode select {
    background: #1e1e1e !important;
    color: #fff !important;
    border: 1px solid #333 !important;
}

/* Fix popup (THIS is one of your white issues) */
body.dark-mode .profile-popup {
    background: #1e1e1e !important;
    color: #fff !important;
}

/* Fix any leftover light badges */
body.dark-mode .overdue-badge,
body.dark-mode .pending-badge,
body.dark-mode .deleted-badge {
    background: #2a2a2a !important;
    border-color: #444 !important;
    color: #ccc !important;
}
    /* DARK MODE: SEARCH BAR */
body.dark-mode #taskSearch {
    background: #1e1e1e !important;
    color: #fff !important;
    border: 1px solid #333 !important;
}

body.dark-mode #taskSearch::placeholder {
    color: #888 !important;
}
        :root { --accent:#1d3557; --bg:#eef2f5; --success:#27ae60; --warning:#f39c12; --danger:#e94560;--accent:#1d3557;

    /* LIGHT MODE */
    --bg:#eef2f5;
    --card:#ffffff;
    --text:#222;
    --text-light:#555;
    --border:#e0e0e0;

    /* STATUS COLORS */
    --success:#27ae60;
    --warning:#f39c12;
    --danger:#e94560; }
    body.dark-mode {
    --bg:#121212;
    --card:#1e1e1e;
    --text:#f1f1f1;
    --text-light:#b0b0b0;
    --border:#333;
}
        *{box-sizing:border-box;} body{ font-family:'Poppins',sans-serif;
    margin:0;
    background: var(--bg);
    color: var(--text);
    display:flex;
    min-height:100vh;}
        /* SIDEBAR */
  
.sidebar {
    width: 260px;
    background: var(--card);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    padding: 18px;
    position: fixed;
    height: 100vh;
    overflow-y: auto;
    z-index: 100;
}
.sidebar .top {
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
}
.sb-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 15px;
    position: relative;
    flex-shrink: 0;
    overflow: hidden;
}
.sb-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.sb-avatar .dot {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    border: 2px solid #fff;
}
.dot-online { background: #10b981; }
.dot-offline { background: #9ca3af; }
.dot-sick { background: #ef4444; }
.dot-focusing { background: #8b5cf6; }
.dot-in_meeting { background: #3b82f6; }
.dot-out_of_office { background: #f59e0b; }

.sb-name { font-weight: 700; font-size: 14px; color: var(--text); }
.sb-role { font-size: 11px; color: var(--text-light); }

.sidebar ul {
    list-style: none;
    margin-top: 16px;
    padding: 0;
}
.sidebar ul li {
    margin-bottom: 4px;
}
.sidebar ul li a {
    color: var(--text-light);
    text-decoration: none;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border-radius: 10px;
    transition: all .15s;
    font-weight: 500;
}
.sidebar ul li a:hover,
.sidebar ul li a.active {
    background: #f1f5f9;
    color: var(--accent);
}
body.dark-mode .sidebar ul li a:hover,
body.dark-mode .sidebar ul li a.active {
    background: rgba(255,255,255,0.08);
    color: #fff;
}
.sidebar ul li a i {
    width: 16px;
    text-align: center;
}
        /* MAIN */
        .dashboard-container{flex:1;margin-left:260px;padding:24px;overflow:auto;}
        .time-bar{background: var(--card);
    color: var(--text);padding:14px 22px;border-radius:12px;display:flex;align-items:center;gap:18px;box-shadow:0 3px 10px rgba(0,0,0,0.05);margin-bottom:22px;}
        .time-bar i{color:var(--accent);}
        #localTime{font-weight:700;color:var(--accent);letter-spacing:.8px;}
        /* TASK LIST */
        .task-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:12px;}
        .task-item{background: var(--card);
    color: var(--text);border-radius:12px;padding:18px 20px;display:flex;justify-content:space-between;align-items:center;border-left:5px solid #ccd1d9;box-shadow:0 2px 6px rgba(0,0,0,0.04);transition:all .22s ease;}
        .task-item:hover{transform:translateX(4px);box-shadow:0 4px 14px rgba(0,0,0,0.08);}
        .completed-task{opacity:.75;border-left-color:var(--success)!important;}
        .task-scroll-area{max-height:70vh;overflow-y:auto;padding-right:6px;}
        .task-scroll-area::-webkit-scrollbar{width:5px;} .task-scroll-area::-webkit-scrollbar-thumb{background:#ddd;border-radius:5px;}
        .task-scroll-area::-webkit-scrollbar-thumb:hover{background:var(--accent);}
        .section-label{font-weight:700;font-size:11px;letter-spacing:1.2px;text-transform:uppercase;padding:4px 0;margin:18px 0 6px;display:flex;align-items:center;gap:8px;}
        .section-label::after{content:"";flex:1;height:1px;background:currentColor;opacity:.2;}
        .project-header{font-size:1.15rem;font-weight:700;color:var(--accent);margin:28px 0 10px;padding-bottom:7px;border-bottom:2px solid #eef2f5;color: var(--text);}
        /* REMINDER */
        .reminder-icon{font-size:17px;cursor:pointer;padding:6px;border-radius:50%;transition:background .2s;}
        .reminder-icon:hover{background:#f0f0f0;}
        .reminder-popup{display:none;position:absolute;top:36px;right:0;background: var(--card);
    color: var(--text);border:1px solid #ddd;padding:13px;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,0.1);z-index:200;min-width:205px;}
        /* SHARED MODAL */
        .modal-overlay{position:fixed;inset:0;   background: rgba(0,0,0,0.8);display:flex;justify-content:center;align-items:center;z-index:9999;}
        .modal-box{background: var(--card);
    color: var(--text);border-radius:16px;padding:26px;width:90%;max-width:540px;box-shadow:0 12px 40px rgba(0,0,0,0.2);animation:slideUp .27s ease;max-height:90vh;overflow-y:auto;}
        @keyframes slideUp{from{opacity:0;transform:translateY(26px);}to{opacity:1;transform:translateY(0);}}
        .modal-box h3{margin:0 0 6px;}
        .modal-box textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;font-family:inherit;font-size:13px;resize:vertical;}
        .modal-box textarea:focus{outline:none;border-color:var(--accent);}
        .btn-row{display:flex;gap:10px;margin-top:16px;}
        .btn{padding:10px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;transition:.2s;font-family:inherit;}
        .btn-primary{background:var(--accent);color:white;} .btn-primary:hover{background:#162a44;}
        .btn-success{background:var(--success);color:white;}
        .btn-danger{background:var(--danger);color:white;} .btn-danger:hover{background:#c53030;}
        .btn-neutral{background:#eee;color:#555;}
        /* REASON OPTIONS */
        .reason-options{display:flex;flex-direction:column;gap:7px;margin:14px 0;}
        .reason-option{display:flex;align-items:center;gap:10px;padding:10px 13px;border:2px solid #eee;border-radius:10px;cursor:pointer;transition:all .17s;font-size:13px;color:#333;}
        .reason-option:hover,.reason-option.selected{border-color:var(--danger);background:#fff5f5;color:#c53030;font-weight:600;}
        .reason-option input[type="radio"]{accent-color:var(--danger);width:14px;height:14px;flex-shrink:0;}
        .extra-reason{width:100%;padding:10px;border:2px solid #eee;border-radius:10px;font-family:inherit;font-size:13px;resize:vertical;min-height:65px;transition:border-color .2s;margin-top:7px;display:none;}
        .extra-reason:focus{outline:none;border-color:var(--danger);}
        /* COLLAPSIBLE */
        .collapsible-section{margin-top:22px;border:1px solid #fed7d7;border-radius:14px;overflow:hidden;background: var(--card);
    color: var(--text);box-shadow:0 2px 8px rgba(197,48,48,0.05);border-color: var(--border) !important;}
        .collapsible-header{ display:flex;
    align-items:center;
    gap:12px;
    padding:15px 18px;
    cursor:pointer;
    user-select:none;
    transition:background .2s;
    background: var(--card);
    color: var(--text);}
        .ch-title{flex:1;font-weight:700;font-size:.92rem;}
        .ch-count{font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;color:white;}
        .ch-arrow{transition:transform .3s ease;font-size:.95rem;}
        .ch-arrow.open{transform:rotate(180deg);}
        .collapsible-body{display:none;padding:16px;}
        .collapsible-body.open{display:block;}
        /* CARD STYLES */
        .overdue-card{background: var(--card);
    color: var(--text);border-radius:10px;padding:15px;margin-bottom:11px;border-left:5px solid var(--danger);box-shadow:0 1px 4px rgba(0,0,0,0.04);display:flex;justify-content:space-between;align-items:flex-start;gap:12px;}
        .overdue-card:last-child{margin-bottom:0;}
        .overdue-badge{display:inline-block;background:#fff0f0;color:#c53030;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;border:1px solid #fed7d7;margin-top:4px;}
        .reason-ok-badge{display:inline-flex;align-items:center;gap:4px;background:#f0fff4;color:#27ae60;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;border:1px solid #c6f6d5;margin-top:4px;}
        .del-req-card{background: var(--card);
    color: var(--text);border-radius:10px;padding:15px;margin-bottom:11px;border-left:5px solid #f39c12;box-shadow:0 1px 4px rgba(0,0,0,0.04);}
        .del-req-card:last-child{margin-bottom:0;}
        .pending-badge{display:inline-block;background:#fffbeb;color:#d97706;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;border:1px solid #fbd38d;margin-top:4px;}
        .deleted-card{background: var(--card);
    color: var(--text);border-radius:10px;padding:15px;margin-bottom:11px;border-left:5px solid #a0aec0;box-shadow:0 1px 3px rgba(0,0,0,0.03);}
        .deleted-card:last-child{margin-bottom:0;}
        .deleted-badge{display:inline-block;background:#edf2f7;color:#718096;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;border:1px solid #e2e8f0;margin-top:4px;}
        .deleted-empty{text-align:center;padding:22px;color:#bbb;font-size:13px;}
    /* ===================== MOBILE ===================== */
.menu-toggle {
    display: none;
    position: fixed;
    top: 15px;
    left: 15px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 10px;
    font-size: 20px;
    z-index: 2000;
    cursor: pointer;
}

/* Tablet */
@media (max-width: 1024px) {
    .sidebar { width: 250px; }
    .dashboard-container { margin-left: 250px; padding: 18px; }
}

/* Mobile phones */
@media (max-width: 768px) {
    body { flex-direction: column; }
    .menu-toggle { display: block; }

    .sidebar {
        position: fixed;
        left: -100%;
        top: 0;
        height: 100%;
        width: 260px;
        max-width: 85vw;
        box-shadow: 0 0 15px rgba(0,0,0,0.25);
        z-index: 1500;
        transition: left 0.3s ease;
    }
    .sidebar.open { left: 0; }

    .dashboard-container {
        margin-left: 0 !important;
        padding: 70px 12px 20px !important;
    }

    /* Header + Search */
    .dashboard-container > div[style*="display:flex"] {
        flex-direction: column !important;
        gap: 12px !important;
    }
    .time-bar {
        padding: 12px 14px !important;
        font-size: 13px;
        gap: 10px !important;
        flex-wrap: wrap;
    }
    .time-bar span {
        font-size: 13px !important;
    }
    #taskSearch {
        font-size: 14px !important;
        padding: 12px 12px 12px 38px !important;
    }

    /* Task items */
    .task-item {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 12px !important;
        padding: 14px !important;
    }
    .task-item > div:last-child {
        width: 100%;
        display: flex !important;
        flex-wrap: wrap;
        gap: 8px !important;
        justify-content: flex-start;
    }
    .task-item button {
        padding: 8px 12px !important;
        font-size: 12px !important;
    }
    .task-item .reminder-icon {
        margin-left: auto;
    }

    /* Project headers & labels */
    .project-header {
        font-size: 1rem !important;
        margin: 20px 0 8px !important;
    }
    .section-label {
        font-size: 10px !important;
        margin: 14px 0 6px !important;
    }

    /* Collapsible sections */
    .collapsible-header {
        padding: 13px 14px !important;
        gap: 8px !important;
    }
    .ch-title {
        font-size: 0.85rem !important;
    }
    .overdue-card,
    .del-req-card,
    .deleted-card {
        flex-direction: column !important;
        gap: 10px !important;
        padding: 13px !important;
    }
    .overdue-card > div:last-child {
        width: 100%;
        flex-direction: row !important;
        flex-wrap: wrap;
    }
    .overdue-card button,
    .del-req-card button {
        font-size: 11px !important;
        padding: 7px 10px !important;
    }

    /* Modals */
    .modal-box {
        width: 94% !important;
        max-width: 94% !important;
        padding: 18px !important;
        max-height: 88vh;
    }
    .modal-box h3 {
        font-size: 1.05rem !important;
    }
    .btn-row {
        flex-direction: column;
    }
    .btn-row .btn {
        width: 100%;
    }
    .reason-option {
        padding: 11px 12px !important;
        font-size: 13px !important;
    }

    /* Reminder popup */
    .reminder-popup {
        right: auto;
        left: 0;
        min-width: 190px;
    }

    /* Scroll area */
    .task-scroll-area {
        max-height: none;
        overflow: visible;
        padding-right: 0;
    }
}

/* Small phones */
@media (max-width: 480px) {
    .dashboard-container {
        padding: 65px 10px 16px !important;
    }
    .time-bar {
        padding: 10px 12px !important;
        font-size: 12px;
    }
    .task-item {
        padding: 12px !important;
    }
    .task-item > div:first-child > div:first-child {
        font-size: 0.95rem !important;
    }
    .project-header {
        font-size: 0.95rem !important;
    }
    .modal-box {
        padding: 15px !important;
    }
    .ch-title {
        font-size: 0.8rem !important;
    }
}
    </style>
</head>
<body>
<button class="menu-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')">
    <i class="fas fa-bars"></i>
</button>
<aside class="sidebar">
    <div class="top">
        <div class="sb-avatar">
            <?php if ($currentProfilePicture && file_exists($currentProfilePicture)): ?>
                <img src="<?php echo htmlspecialchars($currentProfilePicture); ?>" alt="">
            <?php else: ?>
                <?php echo htmlspecialchars($userInitial); ?>
            <?php endif; ?>
            <div class="dot dot-<?php echo htmlspecialchars($currentStatus); ?>"></div>
        </div>
        <div>
            <div class="sb-name"><?php echo htmlspecialchars($userName); ?></div>
            <div class="sb-role"><?php echo htmlspecialchars($currentRole); ?></div>
        </div>
    </div>
    <ul>
        <li><a href="member_dashboard.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
        <li><a href="member_tasks.php" class="active"><i class="fas fa-tasks"></i><span>My Tasks</span></a></li>
        <li><a href="member_projects.php"><i class="fas fa-briefcase"></i><span>Projects</span></a></li>
        <li><a href="member_messages.php"><i class="fas fa-comments"></i><span>Messages</span></a></li>
        <li><a href="upload_timesheet_progress.php"><i class="fas fa-file-signature"></i><span>Timesheet Submissions</span></a></li>
        <li><a href="member_Group.php"><i class="fas fa-users"></i><span>Groups</span></a></li>
        <li><a href="member_Group_chat.php"><i class="fas fa-envelope"></i><span>Group Messages</span></a></li>
        <li><a href="member_attendance.php"><i class="fas fa-check-circle"></i><span>Attendance</span></a></li>
        <li><a href="leave_application.php"><i class="fa-solid fa-calendar-check"></i><span>Leave Application</span></a></li>
        <li><a href="https://investhoodprojects.com/tidytracker/tidytrack/meeting/member_meetings.php"><i class="fas fa-video"></i><span>Meetings</span></a></li>
        <li><a href="member_help.php"><i class="fas fa-life-ring"></i><span>TSM ASSISTANCE</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        <li style="margin-top:auto;"><a href="profile.php"><i class="fas fa-user-circle"></i><span>Profile Settings</span></a></li>
    </ul>
</aside>

<main class="dashboard-container">
    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;gap:14px;flex-wrap:wrap;">
        <div class="time-bar" style="flex:1;">
            <i class="fas fa-clock"></i><span id="localTime">--:--:--</span>
            <i class="fas fa-calendar-alt"></i>
            <span style="font-size:14px;">Track your work, <?php echo htmlspecialchars($userName); ?></span>
        </div>
        <div style="position:relative;flex:1;max-width:370px;">
            <i class="fas fa-search" style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#bbb;font-size:13px;"></i>
            <input type="text" id="taskSearch" onkeyup="filterSearch()" placeholder="Search tasks or projects..."
                   style="width:100%;padding:11px 13px 11px 38px;border-radius:10px;border:1px solid #ddd;outline:none;font-family:inherit;font-size:13px;">
        </div>
        <button onclick="document.getElementById('addTaskModal').style.display='flex'" class="btn btn-primary">+ Create your own task</button>
    </div>

    <div class="task-scroll-area">

        <!-- MAIN TASK LIST -->
        <ul class="task-list" id="mainTaskList">
        <?php
        if (count($tasksToShow) > 0):
            $curProj = null; $activeOpen = $reviewOpen = $doneOpen = false;
            foreach ($tasksToShow as $row):
                $st = strtolower(trim($row['status'] ?? 'in progress'));
                $isReview    = ($st === 'in review');
                $isDone      = ($st === 'completed');
                $canEdit     = $isReview;
                $borderColor = $isDone ? '#27ae60' : ($isReview ? '#f39c12' : '#3498db');

                if ($curProj !== $row['project_name']) {
                    $curProj = $row['project_name'];
                    echo "<h3 class='project-header'>📁 " . htmlspecialchars($curProj) . "</h3>";
                    $activeOpen = $reviewOpen = $doneOpen = false;
                }
                if (!$isReview && !$isDone && !$activeOpen) { echo "<div class='section-label' style='color:#3498db;'>Active / To Do</div>"; $activeOpen = true; }
                if ($isReview && !$reviewOpen) { echo "<div class='section-label' style='color:#f39c12;'>In Review — Waiting Approval</div>"; $reviewOpen = true; }
                if ($isDone && !$doneOpen) { echo "<div class='section-label' style='color:#27ae60;'>Completed</div>"; $doneOpen = true; }
        ?>
            <li class="task-item <?php echo $isDone ? 'completed-task' : ''; ?>"
                data-search="<?php echo strtolower(htmlspecialchars($row['taskTitle'].' '.$row['project_name'])); ?>"
                style="border-left-color:<?php echo $borderColor; ?>;">
                <div style="flex:1;">
                    <div style="font-size:1rem;font-weight:600;margin-bottom:4px;"><?php echo htmlspecialchars($row['taskTitle']); ?></div>
                    <div style="color:#555;font-size:13px;margin-bottom:6px;"><?php echo nl2br(htmlspecialchars($row['description'])); ?></div>
                    <div style="font-size:11px;color:#aaa;">
                        <i class="fas fa-calendar-alt"></i> Due: <?php echo htmlspecialchars($row['dueDate'] ?? 'N/A'); ?>
                        &nbsp;|&nbsp;<i class="fas fa-user-tag"></i> <?php echo htmlspecialchars($row['role'] ?? 'N/A'); ?>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;position:relative;flex-shrink:0;">
                    <?php if ($isReview || $isDone): ?>
                        <button onclick="openViewSubmission(<?php echo $row['task_id']; ?>)" style="background:#8e44ad;color:white;border:none;padding:8px 13px;border-radius:8px;cursor:pointer;font-weight:600;font-size:12px;">View Submission</button>
                    <?php else: ?>
                        <button onclick="openDonePopup(<?php echo $row['task_id']; ?>)" style="background:#3498db;color:white;border:none;padding:8px 13px;border-radius:8px;cursor:pointer;font-weight:600;font-size:12px;">Submit Work</button>
                    <?php endif; ?>
                    <?php if (!$isDone): ?>
                        <button onclick="openDeleteModal(<?php echo $row['task_id']; ?>,'<?php echo addslashes(htmlspecialchars($row['taskTitle'])); ?>')"
                                style="background:#fff0f0;color:#e94560;border:1px solid #fecaca;padding:8px 10px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;" title="Request deletion">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    <?php endif; ?>
                    <span class="reminder-icon" onclick="toggleReminder(this)">⏰</span>
                    <div class="reminder-popup">
                        <form method="POST">
                            <input type="hidden" name="task_id" value="<?php echo $row['task_id']; ?>">
                            <label style="font-size:11px;display:block;margin-bottom:3px;">Date:</label>
                            <input type="date" name="reminder_date" required style="width:100%;margin-bottom:7px;padding:4px;border:1px solid #ccc;border-radius:4px;">
                            <label style="font-size:11px;display:block;margin-bottom:3px;">Time:</label>
                            <input type="time" name="reminder_time" required style="width:100%;margin-bottom:7px;padding:4px;border:1px solid #ccc;border-radius:4px;">
                            <label style="font-size:11px;display:flex;align-items:center;gap:5px;margin-bottom:9px;"><input type="checkbox" name="notification_type" value="email"> Email</label>
                            <button type="submit" name="set_reminder" style="width:100%;background:#1d3557;color:white;border:none;padding:6px;border-radius:4px;cursor:pointer;font-size:11px;">Set Reminder</button>
                        </form>
                    </div>
                </div>
            </li>

            <!-- Submit Work Modal -->
            <div id="done-popup-<?php echo $row['task_id']; ?>" class="modal-overlay" style="display:none;">
                <div class="modal-box">
                    <h3>Submit Results</h3>
                    <p style="font-size:12px;color:#777;margin-bottom:12px;"><?php echo htmlspecialchars($row['taskTitle']); ?></p>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="confirm_task_id" value="<?php echo $row['task_id']; ?>">
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">Your work:</label>
                        <textarea name="code_snippet" rows="6" required placeholder="Paste your work here..."></textarea>
                        <label style="font-size:12px;font-weight:600;display:block;margin:10px 0 5px;">Attach file (optional):</label>
                        <input type="file" name="screenshot" style="width:100%;">
                        <div class="btn-row">
                            <button type="submit" name="submit_done" class="btn btn-success" style="flex:1;">✅ Confirm</button>
                            <button type="button" onclick="closeDonePopup(<?php echo $row['task_id']; ?>)" class="btn btn-neutral" style="flex:1;">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- View/Edit Submission Modal -->
            <div id="view-submission-<?php echo $row['task_id']; ?>" class="modal-overlay" style="display:none;">
                <div class="modal-box" style="max-width:610px;">
                    <span onclick="document.getElementById('view-submission-<?php echo $row['task_id']; ?>').style.display='none'" style="float:right;font-size:1.5rem;cursor:pointer;color:#bbb;">×</span>
                    <h3>Submission: <?php echo htmlspecialchars($row['taskTitle']); ?></h3>
                    <form method="POST" enctype="multipart/form-data" <?php echo $canEdit ? '' : 'onsubmit="return false;"'; ?>>
                        <input type="hidden" name="resubmit_task_id" value="<?php echo $row['task_id']; ?>">
                        <label style="display:block;margin:12px 0 4px;font-weight:600;font-size:12px;">Your Work:</label>
                        <textarea name="code_snippet" rows="7" <?php echo $canEdit ? 'required' : 'readonly style="background:#f9f9f9;"'; ?>><?php echo htmlspecialchars($row['code_snippet'] ?? ''); ?></textarea>
                        <label style="display:block;margin:12px 0 4px;font-weight:600;font-size:12px;">File/Screenshot:</label>
                        <?php if (!empty($row['file_upload']) && file_exists($row['file_upload'])): ?>
                            <?php if (pathinfo($row['file_upload'], PATHINFO_EXTENSION) === 'pdf'): ?>
                                <a href="<?php echo htmlspecialchars($row['file_upload']); ?>" target="_blank" style="color:#3498db;font-size:13px;">View PDF</a>
                            <?php else: ?>
                                <img src="<?php echo htmlspecialchars($row['file_upload']); ?>" alt="Screenshot" style="max-width:100%;border-radius:8px;">
                            <?php endif; ?>
                        <?php else: ?><p style="color:#bbb;font-size:13px;">No file uploaded.</p><?php endif; ?>
                        <?php if ($canEdit): ?>
                            <label style="display:block;margin:10px 0 4px;font-weight:600;font-size:12px;">Replace File (optional):</label>
                            <input type="file" name="screenshot" style="width:100%;">
                            <div class="btn-row">
                                <button type="submit" name="resubmit_task_id" value="<?php echo $row['task_id']; ?>" class="btn btn-success" style="flex:1;">Update Submission</button>
                                <button type="button" onclick="document.getElementById('view-submission-<?php echo $row['task_id']; ?>').style.display='none'" class="btn btn-neutral">Close</button>
                            </div>
                        <?php else: ?>
                            <div class="btn-row"><button type="button" onclick="document.getElementById('view-submission-<?php echo $row['task_id']; ?>').style.display='none'" class="btn btn-neutral" style="flex:1;">Close</button></div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endforeach;
        else: ?>
            <div style="text-align:center;padding:44px;color:#ccc;">
                <i class="fas fa-tasks" style="font-size:2.6rem;margin-bottom:12px;display:block;"></i>No tasks right now.
            </div>
        <?php endif; ?>
        </ul>


        <!-- ══ OVERDUE ARCHIVE ══ -->
        <?php if (!empty($overdueTasks)): ?>
        <div class="collapsible-section";">
           <div class="collapsible-header" onclick="toggleCollapsible('overdueBody','overdueArrow')">
                <span>🗃️</span>
                <span class="ch-title" style="color:#c53030;">Overdue Task Archive</span>
                <span class="ch-count" style="background:#c53030;"><?php echo count($overdueTasks); ?></span>
                <i class="fas fa-chevron-down ch-arrow" id="overdueArrow" style="color:#c53030;"></i>
            </div>
            <div class="collapsible-body" id="overdueBody">
                <p style="font-size:11px;color:#bbb;margin:0 0 13px;">5+ days overdue. Submit a reason to move to In Review, or request deletion.</p>
                <?php foreach ($overdueTasks as $oTask):
                    $daysOv = (int)((strtotime('today') - strtotime($oTask['dueDate'])) / 86400);
                    $hasReason = !empty($oTask['overdue_reason']);
                ?>
                <div class="overdue-card" id="overdue-card-<?php echo $oTask['task_id']; ?>">
                    <div style="flex:1;">
                        <div style="font-weight:600;font-size:.9rem;margin-bottom:2px;"><?php echo htmlspecialchars($oTask['taskTitle']); ?></div>
                        <div style="font-size:11px;color:#aaa;"><?php echo htmlspecialchars($oTask['project_name']); ?> | <?php echo htmlspecialchars($oTask['role'] ?? 'N/A'); ?></div>
                        <span class="overdue-badge">⚠️ <?php echo $daysOv; ?>d overdue — due <?php echo htmlspecialchars($oTask['dueDate']); ?></span>
                        <?php if ($hasReason): ?><br><span class="reason-ok-badge">✅ Reason submitted — In Review</span><?php endif; ?>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:7px;flex-shrink:0;">
                        <?php if (!$hasReason): ?>
                            <button class="btn btn-danger" style="font-size:11px;padding:7px 12px;"
                                    onclick="openOverdueModal(<?php echo $oTask['task_id']; ?>,'<?php echo addslashes(htmlspecialchars($oTask['taskTitle'])); ?>')">
                                <i class="fas fa-comment-alt"></i> Explain Why
                            </button>
                        <?php endif; ?>
                        <button class="btn" style="font-size:11px;padding:7px 12px;background:#fff0f0;color:#e94560;border:1px solid #fecaca;"
                                onclick="openDeleteModal(<?php echo $oTask['task_id']; ?>,'<?php echo addslashes(htmlspecialchars($oTask['taskTitle'])); ?>')">
                            <i class="fas fa-trash-alt"></i> Request Delete
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>


        <!-- ══ DELETE REQUESTED (pending leader) ══ -->
        <?php if (!empty($deleteReqTasks)): ?>
        <div class="collapsible-section" style="margin-top:18px;border-color:#fbd38d;">
            <div class="collapsible-header" style="background:linear-gradient(135deg,#fffbeb,#fef3c7);" onclick="toggleCollapsible('delReqBody','delReqArrow')">
                <span>⏳</span>
                <span class="ch-title" style="color:#d97706;">Deletion Pending — Awaiting Leader Approval</span>
                <span class="ch-count" style="background:#d97706;"><?php echo count($deleteReqTasks); ?></span>
                <i class="fas fa-chevron-down ch-arrow" id="delReqArrow" style="color:#d97706;"></i>
            </div>
            <div class="collapsible-body" id="delReqBody">
                <p style="font-size:11px;color:#bbb;margin:0 0 13px;">Waiting for your leader to approve or reject.</p>
                <?php foreach ($deleteReqTasks as $dt): ?>
                <div class="del-req-card">
                    <div style="font-weight:600;font-size:.9rem;margin-bottom:2px;"><?php echo htmlspecialchars($dt['taskTitle']); ?></div>
                    <div style="font-size:11px;color:#aaa;margin-bottom:4px;"><?php echo htmlspecialchars($dt['project_name']); ?></div>
                    <span class="pending-badge">🕐 Awaiting leader decision</span>
                    <?php if (!empty($dt['delete_reason'])): ?>
                        <div style="margin-top:7px;font-size:11px;color:#777;background:#fffbeb;padding:7px;border-radius:6px;"><strong>Your reason:</strong> <?php echo htmlspecialchars($dt['delete_reason']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>


        <!-- ══ DELETED TASKS ══ -->
        <div class="collapsible-section" style="margin-top:18px;border-color:#e2e8f0;">
            <div class="collapsible-header" style="background:linear-gradient(135deg,#f7fafc,#edf2f7);" onclick="toggleCollapsible('deletedBody','deletedArrow')">
                <span>🗑️</span>
                <span class="ch-title" style="color:#718096;">Deleted Tasks</span>
                <span class="ch-count" style="background:#718096;"><?php echo count($deletedTasks); ?></span>
                <i class="fas fa-chevron-down ch-arrow" id="deletedArrow" style="color:#718096;"></i>
            </div>
            <div class="collapsible-body" id="deletedBody">
                <?php if (empty($deletedTasks)): ?>
                    <div class="deleted-empty">
                        <i class="fas fa-check-circle" style="font-size:1.8rem;color:#48bb78;display:block;margin-bottom:7px;"></i>
                        No deleted tasks — your list is clean! ✨
                    </div>
                <?php else: ?>
                    <p style="font-size:11px;color:#bbb;margin:0 0 13px;">Approved for deletion by your leader.</p>
                    <?php foreach ($deletedTasks as $dt): ?>
                    <div class="deleted-card">
                        <div style="font-weight:600;font-size:.88rem;color:#999;text-decoration:line-through;margin-bottom:2px;"><?php echo htmlspecialchars($dt['taskTitle']); ?></div>
                        <div style="font-size:11px;color:#bbb;margin-bottom:4px;"><?php echo htmlspecialchars($dt['project_name']); ?></div>
                        <span class="deleted-badge">✅ Deletion approved</span>
                        <?php if (!empty($dt['delete_approved_at'])): ?>
                            <span style="font-size:10px;color:#ccc;margin-left:6px;"><?php echo date('d M Y', strtotime($dt['delete_approved_at'])); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($dt['delete_reason'])): ?>
                            <div style="margin-top:6px;font-size:11px;color:#bbb;background:#f7fafc;padding:6px;border-radius:5px;"><strong>Reason:</strong> <?php echo htmlspecialchars($dt['delete_reason']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /task-scroll-area -->
</main>


<!-- ══ OVERDUE REASON MODAL ══ -->
<div id="overdueModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3 style="color:#c53030;">🚨 Why wasn't this completed?</h3>
        <p id="overdueModalTaskName" style="font-size:12px;color:#999;margin-bottom:3px;"></p>
        <p style="font-size:11px;color:#bbb;margin-bottom:0;">Submitting moves this task to <strong>In Review</strong> for your leader.</p>
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


<!-- ══ DELETE REQUEST MODAL ══ -->
<div id="deleteModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3 style="color:#c53030;">🗑️ Request Task Deletion</h3>
        <p id="deleteModalTaskName" style="font-size:12px;color:#999;margin-bottom:3px;"></p>
        <p style="font-size:11px;color:#bbb;margin-bottom:0;">Your leader must approve before it's permanently removed.</p>
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


<div id="addTaskModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>➕ Add New Task</h3>
        <form method="POST">
            <label style="font-size:12px;font-weight:600;display:block;margin:10px 0 5px;">Title:</label>
            <input type="text" name="taskTitle" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;">
            <label style="font-size:12px;font-weight:600;display:block;margin:10px 0 5px;">Description:</label>
            <textarea name="description" rows="4" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;"></textarea>
            <label style="font-size:12px;font-weight:600;display:block;margin:10px 0 5px;">Project:</label>
            <select name="project_id" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;">
                <option value="" disabled selected>Select project</option>
                <?php foreach ($myProjects as $p): ?>
                    <option value="<?php echo $p['project_id']; ?>"><?php echo htmlspecialchars($p['project_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:12px;font-weight:600;display:block;margin:10px 0 5px;">Due Date:</label>
            <input type="date" name="dueDate" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;">
            <div class="btn-row">
                <button type="submit" name="add_own_task" value="1" class="btn btn-success" style="flex:1;">Add Task</button>
                <button type="button" onclick="document.getElementById('addTaskModal').style.display='none'" class="btn btn-neutral" style="flex:1;">Cancel</button>
            </div>
        </form>
    </div>
</div>


<script src="theme_switcher.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let curOvTaskId = null, curDelTaskId = null;

document.addEventListener('DOMContentLoaded', () => {
    if (Notification.permission !== 'granted' && Notification.permission !== 'denied') Notification.requestPermission();
    updateTime(); setInterval(updateTime, 1000);
    checkReminders(); setInterval(checkReminders, 60000);
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

// Reason selection
function selReason(radio, type) {
    const opts = type==='ov' ? '#overdueReasonOpts' : '#deleteReasonOpts';
    const extra = type==='ov' ? 'ovExtra' : 'delExtra';
    document.querySelectorAll(opts+' .reason-option').forEach(o => o.classList.remove('selected'));
    radio.closest('.reason-option').classList.add('selected');
    document.getElementById(extra).style.display = radio.value==='other' ? 'block' : 'none';
}

// OVERDUE MODAL
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
        const res = await fetch('task_actions.php',{method:'POST',body:fd});
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        closeOvModal();
        await Swal.fire({icon:'success',title:'Explanation Submitted',html:'Task moved to <strong>In Review</strong>. Your leader has been notified.',confirmButtonColor:'#27ae60'});
        // Update card visually
        const card = document.getElementById('overdue-card-'+curOvTaskId);
        if (card) { const btns=card.querySelector('div:last-child'); if(btns) btns.innerHTML='<span class="reason-ok-badge">✅ Reason submitted</span>'; }
    } catch(err) {
        Swal.fire({icon:'error',title:'Error',text:err.message||'Something went wrong.'});
        btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> Submit Explanation';
    }
}

// DELETE MODAL
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
        const res = await fetch('task_actions.php',{method:'POST',body:fd});
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        closeDelModal();
        await Swal.fire({icon:'info',title:'Deletion Requested',html:'Sent to your leader for approval. You\'ll be notified by email.',confirmButtonColor:'#1d3557'});
        location.reload();
    } catch(err) {
        Swal.fire({icon:'error',title:'Error',text:err.message||'Something went wrong.'});
        btn.disabled=false; btn.innerHTML='<i class="fas fa-trash-alt"></i> Request Deletion';
    }
}

// AJAX form submit
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

function checkReminders() {
    fetch('check_reminders.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({user_id:<?php echo $userId; ?>})})
    .then(r=>r.json()).then(data=>{
        if(!data.reminders) return;
        data.reminders.forEach(r=>{
            if(r.notification_type==='desktop'||r.notification_type==='both')
                new Notification('Task Reminder: '+r.taskTitle,{body:`Priority: ${r.priority}\nDue: ${r.dueDate}`});
        });
    }).catch(e=>console.error(e));
}

function filterSearch() {
    const inp = document.getElementById('taskSearch').value.toLowerCase();
    document.querySelectorAll('.task-item').forEach(i => { i.style.display=i.getAttribute('data-search').includes(inp)?'flex':'none'; });
    document.querySelectorAll('.project-header,.section-label').forEach(h => { h.style.display=inp.length>0?'none':''; });
}
</script>
<script>
document.querySelectorAll('.sidebar a').forEach(link => {
    link.addEventListener('click', () => {
        document.querySelector('.sidebar').classList.remove('active');
    });
});
</script>
</body>
</html>