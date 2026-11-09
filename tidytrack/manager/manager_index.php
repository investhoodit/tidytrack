<?php
// manager_index.php — main shell for Manager (mobile-friendly + script re-exec)
session_start();
if (file_exists(__DIR__ . '/../profile.php')) {
    include __DIR__ . '/../profile.php';
}
require_once __DIR__ . '/../dp.php';

/* =========================
   HELPERS
========================= */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ms_from_date($dateStr){
  if (!$dateStr) return 0;
  $ts = strtotime($dateStr);
  return $ts ? ($ts * 1000) : 0;
}

/* =========================
   SAFE FALLBACKS
========================= */
$profilePicture = $_SESSION['profile_picture'] ?? null;
$userInitial    = $_SESSION['user_initial'] ?? null;
$userName       = $_SESSION['name'] ?? null;
$currentRole    = $_SESSION['role'] ?? 'Manager';
$statusLabel    = $_SESSION['status_label'] ?? 'Online';
$statusCss      = $_SESSION['status_css'] ?? 'status-ready';

if (!$userName || trim($userName) === '') $userName = 'Manager';
if (!$userInitial || trim($userInitial) === '') $userInitial = strtoupper(mb_substr($userName, 0, 1));

/* =========================
   AUTH
========================= */
$userId = $_SESSION['user_id'] ?? null;
$role   = strtolower(trim($_SESSION['role'] ?? ''));

