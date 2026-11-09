<?php
// leader_index.php
session_start();
require_once '../dp.php';

// --- Guard ---
$userId    = $_SESSION['user_id'] ?? null;
$userName  = trim($_SESSION['name'] ?? '');
$userRole  = trim($_SESSION['role'] ?? 'Leader');

if (!$userId) {
  error_log("leader_index.php - Access denied: No user_id in session");
  header("Location: ../signup.html");
  exit();
}

// Helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ms_from_date($dateStr){
  if (!$dateStr) return 0;
  $ts = strtotime($dateStr);
  return $ts ? ($ts * 1000) : 0;
}

// Fallbacks
if ($userName === '') { $userName = 'Leader'; }
$userInitial = strtoupper(mb_substr($userName, 0, 1));

// Fetch status, profile picture, and (authoritative) role from DB
$profilePicture = null;
$currentStatus  = 'offline';
$currentRole    = $userRole;

$stmt = $conn->prepare("SELECT COALESCE(NULLIF(status,''),'offline') AS status,
                               NULLIF(profile_picture,'') AS profile_picture,
                               COALESCE(NULLIF(role,''), ?) AS role
                        FROM users WHERE user_id = ?");
if (!$stmt) {
  error_log("leader_index.php - SQL prepare failed: " . $conn->error);
  die("Database error");
}
$stmt->bind_param("si", $userRole, $userId);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
  $currentStatus  = $row['status'];
  $profilePicture = $row['profile_picture'];
  $currentRole    = $row['role'];
}
$stmt->close();

// ✅ Status labels + CSS class mapping
$status_info = [
  'online'        => ['name' => 'Online',        'color' => 'status-ready'],
  'out_of_office' => ['name' => 'Out of Office', 'color' => 'status-out_of_office'],
  'sick'          => ['name' => 'Sick',          'color' => 'status-sick'],
  'focusing'      => ['name' => 'Focusing',      'color' => 'status-focusing'],
  'in_meeting'    => ['name' => 'In Meeting',    'color' => 'status-in_meeting'],
  'offline'       => ['name' => 'Offline',       'color' => 'status-offline']
];
$statusLabel = $status_info[$currentStatus]['name'] ?? ucfirst(str_replace('_', ' ', $currentStatus));
$statusCss   = $status_info[$currentStatus]['color'] ?? 'status-offline';

// If profile_picture is a relative path from /leader, normalize for file_exists
$picPathForExists = $profilePicture;
if ($profilePicture && !is_file($picPathForExists)) {
  $try = realpath(__DIR__ . '/../' . ltrim($profilePicture, '/'));
  if ($try && is_file($try)) $picPathForExists = $try;
}

