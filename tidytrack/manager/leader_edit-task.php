<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include '../dp.php';

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function findFirstColumn(mysqli $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '".$conn->real_escape_string($c)."'");
    if ($r && $r->num_rows > 0) { $r->free(); return $c; }
    if ($r) $r->free();
  }
  return null;
}
function tableExists(mysqli $conn, string $table): bool {
  $r = $conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($table)."'");
  $ok = ($r && $r->num_rows > 0);
  if ($r) $r->free();
  return $ok;
}
function setToast(string $type, string $text): void {
  $_SESSION['toast'] = ['type'=>$type,'text'=>$text];
}

/**
 * ✅ Redirect back into leader_index (keeps side nav + header)
 * IMPORTANT: do NOT urlencode the whole page string. Your leader_index script
 * expects ?page=leader_edit-task.php and optional &all=1 separately.
 */
function redirectToIndexEdit(bool $showAll): void {
  $target = "./manager_index.php?page=leader_edit-task.php" . ($showAll ? "&all=1" : "");
  header("Location: $target");
  exit;
}

/* ---------------------------------------------------------
   AUTH
--------------------------------------------------------- */
$leaderId = (int)($_SESSION['user_id'] ?? 0);
if (!$leaderId) {
  header("Location: ../login.php");
  exit;
}

/* ---------------------------------------------------------
   TOGGLE
--------------------------------------------------------- */
$showAll = (isset($_GET['all']) && $_GET['all'] === '1');

/* ---------------------------------------------------------
   Toast flash
--------------------------------------------------------- */
$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);

/* ---------------------------------------------------------
   DB CHECKS
--------------------------------------------------------- */
$statusExists = false;
$createdAtExists = false;
$statusType = '';
$statusHasArchived = false;
$statusHasOnhold   = false;

$colRes = $conn->query("SHOW COLUMNS FROM tasks LIKE 'status'");
if ($colRes && $colRes->num_rows > 0) {
  $statusExists = true;
  $r = $colRes->fetch_assoc();
  $statusType = $r['Type'] ?? '';
  if (stripos($statusType, "enum(") !== false) {
    $statusHasArchived = (stripos($statusType, "'Archived'") !== false);
    $statusHasOnhold   = (stripos($statusType, "'onhold'") !== false) || (stripos($statusType, "'Onhold'") !== false);
  } else {
    $statusHasArchived = true;
    $statusHasOnhold   = true;
  }
}
if ($colRes) $colRes->free();

$colRes2 = $conn->query("SHOW COLUMNS FROM tasks LIKE 'created_at'");
if ($colRes2 && $colRes2->num_rows > 0) $createdAtExists = true;
if ($colRes2) $colRes2->free();

// Archive DB value
$archiveDbValue = null;
if ($statusExists) {
  if ($statusHasArchived) $archiveDbValue = 'Archived';
  elseif ($statusHasOnhold) $archiveDbValue = 'onhold';
}

/* ---------------------------------------------------------
   Detect leader column (for My Projects)
--------------------------------------------------------- */
$projectLeaderCol = findFirstColumn($conn, 'projects_x', [
  'teamLeaderId','team_leader_id','leader_id','teamLeader','team_leader','assigned_leader_id'
]);

/* ---------------------------------------------------------
   Membership table (optional)
--------------------------------------------------------- */
$membershipTable = null;
foreach (['project_members','project_users','user_projects','project_assignments'] as $t) {
  if (tableExists($conn, $t)) { $membershipTable = $t; break; }
}
$userIdCol   = findFirstColumn($conn, 'users', ['user_id','id']);
$userNameCol = findFirstColumn($conn, 'users', ['name','full_name','fullname']) ?? 'name';
$mProjCol    = $membershipTable ? findFirstColumn($conn, $membershipTable, ['project_id','projectId']) : null;
$mUserCol    = $membershipTable ? findFirstColumn($conn, $membershipTable, ['user_id','userId','member_id']) : null;