if (!$userId || $role !== 'manager') {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

/* =========================
   DISPLAY NAME + PROFILE PIC
========================= */
$displayName = $userName;
try {
    if ($stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'name'")) {
      $stmt->execute();
      $hasName = $stmt->get_result()->num_rows > 0;
      $stmt->close();

      $sql = $hasName
        ? "SELECT COALESCE(NULLIF(name,''), email) AS d, NULLIF(profile_picture,'') AS profile_picture, COALESCE(NULLIF(role,''), ?) AS role FROM users WHERE user_id = ? LIMIT 1"
        : "SELECT email AS d, NULLIF(profile_picture,'') AS profile_picture, COALESCE(NULLIF(role,''), ?) AS role FROM users WHERE user_id = ? LIMIT 1";

      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('si', $currentRole, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?? [];
        if (!empty($row['d'])) $displayName = $row['d'];
        if (!empty($row['profile_picture'])) $profilePicture = $row['profile_picture'];
        if (!empty($row['role'])) $currentRole = $row['role'];
        $stmt->close();
      }
    }
} catch (Exception $e) {
    error_log("manager_index.php - DB fetch error: " . $e->getMessage());
}

// Normalize profile picture path (KEEP YOUR LOGIC)
$picPathForExists = null;
$profileSrc = null;
if ($profilePicture) {
    if (preg_match('#^https?://#i', $profilePicture)) {
        $profileSrc = $profilePicture;
    } else {
        if (is_file($profilePicture) && is_readable($profilePicture)) {
            $picPathForExists = $profilePicture;
            $profileSrc = $profilePicture;
        } else {
            $try = realpath(__DIR__ . '/../' . ltrim($profilePicture, '/\\'));
            if ($try && is_file($try) && is_readable($try)) {
                $picPathForExists = $try;
                $profileSrc = $profilePicture;
            } else {
                $try2 = realpath(__DIR__ . '/' . ltrim($profilePicture, '/\\'));
                if ($try2 && is_file($try2) && is_readable($try2)) {
                    $picPathForExists = $try2;
                    $profileSrc = $profilePicture;
                }
            }
        }
    }
}

/* ============================================================
   DEADLINE NOTIFICATION DETAILS FOR FLOATING DASHBOARD CARDS
============================================================ */
date_default_timezone_set("Africa/Johannesburg");
$today = date("Y-m-d");
$reminderDays = 2;

$project_dueSoon = $conn->query("
    SELECT * FROM projects_x
    WHERE DATEDIFF(end_date, '$today') <= $reminderDays
    AND DATEDIFF(end_date, '$today') > 0
");

$project_overdue = $conn->query("
    SELECT * FROM projects_x
    WHERE end_date < '$today'
");

$sub_dueSoon = $conn->query("
    SELECT * FROM sub_projects
    WHERE DATEDIFF(end_date, '$today') <= $reminderDays
    AND DATEDIFF(end_date, '$today') > 0
");

$sub_overdue = $conn->query("
    SELECT * FROM sub_projects
    WHERE end_date < '$today'
");

/* ============================================================
   ✅ NOTIFICATIONS ARRAY (same as yours, but ADD ts)
============================================================ */
$notifications = [];

// Projects ending soon (next 7 days)
$q = $conn->query("
    SELECT project_name, end_date
    FROM projects_x
    WHERE end_date IS NOT NULL
    AND end_date >= CURDATE()
    AND end_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY end_date ASC
    LIMIT 5
") or die($conn->error);
while($r = $q->fetch_assoc()){
    $ts = ms_from_date(($r['end_date'] ?? '') . ' 00:00:00');
    $notifications[] = [
        "title" => "Project ending soon",
        "message" => $r["project_name"] . " (Ends " . date("M d", strtotime($r["end_date"])) . ")",
        "action" => "activeProjects",
        "icon" => "fa-calendar",
        "color" => "warning",
        "ts" => $ts
    ];
}

// Overdue tasks
$q = $conn->query("
    SELECT taskTitle, dueDate
    FROM tasks
    WHERE dueDate IS NOT NULL
    AND dueDate < CURDATE()
    AND status NOT IN ('completed','Completed')
    ORDER BY dueDate ASC
    LIMIT 5
") or die($conn->error);
while($r = $q->fetch_assoc()){
    $ts = ms_from_date(($r['dueDate'] ?? '') . ' 00:00:00');
    $notifications[] = [
        "title" => "Overdue task",
        "message" => ($r["taskTitle"] ?: "Task") . " (Due " . date("M d", strtotime($r["dueDate"])) . ")",
        "action" => "inProgress",
        "icon" => "fa-exclamation-triangle",
        "color" => "danger",
        "ts" => $ts
    ];
}

// Upcoming milestones
$q = $conn->query("
    SELECT project_name, start_date
    FROM projects_x
    WHERE start_date >= CURDATE()
    AND start_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ORDER BY start_date ASC
    LIMIT 3
") or die($conn->error);
while($r = $q->fetch_assoc()){
    $ts = ms_from_date(($r['start_date'] ?? '') . ' 00:00:00');
    $notifications[] = [
        "title" => "Project starting",
        "message" => $r["project_name"] . " (Starts " . date("M d", strtotime($r["start_date"])) . ")",
        "action" => "activeProjects",
        "icon" => "fa-flag",
        "color" => "info",
        "ts" => $ts
    ];
}

$latestTs = 0;
foreach ($notifications as $n) {
  $latestTs = max($latestTs, (int)($n['ts'] ?? 0));
}

$totalNotifications = count($notifications);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manager - INVESTHOOD_TSM</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

  <style>
    /* ===== LIGHT MODE (DEFAULT) ===== */
    :root { 
      --accent: #1b6b45;
      --accent-light: #2f9e6b;
      --bg: #f4f6f4;
      --card-bg: #fff;
      --text: #111a14;
      --text-secondary: #7b867f;
      --border: #e6ebe7;
      --hover-bg: #eef6f1;
      --shadow: rgba(0, 0, 0, 0.08);
      --topbar-bg: rgba(255, 255, 255, 0.85);
      --dropdown-bg: #fff;
      --input-bg: #f8fafc;
      --status-ready: #00d4aa;
      --status-out_of_office: #ffa726;
      --status-sick: #e94560;
      --status-focusing: #9c27b0;
      --status-in_meeting: #2196f3;
      --status-offline: #ccc;
    }

    /* ===== DARK MODE ===== */
    .dark {
      --accent: #5fd39b;
      --accent-light: #7fd1a8;
      --bg: #0f172a;
      --card-bg: #1e293b;
      --text: #f1f5f9;
      --text-secondary: #cbd5e1;
      --border: #334155;
      --hover-bg: #2d3748;
      --shadow: rgba(0, 0, 0, 0.25);
      --topbar-bg: rgba(30, 41, 59, 0.85);
      --dropdown-bg: #1e293b;
      --input-bg: #334155;
      --status-ready: #00d4aa;
      --status-out_of_office: #ffa726;
      --status-sick: #e94560;
      --status-focusing: #9c27b0;
      --status-in_meeting: #2196f3;
      --status-offline: #64748b;
    }

.btn-secondary {
    color: var(--text); /* uses white from dark mode */
}
    * { 
      box-sizing: border-box;
      transition: background-color 0.3s ease, border-color 0.3s ease;
    }
    
    body { 
      margin: 0; 
      font-family: 'Poppins', sans-serif; 
      background: var(--bg); 
      color: var(--text);
      transition: background-color 0.3s ease, color 0.3s ease;
    }

    /* Improved accessibility for dark mode */
    .dark a {
      color: var(--accent-light);
    }
    
    .dark .icon-btn {
      background: var(--card-bg);
      border-color: var(--border);
      color: var(--text-secondary);
    }
    
    .dark .icon-btn:hover {
      background: var(--hover-bg);
      color: var(--accent-light);
    }

    .topbar {
      position: fixed;
      inset: 0 0 auto 320px;
      height: 72px;
      z-index: 50;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 18px;
      background: var(--topbar-bg);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--border);
      transition: all 0.3s ease;
    }
    
    .top-left .title { 
      font-weight: 800; 
      letter-spacing: -0.02em; 
      font-size: 16px; 
      color: var(--text);
    }
    
    .top-left .sub { 
      font-size: 12px; 
      color: var(--text-secondary); 
      margin-top: 2px; 
    }
    
    .top-actions { 
      display: flex; 
      gap: 10px; 
      align-items: center; 
    }

    .icon-btn {
      width: 44px; 
      height: 44px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      display: flex; 
      align-items: center; 
      justify-content: center;
      cursor: pointer;
      position: relative;
      color: var(--text-secondary);
      transition: all 0.2s ease;
    }
    
    .icon-btn:hover { 
      background: var(--hover-bg); 
      color: var(--accent);
    }

    .badge-dot {
      position: absolute; 
      top: -6px; 
      right: -6px;
      background: #ef4444; 
      color: #fff;
      min-width: 20px; 
      height: 20px;
      border-radius: 999px;
      font-size: 12px;
      display: flex; 
      align-items: center; 
      justify-content: center;
      border: 2px solid var(--card-bg);
      padding: 0 6px;
    }
    
    .dark .badge-dot {
      border-color: var(--card-bg);
    }

    .dropdown {
      position: absolute; 
      top: 58px; 
      right: 0;
      width: 360px; 
      max-height: 420px; 
      overflow: auto;
      background: var(--dropdown-bg); 
      border: 1px solid var(--border);
      border-radius: 14px;
      box-shadow: 0 18px 45px var(--shadow);
      display: none; 
      z-index: 99;
    }
    
    .dropdown.open { 
      display: block; 
    }
    
    .dropdown-head {
      padding: 12px 14px;
      border-bottom: 1px solid var(--border);
      font-weight: 800;
      display: flex; 
      justify-content: space-between; 
      align-items: center;
      color: var(--text);
    }
    
    .notif-item { 
      padding: 12px 14px; 
      border-bottom: 1px solid var(--border); 
      cursor: pointer; 
      transition: background 0.2s; 
    }
    
    .notif-item:hover { 
      background: var(--hover-bg); 
    }
    
    .notif-title { 
      font-weight: 800; 
      font-size: 14px; 
      display: flex; 
      align-items: center; 
      gap: 8px; 
      margin-bottom: 4px; 
      color: var(--text);
    }
    
    .notif-msg { 
      font-size: 12px; 
      color: var(--text-secondary); 
    }
    
    .notif-icon { 
      width: 20px; 
      text-align: center; 
    }
    
    .notif-warning { color: #f59e0b; }
    .notif-danger { color: #ef4444; }
    .notif-info { color: #3b82f6; }

    #manager-sidenav {
      width: 320px; 
      background: var(--card-bg); 
      border-right: 1px solid var(--border);
      display: flex; 
      flex-direction: column; 
      padding: 18px; 
      position: fixed; 
      inset: 0 auto 0 0;
      height: 100vh; 
      overflow-y: auto; 
      z-index: 100; 
      transition: transform 0.3s ease, background-color 0.3s ease, border-color 0.3s ease;
    }
    
    #manager-sidenav .top { 
      padding-bottom: 18px; 
      border-bottom: 1px solid var(--border); 
      display: flex; 
      gap: 12px; 
      align-items: center; 
    }
    
    #manager-sidenav .me { 
      width: 44px; 
      height: 44px; 
      border-radius: 50%; 
      background: var(--accent); 
      color: #fff; 
      display: flex; 
      align-items: center; 
      justify-content: center; 
      font-weight: 700; 
      position: relative;
    }
    
    #manager-sidenav .me img { 
      width: 100%; 
      height: 100%; 
      object-fit: cover; 
      border-radius: 50%; 
    }
    
    .status-indicator { 
      position: absolute; 
      bottom: 0; 
      right: 0; 
      width: 10px; 
      height: 10px; 
      border-radius: 50%; 
      border: 2px solid var(--card-bg);
    }
    
    .status-ready { background: var(--status-ready); }
    .status-out_of_office { background: var(--status-out_of_office); }
    .status-sick { background: var(--status-sick); }
    .status-focusing { background: var(--status-focusing); }
    .status-in_meeting { background: var(--status-in_meeting); }
    .status-offline { background: var(--status-offline); }

    #manager-sidenav ul { 
      list-style: none; 
      margin: 20px 0 0; 
      padding: 0; 
    }
    
    #manager-sidenav li { 
      margin-bottom: 12px; 
    }
    
    #manager-sidenav a {
      color: var(--text-secondary); 
      text-decoration: none; 
      font-size: 15px; 
      display: flex; 
      align-items: center; 
      gap: 12px; 
      padding: 10px;
      border-radius: 8px; 
      transition: background 0.2s, color 0.2s; 
      background: none; 
      border: none; 
      cursor: pointer; 
      width: 100%; 
      text-align: left;
    }
    
    #manager-sidenav a:hover,
    #manager-sidenav a.active {
      background: var(--hover-bg);
      color: var(--accent);
    }

    /* Donezo-style active marker: green bar on the sidebar's left edge */
    #manager-sidenav a { position: relative; }
    #manager-sidenav a.active { font-weight: 600; color: var(--text); }
    #manager-sidenav a.active i { color: var(--accent); }
    #manager-sidenav a.active::before {
      content: "";
      position: absolute;
      left: -18px; top: 8px; bottom: 8px;
      width: 4px;
      border-radius: 0 4px 4px 0;
      background: var(--accent);
    }

    #main-content { 
      margin-left: 320px; 
      margin-top: 72px; 
      padding: 20px; 
      min-height: 100vh; 
      background: var(--bg);
      transition: margin-left 0.3s ease, background-color 0.3s ease;
    }

    .mobile-menu-toggle { 
      display: none; 
      position: fixed; 
      top: 12px; 
      left: 12px; 
      z-index: 110; 
      width: 42px; 
      height: 42px; 
      border: 0; 
      border-radius: 10px; 
      background: var(--accent); 
      color: #fff; 
      font-size: 18px; 
      align-items: center; 
      justify-content: center; 
      cursor: pointer;
    }

    /* Calendar adjustments for dark mode */
    .dark .fc {
      --fc-border-color: var(--border);
      --fc-button-bg-color: var(--card-bg);
      --fc-button-border-color: var(--border);
      --fc-button-text-color: var(--text);
      --fc-page-bg-color: var(--card-bg);
      --fc-neutral-bg-color: var(--card-bg);
      --fc-list-event-hover-bg-color: var(--hover-bg);
    }
    
    .dark .fc-theme-standard .fc-scrollgrid,
    .dark .fc-theme-standard td,
    .dark .fc-theme-standard th {
      border-color: var(--border);
    }
    
    .dark .fc .fc-daygrid-day.fc-day-today {
      background-color: rgba(76, 201, 240, 0.1);
    }
    
    .dark .fc .fc-button {
      background-color: var(--card-bg);
      border-color: var(--border);
      color: var(--text);
    }
    
    .dark .fc .fc-button:hover {
      background-color: var(--hover-bg);
    }

    @media (max-width: 768px) {
      .mobile-menu-toggle { 
        display: flex; 
      }
      
      #manager-sidenav { 
        transform: translateX(-100%); 
        width: 280px; 
      }
      
      #manager-sidenav.active { 
        transform: translateX(0); 
      }
      
      #main-content { 
        margin-left: 0; 
        margin-top: 0; 
        padding-top: 60px;
      }
      
      .topbar { 
        inset: 0 0 auto 0; 
      }
      
      .dropdown { 
        width: 300px; 
        right: -50px; 
      }
    }

    /* Dark mode toggle button specific styles */
    #btnDark {
      background: linear-gradient(135deg, var(--accent) 0%, var(--accent-light) 100%);
      color: white;
    }
    
    .dark #btnDark {
      background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
      color: #1e293b;
    }
    
    #btnDark:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
}
  </style>
