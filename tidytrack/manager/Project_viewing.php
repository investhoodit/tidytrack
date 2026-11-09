<?php
session_start();
require_once __DIR__ . '/../dp.php';
require_once __DIR__ . '/teams_schema.php';

/* ==================== Auth ==================== */
$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['name'] ?? 'Manager';
$userRole = strtolower(trim($_SESSION['role'] ?? ''));

if (!$userId || $userRole !== 'manager') {
    header("Location: ../login.php");
    exit;
}

function e($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

// Loaded inside manager_index.php (fetch with X-Requested-With: fetch) or opened directly
$embedded = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

tt_ensure_team_tables($conn);
$taskHasTeam = tt_column_exists($conn, 'tasks', 'team_id');

/* ==================== Projects ==================== */
$projects = [];
$q = $conn->query("
    SELECT p.project_id, p.project_name, p.deliverable, p.start_date, p.end_date,
           COALESCE(la.leader_names, 'Unassigned') AS leader_names,
           c.name AS creator_name,
           (SELECT COUNT(*) FROM sub_projects sp WHERE sp.project_id = p.project_id) AS sub_count
    FROM projects_x p
    LEFT JOIN (
        SELECT pl.project_id, GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS leader_names
        FROM project_leader pl JOIN users u ON u.user_id = pl.teamLeadder_id
        GROUP BY pl.project_id
    ) la ON la.project_id = p.project_id
    LEFT JOIN users c ON c.user_id = p.created_by
    ORDER BY (p.end_date >= CURDATE()) DESC, p.project_name ASC
");
while ($r = $q->fetch_assoc()) {
    $projects[] = [
        'id'          => (int)$r['project_id'],
        'name'        => $r['project_name'],
        'deliverable' => $r['deliverable'],
        'start'       => $r['start_date'],
        'end'         => $r['end_date'],
        'leaders'     => $r['leader_names'],
        'creator'     => $r['creator_name'],
        'sub_count'   => (int)$r['sub_count'],
    ];
}

/* ==================== Teams ==================== */
$teams = tt_fetch_teams($conn);

/* ==================== Tasks (one query, not one per project) ==================== */
$tasks = [];
$q = $conn->query("
    SELECT t.task_id, t.taskTitle, t.status, t.priority, t.dueDate, t.created_at,
           t.project_id, t.sub_project_id,
           COALESCE(NULLIF(u.name,''), t.assignTo) AS assignee
           " . ($taskHasTeam ? ", t.team_id" : "") . "
    FROM tasks t
    LEFT JOIN users u ON u.user_id = t.assignTo
    ORDER BY t.created_at DESC
");
while ($r = $q->fetch_assoc()) {
    $tasks[] = [
        'id'       => (int)$r['task_id'],
        'title'    => $r['taskTitle'] ?: 'Untitled task',
        'status'   => $r['status'],
        'priority' => $r['priority'],
        'due'      => $r['dueDate'],
        'created'  => $r['created_at'],
        'project'  => $r['project_id'] !== null ? (int)$r['project_id'] : null,
        'sub'      => $r['sub_project_id'] !== null ? (int)$r['sub_project_id'] : null,
        'assignee' => $r['assignee'],
        'team'     => ($taskHasTeam && $r['team_id'] !== null) ? (int)$r['team_id'] : null,
    ];
}

/* ==================== Sub-projects & defects (for the project drawer) ==================== */
$subProjects = [];
$q = $conn->query("
    SELECT s.sub_project_id, s.project_id, s.sub_project_name, s.deliverable, s.start_date, s.end_date, u.name AS leader_name
    FROM sub_projects s
    LEFT JOIN users u ON u.user_id = s.teamLeadder_id
    ORDER BY s.start_date ASC
");
while ($r = $q->fetch_assoc()) {
    $subProjects[] = [
        'id' => (int)$r['sub_project_id'], 'project' => (int)$r['project_id'], 'name' => $r['sub_project_name'],
        'deliverable' => $r['deliverable'], 'start' => $r['start_date'], 'end' => $r['end_date'], 'leader' => $r['leader_name'],
    ];
}

$defects = [];
if (tt_table_exists($conn, 'project_defects')) {
    $q = $conn->query("
        SELECT d.project_name, d.title, d.status, d.priority, d.defect_category, d.date_identified, u.name AS assigned_user
        FROM project_defects d
        LEFT JOIN users u ON u.user_id = d.assigned_user_id
        ORDER BY d.date_identified DESC
    ");
    while ($r = $q->fetch_assoc()) $defects[] = $r;
}

$J = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Project Board</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<?php if (!$embedded): ?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<style>body{margin:0;padding:20px;background:#f4f6f4;font-family:'Poppins',sans-serif}</style>
<?php endif; ?>

<style>
/* Scoped under .pb so nothing leaks into manager_index.php */
.pb{
  --bg:#f4f6f4; --card:#fff; --soft:#f1f4f2; --col:#eef2ef; --border:#e6ebe7; --text:#111a14; --muted:#7b867f;
  --g900:#0f3d2a; --g700:#1b6b45; --g500:#2f9e6b; --g100:#e3f4ea;
  --amber:#b8730a; --amber-bg:#fff4dc; --red:#c92a2a; --red-bg:#ffe8e8; --slate:#5c6b63; --slate-bg:#eef1ef;
  --r:22px; --r-sm:12px; --shadow:0 1px 2px rgba(16,40,26,.04),0 8px 24px rgba(16,40,26,.05);
  color:var(--text); font-family:inherit; line-height:1.4;
}
body.dark .pb{ --bg:#0f172a; --card:#1e293b; --soft:#273449; --col:#172234; --border:#334155; --text:#f1f5f9; --muted:#94a3b8;
  --g100:rgba(47,158,107,.18); --amber-bg:rgba(245,159,0,.15); --red-bg:rgba(240,62,62,.16); --slate-bg:rgba(148,163,184,.14); --slate:#cbd5e1; }
.pb *,.pb *::before,.pb *::after{box-sizing:border-box}
.pb h1,.pb h2,.pb h3,.pb h4,.pb p{margin:0}
.pb button{font-family:inherit}
.pb a{color:inherit}

.pb-back{display:inline-flex;gap:8px;align-items:center;font-size:13px;color:var(--muted);text-decoration:none;margin-bottom:12px}

/* ---------- Brand header ---------- */
.pb-top{display:grid;grid-template-columns:auto 1fr auto;gap:18px;align-items:center;background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:16px 20px;box-shadow:var(--shadow);margin-bottom:14px}
.pb-brand{display:flex;align-items:center;gap:12px}
.pb-logo{width:44px;height:44px;border-radius:13px;background:linear-gradient(140deg,var(--g700),var(--g900));display:grid;place-items:center;color:#fff;font-size:18px}
.pb-brand h1{font-size:20px;font-weight:600;letter-spacing:-.02em}
.pb-brand small{display:block;font-size:11px;letter-spacing:.12em;color:var(--muted);font-weight:500}
.pb-views{justify-self:center;display:inline-flex;background:var(--soft);border-radius:999px;padding:4px;gap:2px;flex-wrap:wrap}
.pb-views button{border:none;background:transparent;padding:9px 16px;border-radius:999px;font-size:13px;color:var(--muted);cursor:pointer;display:inline-flex;gap:8px;align-items:center;transition:all .2s}
.pb-views button.active{background:var(--g700);color:#fff;box-shadow:0 4px 12px rgba(15,61,42,.25)}
.pb-views button:not(.active):hover{color:var(--text)}
.pb-ring{display:flex;align-items:center;gap:12px}
.pb-ring svg{width:56px;height:56px;transform:rotate(-90deg)}
.pb-ring circle{fill:none;stroke-width:6}
.pb-ring .track{stroke:var(--soft)}
.pb-ring .val{stroke:var(--g500);stroke-linecap:round;transition:stroke-dashoffset .6s ease}
.pb-ring-wrap{position:relative}
.pb-ring-wrap b{position:absolute;inset:0;display:grid;place-items:center;font-size:13px;font-weight:600}
.pb-ring-txt small{display:block;font-size:11px;color:var(--muted)}
.pb-ring-txt span{font-size:13.5px;font-weight:500}
.pb-new{display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 16px;border-radius:999px;background:transparent;border:1.5px solid var(--g700);color:var(--g700)!important;text-decoration:none;font-size:13px;font-weight:500;white-space:nowrap}
.pb-new:hover{background:var(--g100)}
body.dark .pb .pb-new{border-color:#7fd1a8;color:#7fd1a8!important}

/* ---------- Filters ---------- */
.pb-filters{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:12px;box-shadow:var(--shadow);margin-bottom:12px}
.pb-frow{display:grid;grid-template-columns:minmax(200px,1.6fr) repeat(4,minmax(130px,1fr)) auto;gap:8px}
.pb-f{display:flex;flex-direction:column;gap:3px;background:var(--soft);border:1px solid transparent;border-radius:14px;padding:7px 12px;min-width:0;transition:border-color .2s}
.pb-f:focus-within{border-color:var(--g500);background:var(--card)}
.pb-f label{font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:500}
.pb-f input,.pb-f select{border:none;background:transparent;outline:none;font:inherit;font-size:13px;color:var(--text);padding:0;width:100%;min-width:0}
.pb-f select option{color:#111}
.pb-f.on{border-color:var(--g500)}
.pb-more{border:1px dashed var(--border);background:transparent;border-radius:14px;padding:7px 14px;cursor:pointer;text-align:left;color:var(--text);min-width:150px}
.pb-more small{display:block;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:500}
.pb-more span{font-size:13px}
.pb-more.open,.pb-more:hover{border-color:var(--g500)}
.pb-frow2{display:none;grid-template-columns:repeat(4,minmax(140px,1fr)) auto;gap:8px;margin-top:8px}
.pb-filters.more .pb-frow2{display:grid}
.pb-clear{border:none;background:transparent;color:var(--red);font-size:13px;cursor:pointer;padding:0 12px;border-radius:12px}
.pb-clear:hover{background:var(--red-bg)}

.pb-legend{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--muted);margin:0 4px 12px}
.pb-legend > div{display:flex;gap:14px;flex-wrap:wrap}
.pb-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}

/* ---------- Board ---------- */
.pb-board{display:flex;gap:12px;overflow-x:auto;padding:2px 2px 14px;scroll-snap-type:x proximity;align-items:flex-start}
.pb-board::-webkit-scrollbar{height:10px}
.pb-board::-webkit-scrollbar-thumb{background:var(--border);border-radius:10px}
.pb-col{flex:0 0 272px;background:var(--col);border-radius:18px;padding:10px;scroll-snap-align:start;max-height:calc(100vh - 150px);display:flex;flex-direction:column}
.pb-col-head{padding:8px 8px 10px;border-radius:12px;cursor:pointer;transition:background .2s;position:relative}
.pb-col-head:hover{background:var(--card)}
.pb-col-head h3{font-size:14.5px;font-weight:600;padding-right:28px;line-height:1.25}
.pb-col-head p{font-size:11.5px;color:var(--muted);margin-top:3px}
.pb-col-meta{display:flex;justify-content:space-between;align-items:center;margin-top:8px;font-size:11.5px;color:var(--muted)}
.pb-col-bar{height:4px;background:var(--card);border-radius:4px;overflow:hidden;margin-top:6px}
.pb-col-bar span{display:block;height:100%;background:var(--g500);border-radius:4px}
.pb-col-info{position:absolute;right:6px;top:6px;width:26px;height:26px;border-radius:8px;border:none;background:transparent;color:var(--muted);cursor:pointer;display:grid;place-items:center}
.pb-col-info:hover{background:var(--soft);color:var(--g700)}
.pb-col-tag{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
.pb-col-tag span{font-size:10.5px;padding:2px 7px;border-radius:999px;background:var(--card);border:1px solid var(--border);color:var(--muted)}
.pb-col-tag span.warn{border-style:dashed;color:var(--amber)}
.pb-col-body{overflow-y:auto;display:flex;flex-direction:column;gap:8px;padding:2px}
.pb-col-empty{font-size:12px;color:var(--muted);text-align:center;padding:18px 6px;border:1.5px dashed var(--border);border-radius:12px}
.pb-col.st-head .pb-col-head{cursor:default}
.pb-col.st-head .pb-col-head:hover{background:transparent}
.pb-count{font-size:12px;font-weight:600;padding:1px 9px;border-radius:999px;background:var(--card)}

/* Status tones */
.tone-done{--tone:var(--g500);--tone-bg:var(--g100)}
.tone-amber{--tone:var(--amber);--tone-bg:var(--amber-bg)}
.tone-red{--tone:var(--red);--tone-bg:var(--red-bg)}
.tone-todo{--tone:var(--slate);--tone-bg:var(--slate-bg)}

/* Card */
.pb-card{background:var(--card);border-radius:14px;padding:11px 12px 10px 14px;border:1px solid var(--border);position:relative;cursor:default;transition:box-shadow .2s,transform .2s}
.pb-card::before{content:"";position:absolute;left:0;top:10px;bottom:10px;width:4px;border-radius:0 4px 4px 0;background:var(--tone)}
.pb-card:hover{box-shadow:0 8px 20px rgba(16,40,26,.08);transform:translateY(-1px)}
.pb-status{display:inline-flex;align-items:center;gap:6px;font-size:10.5px;font-weight:600;letter-spacing:.03em;text-transform:uppercase;color:var(--tone);background:var(--tone-bg);padding:3px 8px;border-radius:6px}
.pb-status.blocked{--tone:var(--red);--tone-bg:var(--red-bg)}
.pb-card h4{font-size:13.5px;font-weight:500;margin:7px 0 4px;line-height:1.35;word-break:break-word}
.pb-card .pb-proj{font-size:11.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pb-card-foot{display:flex;align-items:center;gap:6px;margin-top:8px;font-size:11px;color:var(--muted)}
.pb-id{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:var(--muted)}
.pb-pri{font-size:10.5px;padding:2px 7px;border-radius:999px;background:var(--slate-bg);color:var(--slate);text-transform:capitalize}
.pb-pri.high,.pb-pri.critical,.pb-pri.urgent{background:var(--red-bg);color:var(--red)}
.pb-pri.medium{background:var(--amber-bg);color:var(--amber)}
.pb-av{margin-left:auto;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:10px;font-weight:600}
.pb-due.late{color:var(--red);font-weight:500}
.pb-more-btn{border:none;background:var(--card);border-radius:10px;padding:8px;font-size:12px;color:var(--g700);cursor:pointer}
.pb-more-btn:hover{background:var(--g100)}
.pb-group{font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:600;margin:6px 4px 0}

.pb-empty{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:48px 20px;text-align:center;color:var(--muted)}
.pb-empty i{font-size:30px;opacity:.4;display:block;margin-bottom:10px}
.pb-note{font-size:12px;color:var(--muted);margin:0 4px 10px;display:flex;gap:8px;align-items:flex-start}

/* ---------- Drawer ---------- */
.pb-drawer-bg{position:fixed;inset:0;background:rgba(8,20,14,.45);z-index:9998;opacity:0;pointer-events:none;transition:opacity .25s}
.pb-drawer{position:fixed;top:0;right:0;bottom:0;width:min(560px,100%);background:var(--card);color:var(--text);z-index:9999;transform:translateX(100%);transition:transform .3s cubic-bezier(.2,.75,.25,1);display:flex;flex-direction:column;box-shadow:-20px 0 60px rgba(0,0,0,.18)}
.pb.drawer-open .pb-drawer-bg{opacity:1;pointer-events:auto}
.pb.drawer-open .pb-drawer{transform:none}
.pb-dh{padding:20px 22px 14px;border-bottom:1px solid var(--border);display:flex;gap:12px;align-items:flex-start}
.pb-dh h2{font-size:19px;font-weight:600;letter-spacing:-.01em}
.pb-dh p{font-size:12.5px;color:var(--muted);margin-top:4px}
.pb-x{margin-left:auto;width:36px;height:36px;border-radius:10px;border:1px solid var(--border);background:transparent;color:var(--muted);cursor:pointer;flex-shrink:0}
.pb-x:hover{background:var(--soft);color:var(--text)}
.pb-db{padding:18px 22px;overflow:auto;flex:1;display:grid;gap:18px;align-content:start}
.pb-db h4{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:600;margin-bottom:8px}
.pb-kv{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.pb-kv div{background:var(--soft);border-radius:12px;padding:10px 12px;font-size:13px}
.pb-kv small{display:block;font-size:10.5px;color:var(--muted);letter-spacing:.05em;text-transform:uppercase;margin-bottom:2px}
.pb-prog{display:grid;gap:10px}
.pb-prog-row{font-size:12px;color:var(--muted)}
.pb-prog-row div{display:flex;justify-content:space-between;margin-bottom:4px}
.pb-prog-row .bar{height:7px;background:var(--soft);border-radius:6px;overflow:hidden}
.pb-prog-row .bar span{display:block;height:100%;border-radius:6px}
.pb-stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:6px}
.pb-stat-grid div{border-radius:12px;padding:8px;text-align:center;background:var(--tone-bg);color:var(--tone)}
.pb-stat-grid b{display:block;font-size:18px}
.pb-stat-grid small{font-size:10px}
.pb-list{display:grid;gap:8px}
.pb-li{border:1px solid var(--border);border-radius:12px;padding:10px 12px;font-size:13px}
.pb-li small{display:block;color:var(--muted);font-size:11.5px;margin-top:2px}
.pb-chips{display:flex;flex-wrap:wrap;gap:6px}
.pb-chips span{font-size:12px;padding:4px 10px;border-radius:999px;background:var(--g100);color:var(--g700)}
body.dark .pb .pb-chips span{color:#7fd1a8}
.pb-df{padding:14px 22px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap}
.pb-btn{display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 15px;border-radius:999px;font-size:13px;font-weight:500;cursor:pointer;border:1.5px solid var(--border);background:var(--card);color:var(--text);text-decoration:none}
.pb-btn:hover{border-color:var(--g500)}
.pb-btn.primary{background:var(--g700);border-color:var(--g700);color:#fff}
.pb-btn.danger{color:var(--red)}
.pb-btn.danger:hover{background:var(--red-bg);border-color:var(--red)}

@media (max-width:1180px){
  .pb-top{grid-template-columns:1fr auto}
  .pb-views{grid-column:1/-1;grid-row:2;justify-self:stretch;justify-content:center}
  .pb-frow{grid-template-columns:repeat(3,minmax(0,1fr))}
  .pb-frow .pb-f:first-child{grid-column:1/-1}
  .pb-frow2{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width:620px){
  .pb-top{grid-template-columns:1fr}
  .pb-ring{justify-content:space-between}
  .pb-views button{padding:8px 10px;font-size:12px}
  .pb-frow,.pb-frow2{grid-template-columns:1fr 1fr}
  .pb-col{flex-basis:84vw}
  .pb-kv{grid-template-columns:1fr}
  .pb-stat-grid{grid-template-columns:repeat(3,1fr)}
}
@media (prefers-reduced-motion:reduce){.pb *{transition:none!important}}
</style>
</head>
<body>
<div class="pb" id="pbRoot">
  <?php if (!$embedded): ?>
    <a class="pb-back" href="manager_index.php"><i class="fas fa-arrow-left"></i> Back to dashboard</a>
  <?php endif; ?>

  <!-- ===== Header ===== -->
  <header class="pb-top">
    <div class="pb-brand">
      <span class="pb-logo"><i class="fas fa-table-columns"></i></span>
      <div><h1>Tidy Track</h1><small>INVESTHOOD IT / PROJECT BOARD</small></div>
    </div>
    <nav class="pb-views" id="pbViews" aria-label="Board view">
      <button type="button" data-view="projects"><i class="fas fa-folder-open"></i> All Projects View</button>
      <button type="button" data-view="teams"><i class="fas fa-people-group"></i> All Teams View</button>
      <button type="button" data-view="status"><i class="fas fa-list-check"></i> All Status View</button>
    </nav>
    <div class="pb-ring">
      <div class="pb-ring-wrap">
        <svg viewBox="0 0 56 56"><circle class="track" cx="28" cy="28" r="24"/><circle class="val" id="pbRingVal" cx="28" cy="28" r="24" stroke-dasharray="150.8" stroke-dashoffset="150.8"/></svg>
        <b id="pbRingPct">0%</b>
      </div>
      <div class="pb-ring-txt"><small>Portfolio completion</small><span id="pbRingLabel">—</span></div>
      <a href="managerProjects.php" data-page="managerProjects.php" class="pb-new"><i class="fas fa-plus"></i> Projects &amp; teams</a>
    </div>
  </header>

  <!-- ===== Filters ===== -->
  <section class="pb-filters" id="pbFilters">
    <div class="pb-frow">
      <div class="pb-f"><label for="fSearch">Search</label><input id="fSearch" type="search" placeholder="Task title / ID (e.g. TT-224)"></div>
      <div class="pb-f"><label for="fProject">Project</label><select id="fProject"></select></div>
      <div class="pb-f"><label for="fTeam">Team</label><select id="fTeam"></select></div>
      <div class="pb-f"><label for="fStatus">Status</label><select id="fStatus"></select></div>
      <div class="pb-f"><label for="fPriority">Priority</label><select id="fPriority"></select></div>
      <button type="button" class="pb-more" id="pbMoreBtn"><small>More filters</small><span id="pbMoreTxt">Assignee / dates</span></button>
    </div>
    <div class="pb-frow2">
      <div class="pb-f"><label for="fAssignee">Assignee</label><select id="fAssignee"></select></div>
      <div class="pb-f"><label for="fCreated">Created</label>
        <select id="fCreated"><option value="all">Any time</option><option value="last_7_days">Last 7 days</option><option value="this_month">This month</option></select></div>
      <div class="pb-f"><label for="fDueFrom">Due from</label><input id="fDueFrom" type="date"></div>
      <div class="pb-f"><label for="fDueTo">Due to</label><input id="fDueTo" type="date"></div>
      <button type="button" class="pb-clear" id="pbClear"><i class="fas fa-xmark"></i> Clear all</button>
    </div>
  </section>

  <div class="pb-legend">
    <div>
      <span><i style="background:var(--g500)"></i>Done</span>
      <span><i style="background:var(--amber)"></i>Ongoing / review</span>
      <span><i style="background:var(--red)"></i>Blocked / high priority</span>
      <span><i style="background:var(--slate)"></i>To do</span>
    </div>
    <span id="pbShown"></span>
  </div>

  <div id="pbNote"></div>
  <div class="pb-board" id="pbBoard"></div>

  <!-- ===== Project drawer ===== -->
  <div class="pb-drawer-bg" id="pbDrawerBg"></div>
  <aside class="pb-drawer" id="pbDrawer" aria-hidden="true">
    <div class="pb-dh">
      <div><h2 id="pbDTitle">Project</h2><p id="pbDSub"></p></div>
      <button type="button" class="pb-x" id="pbDClose" title="Close"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="pb-db" id="pbDBody"></div>
    <div class="pb-df" id="pbDFoot"></div>
  </aside>
</div>

<script>
/* IIFE so manager_index.php can re-run this script without "already declared" errors. */
(function(){
  const root = document.getElementById('pbRoot');
  if (!root) return;

  const DATA = {
    projects: <?= json_encode($projects, $J) ?>,
    teams:    <?= json_encode($teams, $J) ?>,
    tasks:    <?= json_encode($tasks, $J) ?>,
    subs:     <?= json_encode($subProjects, $J) ?>,
    defects:  <?= json_encode($defects, $J) ?>,
    taskHasTeam: <?= $taskHasTeam ? 'true' : 'false' ?>,
  };

  const STATUSES = [
    { key:'todo',     label:'To Do',               tone:'todo'  },
    { key:'progress', label:'In Progress',         tone:'amber' },
    { key:'review',   label:'In Review / Testing', tone:'amber' },
    { key:'blocked',  label:'Blocked',             tone:'red'   },
    { key:'done',     label:'Done',                tone:'done'  },
  ];
  const STATUS_BY_KEY = Object.fromEntries(STATUSES.map(s => [s.key, s]));
  const PRIORITY_ORDER = ['critical','urgent','high','medium','low'];
  const PAGE = 25;

  const $ = (id) => document.getElementById(id);
  const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
  const hue = (s) => { let h = 0; for (const c of String(s)) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };
  const initials = (s) => String(s || '').trim().split(/\s+/).slice(0, 2).map(w => w[0] || '').join('').toUpperCase() || '?';
  const fmt = (d) => { if (!d) return ''; const x = new Date(String(d).replace(' ', 'T')); return isNaN(x) ? d : x.toLocaleDateString('en-ZA', { day:'numeric', month:'short' }); };
  const fmtLong = (d) => { if (!d) return '—'; const x = new Date(String(d).replace(' ', 'T')); return isNaN(x) ? d : x.toLocaleDateString('en-ZA', { day:'numeric', month:'short', year:'numeric' }); };
  const today = new Date(); today.setHours(0, 0, 0, 0);

  /* ---------- Normalise data ---------- */
  function statusKey(raw){
    const s = String(raw || '').toLowerCase().replace(/[\s_\-\/]+/g, '');
    if (['completed','done','approved','closed','complete'].includes(s)) return 'done';
    if (['blocked','onhold','hold','stuck'].includes(s)) return 'blocked';
    if (s.includes('review') || s.includes('testing') || s === 'qa' || s === 'test') return 'review';
    if (['inprogress','ongoing','started','active','progress','doing','working'].includes(s)) return 'progress';
    return 'todo';
  }
  const projectById = new Map(DATA.projects.map(p => [p.id, p]));
  const teamById    = new Map(DATA.teams.map(t => [t.team_id, t]));
  const teamsOfProject = new Map();
  DATA.teams.forEach(t => t.project_ids.forEach(pid => {
    if (!teamsOfProject.has(pid)) teamsOfProject.set(pid, []);
    teamsOfProject.get(pid).push(t.team_id);
  }));

  DATA.tasks.forEach(t => {
    t.code = 'TT-' + t.id;
    t.skey = statusKey(t.status);
    t.pkey = String(t.priority || '').toLowerCase().trim();
    // A task has an optional team; without tasks.team_id it inherits every team linked to its project
    t.teams = t.team ? [t.team] : (teamsOfProject.get(t.project) || []);
    t.dueDate = t.due ? new Date(String(t.due).replace(' ', 'T')) : null;
    t.late = t.skey !== 'done' && t.dueDate && !isNaN(t.dueDate) && t.dueDate < today;
    t.hay = `${t.code} ${t.title} ${t.assignee || ''} ${projectById.get(t.project)?.name || ''}`.toLowerCase();
  });

  /* ---------- State (view + filters remembered per browser) ---------- */
  const STORE = 'tidytrack_board_v1';
  const defaults = { view:'projects', q:'', project:'', team:'', status:'', priority:'', assignee:'', created:'all', dueFrom:'', dueTo:'', more:false };
  let S = { ...defaults };
  try { S = { ...defaults, ...JSON.parse(localStorage.getItem(STORE) || '{}') }; } catch (e) {}
  const save = () => { try { localStorage.setItem(STORE, JSON.stringify(S)); } catch (e) {} };
  const expanded = new Set();

  /* ---------- Filter controls ---------- */
  const opt = (v, label) => `<option value="${esc(v)}">${esc(label)}</option>`;
  $('fProject').innerHTML  = opt('', 'All projects') + DATA.projects.map(p => opt(p.id, p.name)).join('');
  $('fTeam').innerHTML     = opt('', 'All teams') + DATA.teams.map(t => opt(t.team_id, t.team_name)).join('') + opt('none', 'No team');
  $('fStatus').innerHTML   = opt('', 'All statuses') + STATUSES.map(s => opt(s.key, s.label)).join('');
  const priorities = [...new Set(DATA.tasks.map(t => t.pkey).filter(Boolean))]
    .sort((a, b) => (PRIORITY_ORDER.indexOf(a) + 99) % 99 - (PRIORITY_ORDER.indexOf(b) + 99) % 99);
  $('fPriority').innerHTML = opt('', 'All priorities') + priorities.map(p => opt(p, p[0].toUpperCase() + p.slice(1))).join('');
  const assignees = [...new Set(DATA.tasks.map(t => t.assignee).filter(Boolean))].sort((a, b) => a.localeCompare(b));
  $('fAssignee').innerHTML = opt('', 'Anyone') + assignees.map(a => opt(a, a)).join('');

  const controls = { q:'fSearch', project:'fProject', team:'fTeam', status:'fStatus', priority:'fPriority', assignee:'fAssignee', created:'fCreated', dueFrom:'fDueFrom', dueTo:'fDueTo' };
  function syncControls(){
    Object.entries(controls).forEach(([k, id]) => {
      const el = $(id);
      el.value = S[k];
      if (el.value !== String(S[k])) { S[k] = defaults[k]; el.value = S[k]; }   // stale saved value (deleted project etc.)
      el.closest('.pb-f').classList.toggle('on', S[k] !== defaults[k]);
    });
    $('pbFilters').classList.toggle('more', S.more);
    $('pbMoreBtn').classList.toggle('open', S.more);
    const extra = ['assignee','created','dueFrom','dueTo'].filter(k => S[k] !== defaults[k]).length;
    $('pbMoreTxt').textContent = extra ? `${extra} active` : 'Assignee / dates';
    root.querySelectorAll('#pbViews button').forEach(b => b.classList.toggle('active', b.dataset.view === S.view));
  }
  let qTimer;
  Object.entries(controls).forEach(([k, id]) => {
    $(id).addEventListener(k === 'q' ? 'input' : 'change', (e) => {
      S[k] = e.target.value;
      clearTimeout(qTimer);
      qTimer = setTimeout(render, k === 'q' ? 180 : 0);
    });
  });
  $('pbMoreBtn').addEventListener('click', () => { S.more = !S.more; syncControls(); save(); });
  $('pbClear').addEventListener('click', () => { S = { ...defaults, view:S.view, more:S.more }; expanded.clear(); render(); });
  $('pbViews').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-view]');
    if (b) { S.view = b.dataset.view; render(); }
  });

  /* ---------- Filtering ---------- */
  function createdMatch(t){
    if (S.created === 'all') return true;
    if (!t.created) return false;
    const d = new Date(String(t.created).replace(' ', 'T'));
    if (isNaN(d)) return false;
    const now = new Date();
    if (S.created === 'this_month') return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
    const from = new Date(now); from.setDate(now.getDate() - 7); from.setHours(0, 0, 0, 0);
    return d >= from;
  }
  function matches(t, skip = {}){
    const q = S.q.toLowerCase().trim();
    if (q && !t.hay.includes(q)) return false;
    if (!skip.project && S.project && t.project !== Number(S.project)) return false;
    if (!skip.team && S.team) {
      if (S.team === 'none' ? t.teams.length : !t.teams.includes(Number(S.team))) return false;
    }
    if (!skip.status && S.status && t.skey !== S.status) return false;
    if (S.priority && t.pkey !== S.priority) return false;
    if (S.assignee && t.assignee !== S.assignee) return false;
    if (!createdMatch(t)) return false;
    if (S.dueFrom && !(t.dueDate && t.dueDate >= new Date(S.dueFrom))) return false;
    if (S.dueTo && !(t.dueDate && t.dueDate <= new Date(S.dueTo + 'T23:59:59'))) return false;
    return true;
  }
  const taskFiltersActive = () => S.q || S.status || S.priority || S.assignee || S.created !== 'all' || S.dueFrom || S.dueTo;
  const sortTasks = (list) => list.sort((a, b) =>
    STATUSES.findIndex(s => s.key === a.skey) - STATUSES.findIndex(s => s.key === b.skey)
    || ((a.dueDate || 8.64e15) - (b.dueDate || 8.64e15)) || b.id - a.id);

  /* ---------- Card + column templates ---------- */
  function toneOf(t){
    if (t.skey === 'done') return 'done';
    if (t.skey === 'blocked' || ['high','critical','urgent'].includes(t.pkey)) return 'red';
    if (t.skey === 'todo') return 'todo';
    return 'amber';
  }
  function card(t, { showProject = true } = {}){
    const st = STATUS_BY_KEY[t.skey];
    const proj = projectById.get(t.project);
    const h = hue(t.assignee || '');
    return `
      <article class="pb-card tone-${toneOf(t)}" title="${esc(t.title)}">
        <span class="pb-status tone-${st.tone}">${esc(st.label)}</span>
        <h4>${esc(t.title)}</h4>
        ${showProject && proj ? `<div class="pb-proj"><i class="fas fa-folder" style="opacity:.6"></i> ${esc(proj.name)}</div>` : ''}
        <div class="pb-card-foot">
          <span class="pb-id">${t.code}</span>
          ${t.pkey ? `<span class="pb-pri ${esc(t.pkey)}">${esc(t.pkey)}</span>` : ''}
          ${t.due ? `<span class="pb-due ${t.late ? 'late' : ''}" title="Due date"><i class="far fa-calendar"></i> ${fmt(t.due)}</span>` : ''}
          ${t.assignee ? `<span class="pb-av" style="background:hsl(${h},65%,88%);color:hsl(${h},45%,28%)" title="${esc(t.assignee)}">${esc(initials(t.assignee))}</span>` : ''}
        </div>
      </article>`;
  }
  function cardsWithMore(key, list, opts){
    const open = expanded.has(key);
    const shown = open ? list : list.slice(0, PAGE);
    let html = shown.map(t => card(t, opts)).join('');
    if (list.length > PAGE) html += `<button type="button" class="pb-more-btn" data-expand="${esc(key)}">${open ? 'Show less' : `Show ${list.length - PAGE} more`}</button>`;
    return html || '<div class="pb-col-empty">No tasks</div>';
  }
  function progress(list){
    const done = list.filter(t => t.skey === 'done').length;
    return { done, pct: list.length ? Math.round(done * 100 / list.length) : 0 };
  }

  /* ---------- Views ---------- */
  function projectsView(){
    let projects = DATA.projects;
    if (S.project) projects = projects.filter(p => p.id === Number(S.project));
    if (S.team && S.team !== 'none') projects = projects.filter(p => (teamsOfProject.get(p.id) || []).includes(Number(S.team)));
    if (S.team === 'none') projects = projects.filter(p => !(teamsOfProject.get(p.id) || []).length);

    const cols = projects.map(p => {
      const list = sortTasks(DATA.tasks.filter(t => t.project === p.id && matches(t)));
      return { p, list };
    }).filter(c => !taskFiltersActive() || c.list.length);

    const orphan = sortTasks(DATA.tasks.filter(t => !projectById.has(t.project) && matches(t, { team:false })));
    return { count: cols.reduce((n, c) => n + c.list.length, 0) + (S.project ? 0 : orphan.length), html:
      cols.map(({ p, list }) => {
        const teams = (teamsOfProject.get(p.id) || []).map(id => teamById.get(id)?.team_name).filter(Boolean);
        const pr = progress(list);
        return `
          <section class="pb-col">
            <div class="pb-col-head" data-goto-project="${p.id}" title="Show this project's tasks by status">
              <h3>${esc(p.name)}</h3>
              <button type="button" class="pb-col-info" data-open-project="${p.id}" title="Project details"><i class="fas fa-circle-info"></i></button>
              <div class="pb-col-tag">${teams.length ? teams.map(n => `<span>${esc(n)}</span>`).join('') : '<span class="warn">No team assigned</span>'}</div>
              <div class="pb-col-meta"><span>${list.length} task${list.length === 1 ? '' : 's'}</span><span>${pr.pct}% done</span></div>
              <div class="pb-col-bar"><span style="width:${pr.pct}%"></span></div>
            </div>
            <div class="pb-col-body">${cardsWithMore('p' + p.id, list, { showProject:false })}</div>
          </section>`;
      }).join('') + (orphan.length && !S.project ? `
          <section class="pb-col">
            <div class="pb-col-head" style="cursor:default"><h3>No project</h3><div class="pb-col-meta"><span>${orphan.length} tasks</span></div></div>
            <div class="pb-col-body">${cardsWithMore('orphan', orphan, { showProject:false })}</div>
          </section>` : '') };
  }

  function teamsView(){
    let teams = DATA.teams;
    if (S.team && S.team !== 'none') teams = teams.filter(t => t.team_id === Number(S.team));
    if (S.team === 'none') teams = [];
    if (S.project) teams = teams.filter(t => t.project_ids.includes(Number(S.project)) || DATA.tasks.some(x => x.team === t.team_id && x.project === Number(S.project)));

    const cols = teams.map(team => ({ team, list: sortTasks(DATA.tasks.filter(t => t.teams.includes(team.team_id) && matches(t, { team:true }))) }))
      .filter(c => !taskFiltersActive() || c.list.length);
    const noTeam = (!S.team || S.team === 'none') ? sortTasks(DATA.tasks.filter(t => !t.teams.length && matches(t, { team:true }))) : [];

    const seen = new Set();
    cols.forEach(c => c.list.forEach(t => seen.add(t.id)));
    noTeam.forEach(t => seen.add(t.id));

    return { count: seen.size, html:
      cols.map(({ team, list }) => {
        const projs = team.project_ids.map(id => projectById.get(id)?.name).filter(Boolean);
        const pr = progress(list);
        return `
          <section class="pb-col">
            <div class="pb-col-head" data-goto-team="${team.team_id}" title="Show this team's tasks by status">
              <h3>${esc(team.team_name)}</h3>
              <p>${esc(team.description || 'No responsibilities set')}</p>
              <div class="pb-col-tag">${projs.length ? projs.map(n => `<span>${esc(n)}</span>`).join('') : '<span class="warn">Not on any project</span>'}</div>
              <div class="pb-col-meta"><span>${list.length} task${list.length === 1 ? '' : 's'} · ${team.members.length} member${team.members.length === 1 ? '' : 's'}</span><span>${pr.pct}% done</span></div>
              <div class="pb-col-bar"><span style="width:${pr.pct}%"></span></div>
            </div>
            <div class="pb-col-body">${cardsWithMore('t' + team.team_id, list)}</div>
          </section>`;
      }).join('') + (noTeam.length ? `
          <section class="pb-col">
            <div class="pb-col-head" data-goto-team="none" title="Tasks whose project has no team">
              <h3>No team</h3><p>Tasks on projects without a team. Assign teams in Projects &amp; Teams.</p>
              <div class="pb-col-meta"><span>${noTeam.length} tasks</span></div>
            </div>
            <div class="pb-col-body">${cardsWithMore('noteam', noTeam)}</div>
          </section>` : '') };
  }

  function statusView(){
    const list = DATA.tasks.filter(t => matches(t, { status:true }));
    const statuses = S.status ? STATUSES.filter(s => s.key === S.status) : STATUSES;
    return { count: statuses.reduce((n, s) => n + list.filter(t => t.skey === s.key).length, 0), html:
      statuses.map(s => {
        const col = sortTasks(list.filter(t => t.skey === s.key));
        return `
          <section class="pb-col st-head">
            <div class="pb-col-head">
              <div style="display:flex;justify-content:space-between;align-items:center">
                <span class="pb-status tone-${s.tone}" style="font-size:12px">${esc(s.label)}</span>
                <span class="pb-count">${col.length}</span>
              </div>
            </div>
            <div class="pb-col-body">${cardsWithMore('s' + s.key, col)}</div>
          </section>`;
      }).join('') };
  }

  /* ---------- Render ---------- */
  function render(){
    syncControls();
    save();

    const result = S.view === 'teams' ? teamsView() : S.view === 'status' ? statusView() : projectsView();
    const board = $('pbBoard');
    board.innerHTML = result.html || `<div class="pb-empty" style="flex:1"><i class="fas fa-filter-circle-xmark"></i>Nothing matches these filters.</div>`;

    // Completion ring follows the current filters (all tasks when no filter is set)
    const scope = DATA.tasks.filter(t => matches(t));
    const pr = progress(scope);
    const ring = $('pbRingVal');
    ring.setAttribute('stroke-dashoffset', (150.8 * (1 - pr.pct / 100)).toFixed(1));
    $('pbRingPct').textContent = pr.pct + '%';
    $('pbRingLabel').textContent = scope.length ? `${pr.done} of ${scope.length} done · ${pr.pct === 100 ? 'Complete' : 'Ongoing'}` : 'No tasks';
    $('pbShown').textContent = `${result.count} task${result.count === 1 ? '' : 's'} shown`;

    // Notes the BA review asks for
    const notes = [];
    if (S.view === 'teams' && !DATA.teams.length) notes.push('No teams yet. Create teams and link them to projects in <b>Projects &amp; Teams</b>.');
    if (S.view === 'teams' && DATA.teams.length && !DATA.taskHasTeam) notes.push('Tasks appear under every team linked to their project. To give each task its own team, run the optional step in <b>teams_migration.sql</b>.');
    $('pbNote').innerHTML = notes.map(n => `<div class="pb-note"><i class="fas fa-circle-info" style="margin-top:2px"></i><span>${n}</span></div>`).join('');
  }

  /* Heading clicks: show that project's / team's tasks by status (BA review boards 1 and 2) */
  $('pbBoard').addEventListener('click', (e) => {
    const info = e.target.closest('[data-open-project]');
    if (info) { e.stopPropagation(); openProject(Number(info.dataset.openProject)); return; }
    const more = e.target.closest('[data-expand]');
    if (more) { const k = more.dataset.expand; expanded.has(k) ? expanded.delete(k) : expanded.add(k); render(); return; }
    const p = e.target.closest('[data-goto-project]');
    if (p) { S.project = p.dataset.gotoProject; S.view = 'status'; render(); return; }
    const t = e.target.closest('[data-goto-team]');
    if (t) { S.team = t.dataset.gotoTeam; S.view = 'status'; render(); }
  });

  /* ---------- Project drawer ---------- */
  function openProject(id){
    const p = projectById.get(id);
    if (!p) return;
    const tasks = DATA.tasks.filter(t => t.project === id);
    const subs = DATA.subs.filter(s => s.project === id);
    const defects = DATA.defects.filter(d => d.project_name === p.name);
    const teams = (teamsOfProject.get(id) || []).map(tid => teamById.get(tid)).filter(Boolean);
    const pr = progress(tasks);

    const start = p.start ? new Date(p.start) : null, end = p.end ? new Date(p.end) : null, now = new Date();
    const timeline = (start && end && end > start) ? Math.max(0, Math.min(100, Math.round((now - start) * 100 / (end - start)))) : 0;
    const ended = end && end < today;
    const barColor = pr.pct < 40 ? 'var(--red)' : pr.pct < 80 ? 'var(--amber)' : 'var(--g500)';

    $('pbDTitle').textContent = p.name;
    $('pbDSub').textContent = `${ended ? 'Ended' : 'Running'} · ${fmtLong(p.start)} → ${fmtLong(p.end)}`;
    $('pbDBody').innerHTML = `
      <div class="pb-kv">
        <div><small>Deliverable</small>${esc(p.deliverable || '—')}</div>
        <div><small>Leaders</small>${esc(p.leaders || 'Unassigned')}</div>
        <div><small>Created by</small>${esc(p.creator || '—')}</div>
        <div><small>Sub-projects</small>${subs.length}</div>
      </div>
      <div>
        <h4>Teams on this project</h4>
        <div class="pb-chips">${teams.length ? teams.map(t => `<span>${esc(t.team_name)}</span>`).join('') : '<span style="background:var(--amber-bg);color:var(--amber)">No team assigned</span>'}</div>
      </div>
      <div class="pb-prog">
        <div class="pb-prog-row"><div><span>Timeline</span><span>${timeline}%</span></div><div class="bar"><span style="width:${timeline}%;background:var(--g700)"></span></div></div>
        <div class="pb-prog-row"><div><span>Tasks done</span><span>${pr.done}/${tasks.length} · ${pr.pct}%</span></div><div class="bar"><span style="width:${pr.pct}%;background:${barColor}"></span></div></div>
      </div>
      <div>
        <h4>Tasks by status</h4>
        <div class="pb-stat-grid">${STATUSES.map(s => `<div class="tone-${s.tone}"><b>${tasks.filter(t => t.skey === s.key).length}</b><small>${esc(s.label)}</small></div>`).join('')}</div>
      </div>
      <div>
        <h4>Sub-projects</h4>
        <div class="pb-list">${subs.length ? subs.map(s => `
          <div class="pb-li"><b style="font-weight:500">${esc(s.name)}</b>
            <small>${esc(s.deliverable || '')}</small>
            <small>${fmtLong(s.start)} → ${fmtLong(s.end)} · Leader: ${esc(s.leader || '—')} · ${tasks.filter(t => t.sub === s.id).length} tasks</small></div>`).join('')
          : '<div class="pb-li" style="color:var(--muted)">No sub-projects.</div>'}</div>
      </div>
      <div>
        <h4>Defects</h4>
        <div class="pb-list">${defects.length ? defects.slice(0, 10).map(d => `
          <div class="pb-li"><b style="font-weight:500">${esc(d.title || 'Defect')}</b>
            <small>${esc(d.status || '—')} · ${esc(d.priority || '—')} · ${esc(d.defect_category || '')} · ${fmtLong(d.date_identified)} · ${esc(d.assigned_user || 'Unassigned')}</small></div>`).join('')
            + (defects.length > 10 ? `<a class="pb-li" href="defect_tracker.php" style="text-decoration:none;color:var(--g700)">View all ${defects.length} defects →</a>` : '')
          : '<div class="pb-li" style="color:var(--muted)">No defects logged.</div>'}</div>
      </div>`;

    $('pbDFoot').innerHTML = `
      <button type="button" class="pb-btn primary" data-board-project="${id}"><i class="fas fa-list-check"></i> Tasks by status</button>
      <a class="pb-btn" href="edit_project.php?item_type=project&id=${id}"><i class="fas fa-pen"></i> Edit</a>
      <a class="pb-btn" href="export_project.php?item_type=project&id=${id}"><i class="fas fa-download"></i> PDF</a>
      <form method="post" action="delete_project.php" style="margin-left:auto" onsubmit="return confirm('Delete this project and all linked data?');">
        <input type="hidden" name="item_type" value="project"><input type="hidden" name="id" value="${id}">
        <button type="submit" class="pb-btn danger"><i class="fas fa-trash"></i> Delete</button>
      </form>`;

    root.classList.add('drawer-open');
    $('pbDrawer').setAttribute('aria-hidden', 'false');
  }
  function closeDrawer(){ root.classList.remove('drawer-open'); $('pbDrawer').setAttribute('aria-hidden', 'true'); }
  $('pbDClose').addEventListener('click', closeDrawer);
  $('pbDrawerBg').addEventListener('click', closeDrawer);
  $('pbDFoot').addEventListener('click', (e) => {
    const b = e.target.closest('[data-board-project]');
    if (b) { S.project = b.dataset.boardProject; S.view = 'status'; closeDrawer(); render(); }
  });
  if (window.__pbKeyHandler) document.removeEventListener('keydown', window.__pbKeyHandler);
  window.__pbKeyHandler = (e) => { if (e.key === 'Escape' && root.isConnected) closeDrawer(); };
  document.addEventListener('keydown', window.__pbKeyHandler);

  render();
})();
</script>
</body>
</html>