/* ---------------------------------------------------------
   HANDLE UPDATE / ARCHIVE
--------------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  // UPDATE
  if (isset($_POST['update_task'])) {
    $taskId = (int)($_POST['update_task'] ?? 0);

    $taskTitle = trim($_POST['taskTitle'][$taskId] ?? '');
    $assignTo  = trim($_POST['assignTo'][$taskId] ?? '');
    $dueDate   = trim($_POST['dueDate'][$taskId] ?? '');
    $startDate = trim($_POST['startDate'][$taskId] ?? ''); // ✅ editable

    if ($taskId && $taskTitle !== '' && $assignTo !== '' && $dueDate !== '') {

      $createdAtVal = null;

      if ($createdAtExists) {
        if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
          setToast('warning', 'Start Date must be in YYYY-MM-DD format.');
          redirectToIndexEdit($showAll);
        }

        // Keep existing time part
        $existingTime = "00:00:00";
        $st = $conn->prepare("SELECT created_at FROM tasks WHERE task_id=? LIMIT 1");
        if ($st) {
          $st->bind_param("i", $taskId);
          if ($st->execute()) {
            $row = $st->get_result()->fetch_assoc();
            $existing = $row['created_at'] ?? null;
            if ($existing) {
              $t = date('H:i:s', strtotime($existing));
              if ($t) $existingTime = $t;
            }
          }
          $st->close();
        }

        if ($startDate === '') {
          // keep unchanged
          $st2 = $conn->prepare("SELECT created_at FROM tasks WHERE task_id=? LIMIT 1");
          if ($st2) {
            $st2->bind_param("i", $taskId);
            $st2->execute();
            $row2 = $st2->get_result()->fetch_assoc();
            $createdAtVal = $row2['created_at'] ?? date("Y-m-d H:i:s");
            $st2->close();
          } else {
            $createdAtVal = date("Y-m-d H:i:s");
          }
        } else {
          $createdAtVal = $startDate . " " . $existingTime;
        }

        $stmt = $conn->prepare("UPDATE tasks SET taskTitle=?, assignTo=?, dueDate=?, created_at=? WHERE task_id=? LIMIT 1");
        if (!$stmt) {
          setToast('error', 'Prepare failed: ' . $conn->error);
          redirectToIndexEdit($showAll);
        }
        $stmt->bind_param("ssssi", $taskTitle, $assignTo, $dueDate, $createdAtVal, $taskId);

      } else {
        $stmt = $conn->prepare("UPDATE tasks SET taskTitle=?, assignTo=?, dueDate=? WHERE task_id=? LIMIT 1");
        if (!$stmt) {
          setToast('error', 'Prepare failed: ' . $conn->error);
          redirectToIndexEdit($showAll);
        }
        $stmt->bind_param("sssi", $taskTitle, $assignTo, $dueDate, $taskId);
      }

      if ($stmt->execute()) {
        if ($stmt->affected_rows === 0) setToast('info', 'No changes detected (task already up to date).');
        else setToast('success', 'Task updated successfully.');
      } else {
        setToast('error', 'Failed to update task: ' . $stmt->error);
      }

      $stmt->close();
      redirectToIndexEdit($showAll);

    } else {
      setToast('warning', 'All fields are required.');
      redirectToIndexEdit($showAll);
    }
  }

  // ARCHIVE
  if (isset($_POST['archive_task'])) {
    $taskId = (int)($_POST['archive_task'] ?? 0);

    if (!$statusExists) {
      setToast('error', "Cannot archive: 'status' column is missing.");
      redirectToIndexEdit($showAll);
    }
    if (!$archiveDbValue) {
      setToast('error', "Cannot archive: status does not support 'Archived' (no fallback).");
      redirectToIndexEdit($showAll);
    }
    if (!$taskId) {
      setToast('error', "Missing task id.");
      redirectToIndexEdit($showAll);
    }

    $stmt = $conn->prepare("UPDATE tasks SET status=? WHERE task_id=? LIMIT 1");
    if (!$stmt) {
      setToast('error', 'Prepare failed: ' . $conn->error);
      redirectToIndexEdit($showAll);
    }

    $stmt->bind_param("si", $archiveDbValue, $taskId);

    if ($stmt->execute()) setToast('success', 'Task archived successfully.');
    else setToast('error', 'Failed to archive task: ' . $stmt->error);

    $stmt->close();
    redirectToIndexEdit($showAll);
  }
}

/* ---------------------------------------------------------
   FETCH TASKS
--------------------------------------------------------- */
$projects = [];
$totalTasks = 0;

$startDateSelect = $createdAtExists ? "DATE(t.created_at) AS startDate," : "NULL AS startDate,";
$conds = [];