</head>

<body>

<button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fas fa-bars"></i></button>

<aside id="manager-sidenav">
  <div class="top">
    <div class="me">
      <?php if (!empty($profileSrc) && $picPathForExists && is_file($picPathForExists)): ?>
        <img src="<?= h($profileSrc) ?>" alt="Profile">
      <?php else: ?>
        <?= h($userInitial) ?>
      <?php endif; ?>
      <span class="status-indicator <?= h($statusCss) ?>"></span>
    </div>
    <div>
      <div style="font-weight:700"><?= h($userName) ?></div>
      <div style="font-size:12px;color:var(--text-secondary)"><?= h($statusLabel) ?> | <?= h($currentRole) ?></div>
    </div>
  </div>

  <ul>
    <li><a href="#" class="active" data-page="manager_dashboards.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
    <li><a href="#" data-page="managerProjects.php"><i class="fas fa-folder-open"></i> Projects &amp; Teams</a></li>
    <li><a href="#" data-page="Project_viewing.php"><i class="fas fa-table-columns"></i> Project Board</a></li>
    <li><a href="#" data-page="leader_create-task.php"><i class="fas fa-tasks"></i> Create Task</a></li>
    <li><a href="manager_daily_reports.php"><i class="fa-solid fa-file-invoice"></i>Daily Reports</a></li>
     <li class="nav-item">
