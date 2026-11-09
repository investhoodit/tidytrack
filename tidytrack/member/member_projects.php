<?php
session_start();
include '../dp.php';
include '../track.php';

$userId      = $_SESSION['user_id'] ?? null;
$userName    = $_SESSION['name']    ?? 'Member';
$userInitial = strtoupper($userName[0]);

if (!$userId) {
    header("Location: ../login.php");
    exit;
}

$stmt = $conn->prepare("SELECT name, role, department, profile_picture, status FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$cu = $stmt->get_result()->fetch_assoc();
$stmt->close();

$profilePicture = $cu['profile_picture'] ?? null;
$currentStatus  = $cu['status'] ?? 'offline';
$currentRole    = strtolower(trim($cu['role'] ?? 'member'));
$currentDept    = strtolower(trim($cu['department'] ?? ''));

log_activity($userId, "Viewed My Projects");

// ── IMPROVED ROLE CLASSIFIER (Fixed & More Reliable) ──
$isDev = false;
$isBA  = false;
$isMarketing = false;

$devKeywords = ['developer', 'frontend', 'backend', 'fullstack'];
$baKeywords  = ['ba', 'business analyst', 'ba team'];

foreach ($devKeywords as $k) {
    if (str_contains($currentRole, $k) || str_contains($currentDept, $k)) {
        $isDev = true;
        break;
    }
}

foreach ($baKeywords as $k) {
    if (str_contains($currentRole, $k) || str_contains($currentDept, $k)) {
        $isBA = true;
        break;
    }
}

if (str_contains($currentRole, 'marketing') || str_contains($currentDept, 'marketing')) {
    $isMarketing = true;
}

// Fallback - if nothing matches, treat as Developer
if (!$isDev && !$isBA && !$isMarketing) {
    $isDev = true;
}

$status_info = [
    'online'        => 'Online',
    'out_of_office' => 'Out of Office',
    'sick'          => 'Sick',
    'focusing'      => 'Focusing',
    'in_meeting'    => 'In Meeting',
    'offline'       => 'Offline',
];

// ═══════════════════════════════════════════════
// DATA FETCHING — DEVELOPER VIEW
// ═══════════════════════════════════════════════
$devProjects = [];
if ($isDev) {
    $sql = "
        SELECT
            p.project_id,
            p.project_name,
            p.deliverable,
            p.start_date,
            p.end_date,
            SUM(CASE WHEN LOWER(t.status) IN ('completed','approved') THEN 1 ELSE 0 END) AS completed_tasks,
            COUNT(t.task_id) AS total_tasks,
            SUM(CASE WHEN LOWER(t.status) IN ('in progress','active') THEN 1 ELSE 0 END) AS active_tasks,
            SUM(CASE WHEN LOWER(t.status) = 'in review' THEN 1 ELSE 0 END) AS review_tasks
        FROM projects_x p
        JOIN tasks t ON t.project_id = p.project_id
        WHERE t.assignTo = ?
        GROUP BY p.project_id
        ORDER BY p.end_date DESC
    ";
    $s = $conn->prepare($sql);
    $s->bind_param("s", $userName);
    $s->execute();
    $devProjects = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();

    // Defects per project from BA
    $defectMap = [];
    if (!empty($devProjects)) {
        $dr = $conn->query("
            SELECT project_name,
                   COUNT(*) AS total,
                   SUM(CASE WHEN LOWER(status)='open' THEN 1 ELSE 0 END) AS open_d,
                   SUM(CASE WHEN LOWER(status)='resolved' THEN 1 ELSE 0 END) AS resolved_d,
                   SUM(CASE WHEN LOWER(status)='in progress' THEN 1 ELSE 0 END) AS progress_d
            FROM project_defects
            WHERE assigned = '{$conn->real_escape_string($userName)}'
            GROUP BY project_name
        ");
        if ($dr) {
            while ($row = $dr->fetch_assoc()) {
                $defectMap[$row['project_name']] = $row;
            }
        }
    }
}

// ═══════════════════════════════════════════════
// DATA — BA VIEW
// ═══════════════════════════════════════════════
$baProjects = [];
if ($isBA) {
    $sql = "
        SELECT
            p.project_id,
            p.project_name,
            p.deliverable,
            p.start_date,
            p.end_date,
            SUM(CASE WHEN LOWER(t.status) IN ('completed','approved') THEN 1 ELSE 0 END) AS completed_tasks,
            COUNT(t.task_id) AS total_tasks,
            COUNT(DISTINCT t.assignTo) AS dev_count
        FROM projects_x p
        JOIN tasks t ON t.project_id = p.project_id
        GROUP BY p.project_id
        ORDER BY p.end_date DESC
    ";
    $baProjects = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

    // Defects raised by this BA
    $baDefectMap = [];
    $dr = $conn->prepare("
        SELECT project_name,
               COUNT(*) AS total,
               SUM(CASE WHEN LOWER(status)='open' THEN 1 ELSE 0 END) AS open_d,
               SUM(CASE WHEN LOWER(status)='resolved' THEN 1 ELSE 0 END) AS resolved_d,
               SUM(CASE WHEN LOWER(status)='in progress' THEN 1 ELSE 0 END) AS progress_d,
               SUM(CASE WHEN LOWER(priority)='critical' OR LOWER(priority)='high' THEN 1 ELSE 0 END) AS critical_d
        FROM project_defects
        WHERE created_by = ?
        GROUP BY project_name
    ");
    $dr->bind_param("i", $userId);
    $dr->execute();
    $dr2 = $dr->get_result();
    while ($row = $dr2->fetch_assoc()) {
        $baDefectMap[$row['project_name']] = $row;
    }
    $dr->close();
}

// ═══════════════════════════════════════════════
// DATA — MARKETING VIEW
// ═══════════════════════════════════════════════
$mktProjects = [];
if ($isMarketing) {
    $sql = "
        SELECT
            p.project_id,
            p.project_name,
            p.deliverable,
            p.start_date,
            p.end_date,
            COUNT(DISTINCT t.assignTo) AS dev_count,
            COUNT(t.task_id) AS total_tasks,
            SUM(CASE WHEN LOWER(t.status) IN ('completed','approved') THEN 1 ELSE 0 END) AS completed_tasks
        FROM projects_x p
        LEFT JOIN tasks t ON t.project_id = p.project_id
        GROUP BY p.project_id
        ORDER BY p.end_date DESC
    ";
    $mktProjects = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

    // Developers per project
    $devListMap = [];
    $dlr = $conn->query("
        SELECT p.project_name, GROUP_CONCAT(DISTINCT t.assignTo ORDER BY t.assignTo SEPARATOR ', ') AS devs
        FROM projects_x p
        JOIN tasks t ON t.project_id = p.project_id
        GROUP BY p.project_id
    ");
    if ($dlr) {
        while ($row = $dlr->fetch_assoc()) {
            $devListMap[$row['project_name']] = $row['devs'];
        }
    }
}

// Overall stats for dev header KPIs
$totalDevProjects  = count($devProjects);
$totalDevTasks     = array_sum(array_column($devProjects, 'total_tasks'));
$totalDevCompleted = array_sum(array_column($devProjects, 'completed_tasks'));
$totalDefectsAssigned = 0;
foreach ($defectMap ?? [] as $d) { 
    $totalDefectsAssigned += $d['total']; 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Projects · TidyTrack</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="theme.css">

<style>
:root {
    --accent:#1d3557;
    --bg:#f0f4f9;
    --card:#ffffff;
    --border:#e2e8f0;
    --text:#0f172a;
    --sub:#64748b;
    --blue:#2563eb;
    --green:#059669;
    --amber:#d97706;
    --rose:#dc2626;
    --violet:#7c3aed;
    --cyan:#0891b2;
    --r:16px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;}

/* ── SIDEBAR ── */
.sidebar{width:260px;background:#fff;border-right:1px solid var(--border);display:flex;flex-direction:column;padding:18px;position:fixed;height:100vh;overflow-y:auto;z-index:100;}
.sidebar .top{padding-bottom:16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
.sb-avatar{width:40px;height:40px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;position:relative;flex-shrink:0;overflow:hidden;}
.sb-avatar img{width:100%;height:100%;object-fit:cover;}
.sb-avatar .dot{position:absolute;bottom:0;right:0;width:9px;height:9px;border-radius:50%;border:2px solid #fff;}
.dot-online{background:#10b981;} .dot-offline{background:#9ca3af;} .dot-sick{background:#ef4444;} .dot-focusing{background:#8b5cf6;} .dot-in_meeting{background:#3b82f6;} .dot-out_of_office{background:#f59e0b;}
.sb-name{font-weight:700;font-size:14px;} .sb-role{font-size:11px;color:var(--sub);}
.sidebar ul{list-style:none;margin-top:16px;}
.sidebar ul li{margin-bottom:4px;}
.sidebar ul li a{color:var(--sub);text-decoration:none;font-size:13px;display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:10px;transition:all .15s;font-weight:500;}
.sidebar ul li a:hover,.sidebar ul li a.active{background:#f1f5f9;color:var(--accent);}
.sidebar ul li a i{width:16px;text-align:center;}

/* ── MAIN ── */
.main{flex:1;margin-left:260px;padding:28px 28px 48px;}

/* ── TOPBAR ── */
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px;}
.page-title{font-size:1.5rem;font-weight:700;letter-spacing:-.3px;}
.page-title span{color:var(--blue);}
.topbar-right{display:flex;align-items:center;gap:10px;}
.time-chip{padding:6px 14px;background:#fff;border:1px solid var(--border);border-radius:30px;font-size:12px;color:var(--sub);font-family:'DM Mono',monospace;}

/* ── KPI STRIP ── */
.kpi-strip{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:28px;}
.kpi{background:#fff;border:1px solid var(--border);border-radius:var(--r);padding:18px 20px;position:relative;overflow:hidden;}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r) var(--r) 0 0;}
.kpi.k-blue::before{background:var(--blue);}
.kpi.k-green::before{background:var(--green);}
.kpi.k-amber::before{background:var(--amber);}
.kpi.k-rose::before{background:var(--rose);}
.kpi.k-violet::before{background:var(--violet);}
.kpi-label{font-size:11px;color:var(--sub);font-weight:600;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;}
.kpi-value{font-size:2rem;font-weight:700;line-height:1;}
.kpi-sub{font-size:11px;color:var(--sub);margin-top:4px;}

/* ── SECTION LABEL ── */
.sh{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);display:flex;align-items:center;gap:8px;margin-bottom:16px;}
.sh::after{content:'';flex:1;height:1px;background:var(--border);}

/* ── PROJECT GRID ── */
.proj-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:18px;margin-bottom:32px;}

.proj-card{background:#fff;border:1px solid var(--border);border-radius:20px;padding:22px;transition:all .22s;cursor:pointer;position:relative;overflow:hidden;}
.proj-card::after{content:'';position:absolute;inset:0;border-radius:20px;border:2px solid transparent;transition:border-color .22s;pointer-events:none;}
.proj-card:hover{transform:translateY(-4px);box-shadow:0 12px 40px rgba(0,0,0,.08);}
.proj-card:hover::after{border-color:var(--blue);}

.pc-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:14px;}
.pc-title{font-size:15px;font-weight:700;line-height:1.3;}
.pc-status{font-size:10px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;flex-shrink:0;}
.st-active{background:#dcfce7;color:#166534;}
.st-ended{background:#fee2e2;color:#991b1b;}
.st-soon{background:#fef3c7;color:#92400e;}

.pc-deliverable{font-size:12px;color:var(--sub);margin-bottom:16px;line-height:1.5;}

/* Progress */
.prog-wrap{margin-bottom:14px;}
.prog-top{display:flex;justify-content:space-between;font-size:11px;font-weight:600;color:var(--sub);margin-bottom:5px;}
.prog-bar{height:7px;background:#f1f5f9;border-radius:20px;overflow:hidden;}
.prog-fill{height:100%;border-radius:20px;transition:width .7s ease;}

/* Health ring */
.pc-footer{display:flex;align-items:center;gap:14px;padding-top:14px;border-top:1px solid var(--border);flex-wrap:wrap;}
.health-ring-wrap{position:relative;width:48px;height:48px;flex-shrink:0;}
.health-ring-wrap canvas{position:absolute;top:0;left:0;}
.health-label{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;}

.pc-meta{display:flex;flex-direction:column;gap:3px;flex:1;}
.pc-meta-row{font-size:11px;color:var(--sub);display:flex;align-items:center;gap:5px;}
.pc-meta-row strong{color:var(--text);}

/* Defect pills */
.defect-pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;}
.def-pill{font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;}
.dp-open{background:#fee2e2;color:var(--rose);}
.dp-progress{background:#fef3c7;color:var(--amber);}
.dp-resolved{background:#dcfce7;color:var(--green);}
.dp-total{background:#eff6ff;color:var(--blue);}

/* ── CHART SECTION – COMPACT SIZE ── */
.chart-section{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:32px;}
.chart-card{background:#fff;border:1px solid var(--border);border-radius:20px;padding:16px 20px;}
.chart-card h3{font-size:14px;font-weight:700;margin-bottom:12px;color:var(--text);}
.chart-card canvas{max-height:140px;width:100%;}  /* compact graph height */
@media(max-width:900px){.chart-section{grid-template-columns:1fr;}}

/* ── MARKETING POPUP ── */
.mkt-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
.mkt-modal{background:#fff;border-radius:24px;width:90%;max-width:580px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.25);animation:popUp .4s cubic-bezier(.34,1.56,.64,1) both;}
.mkt-header{background:linear-gradient(135deg,#1d3557,#2563eb);padding:32px;text-align:center;color:#fff;}
.mkt-header h2{font-size:1.6rem;font-weight:700;margin-bottom:8px;}
.mkt-header p{font-size:14px;opacity:.85;}
.mkt-body{padding:28px 32px;}
.mkt-body p{font-size:14px;color:var(--sub);line-height:1.7;margin-bottom:20px;}
.mkt-tips{display:flex;flex-direction:column;gap:10px;margin-bottom:24px;}
.mkt-tip{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;background:#f8fafc;border-radius:12px;border-left:3px solid var(--blue);font-size:13px;}
.mkt-tip i{color:var(--blue);margin-top:1px;flex-shrink:0;}
.mkt-cta{width:100%;padding:14px;border-radius:12px;border:none;background:linear-gradient(135deg,#1d3557,#2563eb);color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;}
.mkt-cta:hover{opacity:.9;transform:translateY(-1px);}

/* ── BA DEFECT TABLE ── */
.defect-table-wrap{background:#fff;border:1px solid var(--border);border-radius:20px;overflow:hidden;margin-bottom:28px;}
.dt-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.dt-head h3{font-size:14px;font-weight:700;}
table.dt{width:100%;border-collapse:collapse;}
table.dt thead th{background:#f8fafc;padding:10px 14px;font-size:11px;font-weight:700;color:var(--sub);text-align:left;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);}
table.dt tbody td{padding:11px 14px;font-size:13px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
table.dt tbody tr:last-child td{border-bottom:none;}
table.dt tbody tr:hover td{background:#f8fafc;}

/* ── MODAL (project detail) ── */
.detail-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:8888;display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);}
.detail-modal-bg.open{display:flex;}
.detail-modal{background:#fff;border-radius:24px;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.2);}
.dm-head{padding:22px 26px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:2;}
.dm-head h2{font-size:1.1rem;font-weight:700;}
.dm-close{width:34px;height:34px;border-radius:9px;border:1px solid var(--border);background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--sub);font-size:14px;}
.dm-close:hover{background:var(--rose);color:#fff;border-color:var(--rose);}
.dm-body{padding:22px 26px;}
.dm-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;}
.dm-item{background:#f8fafc;border-radius:10px;padding:12px 14px;}
.dm-item-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--sub);margin-bottom:4px;}
.dm-item-value{font-size:13px;font-weight:600;color:var(--text);}

@keyframes popUp{from{opacity:0;transform:scale(.85);}to{opacity:1;transform:scale(1);}}

/* Mobile */
.menu-toggle{display:none;position:fixed;top:14px;left:14px;z-index:2000;background:var(--accent);color:#fff;border:none;border-radius:8px;padding:9px 11px;font-size:18px;cursor:pointer;}
@media(max-width:768px){
    .menu-toggle{display:block;}
    .sidebar{left:-100%;transition:left .25s;}
    .sidebar.open{left:0;}
    .main{margin-left:0;padding:16px;}
    .proj-grid{grid-template-columns:1fr;}
    .dm-grid{grid-template-columns:1fr;}
    .kpi-strip{grid-template-columns:repeat(2,1fr);}
}
</style>
</head>
<body>

<button class="menu-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')">
    <i class="fas fa-bars"></i>
</button>

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
    <div class="top">
        <div class="sb-avatar">
            <?php if ($profilePicture && file_exists($profilePicture)): ?>
                <img src="<?= htmlspecialchars($profilePicture) ?>" alt="">
            <?php else: ?>
                <?= $userInitial ?>
            <?php endif; ?>
            <div class="dot dot-<?= htmlspecialchars($currentStatus) ?>"></div>
        </div>
        <div>
            <div class="sb-name"><?= htmlspecialchars($userName) ?></div>
            <div class="sb-role"><?= htmlspecialchars($cu['role'] ?? 'Member') ?></div>
        </div>
    </div>
    <ul>
        <li><a href="member_dashboard.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
        <li><a href="member_tasks.php"><i class="fas fa-tasks"></i><span>My Tasks</span></a></li>
        <li><a href="member_projects.php" class="active"><i class="fas fa-briefcase"></i><span>Projects</span></a></li>
        <li><a href="member_messages.php"><i class="fas fa-comments"></i><span>Messages</span></a></li>
        <li><a href="upload_timesheet_progress.php"><i class="fas fa-file-signature"></i><span>Timesheet</span></a></li>
        <li><a href="member_Group.php"><i class="fas fa-users"></i><span>Groups</span></a></li>
        <li><a href="member_Group_chat.php"><i class="fas fa-envelope"></i><span>Group Messages</span></a></li>
        <li><a href="member_attendance.php"><i class="fas fa-check-circle"></i><span>Attendance</span></a></li>
        <li><a href="leave_application.php"><i class="fa-solid fa-calendar-check"></i><span>Leave Application</span></a></li>
        <li><a href="https://investhoodprojects.com/tidytracker/tidytrack/meeting/member_meetings.php"><i class="fas fa-video"></i><span>Meetings</span></a></li>
        <?php if ($isDev): ?>
        <li><a href="member_defects.php"><i class="fas fa-bug"></i><span>My Defects</span></a></li>
        <?php endif; ?>
        <li><a href="member_help.php"><i class="fas fa-life-ring"></i><span>TSM Assistance</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        <li style="margin-top:auto"><a href="profile.php"><i class="fas fa-user-circle"></i><span>Profile</span></a></li>
    </ul>
</aside>

<!-- ══════════════════════════════════════════
     MARKETING POPUP
════════════════════════════════════════════ -->
<?php if ($isMarketing): ?>
<div class="mkt-overlay" id="mktOverlay">
    <div class="mkt-modal">
        <div class="mkt-header">
            <div style="font-size:2.5rem;margin-bottom:12px;">📣</div>
            <h2>Welcome, Marketing Team!</h2>
            <p>Here's your mission briefing before you explore the project portfolio.</p>
        </div>
        <div class="mkt-body">
            <p>You are the voice of Investhood IT to the outside world. Every project below is an opportunity to build partnerships, attract clients, and grow our brand.</p>
            <div class="mkt-tips">
                <div class="mkt-tip"><i class="fas fa-bullhorn"></i><span><strong>Promote actively</strong> — Share project titles and deliverables with potential clients and partners.</span></div>
                <div class="mkt-tip"><i class="fas fa-handshake"></i><span><strong>Build bridges</strong> — Connect external companies with our solutions. Every project is a product.</span></div>
                <div class="mkt-tip"><i class="fas fa-chart-line"></i><span><strong>Track progress</strong> — Know where each project stands so you can speak confidently to stakeholders.</span></div>
                <div class="mkt-tip"><i class="fas fa-users"></i><span><strong>Know the team</strong> — Each project shows which developers are involved — share their expertise!</span></div>
            </div>
            <button class="mkt-cta" onclick="document.getElementById('mktOverlay').style.display='none'">
                <i class="fas fa-rocket" style="margin-right:8px;"></i> Let's Explore Projects →
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── MAIN ── -->
<main class="main">
    <div class="topbar">
        <div>
            <div class="page-title">
                <?php if ($isDev): ?>🖥️ My <span>Project Portfolio</span>
                <?php elseif ($isBA): ?>📋 BA <span>Project Overview</span>
                <?php elseif ($isMarketing): ?>📣 <span>Project Portfolio</span>
                <?php else: ?>📁 My <span>Projects</span>
                <?php endif; ?>
            </div>
            <div style="font-size:12px;color:var(--sub);margin-top:3px;">
                <?php if ($isDev): ?>Your assigned projects · defects · health status
                elseif ($isBA): ?>All team projects · defects you raised · progress tracking
                <?php elseif ($isMarketing): ?>Full portfolio · deliverables · team composition
                <?php else: ?>Project involvement overview
                <?php endif; ?>
            </div>
        </div>
        <div class="topbar-right">
            <div class="time-chip"><i class="fas fa-clock" style="margin-right:5px;"></i><span id="localTime">--:--:--</span></div>
        </div>
    </div>

    <!-- ══ DEVELOPER VIEW ══ -->
    <?php if ($isDev): ?>

    <!-- KPI Strip -->
    <div class="kpi-strip">
        <div class="kpi k-blue">
            <div class="kpi-label">Projects</div>
            <div class="kpi-value"><?= $totalDevProjects ?></div>
            <div class="kpi-sub">assigned to you</div>
        </div>
        <div class="kpi k-green">
            <div class="kpi-label">Tasks Completed</div>
            <div class="kpi-value"><?= $totalDevCompleted ?></div>
            <div class="kpi-sub">of <?= $totalDevTasks ?> total</div>
        </div>
        <div class="kpi k-amber">
            <div class="kpi-label">Defects Assigned</div>
            <div class="kpi-value"><?= $totalDefectsAssigned ?></div>
            <div class="kpi-sub">from BA team</div>
        </div>
        <div class="kpi k-violet">
            <div class="kpi-label">Overall Progress</div>
            <div class="kpi-value"><?= $totalDevTasks > 0 ? round($totalDevCompleted/$totalDevTasks*100) : 0 ?>%</div>
            <div class="kpi-sub">tasks done</div>
        </div>
    </div>

    <!-- Charts – compact size (height=140) -->
    <div class="chart-section">
        <div class="chart-card">
            <h3>📊 Task Status Breakdown</h3>
            <canvas id="taskStatusChart" height="140"></canvas>
        </div>
        <div class="chart-card">
            <h3>📈 Project Progress Overview</h3>
            <canvas id="projectProgressChart" height="140"></canvas>
        </div>
    </div>

    <!-- Project Cards -->
    <div class="sh"><span>Your Projects</span></div>
    <div class="proj-grid">
        <?php foreach ($devProjects as $proj):
            $pct    = $proj['total_tasks'] > 0 ? round($proj['completed_tasks']/$proj['total_tasks']*100) : 0;
            $ended  = strtotime($proj['end_date']) < time();
            $soon   = !$ended && strtotime($proj['end_date']) < strtotime('+7 days');
            $stCls  = $ended ? 'st-ended' : ($soon ? 'st-soon' : 'st-active');
            $stTxt  = $ended ? 'Ended' : ($soon ? 'Due Soon' : 'Active');
            $pColor = $pct < 40 ? '#dc2626' : ($pct < 80 ? '#d97706' : '#059669');
            $defs   = $defectMap[$proj['project_name']] ?? null;
            // Health score: 100 - (open defects penalty) + progress bonus
            $healthScore = min(100, max(0, $pct - (($defs['open_d'] ?? 0)*5) + (($defs['resolved_d'] ?? 0)*2)));
            $hColor = $healthScore >= 70 ? '#059669' : ($healthScore >= 40 ? '#d97706' : '#dc2626');
        ?>
        <div class="proj-card" onclick="openDetail(<?= htmlspecialchars(json_encode([
            'name'      => $proj['project_name'],
            'deliverable' => $proj['deliverable'],
            'start'     => $proj['start_date'],
            'end'       => $proj['end_date'],
            'total'     => $proj['total_tasks'],
            'completed' => $proj['completed_tasks'],
            'active'    => $proj['active_tasks'],
            'review'    => $proj['review_tasks'],
            'pct'       => $pct,
            'defs'      => $defs,
            'health'    => $healthScore,
        ])) ?>)">
            <div class="pc-top">
                <div class="pc-title"><?= htmlspecialchars($proj['project_name']) ?></div>
                <span class="pc-status <?= $stCls ?>"><?= $stTxt ?></span>
            </div>
            <div class="pc-deliverable"><?= htmlspecialchars($proj['deliverable']) ?></div>

            <div class="prog-wrap">
                <div class="prog-top">
                    <span>Task Progress</span>
                    <span style="color:<?= $pColor ?>;font-weight:700;"><?= $pct ?>%</span>
                </div>
                <div class="prog-bar">
                    <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $pColor ?>;"></div>
                </div>
            </div>

            <div class="pc-footer">
                <!-- Health Ring -->
                <div class="health-ring-wrap">
                    <canvas class="health-canvas" width="48" height="48"
                        data-score="<?= $healthScore ?>"
                        data-color="<?= $hColor ?>"></canvas>
                    <div class="health-label" style="color:<?= $hColor ?>;font-size:9px;"><?= $healthScore ?></div>
                </div>
                <div class="pc-meta">
                    <div class="pc-meta-row"><i class="fas fa-check-circle" style="color:var(--green)"></i> Completed: <strong><?= $proj['completed_tasks'] ?></strong></div>
                    <div class="pc-meta-row"><i class="fas fa-spinner" style="color:var(--amber)"></i> Active: <strong><?= $proj['active_tasks'] ?></strong></div>
                    <div class="pc-meta-row"><i class="fas fa-calendar" style="color:var(--sub)"></i> Due: <strong><?= date('M d, Y', strtotime($proj['end_date'])) ?></strong></div>
                </div>
                <?php if ($defs): ?>
                <div style="width:100%;">
                    <div style="font-size:10px;font-weight:700;color:var(--sub);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">Defects from BA</div>
                    <div class="defect-pills">
                        <span class="def-pill dp-total"><?= $defs['total'] ?> total</span>
                        <?php if ($defs['open_d']): ?><span class="def-pill dp-open"><?= $defs['open_d'] ?> open</span><?php endif; ?>
                        <?php if ($defs['progress_d']): ?><span class="def-pill dp-progress"><?= $defs['progress_d'] ?> in progress</span><?php endif; ?>
                        <?php if ($defs['resolved_d']): ?><span class="def-pill dp-resolved"><?= $defs['resolved_d'] ?> resolved</span><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($devProjects)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--sub);">
            <i class="fas fa-folder-open" style="font-size:3rem;opacity:.3;margin-bottom:12px;display:block;"></i>
            No projects assigned yet.
        </div>
        <?php endif; ?>
    </div>

    <?php endif; // end DEV ?>


    <!-- ══ BA VIEW ══ -->
    <?php if ($isBA): ?>

    <?php
    $totalBAProjects  = count($baProjects);
    $totalBADefects   = array_sum(array_column(array_values($baDefectMap), 'total'));
    $totalBAOpen      = array_sum(array_column(array_values($baDefectMap), 'open_d'));
    $totalBAResolved  = array_sum(array_column(array_values($baDefectMap), 'resolved_d'));
    $totalBACritical  = array_sum(array_column(array_values($baDefectMap), 'critical_d'));
    ?>

    <div class="kpi-strip">
        <div class="kpi k-blue"><div class="kpi-label">Total Projects</div><div class="kpi-value"><?= $totalBAProjects ?></div><div class="kpi-sub">in the system</div></div>
        <div class="kpi k-rose"><div class="kpi-label">Defects Raised</div><div class="kpi-value"><?= $totalBADefects ?></div><div class="kpi-sub">by you total</div></div>
        <div class="kpi k-amber"><div class="kpi-label">Open Defects</div><div class="kpi-value"><?= $totalBAOpen ?></div><div class="kpi-sub">pending fix</div></div>
        <div class="kpi k-violet"><div class="kpi-label">Critical</div><div class="kpi-value"><?= $totalBACritical ?></div><div class="kpi-sub">high/critical priority</div></div>
        <div class="kpi k-green"><div class="kpi-label">Resolved</div><div class="kpi-value"><?= $totalBAResolved ?></div><div class="kpi-sub">defects closed</div></div>
    </div>

    <div class="chart-section">
        <div class="chart-card">
            <h3>🐛 Defects by Status</h3>
            <canvas id="baDefectStatusChart" height="140"></canvas>
        </div>
        <div class="chart-card">
            <h3>📊 Project Progress</h3>
            <canvas id="baProjProgressChart" height="140"></canvas>
        </div>
    </div>

    <div class="sh"><span>All Team Projects</span></div>
    <div class="proj-grid">
        <?php foreach ($baProjects as $proj):
            $pct   = $proj['total_tasks'] > 0 ? round($proj['completed_tasks']/$proj['total_tasks']*100) : 0;
            $ended = strtotime($proj['end_date']) < time();
            $soon  = !$ended && strtotime($proj['end_date']) < strtotime('+7 days');
            $stCls = $ended ? 'st-ended' : ($soon ? 'st-soon' : 'st-active');
            $stTxt = $ended ? 'Ended' : ($soon ? 'Due Soon' : 'Active');
            $pColor = $pct < 40 ? '#dc2626' : ($pct < 80 ? '#d97706' : '#059669');
            $defs  = $baDefectMap[$proj['project_name']] ?? null;
        ?>
        <div class="proj-card" onclick="openDetail(<?= htmlspecialchars(json_encode([
            'name'      => $proj['project_name'],
            'deliverable' => $proj['deliverable'],
            'start'     => $proj['start_date'],
            'end'       => $proj['end_date'],
            'total'     => $proj['total_tasks'],
            'completed' => $proj['completed_tasks'],
            'devs'      => $proj['dev_count'],
            'pct'       => $pct,
            'defs'      => $defs,
        ])) ?>)">
            <div class="pc-top">
                <div class="pc-title"><?= htmlspecialchars($proj['project_name']) ?></div>
                <span class="pc-status <?= $stCls ?>"><?= $stTxt ?></span>
            </div>
            <div class="pc-deliverable"><?= htmlspecialchars($proj['deliverable']) ?></div>

            <div class="prog-wrap">
                <div class="prog-top">
                    <span>Progress</span>
                    <span style="color:<?= $pColor ?>;font-weight:700;"><?= $pct ?>%</span>
                </div>
                <div class="prog-bar">
                    <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $pColor ?>;"></div>
                </div>
            </div>

            <div class="pc-footer">
                <div class="pc-meta">
                    <div class="pc-meta-row"><i class="fas fa-users" style="color:var(--blue)"></i> Developers: <strong><?= $proj['dev_count'] ?></strong></div>
                    <div class="pc-meta-row"><i class="fas fa-tasks" style="color:var(--sub)"></i> Tasks: <strong><?= $proj['completed_tasks'] ?>/<?= $proj['total_tasks'] ?></strong></div>
                    <div class="pc-meta-row"><i class="fas fa-calendar" style="color:var(--sub)"></i> Due: <strong><?= date('M d, Y', strtotime($proj['end_date'])) ?></strong></div>
                </div>
                <?php if ($defs): ?>
                <div style="width:100%;">
                    <div style="font-size:10px;font-weight:700;color:var(--sub);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">Your Defects on This Project</div>
                    <div class="defect-pills">
                        <span class="def-pill dp-total"><?= $defs['total'] ?> raised</span>
                        <?php if ($defs['open_d']): ?><span class="def-pill dp-open"><?= $defs['open_d'] ?> open</span><?php endif; ?>
                        <?php if ($defs['critical_d']): ?><span class="def-pill dp-open"><?= $defs['critical_d'] ?> critical</span><?php endif; ?>
                        <?php if ($defs['resolved_d']): ?><span class="def-pill dp-resolved"><?= $defs['resolved_d'] ?> resolved</span><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; // end BA ?>


    <!-- ══ MARKETING VIEW ══ -->
    <?php if ($isMarketing): ?>

    <?php
    $totalMktProjects = count($mktProjects);
    $totalMktDevs     = array_sum(array_column($mktProjects,'dev_count'));
    $totalMktTasks    = array_sum(array_column($mktProjects,'total_tasks'));
    $totalMktDone     = array_sum(array_column($mktProjects,'completed_tasks'));
    ?>

    <div class="kpi-strip">
        <div class="kpi k-blue"><div class="kpi-label">Total Projects</div><div class="kpi-value"><?= $totalMktProjects ?></div><div class="kpi-sub">in portfolio</div></div>
        <div class="kpi k-violet"><div class="kpi-label">Developers</div><div class="kpi-value"><?= $totalMktDevs ?></div><div class="kpi-sub">across all projects</div></div>
        <div class="kpi k-green"><div class="kpi-label">Overall Delivery</div><div class="kpi-value"><?= $totalMktTasks > 0 ? round($totalMktDone/$totalMktTasks*100) : 0 ?>%</div><div class="kpi-sub">tasks completed</div></div>
        <div class="kpi k-amber"><div class="kpi-label">Active Projects</div><div class="kpi-value"><?= count(array_filter($mktProjects, fn($p) => strtotime($p['end_date']) > time())) ?></div><div class="kpi-sub">currently running</div></div>
    </div>

    <div class="chart-section">
        <div class="chart-card">
            <h3>📊 Portfolio Progress</h3>
            <canvas id="mktProgressChart" height="140"></canvas>
        </div>
        <div class="chart-card">
            <h3>👥 Developer Distribution</h3>
            <canvas id="mktDevChart" height="140"></canvas>
        </div>
    </div>

    <div class="sh"><span>Full Project Portfolio</span></div>
    <div class="proj-grid">
        <?php foreach ($mktProjects as $proj):
            $pct   = $proj['total_tasks'] > 0 ? round($proj['completed_tasks']/$proj['total_tasks']*100) : 0;
            $ended = strtotime($proj['end_date']) < time();
            $stCls = $ended ? 'st-ended' : 'st-active';
            $stTxt = $ended ? 'Ended' : 'Active';
            $pColor = $pct < 40 ? '#dc2626' : ($pct < 80 ? '#d97706' : '#059669');
            $devList = $devListMap[$proj['project_name']] ?? 'N/A';
        ?>
        <div class="proj-card" onclick="openDetail(<?= htmlspecialchars(json_encode([
            'name'      => $proj['project_name'],
            'deliverable' => $proj['deliverable'],
            'start'     => $proj['start_date'],
            'end'       => $proj['end_date'],
            'total'     => $proj['total_tasks'],
            'completed' => $proj['completed_tasks'],
            'devs'      => $proj['dev_count'],
            'dev_list'  => $devList,
            'pct'       => $pct,
        ])) ?>)">
            <div class="pc-top">
                <div class="pc-title"><?= htmlspecialchars($proj['project_name']) ?></div>
                <span class="pc-status <?= $stCls ?>"><?= $stTxt ?></span>
            </div>
            <div class="pc-deliverable"><?= htmlspecialchars($proj['deliverable']) ?></div>

            <div class="prog-wrap">
                <div class="prog-top">
                    <span>Delivery Progress</span>
                    <span style="color:<?= $pColor ?>;font-weight:700;"><?= $pct ?>%</span>
                </div>
                <div class="prog-bar">
                    <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $pColor ?>;"></div>
                </div>
            </div>

            <div class="pc-footer">
                <div class="pc-meta">
                    <div class="pc-meta-row"><i class="fas fa-users" style="color:var(--blue)"></i> Team Size: <strong><?= $proj['dev_count'] ?> devs</strong></div>
                    <div class="pc-meta-row"><i class="fas fa-list-check" style="color:var(--green)"></i> Tasks: <strong><?= $proj['completed_tasks'] ?>/<?= $proj['total_tasks'] ?></strong></div>
                    <div class="pc-meta-row"><i class="fas fa-calendar" style="color:var(--sub)"></i> Deadline: <strong><?= date('M d, Y', strtotime($proj['end_date'])) ?></strong></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; // end marketing ?>

    <!-- DEFAULT VIEW (other roles) -->
    <?php if (!$isDev && !$isBA && !$isMarketing): ?>
    <div class="sh"><span>My Projects</span></div>
    <div class="proj-grid">
        <?php
        $stmt = $conn->prepare("
            SELECT p.project_id, p.project_name, p.deliverable, p.start_date, p.end_date,
                   SUM(CASE WHEN LOWER(t.status) IN ('completed','approved') THEN 1 ELSE 0 END) AS completed_tasks,
                   COUNT(t.task_id) AS total_tasks
            FROM projects_x p
            JOIN tasks t ON t.project_id = p.project_id
            WHERE t.assignTo = ?
            GROUP BY p.project_id ORDER BY p.end_date DESC
        ");
        $stmt->bind_param("s", $userName);
        $stmt->execute();
        $gp = $stmt->get_result();
        while ($proj = $gp->fetch_assoc()):
            $pct = $proj['total_tasks'] > 0 ? round($proj['completed_tasks']/$proj['total_tasks']*100) : 0;
            $ended = strtotime($proj['end_date']) < time();
            $pColor = $pct < 40 ? '#dc2626' : ($pct < 80 ? '#d97706' : '#059669');
        ?>
        <div class="proj-card" onclick="openDetail(<?= htmlspecialchars(json_encode(['name'=>$proj['project_name'],'deliverable'=>$proj['deliverable'],'start'=>$proj['start_date'],'end'=>$proj['end_date'],'total'=>$proj['total_tasks'],'completed'=>$proj['completed_tasks'],'pct'=>$pct])) ?>)">
            <div class="pc-top">
                <div class="pc-title"><?= htmlspecialchars($proj['project_name']) ?></div>
                <span class="pc-status <?= $ended ? 'st-ended' : 'st-active' ?>"><?= $ended ? 'Ended' : 'Active' ?></span>
            </div>
            <div class="pc-deliverable"><?= htmlspecialchars($proj['deliverable']) ?></div>
            <div class="prog-wrap">
                <div class="prog-top"><span>Progress</span><span style="color:<?= $pColor ?>;font-weight:700;"><?= $pct ?>%</span></div>
                <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $pColor ?>;"></div></div>
            </div>
        </div>
        <?php endwhile; $stmt->close(); ?>
    </div>
    <?php endif; ?>

</main>

<!-- ── PROJECT DETAIL MODAL ── -->
<div class="detail-modal-bg" id="detailModal">
    <div class="detail-modal">
        <div class="dm-head">
            <h2 id="dmTitle">Project Details</h2>
            <button class="dm-close" onclick="document.getElementById('detailModal').classList.remove('open')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="dm-body" id="dmBody"></div>
    </div>
</div>

<script src="theme_switcher.js"></script>
<script>
// ── Clock ──
function tick(){ document.getElementById('localTime').textContent = new Date().toLocaleTimeString('en-ZA',{hour:'2-digit',minute:'2-digit',second:'2-digit',timeZone:'Africa/Johannesburg'}); }
setInterval(tick,1000); tick();

// ── Health ring canvases ──
document.querySelectorAll('.health-canvas').forEach(c => {
    const score = parseInt(c.dataset.score), color = c.dataset.color;
    const ctx = c.getContext('2d'), r = 20, cx = 24, cy = 24;
    ctx.clearRect(0,0,48,48);
    ctx.beginPath(); ctx.arc(cx,cy,r,0,Math.PI*2);
    ctx.strokeStyle='#f1f5f9'; ctx.lineWidth=5; ctx.stroke();
    const end = (-Math.PI/2) + (score/100)*Math.PI*2;
    ctx.beginPath(); ctx.arc(cx,cy,r,-Math.PI/2,end);
    ctx.strokeStyle=color; ctx.lineWidth=5; ctx.lineCap='round'; ctx.stroke();
});

// ── Project detail modal ──
function openDetail(data) {
    document.getElementById('dmTitle').textContent = data.name || 'Project';
    let html = '<div class="dm-grid">';

    html += `<div class="dm-item"><div class="dm-item-label">Deliverable</div><div class="dm-item-value">${data.deliverable || '-'}</div></div>`;
    html += `<div class="dm-item"><div class="dm-item-label">Start Date</div><div class="dm-item-value">${data.start || '-'}</div></div>`;
    html += `<div class="dm-item"><div class="dm-item-label">End Date</div><div class="dm-item-value">${data.end || '-'}</div></div>`;
    html += `<div class="dm-item"><div class="dm-item-label">Progress</div><div class="dm-item-value" style="color:${data.pct>=80?'#059669':data.pct>=40?'#d97706':'#dc2626'}">${data.pct}%</div></div>`;
    html += `<div class="dm-item"><div class="dm-item-label">Tasks</div><div class="dm-item-value">${data.completed || 0} / ${data.total || 0} completed</div></div>`;

    if (data.active !== undefined) html += `<div class="dm-item"><div class="dm-item-label">Active Tasks</div><div class="dm-item-value">${data.active}</div></div>`;
    if (data.review !== undefined) html += `<div class="dm-item"><div class="dm-item-label">In Review</div><div class="dm-item-value">${data.review}</div></div>`;
    if (data.devs !== undefined)   html += `<div class="dm-item"><div class="dm-item-label">Developers</div><div class="dm-item-value">${data.devs}</div></div>`;
    if (data.health !== undefined) html += `<div class="dm-item"><div class="dm-item-label">Health Score</div><div class="dm-item-value" style="color:${data.health>=70?'#059669':data.health>=40?'#d97706':'#dc2626'}">${data.health}/100</div></div>`;
    if (data.dev_list)             html += `<div class="dm-item" style="grid-column:1/-1"><div class="dm-item-label">Team Members</div><div class="dm-item-value" style="font-size:12px;font-weight:400;line-height:1.6">${data.dev_list}</div></div>`;

    html += '</div>';

    if (data.defs && data.defs.total > 0) {
        html += `<div style="background:#fff5f5;border:1px solid #fecaca;border-radius:14px;padding:16px;margin-top:4px;">
            <div style="font-size:12px;font-weight:700;color:#991b1b;margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px;">🐛 Defects</div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <span style="background:#fee2e2;color:#dc2626;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;">${data.defs.total} total</span>
                ${data.defs.open_d ? `<span style="background:#fee2e2;color:#dc2626;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;">${data.defs.open_d} open</span>` : ''}
                ${data.defs.progress_d ? `<span style="background:#fef3c7;color:#d97706;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;">${data.defs.progress_d} in progress</span>` : ''}
                ${data.defs.resolved_d ? `<span style="background:#dcfce7;color:#166534;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;">${data.defs.resolved_d} resolved</span>` : ''}
            </div>
        </div>`;
    }

    document.getElementById('dmBody').innerHTML = html;
    document.getElementById('detailModal').classList.add('open');
}

document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
document.addEventListener('keydown', e => { if (e.key==='Escape') document.getElementById('detailModal').classList.remove('open'); });

// ══ CHARTS (compact) ══

<?php if ($isDev && !empty($devProjects)): ?>
// Task Status doughnut
const taskStatusCtx = document.getElementById('taskStatusChart');
if (taskStatusCtx) {
    const totalC = <?= $totalDevCompleted ?>;
    const totalA = <?= array_sum(array_column($devProjects,'active_tasks')) ?>;
    const totalR = <?= array_sum(array_column($devProjects,'review_tasks')) ?>;
    new Chart(taskStatusCtx, {
        type:'doughnut',
        data:{
            labels:['Completed','Active','In Review'],
            datasets:[{data:[totalC,totalA,totalR],backgroundColor:['#059669','#2563eb','#7c3aed'],borderWidth:0,hoverOffset:6}]
        },
        options:{cutout:'68%',plugins:{legend:{position:'bottom',labels:{font:{size:12},padding:16}}}}
    });
}

// Project progress bar chart
const projProgressCtx = document.getElementById('projectProgressChart');
if (projProgressCtx) {
    const labels = <?= json_encode(array_column($devProjects,'project_name')) ?>;
    const data   = <?= json_encode(array_map(fn($p) => $p['total_tasks']>0?round($p['completed_tasks']/$p['total_tasks']*100):0, $devProjects)) ?>;
    new Chart(projProgressCtx, {
        type:'bar',
        data:{labels,datasets:[{label:'% Complete',data,backgroundColor:data.map(v=>v>=80?'#059669':v>=40?'#d97706':'#dc2626'),borderRadius:8,borderSkipped:false}]},
        options:{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{max:100,grid:{color:'#f1f5f9'},ticks:{callback:v=>v+'%'}},y:{grid:{display:false}}}}
    });
}
<?php endif; ?>

<?php if ($isBA && !empty($baProjects)): ?>
const baDefStatusCtx = document.getElementById('baDefectStatusChart');
if (baDefStatusCtx) {
    const open = <?= $totalBAOpen ?>, prog = <?= $totalBADefects - $totalBAOpen - $totalBAResolved ?>, res = <?= $totalBAResolved ?>;
    new Chart(baDefStatusCtx,{type:'doughnut',data:{labels:['Open','In Progress','Resolved'],datasets:[{data:[open,prog,res],backgroundColor:['#dc2626','#d97706','#059669'],borderWidth:0}]},options:{cutout:'65%',plugins:{legend:{position:'bottom'}}}});
}
const baProjCtx = document.getElementById('baProjProgressChart');
if (baProjCtx) {
    const labels = <?= json_encode(array_column($baProjects,'project_name')) ?>;
    const data   = <?= json_encode(array_map(fn($p) => $p['total_tasks']>0?round($p['completed_tasks']/$p['total_tasks']*100):0, $baProjects)) ?>;
    new Chart(baProjCtx,{type:'bar',data:{labels,datasets:[{label:'% Done',data,backgroundColor:'#2563eb',borderRadius:8,borderSkipped:false}]},options:{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{max:100,ticks:{callback:v=>v+'%'}},y:{grid:{display:false}}}}});
}
<?php endif; ?>

<?php if ($isMarketing && !empty($mktProjects)): ?>
const mktProgCtx = document.getElementById('mktProgressChart');
if (mktProgCtx) {
    const labels = <?= json_encode(array_column($mktProjects,'project_name')) ?>;
    const data   = <?= json_encode(array_map(fn($p) => $p['total_tasks']>0?round($p['completed_tasks']/$p['total_tasks']*100):0, $mktProjects)) ?>;
    new Chart(mktProgCtx,{type:'bar',data:{labels,datasets:[{label:'% Done',data,backgroundColor:data.map(v=>v>=80?'#059669':v>=40?'#d97706':'#dc2626'),borderRadius:8,borderSkipped:false}]},options:{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{max:100,ticks:{callback:v=>v+'%'}},y:{grid:{display:false}}}}});
}
const mktDevCtx = document.getElementById('mktDevChart');
if (mktDevCtx) {
    const labels = <?= json_encode(array_column($mktProjects,'project_name')) ?>;
    const data   = <?= json_encode(array_column($mktProjects,'dev_count')) ?>;
    new Chart(mktDevCtx,{type:'bar',data:{labels,datasets:[{label:'Developers',data,backgroundColor:'#7c3aed',borderRadius:8,borderSkipped:false}]},options:{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{ticks:{stepSize:1}},y:{grid:{display:false}}}}});
}
<?php endif; ?>
</script>
</body>
</html>