if (!$showAll && $projectLeaderCol) {
  $conds[] = "p.`$projectLeaderCol` = " . (int)$leaderId;
}
if ($statusExists) {
  $conds[] = "(t.status IS NULL OR (LOWER(t.status) <> 'archived' AND LOWER(t.status) <> 'onhold'))";
  $conds[] = "(t.status IS NULL OR LOWER(t.status) NOT IN ('completed','done','complete'))";
}

$where = !empty($conds) ? ("WHERE " . implode(" AND ", $conds)) : "";

$orderBy = $createdAtExists
  ? "ORDER BY p.project_name ASC, t.created_at DESC, t.task_id DESC"
  : "ORDER BY p.project_name ASC, t.task_id DESC";

$sql = "
  SELECT
    t.task_id,
    t.taskTitle,
    t.assignTo,
    $startDateSelect
    t.dueDate,
    " . ($statusExists ? "t.status," : "NULL AS status,") . "
    p.project_name,
    t.project_id
  FROM tasks t
  JOIN projects_x p ON t.project_id = p.project_id
  $where
  $orderBy
";

$rs = $conn->query($sql);
if ($rs) {
  while ($row = $rs->fetch_assoc()) {
    $pname = trim((string)($row['project_name'] ?? 'Unassigned Project'));
    if ($pname === '') $pname = 'Unassigned Project';
    $projects[$pname][] = $row;
    $totalTasks++;
  }
  $rs->free();
}

/* ---------------------------------------------------------
   Members per project
--------------------------------------------------------- */
$membersByProject = [];

if (!empty($projects)) {
  $projIds = [];
  foreach ($projects as $rows) {
    foreach ($rows as $t) {
      $pid = (int)($t['project_id'] ?? 0);
      if ($pid) $projIds[$pid] = true;
    }
  }
  $projIds = array_keys($projIds);

  if ($membershipTable && $userIdCol && $mProjCol && $mUserCol && !empty($projIds)) {
    $in = implode(',', array_fill(0, count($projIds), '?'));
    $types = str_repeat('i', count($projIds));
    $q = "
      SELECT m.`$mProjCol` AS project_id, u.`$userNameCol` AS name
      FROM `$membershipTable` m
      JOIN users u ON u.`$userIdCol` = m.`$mUserCol`
      WHERE m.`$mProjCol` IN ($in)
      ORDER BY u.`$userNameCol` ASC
    ";
    $st = $conn->prepare($q);
    if ($st) {
      $st->bind_param($types, ...$projIds);
      $st->execute();
      $r = $st->get_result();
      while ($row = $r->fetch_assoc()) {
        $pid = (int)$row['project_id'];
        $nm = trim((string)$row['name']);
        if ($pid && $nm !== '') $membersByProject[$pid][$nm] = true;
      }
      $st->close();
    }
  }

  foreach ($projIds as $pid) {
    if (!isset($membersByProject[$pid]) || empty($membersByProject[$pid])) {
      $membersByProject[$pid] = [];
      $st = $conn->prepare("SELECT DISTINCT TRIM(assignTo) AS name FROM tasks WHERE project_id=? AND assignTo IS NOT NULL AND TRIM(assignTo)<>'' ORDER BY name ASC");
      if ($st) {
        $st->bind_param("i", $pid);
        $st->execute();
        $r = $st->get_result();
        while ($row = $r->fetch_assoc()) {
          $nm = trim((string)$row['name']);
          if ($nm !== '') $membersByProject[$pid][$nm] = true;
        }
        $st->close();
      }
    }
  }

  foreach ($membersByProject as $pid => $set) {
    $membersByProject[$pid] = array_values(array_unique(array_keys($set)));
    sort($membersByProject[$pid], SORT_NATURAL | SORT_FLAG_CASE);
  }
}