</li>

    <li><a href="leader_create-task2.php"><i class="fas fa-tasks"></i> <span>Create Multiple Tasks</span></a></li>
    <!--<li><a href="#" data-page="leader_edit-task.php"><i class="fas fa-edit"></i> <span>Edit / Reassign Task</span></a></li>-->
    <li><a href="manager_project_performance.php"><i class="fas fa-chart-line"></i> Project Performance</a></li>
    <li><a href="https://investhoodprojects.com/tidytracker/tidytrack/manager/manager_index.phpg/member_meetings.php"><i class="fas fa-video"></i> Meetings</a></li>
    <li><a href="#" data-page="create_timesheet.php"><i class="fas fa-clock"></i> Work Log</a></li>
    
    <li class="nav-item">
    <a href="defect_tracker.php" class="nav-link">
        <i class="fa-solid fa-bug"></i>
        <span>Defects</span>
    </a>
</li>
    
  <!--  <li><a href="manager_Group.php"><i class="fas fa-users"></i> <span>Groups</span></a></li>
    <li><a href="manager_Group_chat.php"><i class="fas fa-envelope"></i> <span>Group Messages</span></a></li>-->
    <li><a href="activity_log.php"><i class="fas fa-user-clock"></i> Users Activity</a></li>
    <li><a href="manager_messages.php"><i class="fas fa-comments"></i> Messages</a></li>
    
      <li class="nav-item">
  <a href="manager_review_submissions.php" class="nav-link">
    <i class="fas fa-file-signature"></i>
    <span>Timesheet Submissions</span>
  </a>
