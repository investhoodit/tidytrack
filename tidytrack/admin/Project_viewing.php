<?php
session_start();
require_once __DIR__ . '/../dp.php';

// ==================== Authentication & Role Check ====================
$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Admin';
$userInitial = strtoupper($userName[0]);
$userRole = strtolower($_SESSION['role'] ?? '');

if (!$userId || $userRole !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Fetch current user details for sidebar
$stmt = $conn->prepare("SELECT name, role, department, profile_picture, status FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

$profilePicture = $currentUser['profile_picture'] ?? null;
$currentStatus  = $currentUser['status'] ?? 'offline';
$currentRole    = strtolower(trim($currentUser['role'] ?? 'admin'));

function e($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

// ==================== DATA FETCHING ====================
$leadersAggSql = "
    SELECT pl.project_id,
           GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS leader_names,
           GROUP_CONCAT(DISTINCT u.user_id) AS leader_ids
    FROM project_leader pl
    JOIN users u ON u.user_id = pl.teamLeadder_id
    GROUP BY pl.project_id
";

$stmt = $conn->prepare("
    SELECT p.*,
           COALESCE(la.leader_names, 'Unassigned') AS leader_names,
           c.name AS creator_name,
           (SELECT COUNT(*) FROM sub_projects sp WHERE sp.project_id = p.project_id) AS sub_project_count,
           (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.project_id) AS total_tasks_count,
           (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.project_id AND LOWER(t.status) IN ('completed','approved')) AS completed_tasks_count
    FROM projects_x p
    LEFT JOIN ($leadersAggSql) la ON la.project_id = p.project_id
    LEFT JOIN users c ON p.created_by = c.user_id
    ORDER BY p.start_date DESC
");
$stmt->execute();
$projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$projectDetails = [];
foreach ($projects as $proj) {
    $pid = $proj['project_id'];
    
    $taskStmt = $conn->prepare("
        SELECT t.*, u.name AS assigned_user
        FROM tasks t
        LEFT JOIN users u ON t.assignTo = u.user_id
        WHERE t.project_id = ?
        ORDER BY t.dueDate ASC
    ");
    $taskStmt->bind_param("i", $pid);
    $taskStmt->execute();
    $tasks = $taskStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $taskStmt->close();
    
    $defectStmt = $conn->prepare("
    SELECT d.*, u.name AS assigned_user
    FROM project_defects d
    LEFT JOIN users u ON d.assigned_user_id = u.user_id
    WHERE d.project_name = ?
    ORDER BY d.date_identified DESC
");
$defectStmt->bind_param("s", $proj['project_name']);
$defectStmt->execute();
$defects = $defectStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$defectStmt->close();
    
    $subStmt = $conn->prepare("
        SELECT s.*, u.name AS leader_name, c.name AS creator_name
        FROM sub_projects s
        LEFT JOIN users u ON s.teamLeadder_id = u.user_id
        LEFT JOIN users c ON s.created_by = c.user_id
        WHERE s.project_id = ?
        ORDER BY s.start_date ASC
    ");
    $subStmt->bind_param("i", $pid);
    $subStmt->execute();
    $subProjects = $subStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $subStmt->close();
    
    foreach ($subProjects as &$sub) {
        $subTaskStmt = $conn->prepare("
            SELECT t.*, u.name AS assigned_user
            FROM tasks t
            LEFT JOIN users u ON t.assignTo = u.user_id
            WHERE t.sub_project_id = ?
            ORDER BY t.dueDate ASC
        ");
        $subTaskStmt->bind_param("i", $sub['sub_project_id']);
        $subTaskStmt->execute();
        $sub['tasks'] = $subTaskStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $subTaskStmt->close();
    }
    
    $projectDetails[$pid] = [
        'project_name'    => $proj['project_name'],
        'deliverable'     => $proj['deliverable'],
        'start_date'      => $proj['start_date'],
        'end_date'        => $proj['end_date'],
        'leader_names'    => $proj['leader_names'],
        'creator_name'    => $proj['creator_name'],
        'tasks'           => $tasks,
        'sub_projects'    => $subProjects,
        'defects' => $defects
    ];
}

$totalProjects = count($projects);
$totalSubProjects = array_sum(array_column($projects, 'sub_project_count'));
$totalTasks = array_sum(array_column($projects, 'total_tasks_count'));
$totalCompletedTasks = array_sum(array_column($projects, 'completed_tasks_count'));
$overallCompletion = $totalTasks > 0 ? round(($totalCompletedTasks / $totalTasks) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>All Projects · Admin Portal</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    --r:16px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;}
.main{flex:1;margin-left:0;padding:28px 28px 48px;}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px;}
.page-title{font-size:1.5rem;font-weight:700;letter-spacing:-.3px;}
.page-title span{color:var(--blue);}
.topbar-right{display:flex;align-items:center;gap:10px;}
.time-chip{padding:6px 14px;background:#fff;border:1px solid var(--border);border-radius:30px;font-size:12px;color:var(--sub);font-family:'DM Mono',monospace;}

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

.sh{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);display:flex;align-items:center;gap:8px;margin-bottom:16px;}
.sh::after{content:'';flex:1;height:1px;background:var(--border);}

.proj-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:18px;margin-bottom:32px;}
.proj-card{background:#fff;border:1px solid var(--border);border-radius:20px;padding:22px;transition:all .22s;position:relative;overflow:hidden;}
.proj-card::after{content:'';position:absolute;inset:0;border-radius:20px;border:2px solid transparent;transition:border-color .22s;pointer-events:none;}
.proj-card:hover{transform:translateY(-4px);box-shadow:0 12px 40px rgba(0,0,0,.08);}
.proj-card:hover::after{border-color:var(--blue);}
.pc-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:14px;}
.pc-title{font-size:16px;font-weight:700;line-height:1.3;}
.pc-status{font-size:10px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;flex-shrink:0;}
.st-active{background:#dcfce7;color:#166534;}
.st-ended{background:#fee2e2;color:#991b1b;}
.st-soon{background:#fef3c7;color:#92400e;}
.pc-deliverable{font-size:12px;color:var(--sub);margin-bottom:12px;line-height:1.5;}

.prog-wrap{margin-bottom:12px;}
.prog-top{display:flex;justify-content:space-between;font-size:11px;font-weight:600;color:var(--sub);margin-bottom:5px;}
.prog-bar{height:6px;background:#f1f5f9;border-radius:20px;overflow:hidden;}
.prog-fill{height:100%;border-radius:20px;transition:width .7s ease;}

.pc-meta{display:flex;flex-direction:column;gap:5px;margin:12px 0;}
.pc-meta-row{font-size:11px;color:var(--sub);display:flex;align-items:center;gap:6px;}
.pc-meta-row strong{color:var(--text);}

.card-actions{display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);flex-wrap:wrap;}
.btn-icon{padding:6px 12px;border-radius:30px;font-size:11px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .2s;cursor:pointer;border:none;}
.btn-view{background:#f1f5f9;color:var(--accent);}
.btn-view:hover{background:var(--accent);color:#fff;}

.chart-section{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:32px;}
.chart-card{background:#fff;border:1px solid var(--border);border-radius:20px;padding:22px;  height:320px; display:flex; flex-direction:column;}
.chart-card h3{font-size:14px;font-weight:700;margin-bottom:18px;color:var(--text);}
@media(max-width:900px){.chart-section{grid-template-columns:1fr;}}

/* Modal with filter styles */
.detail-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:8888;display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);}
.detail-modal-bg.open{display:flex;}
.detail-modal{background:#fff;border-radius:24px;width:100%;max-width:900px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.2);}
.dm-head{padding:22px 26px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:2;}
.dm-head h2{font-size:1.2rem;font-weight:700;}
.dm-close{width:34px;height:34px;border-radius:9px;border:1px solid var(--border);background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--sub);font-size:14px;}
.dm-close:hover{background:var(--rose);color:#fff;border-color:var(--rose);}
.dm-body{padding:22px 26px;}
.dm-section{margin-bottom:24px;}
.dm-section h4{font-size:13px;font-weight:700;margin-bottom:12px;color:var(--accent);display:flex;align-items:center;gap:8px;}
.filter-bar{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;background:#f8fafc;padding:12px;border-radius:16px;}
.filter-group{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--border);border-radius:40px;padding:4px 12px;}
.filter-group i{color:var(--sub);font-size:12px;}
.filter-group input,.filter-group select{border:none;padding:6px 0;font-size:12px;background:transparent;outline:none;min-width:120px;}
/* CREATION FILTER: style for creation filter */
.filter-group.creation-filter {
    border-color:#c7d2fe;
    background:#eef2ff;
}
.filter-group.creation-filter select {
    color:#3730a3;
    font-weight:600;
}

.task-table{width:100%;border-collapse:collapse;font-size:12px;}
.task-table th,.task-table td{padding:8px 10px;text-align:left;border-bottom:1px solid var(--border);}
.task-table th{background:#f8fafc;font-weight:600;color:var(--sub);}
.sub-card{background:#f8fafc;border-radius:16px;padding:16px;margin-bottom:16px;}
.sub-card h5{font-size:14px;font-weight:700;margin-bottom:8px;}

/* KANBAN: Added styles for Kanban board */
.kanban-board {
    display:flex;
    gap:16px;
    overflow-x:auto;
    padding:8px 0 16px;
    min-height:200px;
}

.kanban-column {
    flex:0 0 220px;
    background:#f8fafc;
    border-radius:12px;
    padding:12px;
    border:1px solid var(--border);
    display:flex;
    flex-direction:column;
}

.kanban-column-header {
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:0.5px;
    color:var(--sub);
    padding-bottom:8px;
    border-bottom:1px solid var(--border);
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
}

.kanban-column-header span:last-child {
    background:var(--border);
    padding:0 8px;
    border-radius:12px;
    font-size:10px;
    color:var(--text);
}

.kanban-card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:12px;
    margin-bottom:10px;
    box-shadow:0 2px 4px rgba(0,0,0,.02);
    transition:all .15s;
    cursor:default;
}

.kanban-card:hover {
    box-shadow:0 4px 12px rgba(0,0,0,.06);
    border-color:var(--blue);
}

.kanban-card-title {
    font-size:13px;
    font-weight:600;
    margin-bottom:4px;
    line-height:1.3;
}

.kanban-card-assignee {
    font-size:11px;
    color:var(--sub);
    display:flex;
    align-items:center;
    gap:4px;
}

.kanban-card-meta {
    display:flex;
    justify-content:space-between;
    font-size:10px;
    color:var(--sub);
    margin-top:6px;
    align-items:center;
}

.kanban-card-priority {
    font-weight:600;
    padding:2px 8px;
    border-radius:20px;
    background:#f1f5f9;
}

.priority-low { background:#e2e8f0; color:#475569; }
.priority-medium { background:#fef3c7; color:#92400e; }
.priority-high { background:#fee2e2; color:#991b1b; }
.priority-critical { background:#fecaca; color:#7f1d1d; }

/* View toggle buttons */
.view-toggle-group {
    display:flex;
    gap:4px;
    background:#f1f5f9;
    padding:4px;
    border-radius:30px;
    margin-bottom:12px;
}

.view-toggle {
    border:none;
    background:transparent;
    padding:6px 16px;
    border-radius:30px;
    font-size:12px;
    font-weight:600;
    color:var(--sub);
    cursor:pointer;
    transition:all .15s;
}

.view-toggle.active {
    background:#fff;
    color:var(--text);
    box-shadow:0 2px 6px rgba(0,0,0,.06);
}

.view-toggle:hover:not(.active) {
    color:var(--text);
}

.task-filter-count {
    font-size:11px;
    color:var(--sub);
}

.menu-toggle{display:none;position:fixed;top:14px;left:14px;z-index:2000;background:var(--accent);color:#fff;border:none;border-radius:8px;padding:9px 11px;font-size:18px;cursor:pointer;}
@media(max-width:768px){
    .menu-toggle{display:block;}
    .sidebar{left:-100%;transition:left .25s;}
    .sidebar.open{left:0;}
    .main{margin-left:0;padding:16px;}
    .proj-grid{grid-template-columns:1fr;}
    .kpi-strip{grid-template-columns:repeat(2,1fr);}
    .filter-group input,.filter-group select{min-width:100px;}}

.kpi { cursor:pointer; transition:all .2s ease; }

.kpi.active-kpi {
    outline:2px solid var(--blue);
    transform:scale(1.03);
    box-shadow:0 10px 25px rgba(0,0,0,0.08);
}
.back-btn {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:8px 14px;
    background:var(--accent);
    color:#fff;
    border-radius:30px;
    font-size:12px;
    font-weight:600;
    text-decoration:none;
    transition:all .2s ease;
}

.back-btn:hover {
    background:#0f2a44;
    transform:translateY(-1px);
}
</style>
</head>
<body>

<main class="main">
    <div class="topbar">
        <div>
            <div class="page-title">All <span>Projects</span></div>
            <div style="font-size:12px;color:var(--sub);margin-top:3px;">Full portfolio overview · Tasks & Sub-projects</div>
        </div>
        <div class="topbar-right">
            <a href="admin_index.php" class="back-btn">
    <i class="fas fa-arrow-left"></i>
    Dashboard
</a>
            <div class="time-chip"><i class="fas fa-clock" style="margin-right:5px;"></i><span id="localTime">--:--:--</span></div>
        </div>
    </div>

 <!-- ================= KPI STRIP (UPDATED) ================= -->
<div class="kpi-strip">
    <div class="kpi k-blue active-kpi" data-filter="all">
        <div class="kpi-label">Total Projects</div>
        <div class="kpi-value"><?= $totalProjects ?></div>
        <div class="kpi-sub">active & completed</div>
    </div>

    <div class="kpi k-violet" data-filter="subprojects">
        <div class="kpi-label">Sub-Projects</div>
        <div class="kpi-value"><?= $totalSubProjects ?></div>
        <div class="kpi-sub">across portfolio</div>
    </div>

    <div class="kpi k-green" data-filter="completed">
        <div class="kpi-label">Tasks Completion</div>
        <div class="kpi-value"><?= $overallCompletion ?>%</div>
        <div class="kpi-sub"><?= $totalCompletedTasks ?>/<?= $totalTasks ?> done</div>
    </div>

    <div class="kpi k-amber" data-filter="active">
        <div class="kpi-label">Active Projects</div>
        <div class="kpi-value"><?= count(array_filter($projects, fn($p) => strtotime($p['end_date']) > time())) ?></div>
        <div class="kpi-sub">currently running</div>
    </div>
</div>

    <div class="chart-section">
        <div class="chart-card">
            <h3>📊 Task Progress Overview</h3>
            <canvas id="taskProgressChart" height="200"></canvas>
        </div>
        <div class="chart-card">
            <h3>🏁 Project Status Distribution</h3>
            <canvas id="projectStatusChart" height="200"></canvas>
        </div>
    </div>

    <div class="sh"><span>All Projects</span></div>
    <div class="proj-grid">
        <?php foreach ($projects as $proj):
            $pid = $proj['project_id'];
            $startTs = strtotime($proj['start_date']);
            $endTs = strtotime($proj['end_date']);
            $now = time();
            $timelineProgress = ($now <= $startTs) ? 0 : (($now >= $endTs) ? 100 : round((($now - $startTs) / ($endTs - $startTs)) * 100));
            $taskProgress = $proj['total_tasks_count'] > 0 ? round(($proj['completed_tasks_count'] / $proj['total_tasks_count']) * 100) : 0;
            $ended = $endTs < $now;
            $soon = !$ended && ($endTs < strtotime('+7 days'));
            $statusClass = $ended ? 'st-ended' : ($soon ? 'st-soon' : 'st-active');
            $statusText = $ended ? 'Ended' : ($soon ? 'Due Soon' : 'Active');
        ?>
        <div class="proj-card"
     data-status="<?= $ended ? 'ended' : ($soon ? 'soon' : 'active') ?>"
     data-completed="<?= $taskProgress == 100 ? 'yes' : 'no' ?>"
     data-subprojects="<?= $proj['sub_project_count'] > 0 ? 'yes' : 'no' ?>">
            <div class="pc-top">
                <div class="pc-title"><?= e($proj['project_name']) ?></div>
                <span class="pc-status <?= $statusClass ?>"><?= $statusText ?></span>
            </div>
            <div class="pc-deliverable"><?= e($proj['deliverable']) ?></div>
            
            <div class="prog-wrap">
                <div class="prog-top"><span>📅 Timeline</span><span><?= $timelineProgress ?>%</span></div>
                <div class="prog-bar"><div class="prog-fill" style="width:<?= $timelineProgress ?>%;background:var(--blue);"></div></div>
            </div>
            <div class="prog-wrap">
                <div class="prog-top"><span>✅ Tasks</span><span><?= $taskProgress ?>%</span></div>
                <div class="prog-bar"><div class="prog-fill" style="width:<?= $taskProgress ?>%;background:<?= $taskProgress < 40 ? '#dc2626' : ($taskProgress < 80 ? '#d97706' : '#059669') ?>;"></div></div>
            </div>
            
            <div class="pc-meta">
                <div class="pc-meta-row"><i class="fas fa-users"></i> Leaders: <strong><?= e($proj['leader_names']) ?></strong></div>
                <div class="pc-meta-row"><i class="fas fa-layer-group"></i> Sub-projects: <strong><?= $proj['sub_project_count'] ?></strong> · Tasks: <strong><?= $proj['total_tasks_count'] ?></strong></div>
                <div class="pc-meta-row"><i class="fas fa-calendar"></i> <?= date('M d, Y', $startTs) ?> → <?= date('M d, Y', $endTs) ?></div>
            </div>
            
            <div class="card-actions">
                <button class="btn-icon btn-view" onclick="openProjectDetail(<?= $pid ?>)"><i class="fas fa-eye"></i> Details</button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($projects)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--sub);">
            <i class="fas fa-folder-open" style="font-size:3rem;opacity:.3;margin-bottom:12px;display:block;"></i>
            No projects found.
        </div>
        <?php endif; ?>
    </div>
</main>

<div class="detail-modal-bg" id="detailModal">
    <div class="detail-modal">
        <div class="dm-head">
            <h2 id="modalTitle">Project Details</h2>
            <button class="dm-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="dm-body" id="modalBody"></div>
    </div>
</div>

<script>
// Embed project details as JSON
const projectData = <?php echo json_encode($projectDetails, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function tick() {
    const el = document.getElementById('localTime');
    if (el) el.textContent = new Date().toLocaleTimeString('en-ZA', {hour:'2-digit',minute:'2-digit',second:'2-digit',timeZone:'Africa/Johannesburg'});
}
setInterval(tick, 1000);
tick();

function closeModal() {
    document.getElementById('detailModal').classList.remove('open');
}

// KANBAN: View switch function (global)
let currentView = 'table'; // 'table' or 'kanban'

function switchView(view) {
    currentView = view;
    // Update toggle buttons
    document.querySelectorAll('.view-toggle').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.view === view);
    });
    // Show/hide containers
    const tableView = document.getElementById('tableView');
    const kanbanView = document.getElementById('kanbanView');
    if (tableView) tableView.style.display = view === 'table' ? '' : 'none';
    if (kanbanView) kanbanView.style.display = view === 'kanban' ? '' : 'none';
    // Re-render tasks (filters already applied)
    const renderFn = window._renderTasks;
    if (typeof renderFn === 'function') renderFn();
}

function openProjectDetail(projectId) {
    const p = projectData[projectId];
    if (!p) {
        alert('Project details not found');
        return;
    }
    document.getElementById('modalTitle').innerHTML = p.project_name;
    
    let html = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
            <div style="background:#f8fafc;padding:12px;border-radius:12px;"><div style="font-size:10px;font-weight:700;color:var(--sub);">Deliverable</div><div style="font-size:13px;">${escapeHtml(p.deliverable || '-')}</div></div>
            <div style="background:#f8fafc;padding:12px;border-radius:12px;"><div style="font-size:10px;font-weight:700;color:var(--sub);">Leaders</div><div style="font-size:13px;">${escapeHtml(p.leader_names || 'Unassigned')}</div></div>
            <div style="background:#f8fafc;padding:12px;border-radius:12px;"><div style="font-size:10px;font-weight:700;color:var(--sub);">Timeline</div><div style="font-size:13px;">${p.start_date} → ${p.end_date}</div></div>
            <div style="background:#f8fafc;padding:12px;border-radius:12px;"><div style="font-size:10px;font-weight:700;color:var(--sub);">Created By</div><div style="font-size:13px;">${escapeHtml(p.creator_name || '-')}</div></div>
        </div>
    `;
    
    // Tasks section with filter bar (including Deleted and Delete Requested)
    if (p.tasks && p.tasks.length) {
        html += `<div class="dm-section" id="tasksSection">
            <h4><i class="fas fa-tasks"></i> Tasks (${p.tasks.length})</h4>
            <div class="filter-bar">
                <div class="filter-group"><i class="fas fa-search"></i><input type="text" id="taskTitleSearch" placeholder="Search by title..."></div>
                <div class="filter-group"><i class="fas fa-user"></i><input type="text" id="assignedToSearch" placeholder="Assigned to..."></div>
                <div class="filter-group"><i class="fas fa-flag-checkered"></i>
                    <select id="statusFilter">
                        <option value="">All Statuses</option>
                        <option value="to do">To Do</option>
                        <option value="in progress">In Progress</option>
                        <option value="in review">In Review</option>
                        <option value="completed">Completed</option>
                        <option value="approved">Approved</option>
                        <option value="deleted">Deleted</option>
                        <option value="delete requested">Delete Requested</option>
                    </select>
                </div>
                <div class="filter-group"><i class="fas fa-exclamation-triangle"></i>
                    <select id="priorityFilter">
                        <option value="">All Priorities</option>
                        <option value="low">Low</option>
                        <option value="medium">Normal</option>
                        <option value="high">High</option>
                        <option value="critical">Urgent</option>
                    </select>
                </div>
                <!-- CREATION FILTER: new filter group -->
                <div class="filter-group creation-filter">
                    <i class="fas fa-calendar-plus"></i>
                    <select id="createdFilter">
                        <option value="this_month" selected>This Month</option>
                        <option value="last_7_days">Last 7 Days</option>
                        <option value="all">All Tasks</option>
                    </select>
                </div>
            </div>
            <!-- KANBAN: View toggle and containers -->
            <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap;">
                <div class="view-toggle-group">
                    <button class="view-toggle active" data-view="table" onclick="switchView('table')">Table</button>
                    <button class="view-toggle" data-view="kanban" onclick="switchView('kanban')">Kanban</button>
                </div>
                <span class="task-filter-count" id="taskFilterCount">Showing all tasks</span>
            </div>

            <div id="tableView">
                <table class="task-table" id="tasksTable">
                    <thead><tr><th>Title</th><th>Assigned To</th><th>Due Date</th><th>Priority</th><th>Status</th></tr></thead>
                    <tbody id="tasksTableBody"></tbody>
                </table>
            </div>
            <div id="kanbanView" style="display:none;">
                <div class="kanban-board" id="kanbanBoard"></div>
            </div>
        </div>`;
    } else {
        html += `<div class="dm-section"><h4><i class="fas fa-tasks"></i> Tasks</h4><p style="color:var(--sub);">No tasks found for this project.</p></div>`;
    }
    
    // Defects Section
if (p.defects && p.defects.length) {
    html += `
    <div class="dm-section">
        <h4><i class="fas fa-bug"></i> Defects (${p.defects.length})</h4>
        <table class="task-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
    `;

    p.defects.forEach(d => {
        html += `
        <tr>
            <td>${escapeHtml(d.title)}</td>
            <td>${escapeHtml(d.defect_category)}</td>
            <td>${escapeHtml(d.priority)}</td>
            <td>${escapeHtml(d.status)}</td>
            <td>${escapeHtml(d.assigned_user || d.assigned)}</td>
            <td>${d.date_identified || '-'}</td>
        </tr>`;
    });

    html += `</tbody></table></div>`;
} else {
    html += `
    <div class="dm-section">
        <h4><i class="fas fa-bug"></i> Defects</h4>
        <p style="color:var(--sub);">No defects found for this project.</p>
    </div>`;
}
    
    // Sub-projects section (unchanged)
    if (p.sub_projects && p.sub_projects.length) {
        html += `<div class="dm-section"><h4><i class="fas fa-layer-group"></i> Sub-Projects (${p.sub_projects.length})</h4>`;
        p.sub_projects.forEach(sp => {
            html += `<div class="sub-card"><h5>${escapeHtml(sp.sub_project_name)}</h5>
                     <div style="font-size:12px;color:var(--sub);margin-bottom:8px;">${escapeHtml(sp.deliverable)} | Leader: ${escapeHtml(sp.leader_name || 'Unassigned')} | ${sp.start_date} → ${sp.end_date}</div>`;
            if (sp.tasks && sp.tasks.length) {
                html += `<table class="task-table" style="margin-top:8px;"><thead><tr><th>Task</th><th>Assigned</th><th>Due</th><th>Status</th></tr></thead><tbody>`;
                sp.tasks.forEach(t => {
                    html += `<tr><td>${escapeHtml(t.taskTitle)}</td><td>${escapeHtml(t.assigned_user || t.assignTo)}</td><td>${t.dueDate || '-'}</td><td>${escapeHtml(t.status)}</td></tr>`;
                });
                html += `</tbody></table>`;
            } else {
                html += `<p style="font-size:12px;color:var(--sub);margin-top:8px;">No tasks in this sub-project.</p>`;
            }
            html += `</div>`;
        });
        html += `</div>`;
    } else {
        html += `<div class="dm-section"><h4><i class="fas fa-layer-group"></i> Sub-Projects</h4><p style="color:var(--sub);">No sub-projects found.</p></div>`;
    }
    
    document.getElementById('modalBody').innerHTML = html;
    
    // Populate tasks table and attach filters if tasks exist
    if (p.tasks && p.tasks.length) {
        const tasks = p.tasks;
        const tbody = document.getElementById('tasksTableBody');
        const kanbanBoard = document.getElementById('kanbanBoard');
        const countDisplay = document.getElementById('taskFilterCount');
        
        // Helper: get task creation date
        function getTaskDate(task) {
            if (!task.created_at) return null;
            const date = new Date(task.created_at.replace(' ', 'T'));
            return isNaN(date.getTime()) ? null : date;
        }
        
        // Helper: creation filter matching
        function matchesCreationFilter(task, filter) {
            if (filter === 'all') return true;
            const taskDate = getTaskDate(task);
            if (!taskDate) return false;
            const now = new Date();
            if (filter === 'this_month') {
                return taskDate.getFullYear() === now.getFullYear() &&
                       taskDate.getMonth() === now.getMonth();
            }
            if (filter === 'last_7_days') {
                const startDate = new Date(now);
                startDate.setDate(now.getDate() - 7);
                startDate.setHours(0,0,0,0);
                return taskDate >= startDate;
            }
            return true;
        }
        
        // KANBAN: render function for both views
        function renderTasks() {
            const titleSearch = document.getElementById('taskTitleSearch').value.toLowerCase();
            const assignedSearch = document.getElementById('assignedToSearch').value.toLowerCase();
            const statusVal = document.getElementById('statusFilter').value.toLowerCase();
            const priorityVal = document.getElementById('priorityFilter').value.toLowerCase();
            const createdVal = document.getElementById('createdFilter').value;
            
            const filtered = tasks.filter(task => {
                const matchTitle = !titleSearch || (task.taskTitle && task.taskTitle.toLowerCase().includes(titleSearch));
                const matchAssigned = !assignedSearch || (task.assigned_user && task.assigned_user.toLowerCase().includes(assignedSearch)) || (task.assignTo && task.assignTo.toLowerCase().includes(assignedSearch));
                const matchStatus = !statusVal || (task.status && task.status.toLowerCase() === statusVal);
                
                // Priority mapping: "medium" -> "normal", "critical" -> "urgent"
                let matchPriority = true;
                if (priorityVal) {
                    let taskPriority = task.priority ? task.priority.toLowerCase() : '';
                    if (priorityVal === 'medium') {
                        matchPriority = taskPriority === 'normal';
                    } else if (priorityVal === 'critical') {
                        matchPriority = taskPriority === 'urgent';
                    } else {
                        matchPriority = taskPriority === priorityVal;
                    }
                }
                
                const matchCreated = matchesCreationFilter(task, createdVal);
                
                return matchTitle && matchAssigned && matchStatus && matchPriority && matchCreated;
            });
            
            // Sort by created_at descending (newest first)
            filtered.sort((a, b) => {
                const da = getTaskDate(a);
                const db = getTaskDate(b);
                if (!da && !db) return 0;
                if (!da) return 1;
                if (!db) return -1;
                return db.getTime() - da.getTime();
            });
            
            // Update table
            tbody.innerHTML = '';
            if (filtered.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--sub);">No tasks match the filters</td></tr>';
            } else {
                filtered.forEach(t => {
                    const row = `<tr>
                        <td>${escapeHtml(t.taskTitle)}</td>
                        <td>${escapeHtml(t.assigned_user || t.assignTo)}</td>
                        <td>${t.dueDate || '-'}</td>
                        <td>${escapeHtml(t.priority)}</td>
                        <td>${escapeHtml(t.status)}</td>
                    </tr>`;
                    tbody.insertAdjacentHTML('beforeend', row);
                });
            }
            
            // Update Kanban
            renderKanban(filtered);
            
            // Update count
            countDisplay.textContent = `Showing ${filtered.length} of ${tasks.length} tasks`;
        }
        
        // KANBAN: render kanban function
        function renderKanban(filtered) {
            const groups = {};
            const statusOrder = ['to do', 'in progress', 'in review', 'completed', 'approved', 'deleted', 'delete requested'];
            statusOrder.forEach(s => groups[s] = []);
            
            filtered.forEach(task => {
                const status = (task.status || '').toLowerCase();
                if (groups[status]) {
                    groups[status].push(task);
                } else {
                    if (!groups['other']) groups['other'] = [];
                    groups['other'].push(task);
                }
            });
            
            let columnsHtml = '';
            statusOrder.forEach(status => {
                const tasksInColumn = groups[status] || [];
                if (tasksInColumn.length === 0) return;
                
                const count = tasksInColumn.length;
                const label = status.charAt(0).toUpperCase() + status.slice(1);
                
                columnsHtml += `
                    <div class="kanban-column">
                        <div class="kanban-column-header">
                            <span>${label}</span>
                            <span>${count}</span>
                        </div>
                `;
                
                tasksInColumn.forEach(task => {
                    let priorityClass = '';
                    const pri = (task.priority || '').toLowerCase();
                    if (pri === 'low' || pri === 'normal') priorityClass = 'priority-low';
                    else if (pri === 'medium') priorityClass = 'priority-medium';
                    else if (pri === 'high') priorityClass = 'priority-high';
                    else if (pri === 'critical' || pri === 'urgent') priorityClass = 'priority-critical';
                    
                    columnsHtml += `
                        <div class="kanban-card">
                            <div class="kanban-card-title">${escapeHtml(task.taskTitle || 'Untitled')}</div>
                            <div class="kanban-card-assignee"><i class="fas fa-user"></i> ${escapeHtml(task.assigned_user || task.assignTo || 'Unassigned')}</div>
                            <div class="kanban-card-meta">
                                <span>${task.dueDate ? escapeHtml(task.dueDate) : '-'}</span>
                                <span class="kanban-card-priority ${priorityClass}">${escapeHtml(task.priority || 'N/A')}</span>
                            </div>
                        </div>
                    `;
                });
                
                columnsHtml += `</div>`;
            });
            
            if (columnsHtml === '') {
                columnsHtml = `
                    <div style="padding:20px; color:var(--sub); text-align:center; width:100%;">
                        <i class="fas fa-filter" style="margin-right:6px;"></i>
                        No tasks match the selected filters.
                    </div>
                `;
            }
            
            kanbanBoard.innerHTML = columnsHtml;
        }
        
        // Store render function globally for switchView
        window._renderTasks = renderTasks;
        
        // Initial render (default view: table)
        renderTasks();
        
        // Attach event listeners
        const titleInput = document.getElementById('taskTitleSearch');
        const assignedInput = document.getElementById('assignedToSearch');
        const statusSelect = document.getElementById('statusFilter');
        const prioritySelect = document.getElementById('priorityFilter');
        const createdSelect = document.getElementById('createdFilter');
        
        if (titleInput) titleInput.addEventListener('input', renderTasks);
        if (assignedInput) assignedInput.addEventListener('input', renderTasks);
        if (statusSelect) statusSelect.addEventListener('change', renderTasks);
        if (prioritySelect) prioritySelect.addEventListener('change', renderTasks);
        if (createdSelect) createdSelect.addEventListener('change', renderTasks);
    }
    
    document.getElementById('detailModal').classList.add('open');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// Charts
<?php
$activeCount = $soonCount = $endedCount = 0;
foreach ($projects as $p) {
    $endTs = strtotime($p['end_date']);
    if ($endTs < time()) $endedCount++;
    elseif ($endTs < strtotime('+7 days')) $soonCount++;
    else $activeCount++;
}
$taskLabels = [];
$taskData = [];
foreach ($projects as $p) {
    $taskLabels[] = addslashes(substr($p['project_name'], 0, 20));
    $taskData[] = $p['total_tasks_count'] > 0 ? round(($p['completed_tasks_count'] / $p['total_tasks_count']) * 100) : 0;
}
?>
const taskCtx = document.getElementById('taskProgressChart');
if (taskCtx) {
    new Chart(taskCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($taskLabels) ?>,
            datasets: [{ label: 'Tasks Completed (%)', data: <?= json_encode($taskData) ?>, backgroundColor: '#2563eb', borderRadius: 8 }]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'top' } }, scales: { y: { max: 100, ticks: { callback: v => v + '%' } } } }
    });
}
const statusCtx = document.getElementById('projectStatusChart');
if (statusCtx) {
    new Chart(statusCtx, {
        type: 'doughnut',
        data: { labels: ['Active', 'Due Soon', 'Ended'], datasets: [{ data: [<?= $activeCount ?>, <?= $soonCount ?>, <?= $endedCount ?>], backgroundColor: ['#059669', '#d97706', '#64748b'], borderWidth: 0 }] },
        options: { cutout: '65%', plugins: { legend: { position: 'bottom' } } }
    });
}
// ================= KPI FILTERING =================
document.querySelectorAll('.kpi').forEach(kpi => {
    kpi.addEventListener('click', () => {

        const filter = kpi.dataset.filter;
        const cards = document.querySelectorAll('.proj-card');

        cards.forEach(card => {
            let show = true;

            switch (filter) {
                case 'active':
                    show = card.dataset.status === 'active';
                    break;

                case 'completed':
                    show = card.dataset.completed === 'yes';
                    break;

                case 'subprojects':
                    show = card.dataset.subprojects === 'yes';
                    break;

                case 'all':
                default:
                    show = true;
            }

            card.style.display = show ? '' : 'none';
        });

        // Highlight active KPI
        document.querySelectorAll('.kpi').forEach(k => k.classList.remove('active-kpi'));
        kpi.classList.add('active-kpi');
    });
});
</script>
</body>
</html>