$formAction = "./leader_edit-task.php" . ($showAll ? "?all=1" : "");
?>
<style>
  .tt-shell{ border-radius:18px; border:1px solid rgba(15,23,42,.08); overflow:hidden; background:#fff; box-shadow:0 18px 45px rgba(2,6,23,.08); }
  body.dark .tt-shell, html.dark .tt-shell{ background: rgba(255,255,255,.03); border-color: rgba(255,255,255,.08); box-shadow:none; }
  .tt-topbar{ padding:18px 18px 14px; background: linear-gradient(135deg, rgba(29,53,87,.12), rgba(39,76,119,.06)); border-bottom:1px solid rgba(15,23,42,.06); }
  body.dark .tt-topbar, html.dark .tt-topbar{ background: rgba(255,255,255,.05); border-bottom-color: rgba(255,255,255,.08); }
  .tt-title{ font-weight:950; font-size:18px; color:#0f172a; }
  body.dark .tt-title, html.dark .tt-title{ color:#e5e7eb; }
  .tt-sub{ font-size:12px; color:#64748b; margin-top:4px; font-weight:800; }
  body.dark .tt-sub, html.dark .tt-sub{ color:#cbd5e1; }
  .tt-controls{ display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; align-items:center; justify-content:space-between; }
  .tt-search{ width:min(420px, 100%); border:1px solid rgba(15,23,42,.12); border-radius:14px; padding:10px 12px; font-weight:900; font-size:13px; outline:none; background:#fff; }
  body.dark .tt-search, html.dark .tt-search{ background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.12); color:#e5e7eb; }
  .tt-chip{ padding:7px 10px; border-radius:999px; font-size:12px; font-weight:900; border:1px solid rgba(15,23,42,.10); background:#f8fafc; color:#0f172a; }
  body.dark .tt-chip, html.dark .tt-chip{ background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.10); color:#e5e7eb; }

  .tt-toggle a{ display:inline-block; padding:8px 10px; border-radius:12px; font-weight:950; font-size:12px; border:1px solid rgba(15,23,42,.12); text-decoration:none; color:#0f172a; background:#fff; }
  .tt-toggle a.active{ background:#1d3557; color:#fff; border-color:#1d3557; }
  body.dark .tt-toggle a, html.dark .tt-toggle a{ background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.12); color:#e5e7eb; }
  body.dark .tt-toggle a.active, html.dark .tt-toggle a.active{ background:#1d3557; border-color:#1d3557; color:#fff; }

  .tt-card{ margin:14px 16px; border:1px solid rgba(15,23,42,.08); border-radius:16px; overflow:hidden; background:#fff; }
  body.dark .tt-card, html.dark .tt-card{ background: rgba(255,255,255,.03); border-color: rgba(255,255,255,.08); }
  .tt-card-head{ padding:14px 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
  .tt-pname{ font-size:15px; font-weight:950; color:#0f172a; }
  body.dark .tt-pname, html.dark .tt-pname{ color:#e5e7eb; }
  .tt-btn{ border:1px solid rgba(15,23,42,.12); background:#fff; padding:8px 10px; border-radius:12px; font-weight:950; cursor:pointer; font-size:12px; }
  body.dark .tt-btn, html.dark .tt-btn{ background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.12); color:#e5e7eb; }
  .hidden{ display:none !important; }
  .tt-body{ padding:0 14px 14px; }

  .tt-table{ width:100%; border-collapse:separate; border-spacing:0; }
  .tt-table th{ font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#64748b; padding:10px 8px; border-bottom:1px solid rgba(15,23,42,.08); }
  .tt-table td{ padding:10px 8px; border-bottom:1px solid rgba(15,23,42,.06); vertical-align:top; }
  body.dark .tt-table th, html.dark .tt-table th{ color:#cbd5e1; border-bottom-color: rgba(255,255,255,.10); }
  body.dark .tt-table td, html.dark .tt-table td{ color:#e5e7eb; border-bottom-color: rgba(255,255,255,.08); }

  .tt-input, .tt-select{ width:100%; border:1px solid rgba(15,23,42,.14); border-radius:12px; padding:8px 10px; font-weight:900; font-size:13px; background:#fff; }
  body.dark .tt-input, body.dark .tt-select, html.dark .tt-input, html.dark .tt-select{ background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.12); color:#e5e7eb; }

  .tt-action{ border:0; border-radius:12px; padding:10px 12px; font-weight:950; cursor:pointer; font-size:12px; }
  .tt-update{ background:#1d3557; color:#fff; }
  .tt-update:hover{ background:#162a44; }
  .tt-archive{ background:#64748b; color:#fff; }
  .tt-archive:hover{ background:#475569; }

  .tt-status{ display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; font-size:12px; font-weight:950; border:1px solid rgba(15,23,42,.10); background:#f3f4f6; color:#111827; white-space:nowrap; }
  .tt-dot{ width:8px; height:8px; border-radius:999px; background:#6b7280; }
  .tt-status.done{ background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.25); color:#14532d; }
  .tt-status.review{ background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.25); color:#7c2d12; }
  .tt-status.progress{ background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.25); color:#1e3a8a; }
  .tt-status.archived{ background: rgba(148,163,184,.16); border-color: rgba(148,163,184,.30); color:#111827; }
  .tt-dot.done{ background:#22c55e; } .tt-dot.review{ background:#f59e0b; } .tt-dot.progress{ background:#3b82f6; } .tt-dot.archived{ background:#94a3b8; }
  body.dark .tt-status, html.dark .tt-status{ background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.12); color:#e5e7eb; }

  .tt-toast{ position:fixed; top:16px; right:16px; z-index:99999; min-width:260px; max-width:420px; border-radius:14px; padding:12px 14px;
    box-shadow:0 18px 45px rgba(2,6,23,.18); border:1px solid rgba(15,23,42,.12); background:#fff; color:#0f172a;
    font-weight:950; font-size:13px; display:flex; align-items:flex-start; gap:10px; transition: all .35s ease;
  }
  .tt-toast .dot{ width:10px; height:10px; border-radius:999px; margin-top:4px; background:#64748b; }
  .tt-toast.success .dot{ background:#22c55e; } .tt-toast.error .dot{ background:#ef4444; }
  .tt-toast.warning .dot{ background:#f59e0b; } .tt-toast.info .dot{ background:#3b82f6; }
  body.dark .tt-toast, html.dark .tt-toast{ background: rgba(17,24,39,.92); color:#e5e7eb; border-color: rgba(255,255,255,.10); box-shadow:none; }
</style>

<script>
  function ttFilterProjects(){
    const q = (document.getElementById('ttSearch')?.value || '').toLowerCase().trim();
    document.querySelectorAll('.tt-project-card').forEach(card=>{
      if(!q){ card.style.display=''; return; }
      const hay = (card.getAttribute('data-hay') || '').toLowerCase();
      card.style.display = hay.includes(q) ? '' : 'none';
    });
  }
  function toggleBody(id, btn){
    const el = document.getElementById(id);
    if(!el) return;
    const nowHidden = el.classList.toggle('hidden');
    btn.textContent = nowHidden ? 'View Tasks' : 'Hide Tasks';
  }
  function autoHideToast(){
    const t = document.getElementById('ttToast');
    if(!t) return;
    setTimeout(()=>{ t.style.opacity='0'; t.style.transform='translateY(-6px)'; }, 2600);
    setTimeout(()=>{ if(t && t.parentNode) t.parentNode.removeChild(t); }, 3300);
  }
  document.addEventListener('DOMContentLoaded', autoHideToast);
</script>

<?php if ($toast && is_array($toast) && !empty($toast['text'])): ?>
  <div id="ttToast" class="tt-toast <?php echo esc($toast['type'] ?? 'info'); ?>">
    <span class="dot"></span>
    <div><?php echo esc($toast['text']); ?></div>
  </div>
<?php endif; ?>

<main>
  <div class="tt-shell">
    <div class="tt-topbar">
      <div class="tt-title">✏️ Edit / Reassign Tasks</div>

      <div class="tt-controls">
        <input id="ttSearch" class="tt-search" oninput="ttFilterProjects()" placeholder="Search project / task / member..." />

        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
          <span class="tt-chip">Projects: <?php echo (int)count($projects); ?></span>
          <span class="tt-chip">Tasks: <?php echo (int)$totalTasks; ?></span>

          <!-- ✅ FIXED TOGGLE: separate page + all=1 (no urlencode) -->
          <div class="tt-toggle" style="display:flex; gap:8px;">
            <a href="./leader_index.php?page=leader_edit-task.php&all=1"
               class="<?php echo $showAll ? 'active' : ''; ?>">All Projects</a>

            <!--<a href="./leader_index.php?page=leader_edit-task.php"-->
            <!--   class="<?php echo !$showAll ? 'active' : ''; ?>">My Projects</a>-->
          </div>
        </div>
      </div>
    </div>

    <form method="POST" action="<?php echo esc($formAction); ?>">
      <?php if (empty($projects)): ?>
        <div style="padding:22px; color:#64748b; font-weight:900;">No projects/tasks found.</div>
      <?php else: ?>

        <?php $i = 0; foreach ($projects as $projectName => $rows): $i++; ?>
          <?php
            $bodyId = "ttBody_" . $i;

            $hay = $projectName;
            foreach ($rows as $r) $hay .= ' ' . ($r['taskTitle'] ?? '') . ' ' . ($r['assignTo'] ?? '') . ' ' . ($r['status'] ?? '');

            $projId = (int)($rows[0]['project_id'] ?? 0);
            $projMembers = ($projId && isset($membersByProject[$projId])) ? $membersByProject[$projId] : [];
          ?>
          <div class="tt-card tt-project-card" data-hay="<?php echo esc($hay); ?>">
            <div class="tt-card-head">
              <div>
                <div class="tt-pname">📁 <?php echo esc($projectName); ?></div>
                <div class="tt-sub" style="margin-top:3px;">Tasks: <b><?php echo count($rows); ?></b></div>
              </div>
              <button type="button" class="tt-btn" onclick="toggleBody('<?php echo esc($bodyId); ?>', this)">View Tasks</button>
            </div>

            <div id="<?php echo esc($bodyId); ?>" class="tt-body hidden">
              <div style="overflow-x:auto;">
                <table class="tt-table">
                  <thead>
                    <tr>
                      <th style="min-width:240px;">Task</th>
                      <th style="min-width:220px;">Responsible</th>
                      <th style="min-width:150px;">Start Date</th>
                      <th style="min-width:150px;">Deadline</th>
                      <th style="min-width:140px;">Status</th>
                      <th style="min-width:220px; text-align:right;">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $task): ?>
                      <?php
                        $id = (int)$task['task_id'];
                        $startDate = $task['startDate'] ?? '';
                        $currentAssignee = (string)($task['assignTo'] ?? '');

                        $localMembers = $projMembers;
                        if ($currentAssignee !== '' && !in_array($currentAssignee, $localMembers, true)) {
                          $localMembers[] = $currentAssignee;
                          sort($localMembers, SORT_NATURAL | SORT_FLAG_CASE);
                        }

                        $rawStatus = (string)($task['status'] ?? '');
                        $s = strtolower(trim($rawStatus));
                        $label = ($rawStatus === '') ? 'In progress' : $rawStatus;

                        $badgeClass = 'progress';
                        if (in_array($s, ['completed','done','complete'], true)) $badgeClass = 'done';
                        elseif (in_array($s, ['in review','review','inreview','pending review'], true)) $badgeClass = 'review';
                        elseif (in_array($s, ['archived','onhold'], true)) $badgeClass = 'archived';
                      ?>
                      <tr>
                        <td>
                          <input class="tt-input" name="taskTitle[<?php echo $id; ?>]" value="<?php echo esc($task['taskTitle'] ?? ''); ?>" required>
                        </td>

                        <td>
                          <select class="tt-select" name="assignTo[<?php echo $id; ?>]" required>
                            <option value="">-- Select Member --</option>
                            <?php foreach ($localMembers as $u): ?>
                              <option value="<?php echo esc($u); ?>" <?php echo ($u === $currentAssignee) ? 'selected' : ''; ?>>
                                <?php echo esc($u); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </td>

                        <td>
                          <input class="tt-input" type="date" name="startDate[<?php echo $id; ?>]" value="<?php echo esc($startDate); ?>">
                        </td>

                        <td>
                          <input class="tt-input" type="date" name="dueDate[<?php echo $id; ?>]" value="<?php echo esc($task['dueDate'] ?? ''); ?>" required>
                        </td>

                        <td>
                          <span class="tt-status <?php echo esc($badgeClass); ?>">
                            <span class="tt-dot <?php echo esc($badgeClass); ?>"></span>
                            <?php echo esc($label); ?>
                          </span>
                        </td>

                        <td style="text-align:right; white-space:nowrap;">
                          <button class="tt-action tt-update" type="submit" name="update_task" value="<?php echo $id; ?>">Update</button>
                          <button class="tt-action tt-archive" type="submit" name="archive_task" value="<?php echo $id; ?>" onclick="return confirm('Archive this task?');">Archive</button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>

          </div>
        <?php endforeach; ?>

      <?php endif; ?>
    </form>
  </div>
</main>