</li>
    
    <li><a href="#" data-page="manager_leave_requests.php"><i class="fas fa-calendar-check"></i> Leave Requests</a></li>
    <li><a href="#" data-page="manager_leave_form.php"><i class="fas fa-clipboard-list"></i> Leave Form</a></li>
    <li style="margin-top:auto"><a href="profile.php"><i class="fas fa-user-circle"></i> Profile Settings</a></li>
    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
  </ul>
</aside>

<!-- TOPBAR -->
<div class="topbar">
  <div class="top-left">
    <div class="title" id="pageTitle">Manager Dashboard</div>
    <div class="sub" id="pageSubtitle">Portfolio overview • Projects • Workload • Calendar</div>
  </div>

  <div class="top-actions">
    <!-- Dark Mode Toggle -->
    <button id="btnDark" type="button" class="icon-btn" title="Toggle Dark Mode">
      <i class="fas fa-moon"></i>
    </button>

    <!-- Calendar dropdown -->
    <div style="position:relative;">
      <button id="btnCal" type="button" class="icon-btn" title="Calendar">
        <i class="fas fa-calendar-alt"></i>
      </button>
      <div id="calDrop" class="dropdown">
        <div class="dropdown-head">Calendar</div>
        <div style="padding:10px;">
          <div id="miniCalendar" style="min-height:330px;"></div>
        </div>
      </div>
    </div>

    <!-- Notifications dropdown -->
    <div style="position:relative;">
      <button id="btnNotif" type="button" class="icon-btn" title="Notifications">
        <i class="fas fa-bell"></i>
        <!-- badge is now controlled by JS (unread only) -->
        <span id="notifCount" class="badge-dot" style="display:none;">0</span>
      </button>

      <div id="notifDrop" class="dropdown">
        <div class="dropdown-head">
          Notifications
          <button id="btnCloseNotif" type="button" class="icon-btn" style="width:34px;height:34px;border-radius:10px;">
            <i class="fas fa-times"></i>
          </button>
        </div>

        <div id="notificationsContent">
          <!-- JS will render -->
          <div class="notif-item">
            <div class="notif-title"><i class="fas fa-bell notif-info notif-icon"></i> Loading…</div>
            <div class="notif-msg">Please wait</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<main id="main-content"></main>