/* =========================================================
   ✅ AJAX: Notifications (ONLY tasks created by logged-in user)
   GET leader_index.php?ajax=notif
========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'notif') {
  header('Content-Type: application/json; charset=utf-8');

  $doneStatuses = "('approved','rejected','archived')";
  $creatorId = (int)$userId;

  // counts (filtered by created_by)
  $awaitingApprovalCount = 0;
  $overdueCount = 0;
  $dueSoonCount = 0;

  // awaiting approval count
  if ($stmt = $conn->prepare("SELECT COUNT(*) AS c FROM tasks WHERE status='completed' AND created_by=?")) {
    $stmt->bind_param("i", $creatorId);
    $stmt->execute();
    $awaitingApprovalCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
  }

  // overdue count
  if ($stmt = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM tasks
      WHERE dueDate IS NOT NULL
        AND dueDate < CURDATE()
        AND status NOT IN $doneStatuses
        AND created_by=?
  ")) {
    $stmt->bind_param("i", $creatorId);
    $stmt->execute();
    $overdueCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
  }

  // due soon count
  if ($stmt = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM tasks
      WHERE dueDate IS NOT NULL
        AND dueDate >= CURDATE()
        AND dueDate <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        AND status NOT IN $doneStatuses
        AND created_by=?
  ")) {
    $stmt->bind_param("i", $creatorId);
    $stmt->execute();
    $dueSoonCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
  }

  $items = [];
  $latestTs = 0;

  // awaiting approval feed (filtered)
  if ($stmt = $conn->prepare("
      SELECT task_id, taskTitle, assignTo, created_at
      FROM tasks
      WHERE status='completed'
        AND created_by=?
      ORDER BY created_at DESC
      LIMIT 5
  ")) {
    $stmt->bind_param("i", $creatorId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc()) {
      $ts = ms_from_date($x['created_at'] ?? '');
      $latestTs = max($latestTs, $ts);
      $items[] = [
        'kind'      => 'approval',
        'title'     => 'Awaiting approval',
        'message'   => 'Task completed: "'.($x['taskTitle'] ?? '').'" by '.($x['assignTo'] ?? ''),
        'actionUrl' => 'leader_approve-tasks.php',
        'ts'        => $ts
      ];
    }
    $stmt->close();
  }

  // overdue feed (filtered)
  if ($stmt = $conn->prepare("
      SELECT task_id, taskTitle, assignTo, dueDate
      FROM tasks
      WHERE dueDate IS NOT NULL
        AND dueDate < CURDATE()
        AND status NOT IN $doneStatuses
        AND created_by=?
      ORDER BY dueDate DESC
      LIMIT 5
  ")) {
    $stmt->bind_param("i", $creatorId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc()) {
      $ts = ms_from_date(($x['dueDate'] ?? '') . ' 00:00:00');
      $latestTs = max($latestTs, $ts);
      $items[] = [
        'kind'      => 'overdue',
        'title'     => 'Overdue task',
        'message'   => 'Overdue: "'.($x['taskTitle'] ?? '').'" (due '.($x['dueDate'] ?? '').')',
        'actionUrl' => 'leader_dashboard.php',
        'ts'        => $ts
      ];
    }
    $stmt->close();
  }

  // due soon feed (filtered)
  if ($stmt = $conn->prepare("
      SELECT task_id, taskTitle, assignTo, dueDate
      FROM tasks
      WHERE dueDate IS NOT NULL
        AND dueDate >= CURDATE()
        AND dueDate <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        AND status NOT IN $doneStatuses
        AND created_by=?
      ORDER BY dueDate DESC
      LIMIT 5
  ")) {
    $stmt->bind_param("i", $creatorId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc()) {
      $ts = ms_from_date(($x['dueDate'] ?? '') . ' 00:00:00');
      $latestTs = max($latestTs, $ts);
      $items[] = [
        'kind'      => 'duesoon',
        'title'     => 'Due soon',
        'message'   => 'Due soon: "'.($x['taskTitle'] ?? '').'" (due '.($x['dueDate'] ?? '').')',
        'actionUrl' => 'leader_dashboard.php',
        'ts'        => $ts
      ];
    }
    $stmt->close();
  }

  // ✅ NEWEST ON TOP
  usort($items, function($a, $b){
    return (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0);
  });

  echo json_encode([
    'ok'       => true,
    'counts'   => [
      'awaitingApproval' => $awaitingApprovalCount,
      'overdue'          => $overdueCount,
      'dueSoon'          => $dueSoonCount,
      'total'            => $awaitingApprovalCount + $overdueCount + $dueSoonCount
    ],
    'items'    => $items,
    'latestTs' => $latestTs ?: (time() * 1000)
  ]);
  exit;
}

/* =========================================================
   ✅ AJAX: Calendar events (ONLY tasks created by logged-in user)
   FullCalendar calls: leader_index.php?ajax=calendar&start=...&end=...
========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'calendar') {
  header('Content-Type: application/json; charset=utf-8');

  $start = $_GET['start'] ?? date('Y-m-01');
  $end   = $_GET['end']   ?? date('Y-m-t');

  if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $start)) $start = date('Y-m-01');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $end))   $end   = date('Y-m-t');

  $events = [];
  $creatorId = (int)$userId;

  $stmt = $conn->prepare("
    SELECT task_id, taskTitle, assignTo, status, dueDate
    FROM tasks
    WHERE created_by = ?
      AND dueDate IS NOT NULL
      AND dueDate >= ?
      AND dueDate < ?
    ORDER BY dueDate ASC
  ");
  $stmt->bind_param("iss", $creatorId, $start, $end);
  $stmt->execute();
  $rs = $stmt->get_result();

  while ($x = $rs->fetch_assoc()) {
    $events[] = [
      'title' => ($x['taskTitle'] ?? 'Task') . ' • ' . ($x['assignTo'] ?? ''),
      'start' => $x['dueDate'],
      'allDay'=> true,
      'extendedProps' => [
        'task_id'  => $x['task_id'] ?? null,
        'status'   => $x['status'] ?? '',
        'assignTo' => $x['assignTo'] ?? ''
      ]
    ];
  }
  $stmt->close();

  echo json_encode($events);
  exit;
}

// Log for debug
error_log("leader_index.php - Session: userId=$userId, userRole=$userRole, userName=$userName; DB role=$currentRole, status=$currentStatus, pic=" . ($profilePicture ?: 'null'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Leader</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

  <style>
    body { margin:0; font-family:'Poppins', sans-serif; }
    html, body { height: 100%; }
    #main-content { min-height: 100vh; }

    /* ===== Sidebar ===== */
    #leader-sidenav{
      width:320px; background:#fff; border-right:1px solid #e0e0e0;
      display:flex; flex-direction:column; padding:18px; position:fixed;
      height:100vh; overflow-y:auto; z-index:60;
    }
    #leader-sidenav .top{ padding-bottom:18px; border-bottom:1px solid #eee; display:flex; align-items:center; gap:12px; }
    #leader-sidenav .me{
      width:44px; height:44px; border-radius:50%; background:#1d3557; color:#fff;
      display:flex; align-items:center; justify-content:center; font-weight:700; position:relative;
      transition:transform .3s ease; cursor:pointer;
    }
    #leader-sidenav .me:hover{ transform:scale(1.1); }
    #leader-sidenav .me img{ width:100%; height:100%; object-fit:cover; border-radius:50%; }
    #leader-sidenav .status-indicator{
      position:absolute; bottom:0; right:0; width:10px; height:10px; border-radius:50%; border:2px solid #fff;
    }

    .status-ready{ background:#00d4aa; box-shadow:0 0 15px rgba(0,212,170,.4); }
    .status-out_of_office{ background:#ffa726; box-shadow:0 0 15px rgba(255,167,38,.4); }
    .status-sick{ background:#e94560; box-shadow:0 0 15px rgba(233,69,96,.4); }
    .status-focusing{ background:#9c27b0; box-shadow:0 0 15px rgba(156,39,176,.4); }
    .status-in_meeting{ background:#2196f3; box-shadow:0 0 15px rgba(33,150,243,.4); }
    .status-offline{ background:#ccc; box-shadow:0 0 15px rgba(204,204,204,.4); }

    #leader-sidenav .profile-popup{
      display:none; position:absolute; top:60px; left:50%; transform:translateX(-50%);
      background:#fff; padding:1rem; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,.1);
      z-index:1000; text-align:center; width:200px;
    }
    #leader-sidenav .me:hover .profile-popup{ display:block; }
    #leader-sidenav .profile-popup .popup-role{ font-size:14px; font-weight:600; color:#1d3557; }

    #leader-sidenav ul{ list-style:none; margin:20px 0 0; padding:0; display:flex; flex-direction:column; gap:12px; }
    #leader-sidenav a{
      color:#666; text-decoration:none; font-size:15px; display:flex; align-items:center; gap:12px;
      padding:10px; border-radius:10px;
    }
    #leader-sidenav a:hover, #leader-sidenav a.active{ background:#eef5ff; color:#1d3557; }

    @media (max-width:900px){
      #leader-sidenav{ width:90px; padding:18px 10px; }
      #leader-sidenav .top > div:not(.me){ display:none; }
      #leader-sidenav a span{ display:none; }
    }

    /* ===== Topbar ===== */
    #leader-topbar{
      position: fixed;
      left: 320px;
      right: 0;
      top: 0;
      height: 72px;
      z-index: 50;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 18px;
      background: rgba(255,255,255,.85);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid #e8e8e8;
    }
    @media (max-width:900px){ #leader-topbar{ left: 90px; } }

    .top-actions { display:flex; gap:10px; align-items:center; }
    .icon-btn{
      width: 44px; height: 44px;
      border-radius: 12px;
      border: 1px solid #e6e6e6;
      background: #fff;
      display:flex; align-items:center; justify-content:center;
      cursor:pointer;
      position:relative;
    }
    .icon-btn:hover{ background:#f3f6ff; }

    .badge-dot{
      position:absolute; top:-6px; right:-6px;
      background:#ef4444; color:white;
      min-width: 20px; height:20px;
      border-radius: 999px;
      font-size: 12px;
      display:flex; align-items:center; justify-content:center;
      border: 2px solid #fff;
      padding: 0 6px;
    }

    .dropdown-panel{
      position: absolute;
      top: 58px;
      right: 0;
      width: 360px;
      max-height: 420px;
      overflow:auto;
      background:#fff;
      border:1px solid #e7e7e7;
      border-radius: 14px;
      box-shadow: 0 18px 45px rgba(0,0,0,.12);
      display:none;
      z-index: 99;
    }
    .dropdown-panel.open{ display:block; }

    .dropdown-head{
      padding: 12px 14px;
      border-bottom:1px solid #eee;
      font-weight:700;
      display:flex;
      justify-content: space-between;
      align-items:center;
      gap:10px;
    }

    .notif-item{
      padding: 12px 14px;
      border-bottom:1px solid #f1f1f1;
      cursor:pointer;
    }
    .notif-item:hover{ background:#f8fafc; }
    .notif-title{ font-weight:700; font-size:14px; }
    .notif-msg{ font-size:12px; color:#6b7280; margin-top:2px; }

    /* ===== Main Content ===== */
    #main-content{
      margin-left:320px;
      padding: 92px 20px 20px;
    }
    @media (max-width:900px){ #main-content{ margin-left:90px; } }

    /* Notification toast */
    .notification {
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 15px 25px;
      border-radius: 10px;
      color: #fff;
      font-weight: 600;
      box-shadow: 0 10px 30px rgba(0,0,0,0.15);
      transform: translateX(120%);
      transition: transform 0.35s ease-out, opacity 0.35s ease-out;
      opacity: 0;
      z-index: 10000;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .notification.show { transform: translateX(0); opacity: 1; }
    .notification.success { background-color: #00d4aa; }
    .notification.error { background-color: #dc3545; }

    /* =========================
       ✅ DARK MODE (CLEAN + WORKING WITH DROPDOWNS + CALENDAR)
       ========================= */
    body.dark, html.dark {
      background-color: #0f172a !important;
      color: #e2e8f0 !important;
    }

    body.dark #leader-topbar{
      background: rgba(17,24,39,.85) !important;
      border-bottom:1px solid rgba(255,255,255,.08) !important;
      backdrop-filter: blur(12px);
    }

    body.dark #leader-sidenav{
      background: #1e293b !important;
      border-right: 1px solid #334155 !important;
    }

    body.dark #leader-sidenav .profile-popup{
      background:#0f172a !important;
      border:1px solid rgba(255,255,255,.10) !important;
      color:#e2e8f0 !important;
    }
    body.dark #leader-sidenav .profile-popup .popup-role{ color:#e2e8f0 !important; }

    body.dark #leader-sidenav a{ color:#cbd5e1 !important; }
    body.dark #leader-sidenav a.active,
    body.dark #leader-sidenav a:hover{
      background:#334155 !important;
      color:#fff !important;
    }

    body.dark #main-content{
      background: #0f172a !important;
      color: #e2e8f0 !important;
      min-height: 100vh;
    }

    /* Buttons + dropdown panels */
    body.dark .icon-btn{
      background:#0b1220 !important;
      border:1px solid rgba(255,255,255,.10) !important;
      color:#e5e7eb !important;
    }
    body.dark .icon-btn:hover{ background:#111827 !important; }

    body.dark .dropdown-panel{
      background:#0b1220 !important;
      border:1px solid rgba(255,255,255,.10) !important;
      color:#e5e7eb !important;
    }
    body.dark .dropdown-head{
      background:#0f172a !important;
      border-bottom:1px solid rgba(255,255,255,.10) !important;
      color:#e5e7eb !important;
    }
    body.dark .notif-item{ border-bottom:1px solid rgba(255,255,255,.08) !important; }
    body.dark .notif-item:hover{ background: rgba(255,255,255,.05) !important; }
    body.dark .notif-msg{ color:#94a3b8 !important; }

    /* Common white boxes/cards in loaded pages */
    body.dark .bg-white,
    body.dark .card,
    body.dark .container,
    body.dark .page-wrap,
    body.dark .shadow-md,
    body.dark .rounded-xl,
    body.dark .rounded-lg{
      background:#1e293b !important;
      color:#e2e8f0 !important;
      border:1px solid #334155 !important;
      box-shadow:none !important;
    }

    /* Tables (general) */
    body.dark table { background: transparent !important; color:#e2e8f0 !important; }
    body.dark thead th{
      background:#111827 !important;
      color:#94a3b8 !important;
      border-bottom:1px solid #334155 !important;
    }
    body.dark tbody td{
      border-bottom:1px solid #334155 !important;
      color:#e2e8f0 !important;
    }
    body.dark tbody tr:hover{ background: rgba(51, 65, 85, 0.35) !important; }

    /* Inputs/selects */
    body.dark input,
    body.dark select,
    body.dark textarea{
      background:#0b1220 !important;
      color:#e2e8f0 !important;
      border:1px solid #334155 !important;
    }
    body.dark input::placeholder,
    body.dark textarea::placeholder{ color:#94a3b8 !important; }

    /* ✅ FullCalendar dark mode (mini calendar dropdown) */
    body.dark .fc{
      background: transparent !important;
      color:#e2e8f0 !important;
    }
    body.dark .fc .fc-toolbar-title{ color:#e2e8f0 !important; }
    body.dark .fc .fc-button{
      background:#111827 !important;
      border:1px solid rgba(255,255,255,.10) !important;
      color:#e2e8f0 !important;
    }
    body.dark .fc .fc-button:hover{ background:#1f2937 !important; }
    body.dark .fc .fc-scrollgrid,
    body.dark .fc .fc-scrollgrid-section > td,
    body.dark .fc .fc-scrollgrid-section-header > td{
      border-color: rgba(255,255,255,.10) !important;
    }
    body.dark .fc .fc-col-header-cell{
      background:#0f172a !important;
      border-color: rgba(255,255,255,.10) !important;
    }
    body.dark .fc .fc-col-header-cell-cushion{ color:#94a3b8 !important; }
    body.dark .fc .fc-daygrid-day{
      border-color: rgba(255,255,255,.08) !important;
    }
    body.dark .fc .fc-daygrid-day-number{ color:#e2e8f0 !important; }
    body.dark .fc .fc-daygrid-day.fc-day-today{
      background: rgba(59,130,246,.10) !important;
    }

    /* =========================
       ✅ DASHBOARD CARD FIX (DARK MODE)
       Put this at the VERY END of your <style>
       ========================= */
    body.dark .status-card,
    body.dark .kpi-card,
    body.dark .dash-card,
    body.dark .summary-card,
    body.dark .card,
    body.dark .bg-white.shadow,
    body.dark .bg-white.shadow-sm,
    body.dark .bg-white.shadow-md,
    body.dark .bg-white.shadow-lg,
    body.dark .bg-white.rounded,
    body.dark .bg-white.rounded-lg,
    body.dark .bg-white.rounded-xl,
    body.dark .rounded-xl.shadow,
    body.dark .rounded-xl.shadow-md,
    body.dark .rounded-lg.shadow,
    body.dark .shadow,
    body.dark .shadow-sm,
    body.dark .shadow-md,
    body.dark .shadow-lg {
      background: #111827 !important;
      border: 1px solid #334155 !important;
      color: #e2e8f0 !important;
    }

    body.dark .status-card h1,
    body.dark .status-card h2,
    body.dark .status-card h3,
    body.dark .status-card h4,
    body.dark .status-card h5,
    body.dark .status-card h6,
    body.dark .dash-card h1,
    body.dark .dash-card h2,
    body.dark .dash-card h3,
    body.dark .dash-card h4,
    body.dark .dash-card h5,
    body.dark .dash-card h6,
    body.dark .card h1,
    body.dark .card h2,
    body.dark .card h3,
    body.dark .card h4,
    body.dark .card h5,
    body.dark .card h6 {
      color: #f8fafc !important;
    }

    body.dark .text-gray-900,
    body.dark .text-gray-800,
    body.dark .text-gray-700,
    body.dark .text-slate-900,
    body.dark .text-slate-800,
    body.dark .text-slate-700,
    body.dark .text-neutral-900,
    body.dark .text-zinc-900,
    body.dark .text-black {
      color: #f1f5f9 !important;
    }

    body.dark .text-gray-600,
    body.dark .text-gray-500,
    body.dark .text-slate-600,
    body.dark .text-slate-500,
    body.dark .text-neutral-600,
    body.dark .text-neutral-500 {
      color: #94a3b8 !important;
    }

    body.dark .status-card p,
    body.dark .status-card span,
    body.dark .dash-card p,
    body.dark .dash-card span,
    body.dark .card p,
    body.dark .card span {
      color: #e2e8f0 !important;
    }

    body.dark .status-card {
      transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
    }
    body.dark .status-card:hover {
      border-color: rgba(59,130,246,.55) !important;
      box-shadow: 0 10px 25px rgba(0,0,0,.35) !important;
      transform: translateY(-1px);
    }

    body.dark .badge,
    body.dark .pill,
    body.dark .chip {
      background: rgba(148,163,184,.15) !important;
      border: 1px solid rgba(148,163,184,.25) !important;
      color: #e2e8f0 !important;
    }

    body.dark .status-card table,
    body.dark .dash-card table,
    body.dark .card table {
      background: transparent !important;
    }

    /* =========================
       ✅ VIEW PROJECTS CARDS + TASK MODAL DARK FIX
       ========================= */
    body.dark [class*="project-card"],
    body.dark [class*="project-card"] *,
    body.dark [class*="project-box"],
    body.dark [class*="project-box"] *,
    body.dark [class*="project-item"],
    body.dark [class*="project-item"] *,
    body.dark [class*="project-tile"],
    body.dark [class*="project-tile"] *,
    body.dark [class*="project-summary"],
    body.dark [class*="project-summary"] * {
      color: #e2e8f0 !important;
    }

    body.dark .card,
    body.dark .card-header,
    body.dark .card-body,
    body.dark .card-footer,
    body.dark .project-card,
    body.dark .project-box,
    body.dark .project-item {
      background: #111827 !important;
      border: 1px solid #334155 !important;
      color: #e2e8f0 !important;
    }

    body.dark .project-card a,
    body.dark .project-box a,
    body.dark .project-item a {
      color: #93c5fd !important;
    }
    body.dark .project-card a:hover,
    body.dark .project-box a:hover,
    body.dark .project-item a:hover {
      color: #bfdbfe !important;
    }

    body.dark .modal-content {
      background: #0f172a !important;
      border: 1px solid #334155 !important;
      color: #e2e8f0 !important;
    }
    body.dark .modal-header,
    body.dark .modal-footer {
      background: #111827 !important;
      border-color: #334155 !important;
    }
    body.dark .modal-title { color: #f8fafc !important; }
    body.dark .modal-body {
      background: #0f172a !important;
      color: #e2e8f0 !important;
    }
    body.dark .btn-close { filter: invert(1) grayscale(100%); opacity: 0.85; }
    body.dark .modal-backdrop.show { opacity: 0.75 !important; }

    body.dark .modal-content table,
    body.dark .modal-content thead,
    body.dark .modal-content tbody,
    body.dark .modal-content tr,
    body.dark .modal-content th,
    body.dark .modal-content td {
      background: transparent !important;
      color: #e2e8f0 !important;
      border-color: #334155 !important;
    }
    body.dark .modal-content thead th {
      color: #94a3b8 !important;
      border-bottom: 1px solid #334155 !important;
    }
    body.dark .modal-content tbody tr:hover {
      background: rgba(51, 65, 85, 0.35) !important;
    }

    body.dark .modal-content input,
    body.dark .modal-content select,
    body.dark .modal-content textarea {
      background: #0b1220 !important;
      color: #e2e8f0 !important;
      border: 1px solid #334155 !important;
    }

    body.dark .modal-content .text-gray-900,
    body.dark .modal-content .text-gray-800,
    body.dark .modal-content .text-gray-700,
    body.dark .modal-content .text-slate-900,
    body.dark .modal-content .text-slate-800,
    body.dark .modal-content .text-black,
    body.dark [class*="project"] .text-gray-900,
    body.dark [class*="project"] .text-gray-800,
    body.dark [class*="project"] .text-gray-700,
    body.dark [class*="project"] .text-slate-900,
    body.dark [class*="project"] .text-slate-800,
    body.dark [class*="project"] .text-black {
      color: #f1f5f9 !important;
    }

    body.dark .modal-content .text-gray-600,
    body.dark .modal-content .text-gray-500,
    body.dark [class*="project"] .text-gray-600,
    body.dark [class*="project"] .text-gray-500 {
      color: #94a3b8 !important;
    }
  </style>
</head>

<body>

  <!-- Sidebar -->
  <aside id="leader-sidenav" class="sidebar">
    <div class="top">
      <div class="me">
        <?php if ($profilePicture && $picPathForExists && is_file($picPathForExists)): ?>
          <img src="<?php echo h($profilePicture); ?>" alt="Profile">
        <?php else: ?>
          <?php echo h($userInitial); ?>
        <?php endif; ?>
        <div class="status-indicator <?php echo h($statusCss); ?>"></div>

        <div class="profile-popup">
          <div class="popup-role"><?php echo h($currentRole); ?></div>
        </div>
      </div>

      <div>
        <div style="font-weight:700"><?php echo h($userName); ?></div>
        <div style="font-size:12px;color:#777">
          <?php echo h($statusLabel); ?> | <?php echo h($currentRole); ?>
        </div>
      </div>
    </div>

    <button id="darkModeToggle"
      style="margin-top:12px;background:#1d3557;color:white;padding:8px 16px;border:none;border-radius:10px;cursor:pointer;font-weight:600;">
      🌙 Dark Mode
    </button>

    <ul>
      <li><a href="#" data-page="leader_dashboard.php" class="active"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
      
      <li><a href="#" data-page="leader_tasks.php"><i class="fas fa-list-check"></i> <span>My Tasks</span></a></li>
      
      <li><a href="#" data-page="leader_create-task.php"><i class="fas fa-tasks"></i> 
      
      <span>Create Task</span></a></li>
      
      
      <li><a href="leader_create-task2.php"><i class="fas fa-tasks"></i> <span>Create Multiple Tasks</span></a></li>
      <li><a href="#" data-page="leader_edit-task.php"><i class="fas fa-edit"></i> <span>Edit / Reassign Task</span></a></li>
      <li><a href="#" data-page="leader_approve-tasks.php"><i class="fas fa-check-circle"></i> <span>Approve Completed Task</span></a></li>
      
         <li><a href="#" data-page="leader_task_requests.php"><i class="fas fa-check-circle"></i> <span>Overdue Requests</span></a></li>
      
      <li><a href="#" data-page="leader_view-projects.php"><i class="fas fa-folder-open"></i> <span>View Projects</span></a></li>
       <li><a href="upload_timesheet_progress.php" ><i class="fas fa-file-signature"></i> <span>Timesheet</span></a></li>
      <!--<li><a href="#" data-page="leader_manage-team.php"><i class="fa-solid fa-users"></i> <span>Coordinate Team Members</span></a></li>-->
      <li style="margin-top: auto;"><a href="leader_messages.php"><i class="fas fa-comments"></i> <span>Messages</span></a></li>
      <li><a href="https://investhoodprojects.com/tidytracker/tidytrack/meeting/member_meetings.php"><i class="fas fa-video"></i> <span class="nav-text">Meetings</span></a></li>
      <!--<li><a href="groups.php"><i class="fas fa-users"></i> <span>Manage Groups</span></a></li>-->
      <li><a href="leader_group_chat.php"><i class="fas fa-envelope"></i> <span>Group Messages</span></a></li>
      <li><a href="leave_application.php"><i class="fa-solid fa-calendar-check"></i> <span>Leave Application</span></a></li>
      <li style="margin-top:auto;"><a href="profile.php"><i class="fas fa-user-circle"></i> <span>Profile Settings</span></a></li>
      <li><a href="leader_logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
    </ul>
    
  </aside>

  <!-- Topbar -->
  <header id="leader-topbar">
    <div></div>

    <div class="top-actions">

      <!-- Calendar dropdown -->
      <div style="position:relative;">
        <button id="btnCal" type="button" class="icon-btn" title="Calendar">
          <i class="fa-regular fa-calendar"></i>
        </button>

        <div id="calDrop" class="dropdown-panel">
          <div class="dropdown-head">Calendar</div>
          <div style="padding:10px;">
            <div id="miniCalendar" style="min-height:330px;"></div>
          </div>
        </div>
      </div>

      <!-- Notifications dropdown -->
      <div style="position:relative;">
        <button id="btnNotif" type="button" class="icon-btn" title="Notifications">
          <i class="fa-regular fa-bell"></i>
          <span id="notifCount" class="badge-dot" style="display:none;">0</span>
        </button>

        <div id="notifDrop" class="dropdown-panel">
          <div class="dropdown-head">
            <span>Notifications</span>
            <button id="notifRefresh" type="button" class="btn btn-sm btn-light">Refresh</button>
          </div>
          <div id="notifList">
            <div class="notif-item">
              <div class="notif-title">Loading…</div>
              <div class="notif-msg">Please wait</div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </header>

  <div id="main-content"></div>
  <div id="notification" class="notification"></div>

  <script>
   function showNotification(message, type) {
     const notification = document.getElementById('notification');
     let iconHtml = '';
     if (type === 'success') iconHtml = '<i class="fas fa-check-circle"></i>';
     else if (type === 'error') iconHtml = '<i class="fas fa-exclamation-circle"></i>';

     notification.innerHTML = iconHtml + ' ' + message;
     notification.className = `notification ${type}`;
     notification.classList.add('show');
     setTimeout(() => notification.classList.remove('show'), 4000);
   }

   // ✅ Make scripts inside fetched pages RUN
   function setHTMLAndRunScripts(el, html) {
     el.innerHTML = html;
     el.querySelectorAll("script").forEach(oldScript => {
       const s = document.createElement("script");
       [...oldScript.attributes].forEach(a => s.setAttribute(a.name, a.value));
       s.text = oldScript.textContent;
       oldScript.replaceWith(s);
     });
   }

   // ✅ Dark Mode (single source of truth)
   function setDarkMode(enabled){
     document.body.classList.toggle("dark", enabled);
     document.documentElement.classList.toggle("dark", enabled);
     localStorage.setItem("darkMode", enabled ? "enabled" : "disabled");

     const toggle = document.getElementById("darkModeToggle");
     if (toggle) toggle.textContent = enabled ? "☀️ Light Mode" : "🌙 Dark Mode";

     // If mini calendar already rendered, force a repaint
     if (window.__miniCal && window.__miniCal.render) {
       try { window.__miniCal.render(); } catch(e){}
     }
   }

   function applyDarkFromStorage() {
     const enabled = localStorage.getItem("darkMode") === "enabled";
     setDarkMode(enabled);
   }

   // ✅ Notif read tracking (badge clears when opened)
   const SEEN_KEY = "leader_notif_seen_at";
   function getSeenTs(){ return parseInt(localStorage.getItem(SEEN_KEY) || "0", 10); }
   function setSeenTs(ts){ localStorage.setItem(SEEN_KEY, String(ts || 0)); }

   function escapeHtml(str){
     return String(str ?? '')
       .replaceAll('&','&amp;')
       .replaceAll('<','&lt;')
       .replaceAll('>','&gt;')
       .replaceAll('"','&quot;')
       .replaceAll("'","&#039;");
   }

   document.addEventListener('DOMContentLoaded', function () {

     // Dark mode init
     applyDarkFromStorage();
     document.getElementById("darkModeToggle")?.addEventListener("click", () => {
       const enabled = !document.body.classList.contains("dark");
       setDarkMode(enabled);
     });

    //  // Load default page
    //  loadPage('leader_dashboard.php');

    //  // Sidebar navigation
    //  document.querySelectorAll('#leader-sidenav a[data-page]').forEach(link => {
    //   link.addEventListener('click', function (e) {
    //      e.preventDefault();
    //      const page = this.getAttribute('data-page');

    //      document.querySelectorAll('#leader-sidenav a[data-page]').forEach(a => a.classList.remove('active'));
    //      this.classList.add('active');

    //      loadPage(page);
    //   });
    //  });
    
    // ✅ Load initial page from URL (?page=...) else dashboard
const urlParams = new URLSearchParams(window.location.search);
const initialPage = urlParams.get('page') || 'leader_dashboard.php';

loadPage(initialPage);
setActive(initialPage);

// Sidebar navigation (keeps URL in sync)
document.querySelectorAll('#leader-sidenav a[data-page]').forEach(link => {
  link.addEventListener('click', function (e) {
    e.preventDefault();
    const page = this.getAttribute('data-page');

    setActive(page);

    // ✅ update URL so refresh keeps the current page
    const u = new URL(window.location.href);
    u.searchParams.set('page', page);
    window.history.pushState({}, '', u.toString());

    loadPage(page);
  });
});

// ✅ browser back/forward
window.addEventListener('popstate', function () {
  const p = new URLSearchParams(window.location.search).get('page') || 'leader_dashboard.php';
  setActive(p);
  loadPage(p);
});

function setActive(page){
  document.querySelectorAll('#leader-sidenav a[data-page]').forEach(a => {
    a.classList.toggle('active', a.getAttribute('data-page') === page);
  });
}

    

     function loadPage(page) {
       fetch(page, { headers: { 'X-Requested-With': 'fetch' } })
         .then(res => {
           if (!res.ok) throw new Error('Page not found');
           return res.text();
         })
         .then(html => {
           setHTMLAndRunScripts(document.getElementById('main-content'), html);
           // keep dark mode after page injections
           applyDarkFromStorage();
           if (page === 'groups.php' && typeof attachGroupPageListeners === 'function') attachGroupPageListeners();
         })
         .catch(err => {
           document.getElementById('main-content').innerHTML =
             `<div class="alert alert-danger mt-3">Error: ${err.message}</div>`;
         });
     }

     /* =========================
        TOPBAR: Notifications + Calendar (WORKING)
        ========================= */
     const notifDrop  = document.getElementById("notifDrop");
     const btnNotif   = document.getElementById("btnNotif");
     const notifList  = document.getElementById("notifList");
     const notifCount = document.getElementById("notifCount");

     const calDrop = document.getElementById("calDrop");
     const btnCal  = document.getElementById("btnCal");

     let miniCalInstance = null;

     function renderNotifs(payload) {
       notifList.innerHTML = "";

       const items    = payload?.items || [];
       // ✅ ensure newest notifications appear on top (client-side safety)
       items.sort((a,b) => (b.ts || 0) - (a.ts || 0));

       const latestTs = payload?.latestTs || Date.now();
       const seenTs   = getSeenTs();

       const unread = items.filter(n => (n.ts || 0) > seenTs);

       if (unread.length > 0) {
         notifCount.textContent = unread.length;
         notifCount.style.display = "flex";
       } else {
         notifCount.style.display = "none";
       }

       if (!items.length) {
         notifList.innerHTML =
           `<div class="notif-item"><div class="notif-title">No notifications</div><div class="notif-msg">You’re all caught up.</div></div>`;
         return;
       }

       items.forEach(n => {
         const isUnread = (n.ts || 0) > seenTs;
         const div = document.createElement("div");
         div.className = "notif-item";
         div.innerHTML = `
           <div class="notif-title">${escapeHtml(n.title || 'Notification')} ${isUnread ? '<span style="color:#ef4444;font-size:12px;">•</span>' : ''}</div>
           <div class="notif-msg">${escapeHtml(n.message || '')}</div>
         `;
         div.addEventListener("click", () => {
           notifDrop.classList.remove("open");
           loadPage(n.actionUrl || "leader_dashboard.php");
         });
         notifList.appendChild(div);
       });

       // when opened: mark as read
       if (notifDrop.classList.contains("open")) {
         setSeenTs(latestTs);
         notifCount.style.display = "none";
       }
     }

     function loadNotifs() {
       fetch("leader_index.php?ajax=notif")
         .then(r => r.json())
         .then(data => {
           if (!data || !data.ok) throw new Error("bad");
           renderNotifs(data);
         })
         .catch(() => renderNotifs({ items: [], latestTs: Date.now() }));
     }

     document.getElementById("notifRefresh")?.addEventListener("click", (e) => {
       e.preventDefault();
       e.stopPropagation();
       loadNotifs();
     });

     btnNotif?.addEventListener("click", (e) => {
       e.preventDefault();
       e.stopPropagation();
       notifDrop.classList.toggle("open");
       calDrop.classList.remove("open");
       if (notifDrop.classList.contains("open")) loadNotifs();
     });

     btnCal?.addEventListener("click", (e) => {
       e.preventDefault();
       e.stopPropagation();
       calDrop.classList.toggle("open");
       notifDrop.classList.remove("open");

       if (calDrop.classList.contains("open") && window.FullCalendar && !miniCalInstance) {
         miniCalInstance = new FullCalendar.Calendar(document.getElementById("miniCalendar"), {
           initialView: "dayGridMonth",
           height: 330,
           events: "leader_index.php?ajax=calendar",
           eventClick: function(info) {
             info.jsEvent.preventDefault();
             info.jsEvent.stopPropagation();
             showNotification(info.event.title || "Event", "success");
           }
         });
         window.__miniCal = miniCalInstance;
         miniCalInstance.render();
       }
     });

     // prevent closing when clicking inside dropdowns
     notifDrop?.addEventListener("click", (e) => e.stopPropagation());
     calDrop?.addEventListener("click", (e) => e.stopPropagation());

     // close when clicking outside
     document.addEventListener("click", () => {
       notifDrop.classList.remove("open");
       calDrop.classList.remove("open");
     });

     // preload notifications quietly
     loadNotifs();
   });
  </script>
</body>
</html>
