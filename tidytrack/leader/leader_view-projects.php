<?php
// leader_my-projects.php
session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo 'Please sign in.';
  exit;
}

$userId = (int) $_SESSION['user_id'];
require '../dp.php';

function esc($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

// IMPORTANT: used by JS so it always calls THIS file (even when embedded in leader_index.php)
$tasksEndpoint = basename(__FILE__);

/* =========================================================
   AJAX MODE: return tasks JSON (NO extra page needed)
   Call: leader_my-projects.php?ajax=1&project_id=123
========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
  header('Content-Type: application/json; charset=utf-8');

  $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
  if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing project_id']);
    exit;
  }

  // Security: same rule as listing projects (leader or member)
  $check = $conn->prepare("
    SELECT p.project_id
    FROM projects_x p
    LEFT JOIN team_members tm ON tm.project_id = p.project_id
    LEFT JOIN users me ON me.user_id = ?
    WHERE p.project_id = ?
      AND (
        p.teamLeadder_id = ?
        OR (tm.email IS NOT NULL AND (tm.email = me.email OR tm.name = me.name))
      )
    LIMIT 1
  ");
  $check->bind_param("iii", $userId, $projectId, $userId);
  $check->execute();
  $ok = $check->get_result()->fetch_assoc();
  $check->close();

  if (!$ok) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Forbidden']);
    exit;
  }

  // Fetch tasks for project
  $stmt = $conn->prepare("
    SELECT t.task_id, t.taskTitle, t.description, t.dueDate, t.priority, t.status,
           t.created_at,
           t.assignTo, u.name AS assigned_user
    FROM tasks t
    LEFT JOIN users u ON u.user_id = t.assignTo
    WHERE t.project_id = ?
    ORDER BY t.dueDate ASC
  ");

  $stmt->bind_param("i", $projectId);
  $stmt->execute();
  $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  echo json_encode(['ok'=>true,'tasks'=>$tasks]);
  exit;
}

/* ------------------------------
   Optional logging
------------------------------ */
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$conn->query(
  "INSERT INTO user_activity (user_id, activity_type, ip_address, user_agent)
   VALUES ($userId, 'Viewed My Projects', '".$conn->real_escape_string($ip)."', '".$conn->real_escape_string($ua)."')"
);

// filters
$q = trim($_GET['q'] ?? '');
$involvement = $_GET['involvement'] ?? '';

$params = [];
$types  = "";

// core query (status counts + tolerant matching)
$sql = "
SELECT
  p.project_id,
  p.project_name,
  p.deliverable,
  p.start_date,
  p.end_date,
  u.name AS leader_name,
  CASE
    WHEN p.teamLeadder_id = ? THEN 'Leader'
    ELSE 'Member'
  END AS involvement,

  (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.project_id) AS total_tasks,

  (SELECT COUNT(*)
     FROM tasks t
    WHERE t.project_id = p.project_id
      AND LOWER(TRIM(t.status)) IN ('completed','done','complete')
  ) AS completed_tasks,

  (SELECT COUNT(*)
     FROM tasks t
    WHERE t.project_id = p.project_id
      AND LOWER(TRIM(t.status)) IN ('in progress','inprogress','progress','active')
  ) AS inprogress_tasks,

  (SELECT COUNT(*)
     FROM tasks t
    WHERE t.project_id = p.project_id
      AND LOWER(TRIM(t.status)) IN ('in review','review','inreview','pending review')
  ) AS inreview_tasks,

  /* ✅ Archived counts (Archived OR legacy onhold) */
  (SELECT COUNT(*)
     FROM tasks t
    WHERE t.project_id = p.project_id
      AND LOWER(TRIM(t.status)) IN ('archived','onhold')
  ) AS archived_tasks,

  (SELECT COUNT(*)
     FROM tasks t
    WHERE t.project_id = p.project_id
      AND LOWER(TRIM(t.status)) IN ('on hold','hold','blocked')
  ) AS onhold_tasks

FROM projects_x p
JOIN users u ON u.user_id = p.teamLeadder_id
LEFT JOIN team_members tm ON tm.project_id = p.project_id
LEFT JOIN users me ON me.user_id = ?
WHERE
  (
    p.teamLeadder_id = ?
    OR
    (tm.email IS NOT NULL AND (tm.email = me.email OR tm.name = me.name))
  )
";

$types .= "iii";
$params = [$userId, $userId, $userId];

if ($involvement === 'Leader') {
  $sql .= " AND p.teamLeadder_id = ? ";
  $types .= "i";
  $params[] = $userId;
} elseif ($involvement === 'Member') {
  $sql .= " AND p.teamLeadder_id <> ? ";
  $types .= "i";
  $params[] = $userId;
}

if ($q !== '') {
  $sql .= " AND (p.project_name LIKE ? OR p.deliverable LIKE ?) ";
  $types .= "ss";
  $like = "%$q%";
  $params[] = $like;
  $params[] = $like;
}

$sql .= " GROUP BY p.project_id ORDER BY p.end_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

function days_left($end) {
  if (!$end) return null;
  $today = new DateTime('today');
  $endD  = new DateTime($end);
  return (int)$today->diff($endD)->format('%r%a');
}
function pct($done, $total){
  $total = (int)$total; $done = (int)$done;
  if ($total <= 0) return 0;
  return round(($done / $total) * 100, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Leader • My Projects</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root{
  --bg:#f4f6fb; --card:#fff; --text:#111827; --muted:#6b7280; --border:#e5e7eb;
  --accent:#1d4ed8; --accent2:#0ea5e9;
  --good:#16a34a; --warn:#f59e0b; --info:#2563eb; --danger:#ef4444;
  --chipbg:#f3f4f6; --shadow: 0 10px 30px rgba(17,24,39,.08); --shadow2: 0 6px 18px rgba(17,24,39,.08);
  --radius:18px; --radius2:14px;
}
body{ margin:0; background:var(--bg); color:var(--text); font-family:'Poppins',sans-serif; transition:.25s; }

/* ✅ IMPORTANT: support BOTH "dark" (leader_index) and "dark-mode" (standalone) */
body.dark, html.dark,
body.dark-mode{
  --bg:#0f172a; --card:#111827; --text:#e5e7eb; --muted:#9ca3af; --border:#1f2937;
  --chipbg:#0b1220; --shadow: 0 10px 30px rgba(0,0,0,.35); --shadow2: 0 6px 18px rgba(0,0,0,.28);
}

.topbar{
  position:sticky; top:0; z-index:50;
  backdrop-filter: blur(10px);
  background: color-mix(in oklab, var(--bg) 70%, transparent);
  border-bottom:1px solid color-mix(in oklab, var(--border) 70%, transparent);
  padding:14px 0; margin-bottom:16px;
}
.topbar-inner{
  max-width:1180px; margin:0 auto; padding:0 18px;
  display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap;
}
.brand{display:flex; gap:12px; align-items:center;}
.logo{
  width:42px;height:42px;border-radius:14px;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  display:flex;align-items:center;justify-content:center;
  color:#fff; box-shadow: var(--shadow2);
}
.brand h1{margin:0;font-size:18px;font-weight:900;}
.brand .sub{font-size:12px;color:var(--muted);margin-top:2px;}

.actions{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
.btn{
  border:none; cursor:pointer; border-radius:999px; padding:10px 12px;
  font-weight:800; font-size:13px; display:inline-flex; align-items:center; gap:8px; text-decoration:none;
}
.btn-primary{ background:var(--accent); color:#fff; }
.btn-primary:hover{ filter:brightness(1.06); }
.btn-ghost{
  background: color-mix(in oklab, var(--card) 70%, transparent);
  color:var(--text); border:1px solid var(--border);
}
.btn-ghost:hover{ background: color-mix(in oklab, var(--card) 90%, transparent); }

.page{ max-width:1180px; margin:0 auto; padding:0 18px 40px; }
.grid{ display:grid; grid-template-columns: 1fr; gap:16px; }

.card{
  background: var(--card);
  border:1px solid var(--border);
  border-radius: var(--radius);
  padding:16px;
  box-shadow: var(--shadow);
}

.head{ display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap; align-items:flex-start; }
.title{ display:flex; gap:10px; align-items:flex-start; }
.icon{
  width:42px;height:42px;border-radius:14px;
  background: color-mix(in oklab, var(--accent) 14%, transparent);
  display:flex;align-items:center;justify-content:center;
  color: var(--accent);
  border:1px solid color-mix(in oklab, var(--accent) 25%, var(--border));
}
.title h2{ margin:0; font-size:18px; font-weight:900; }
.meta{
  margin-top:6px; font-size:12px; color:var(--muted);
  display:flex; gap:10px; flex-wrap:wrap;
}

.kv{
  margin-top:12px;
  border:1px solid var(--border);
  background: color-mix(in oklab, var(--card) 92%, transparent);
  border-radius: var(--radius2);
  padding:12px;
  display:grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap:10px;
}
@media (max-width: 720px){ .kv{ grid-template-columns:1fr; } }
.kv .item{ font-size:13px; }
.kv .label{ color:var(--muted); font-weight:800; display:block; font-size:12px; margin-bottom:2px; }
.kv .value{ font-weight:800; }

.chips{ display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
.chip{
  background: var(--chipbg);
  border:1px solid var(--border);
  border-radius:999px;
  padding:7px 10px;
  font-size:12px;
  font-weight:900;
  display:inline-flex;
  gap:8px;
  align-items:center;
}
.dot{ width:8px;height:8px;border-radius:50%; background:var(--muted); }
.dot-done{ background: var(--good) !important; }
.dot-review{ background: var(--warn) !important; }
.dot-progress{ background: var(--info) !important; }
.dot-hold{ background: var(--danger) !important; }
.dot-total{ background: var(--muted) !important; }

.progress-wrap{ margin-top:12px; }
.plabel{
  font-size:12px; color:var(--muted); font-weight:900;
  display:flex; justify-content:space-between; align-items:center;
}
.pbar{
  width:100%; height:12px; border-radius:999px;
  background: color-mix(in oklab, var(--border) 70%, transparent);
  margin-top:8px; overflow:hidden;
}
.pfill{
  height:100%; border-radius:999px;
  background: linear-gradient(135deg, var(--good), color-mix(in oklab, var(--good) 55%, var(--accent2)));
  transition: width .35s ease;
}
.note{ color:var(--muted); font-size:12px; font-weight:700; margin-top:8px; }

.badge{
  display:inline-flex; align-items:center; gap:6px;
  padding:5px 10px; border-radius:999px;
  font-size:11px; font-weight:900;
  border:1px solid var(--border);
  background: var(--card);
}
.badge-leader{ border-color: color-mix(in oklab, var(--accent) 45%, var(--border)); color: var(--accent); }
.badge-member{ border-color: var(--border); color: var(--muted); }

.card-actions{ margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; }
.btn-mini{
  border:1px solid var(--border);
  background: var(--card);
  color: var(--text);
  border-radius:999px;
  padding:9px 12px;
  font-weight:900;
  font-size:12px;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  gap:8px;
}
.btn-mini:hover{ transform: translateY(-1px); box-shadow: var(--shadow2); }
.btn-view{ border-color: color-mix(in oklab, var(--accent) 35%, var(--border)); }
.btn-close{
  background: var(--accent);
  color:#fff;
  border:none;
}
.btn-close:hover{ filter:brightness(1.06); }

.empty{
  background: var(--card);
  border:1px solid var(--border);
  border-radius: var(--radius);
  padding:16px;
  box-shadow: var(--shadow);
  color: var(--muted);
  font-weight:700;
}

/* MODAL */
.tt-modal-backdrop{
  position:fixed; inset:0;
  background: rgba(0,0,0,.55);
  display:none;
  align-items:center;
  justify-content:center;
  padding:18px;
  z-index:999999;
}
.tt-modal-backdrop.open{ display:flex; }
.tt-modal-box{
  width:min(980px, 100%);
  max-height:86vh;
  overflow:auto;
  background: var(--card);
  color: var(--text);
  border:1px solid var(--border);
  border-radius:20px;
  box-shadow: var(--shadow);
}
.tt-modal-header{
  padding:14px 16px;
  border-bottom:1px solid var(--border);
  display:flex;
  gap:10px;
  align-items:flex-start;
  justify-content:space-between;
}
.tt-modal-title{ font-weight:900; font-size:15px; }
.tt-modal-sub{ font-size:12px; color:var(--muted); margin-top:2px; }
.tt-modal-body{ padding:14px 16px; }

/* Table base styles (ours) */
.tt-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
  overflow:hidden;
  border:1px solid var(--border);
  border-radius:14px;
}
.tt-table th, .tt-table td{
  padding:10px 10px;
  font-size:12px;
  border-bottom:1px solid var(--border);
  vertical-align:top;
}
.tt-table th{
  text-align:left;
  font-weight:900;
  color:var(--muted);
  background: color-mix(in oklab, var(--card) 90%, transparent);
}
.tt-table tr:last-child td{ border-bottom:none; }

.status-pill{
  display:inline-flex;
  padding:4px 10px;
  border-radius:999px;
  font-weight:900;
  font-size:11px;
  border:1px solid var(--border);
}
.p-done{ border-color: color-mix(in oklab, var(--good) 45%, var(--border)); color: var(--good); }
.p-review{ border-color: color-mix(in oklab, var(--warn) 45%, var(--border)); color: var(--warn); }
.p-progress{ border-color: color-mix(in oklab, var(--info) 45%, var(--border)); color: var(--info); }
.p-other{ color: var(--muted); }

/* ===========================================
   ✅ FIX: Bootstrap .table cell backgrounds in modal (dark mode + light)
   Bootstrap applies background on:
   .table > :not(caption) > * > * { background-color: var(--bs-table-bg); }
   =========================================== */
.tt-modal-box .tt-table > :not(caption) > * > *{
  background-color: transparent !important;
  color: var(--text) !important;
  border-color: var(--border) !important;
}
.tt-modal-box .tt-table{
  background: var(--card) !important;
  color: var(--text) !important;
  border-color: var(--border) !important;
}
.tt-modal-box .tt-table thead th{
  background: rgba(148,163,184,.12) !important;
  color: var(--muted) !important;
  border-bottom: 1px solid var(--border) !important;
}
.tt-modal-box .tt-table tbody tr:hover > *{
  background: rgba(148,163,184,.08) !important;
}
body.dark .tt-modal-box .tt-table thead th,
html.dark .tt-modal-box .tt-table thead th{
  background: rgba(15,23,42,.55) !important;
}
body.dark .tt-modal-box .tt-table tbody tr:hover > *,
html.dark .tt-modal-box .tt-table tbody tr:hover > *{
  background: rgba(255,255,255,.05) !important;
}
</style>
</head>

<body>

<div class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <div class="logo"><i class="fa-solid fa-diagram-project"></i></div>
      <div>
        <h1>My Projects</h1>
      </div>
    </div>

    <div class="actions">
      <!-- optional buttons here -->
    </div>
  </div>
</div>

<div class="page">
  <div class="grid">

    <?php if (!$res || $res->num_rows === 0): ?>
      <div class="empty">No projects found for your account.</div>
    <?php else: ?>

      <?php while($row = $res->fetch_assoc()):
        $dl = days_left($row['end_date']);

        $total    = (int)$row['total_tasks'];
        $done     = (int)$row['completed_tasks'];
        $review   = (int)$row['inreview_tasks'];
        $prog     = (int)$row['inprogress_tasks'];
        $archived = (int)($row['archived_tasks'] ?? 0);

        $percent  = pct($done, $total);
        $invol    = $row['involvement'] ?? '';
      ?>
        <div class="card">

          <div class="head">
            <div class="title">
              <div class="icon"><i class="fa-solid fa-folder-open"></i></div>
              <div>
                <h2><?= esc($row['project_name']) ?></h2>
                <div class="meta">
                  <span><i class="fa-solid fa-user-tie"></i> <?= esc($row['leader_name']) ?></span>
                  <span><i class="fa-regular fa-calendar"></i> <?= esc($row['start_date']) ?> → <?= esc($row['end_date']) ?></span>
                  <?php if ($dl !== null): ?>
                    <span><i class="fa-regular fa-hourglass-half"></i>
                      <?= $dl < 0 ? (abs($dl) . " days overdue") : ($dl . " days left") ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div>
              <?php if ($invol === 'Leader'): ?>
                <span class="badge badge-leader"><i class="fa-solid fa-crown"></i> Leader</span>
              <?php else: ?>
                <span class="badge badge-member"><i class="fa-regular fa-user"></i> Member</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="kv">
            <div class="item">
              <span class="label">Deliverable</span>
              <span class="value"><?= esc($row['deliverable']) ?></span>
            </div>
            <div class="item">
              <span class="label">Completion</span>
              <span class="value"><?= esc($percent) ?>%</span>
            </div>
          </div>

          <div class="chips">
            <span class="chip"><span class="dot dot-done"></span> Completed <?= $done ?></span>
            <span class="chip"><span class="dot dot-review"></span> In Review <?= $review ?></span>
            <span class="chip"><span class="dot dot-progress"></span> In Progress <?= $prog ?></span>
            <span class="chip"><span class="dot dot-hold"></span> Archived <?= $archived ?></span>
            <span class="chip"><span class="dot dot-total"></span> Total <?= $total ?></span>
          </div>

          <div class="progress-wrap">
            <div class="plabel">
              <span><i class="fa-solid fa-check-double"></i> Task completion</span>
              <span><?= esc($percent) ?>%</span>
            </div>
            <div class="pbar">
              <div class="pfill" style="width: <?= esc($percent) ?>%"></div>
            </div>
            <div class="note">
              <?= $done ?> of <?= $total ?> tasks completed
              <?php if ($total === 0): ?> • No tasks yet<?php endif; ?>
            </div>
          </div>

          <div class="card-actions">
            <button class="btn-mini btn-view"
                    type="button"
                    data-project-id="<?= (int)$row['project_id'] ?>"
                    data-project-name="<?= esc($row['project_name']) ?>">
              <i class="fa-solid fa-list-check"></i> View Tasks
            </button>
          </div>

        </div>
      <?php endwhile; ?>

    <?php endif; ?>

  </div>
</div>

<!-- MODAL -->
<div class="tt-modal-backdrop" id="tasksModal" aria-hidden="true">
  <div class="tt-modal-box" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="tt-modal-header">
      <div>
        <div class="tt-modal-title" id="modalTitle">Tasks</div>
        <div class="tt-modal-sub" id="modalSub">Loading...</div>
      </div>

      <button class="btn-mini btn-close" id="closeModalBtn" type="button">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div class="tt-modal-body" id="modalBody">
      <div class="note">Loading tasks...</div>
    </div>
  </div>
</div>

<script>
(function(){
  // modal controls
  const modal = document.getElementById('tasksModal');
  const closeBtn = document.getElementById('closeModalBtn');
  const modalBody = document.getElementById('modalBody');
  const modalTitle = document.getElementById('modalTitle');
  const modalSub = document.getElementById('modalSub');

  function openModal(){
    modal.classList.add('open');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
  }
  function closeModal(){
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
  }
  closeBtn?.addEventListener('click', closeModal);
  modal?.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
  });

  function statusClass(status){
    const s = (status || '').toString().trim().toLowerCase().replace(/\s+/g,' ');
    if (['completed','done','complete'].includes(s)) return 'status-pill p-done';
    if (['in review','review','inreview','pending review'].includes(s)) return 'status-pill p-review';
    if (['in progress','progress','inprogress','active'].includes(s)) return 'status-pill p-progress';
    return 'status-pill p-other';
  }

  function escapeHtml(str){
    return (str ?? '').toString()
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function formatDate(v){
    if (!v) return '';
    const s = v.toString();
    return s.length >= 10 ? s.slice(0, 10) : s;
  }

  // ALWAYS call THIS file (works even if embedded)
  const TASKS_ENDPOINT = <?= json_encode($tasksEndpoint) ?>;

  async function fetchTasks(projectId){
    const url = TASKS_ENDPOINT + '?ajax=1&project_id=' + encodeURIComponent(projectId);
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });

    const ct = (res.headers.get('content-type') || '').toLowerCase();
    if (!ct.includes('application/json')) {
      const text = await res.text();
      throw new Error('Server did not return JSON. First 200 chars: ' + text.slice(0, 200));
    }

    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Failed to load tasks');
    return data.tasks || [];
  }

  document.querySelectorAll('.btn-view').forEach(btn => {
    btn.addEventListener('click', async () => {
      const projectId = btn.getAttribute('data-project-id');
      const projectName = btn.getAttribute('data-project-name') || 'Project';

      modalTitle.textContent = 'Tasks • ' + projectName;
      modalSub.textContent = 'Loading tasks...';
      modalBody.innerHTML = '<div class="note">Loading tasks...</div>';
      openModal();

      try{
        const tasks = await fetchTasks(projectId);
        modalSub.textContent = tasks.length + ' task(s)';

        if (!tasks.length){
          modalBody.innerHTML = '<div class="empty">No tasks found for this project.</div>';
          return;
        }

        let html = `
          <table class="table tt-table">
            <thead>
              <tr>
                <th style="width:20%;">Task</th>
                <th style="width:12%;">Responsible Person</th>
                <th style="width:12%;">Start Date</th>
                <th style="width:10%;">Due Date</th>
                <th style="width:10%;">Priority</th>
                <th style="width:12%;">Status</th>
                <th>Description</th>
              </tr>
            </thead>
            <tbody>
        `;

        tasks.forEach(t => {
          const st = t.status || '';
          html += `
            <tr>
              <td><b>${escapeHtml(t.taskTitle || '')}</b></td>
              <td>${escapeHtml(t.assigned_user || t.assignTo || '')}</td>
              <td>${escapeHtml(formatDate(t.created_at || ''))}</td>
              <td>${escapeHtml(t.dueDate || '')}</td>
              <td>${escapeHtml(t.priority || '')}</td>
              <td><span class="${statusClass(st)}">${escapeHtml(st)}</span></td>
              <td>${escapeHtml(t.description || '')}</td>
            </tr>
          `;
        });

        html += '</tbody></table>';
        modalBody.innerHTML = html;

      }catch(err){
        modalSub.textContent = 'Error';
        modalBody.innerHTML = '<div class="empty">' + escapeHtml(err.message || 'Could not load tasks.') + '</div>';
      }
    });
  });

})();
</script>

</body>
</html>