<script>
/* =========================
   DARK MODE TOGGLE
========================= */
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('btnDark');
  const body = document.body;

  // Check for saved theme preference, default to light
  const darkEnabled = localStorage.getItem("manager_dark") === "1";
  if (darkEnabled) {
    body.classList.add("dark");
  }
  updateToggleIcon(darkEnabled);

  toggle.addEventListener("click", () => {
    const enabled = body.classList.toggle("dark");
    localStorage.setItem("manager_dark", enabled ? "1" : "0");
    updateToggleIcon(enabled);
  });

  function updateToggleIcon(enabled) {
    toggle.innerHTML = enabled 
      ? '<i class="fas fa-sun"></i>' 
      : '<i class="fas fa-moon"></i>';
    toggle.title = enabled 
      ? "Switch to Light Mode" 
      : "Switch to Dark Mode";
  }
});

/* =========================
   READ / UNREAD (localStorage)
   ✅ Badge disappears when dropdown opens
========================= */
const NOTIF_SEEN_KEY = "manager_notif_seen_at";
function getSeenTs(){ return parseInt(localStorage.getItem(NOTIF_SEEN_KEY) || "0", 10); }
function setSeenTs(ts){ localStorage.setItem(NOTIF_SEEN_KEY, String(ts || 0)); }

const SERVER_NOTIFS = <?= json_encode($notifications, JSON_UNESCAPED_SLASHES) ?>;
const SERVER_LATEST_TS = <?= (int)($latestTs ?: (time()*1000)) ?>;

function escHtml(str){
  return String(str ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

function computeUnread(list){
  const seen = getSeenTs();
  return (list || []).filter(n => (n.ts || 0) > seen);
}

function renderNotifications(list){
  const notifCount = document.getElementById('notifCount');
  const notifContent = document.getElementById('notificationsContent');

  const unread = computeUnread(list);
  if (unread.length > 0) {
    notifCount.textContent = unread.length;
    notifCount.style.display = 'flex';
  } else {
    notifCount.style.display = 'none';
  }

  if (!list || list.length === 0) {
    notifContent.innerHTML = `
      <div class="notif-item">
        <div class="notif-title">No notifications</div>
        <div class="notif-msg">You're all caught up.</div>
      </div>`;
    return;
  }

  let html = "";
  const seen = getSeenTs();

  list.forEach(n => {
    const color = n.color || 'info';
    const icon  = n.icon  || 'fa-bell';
    const isUnread = (n.ts || 0) > seen;

    html += `
      <div class="notif-item" data-jump="${escHtml(n.action || '')}">
        <div class="notif-title">
          <i class="fas ${escHtml(icon)} notif-${escHtml(color)} notif-icon"></i>
          ${escHtml(n.title || 'Notification')}
          ${isUnread ? '<span style="color:#ef4444;font-size:12px;">•</span>' : ''}
        </div>
        <div class="notif-msg">${escHtml(n.message || '')}</div>
      </div>
    `;
  });

  notifContent.innerHTML = html;
}

// Initial render from PHP (so no extra pages needed)
renderNotifications(SERVER_NOTIFS);

// Mark as read when opened
function markAllRead(){
  setSeenTs(SERVER_LATEST_TS);
  // update UI immediately
  renderNotifications(SERVER_NOTIFS);
}

/* =========================
   YOUR EXISTING JS (kept)
========================= */
const sidebar = document.getElementById('manager-sidenav');
const mobileBtn = document.getElementById('mobileMenuToggle');
mobileBtn.addEventListener('click', () => sidebar.classList.toggle('active'));

/* CALENDAR FUNCTIONALITY (unchanged) */
let calendar = null;
let calendarInitialized = false;

function initializeMiniCalendar() {
  if (calendarInitialized) return;

  const calendarEl = document.getElementById('miniCalendar');
  if (!calendarEl) return;

  if (calendar) calendar.destroy();

  calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    headerToolbar: false,
    height: 300,
    contentHeight: 280,
    dayMaxEvents: true,
    events: [],
    eventDisplay: 'block',
    dayCellContent: function(info) {
      return { html: `<div style="font-size:11px;font-weight:600;padding:2px 4px;text-align:center;color:var(--text)">${info.dayNumberText}</div>` };
    },
    dayHeaderContent: function(info) {
      return { html: `<div style="font-size:10px;font-weight:700;text-align:center;color:var(--text)">${info.text}</div>` };
    },
    themeSystem: 'standard',
    dayMaxEventRows: 2
  });

  calendar.render();
  calendarInitialized = true;
}

/* TOOLBAR DROPDOWNS */
const notifDrop = document.getElementById("notifDrop");
const calDrop = document.getElementById("calDrop");

document.getElementById("btnNotif").addEventListener("click", (e) => {
  e.preventDefault();
  e.stopPropagation();
  notifDrop.classList.toggle("open");
  calDrop.classList.remove("open");

  // ✅ mark as read when opened
  if (notifDrop.classList.contains("open")) {
    markAllRead();
  }
});

document.getElementById("btnCal").addEventListener("click", (e) => {
  e.preventDefault();
  e.stopPropagation();
  calDrop.classList.toggle("open");
  notifDrop.classList.remove("open");

  setTimeout(() => {
    if (!calendarInitialized && calDrop.classList.contains("open")) {
      initializeMiniCalendar();
    }
  }, 100);
});

document.getElementById("btnCloseNotif").addEventListener("click", (e) => {
  e.preventDefault();
  e.stopPropagation();
  notifDrop.classList.remove("open");
});

document.addEventListener("click", (e) => {
  if (!notifDrop.contains(e.target) && !document.getElementById("btnNotif").contains(e.target)) {
    notifDrop.classList.remove("open");
  }
  if (!calDrop.contains(e.target) && !document.getElementById("btnCal").contains(e.target)) {
    calDrop.classList.remove("open");
  }
});

// keep dropdowns open when clicking inside
notifDrop.addEventListener("click", (e) => e.stopPropagation());
calDrop.addEventListener("click", (e) => e.stopPropagation());

/* AJAX PAGE LOADING (unchanged) */
function executeScripts(container) {
  const scripts = container.querySelectorAll('script');
  scripts.forEach(old => {
    const s = document.createElement('script');
    if (old.src) s.src = old.src;
    else s.textContent = old.textContent;
    old.replaceWith(s);
  });
}

const main = document.getElementById('main-content');

async function loadPage(page) {
  try {
    const res = await fetch(page, { headers:{'X-Requested-With':'fetch'} });
    if (!res.ok) throw new Error('Page not found');
    const html = await res.text();
    main.innerHTML = html;
    executeScripts(main);

    const titleMatch = html.match(/<title[^>]*>([^<]+)<\/title>/i);
    const h1Match = html.match(/<h1[^>]*>([^<]+)<\/h1>/i);

    if (titleMatch) document.getElementById('pageTitle').textContent = titleMatch[1].replace('Manager - ', '');
    else if (h1Match) document.getElementById('pageTitle').textContent = h1Match[1];

    attachPageLinks(main);
    sidebar.classList.remove('active');

  } catch (err) {
    main.innerHTML = `<div style="color:#b91c1c;padding:1em;">Error: ${err.message}</div>`;
  }
}

function attachPageLinks(scope=document) {
  scope.querySelectorAll('[data-page]').forEach(el=>{
    if(el.dataset.pageBound==='true') return;
    el.dataset.pageBound='true';

    el.addEventListener('click',e=>{
      e.preventDefault();
      const page = el.getAttribute('data-page');
      if(!page) return;

      document.querySelectorAll('#manager-sidenav a')
        .forEach(a=>a.classList.toggle('active', a.getAttribute('data-page')===page));

      loadPage(page);
    });
  });
}

// Notification item click handler (unchanged)
document.addEventListener('click', (e) => {
  const notifItem = e.target.closest('.notif-item');
  if (notifItem) {
    const action = notifItem.getAttribute('data-jump');
    if (action) {
      notifDrop.classList.remove('open');

      const currentPage = document.querySelector('#manager-sidenav a.active')?.getAttribute('data-page');
      if (currentPage !== 'manager_dashboards.php') {
        document.querySelector('[data-page="manager_dashboards.php"]')?.click();
      }

      setTimeout(() => {
        if (typeof window.focusSection === 'function') {
          window.focusSection(action);
        }
      }, 500);
    }
  }
});

// Initialize
document.addEventListener('DOMContentLoaded',()=>{
  attachPageLinks();
  loadPage('manager_dashboards.php');
});

// Close sidebar on mobile outside click
document.addEventListener('click', (e) => {
  if (window.innerWidth <= 768) {
    if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target) && sidebar.classList.contains('active')) {
      sidebar.classList.remove('active');
    }
  }
});

// If dashboard posts new notifications, we keep read/unread working
window.addEventListener('message', (event) => {
  if (event.data.type === 'updateNotifications') {
    const notifications = event.data.notifications || [];
    // Ensure ts exists (if not provided by child page, fallback to now)
    const now = Date.now();
    notifications.forEach(n => { if (!n.ts) n.ts = now; });

    // Update render
    renderNotifications(notifications);

    // Update latest seen baseline so "markAllRead" uses latest
    const maxTs = notifications.reduce((m,n)=> Math.max(m, n.ts||0), 0);
    if (maxTs > 0) {
      // store maxTs in a variable-like way
      // simplest: set a global for markAllRead
      window.__managerLatestTs = maxTs;
    }
  }
});

// Override markAllRead to prefer latest from live updates
const _oldMarkAllRead = markAllRead;
markAllRead = function(){
  const lt = window.__managerLatestTs || SERVER_LATEST_TS;
  setSeenTs(lt);
  // re-render whichever list is currently shown
  const content = document.getElementById('notificationsContent');
  // if content was updated by message event, we don't have the array here,
  // but renderNotifications will have set badge already; easiest is just hide badge:
  document.getElementById('notifCount').style.display = 'none';
};
</script>

</body>
</html>