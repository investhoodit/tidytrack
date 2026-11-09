<?php
// manager_project_insights.php
session_start();

require_once __DIR__ . '/../dp.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* ------------------------------------------
   Helpers
---------------------------------------------*/
if (!function_exists('esc')) {
  function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function tableExists(mysqli $conn, string $table): bool {
  $r = $conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($table)."'");
  $ok = ($r && $r->num_rows > 0);
  if ($r) $r->free();
  return $ok;
}

function findFirstColumn(mysqli $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '".$conn->real_escape_string($c)."'");
    if ($r && $r->num_rows > 0) { $r->free(); return $c; }
    if ($r) $r->free();
  }
  return null;
}

/* ------------------------------------------
   AUTH: managers only
---------------------------------------------*/
$userId = $_SESSION['user_id'] ?? null;
$role   = strtolower(trim($_SESSION['role'] ?? ''));
if (!$userId || $role !== 'manager') {
  http_response_code(403);
  exit('Forbidden');
}

/* ------------------------------------------
   PROJECT ID
---------------------------------------------*/
$pid = (int)($_GET['project_id'] ?? 0);
if ($pid <= 0) {
  http_response_code(400);
  exit('Invalid project id');
}

/* ------------------------------------------
   CHECK IF sub_projects TABLE EXISTS
---------------------------------------------*/
$hasSubProjects = tableExists($conn, 'sub_projects');

/* ------------------------------------------
   Detect subproject FK column in TASKS
   (fixes: Unknown column 'subproject_id')
---------------------------------------------*/
$taskSubCol = findFirstColumn($conn, 'tasks', [
  'sub_project_id',
  'subproject_id',
  'subProjectId',
  'sub_project',
  'subproject'
]);

/* ------------------------------------------
   PROJECT HEADER
---------------------------------------------*/
$sqlP = "
  SELECT p.project_id, p.project_name, p.start_date, p.end_date, p.teamLeadder_id,
         u.name AS leader_name, u.email AS leader_email
  FROM projects_x p
  LEFT JOIN users u ON u.user_id = p.teamLeadder_id
  WHERE p.project_id = ?
";
$st = $conn->prepare($sqlP);
$st->bind_param('i', $pid);
$st->execute();
$proj = $st->get_result()->fetch_assoc();
$st->close();

if (!$proj) {
  exit('<div style="color:#b91c1c">Project not found.</div>');
}

/* ------------------------------------------
   STATUS ROLLUP (overall by project_id)
---------------------------------------------*/
$sqlR = "
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status='Completed'    THEN 1 ELSE 0 END) AS done,
    SUM(CASE WHEN status='In progress'  THEN 1 ELSE 0 END) AS inprog,
    SUM(CASE WHEN status='In review'    THEN 1 ELSE 0 END) AS inrev,
    SUM(CASE WHEN status='On hold'      THEN 1 ELSE 0 END) AS onhold,
    SUM(CASE WHEN dueDate < CURDATE() AND status <> 'Completed' THEN 1 ELSE 0 END) AS overdue
  FROM tasks
  WHERE project_id = ?
";
$st = $conn->prepare($sqlR);
$st->bind_param('i', $pid);
$st->execute();
$roll = $st->get_result()->fetch_assoc();
$st->close();

$roll = $roll ?: [
  'total'   => 0,
  'done'    => 0,
  'inprog'  => 0,
  'inrev'   => 0,
  'onhold'  => 0,
  'overdue' => 0,
];
$roll = array_map('intval', $roll);
$pct  = $roll['total'] > 0 ? round(($roll['done'] / $roll['total']) * 100, 1) : 0;

/* ------------------------------------------
   SUBPROJECTS
---------------------------------------------*/
$subprojects = [];
$subStatsNote = ''; // message if tasks can't be rolled up by subproject

if ($hasSubProjects) {
  $sqlSub = "
    SELECT 
      sp.sub_project_id,
      sp.sub_project_name,
      sp.start_date,
      sp.end_date,
      sp.teamLeadder_id,
      u.name AS leader_name,
      u.email AS leader_email
    FROM sub_projects sp
    LEFT JOIN users u ON u.user_id = sp.teamLeadder_id
    WHERE sp.project_id = ?
    ORDER BY sp.sub_project_name ASC
  ";
  $st = $conn->prepare($sqlSub);
  $st->bind_param('i', $pid);
  $st->execute();
  $subprojects = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  // If tasks table has no subproject FK column, don't crash — just show 0s
  if (!$taskSubCol) {
    $subStatsNote = "Note: tasks are not linked to subprojects in the tasks table, so subproject stats show 0.";
    foreach ($subprojects as &$sp) {
      $sp['roll'] = ['total'=>0,'done'=>0,'inprog'=>0,'inrev'=>0,'onhold'=>0,'overdue'=>0];
      $sp['pct']  = 0;
    }
    unset($sp);
  } else {
    foreach ($subprojects as &$sp) {
      $sqlSPR = "
        SELECT
          COUNT(*) AS total,
          SUM(CASE WHEN status='Completed'    THEN 1 ELSE 0 END) AS done,
          SUM(CASE WHEN status='In progress'  THEN 1 ELSE 0 END) AS inprog,
          SUM(CASE WHEN status='In review'    THEN 1 ELSE 0 END) AS inrev,
          SUM(CASE WHEN status='On hold'      THEN 1 ELSE 0 END) AS onhold,
          SUM(CASE WHEN dueDate < CURDATE() AND status <> 'Completed' THEN 1 ELSE 0 END) AS overdue
        FROM tasks
        WHERE `$taskSubCol` = ?
      ";
      $st = $conn->prepare($sqlSPR);
      $st->bind_param("i", $sp['sub_project_id']);
      $st->execute();
      $sp['roll'] = $st->get_result()->fetch_assoc();
      $st->close();

      $sp['roll'] = $sp['roll'] ?: ['total'=>0,'done'=>0,'inprog'=>0,'inrev'=>0,'onhold'=>0,'overdue'=>0];
      $total = (int)$sp['roll']['total'];
      $sp['pct'] = $total > 0 ? round(((int)$sp['roll']['done'] / $total) * 100, 1) : 0;
    }
    unset($sp);
  }
}

/* ------------------------------------------
   TEAM SUMMARY
---------------------------------------------*/
$sqlT = "
  SELECT COALESCE(u.name, t.assignTo, 'Unassigned') AS who, COUNT(*) cnt
  FROM tasks t
  LEFT JOIN users u ON t.assignTo = u.name
  WHERE t.project_id = ?
  GROUP BY who
  ORDER BY cnt DESC, who ASC
";
$st = $conn->prepare($sqlT);
$st->bind_param('i', $pid);
$st->execute();
$rs = $st->get_result();

$teamPairs = [];
while ($r = $rs->fetch_assoc()) {
  $teamPairs[] = esc($r['who']) . ' (' . (int)$r['cnt'] . ')';
}
$st->close();

$teamSummary = $teamPairs ? implode(', ', array_slice($teamPairs, 0, 8)) : '—';

/* ------------------------------------------
   TASK LIST
---------------------------------------------*/
$sqlTasks = "
  SELECT t.task_id, t.taskTitle, t.status, t.dueDate,
         COALESCE(u.name, t.assignTo, 'Unassigned') AS assignee
  FROM tasks t
  LEFT JOIN users u ON t.assignTo = u.name
  WHERE t.project_id = ?
  ORDER BY FIELD(t.status,'In progress','In review','On hold','Completed'), t.dueDate ASC
  LIMIT 200
";
$st = $conn->prepare($sqlTasks);
$st->bind_param('i', $pid);
$st->execute();
$list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ------------------------------------------
   HEADER VALUES
---------------------------------------------*/
$leaderName  = $proj['leader_name'] ? esc($proj['leader_name']) : '—';
$leaderEmail = $proj['leader_email'] ? esc($proj['leader_email']) : '';
$leaderLabel = $leaderEmail
  ? $leaderName . ' <span class="tiny" style="color:#6b7280">(' . esc($leaderEmail) . ')</span>'
  : $leaderName;

$start = $proj['start_date'] ? esc($proj['start_date']) : '—';
$end   = $proj['end_date']   ? esc($proj['end_date'])   : '—';

$projName = esc($proj['project_name']);
?>
<div data-proj-title="<?= $projName ?>" style="font-family:system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size:14px; color:#111827;">
  <h4 style="margin:0 0 4px 0; font-size:18px;"><?= $projName ?></h4>
  <div style="margin-bottom:10px; font-size:12px; color:#6b7280;">
    <span>Leader: <?= $leaderLabel ?></span><br>
    <span>Start: <?= $start ?> &nbsp; | &nbsp; End: <?= $end ?></span><br>
    <span>Team: <?= $teamSummary ?></span>
  </div>

  <div style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
    <div style="flex:1; min-width:160px; padding:8px; border:1px solid #e5e7eb; border-radius:8px;">
      <div style="font-size:11px; text-transform:uppercase; color:#6b7280;">Overall progress</div>
      <div style="font-size:20px; font-weight:600; margin:2px 0;"><?= $pct ?>%</div>
      <div style="height:8px; background:#e5e7eb; border-radius:999px; overflow:hidden;">
        <div style="width:<?= $pct ?>%; height:8px; background:#1d4ed8;"></div>
      </div>
      <div style="font-size:11px; color:#6b7280; margin-top:4px;">
        <?= $roll['done'] ?>/<?= $roll['total'] ?> tasks completed • <?= $roll['overdue'] ?> overdue
      </div>
    </div>

    <div style="flex:1; min-width:160px; padding:8px; border:1px solid #e5e7eb; border-radius:8px;">
      <div style="font-size:11px; text-transform:uppercase; color:#6b7280;">Status breakdown</div>
      <div style="font-size:12px; margin-top:4px;">
        <div>In progress: <strong><?= $roll['inprog'] ?></strong></div>
        <div>In review: <strong><?= $roll['inrev'] ?></strong></div>
        <div>On hold: <strong><?= $roll['onhold'] ?></strong></div>
        <div style="color:#b91c1c;">Overdue: <strong><?= $roll['overdue'] ?></strong></div>
      </div>
    </div>
  </div>

  <?php if ($hasSubProjects): ?>
    <h5 style="margin:8px 0 4px 0; font-size:14px;">Subprojects</h5>

    <?php if ($subStatsNote): ?>
      <div style="margin:0 0 8px 0; font-size:12px; color:#92400e; background:#fffbeb; border:1px solid #fde68a; padding:8px; border-radius:8px;">
        <?= esc($subStatsNote) ?>
      </div>
    <?php endif; ?>

    <?php if (!$subprojects): ?>
      <div style="font-size:12px; color:#6b7280; margin-bottom:8px;">No subprojects found for this project.</div>
    <?php else: ?>
      <table style="width:100%; border-collapse:collapse; margin-bottom:10px; font-size:12px;">
        <thead>
          <tr style="background:#f9fafb;">
            <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Subproject</th>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Leader</th>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Progress</th>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Start</th>
            <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">End</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subprojects as $sp):
            $spName  = esc($sp['sub_project_name']);
            $spLead  = $sp['leader_name'] ? esc($sp['leader_name']) : '—';
            $spStart = $sp['start_date'] ? esc($sp['start_date']) : '—';
            $spEnd   = $sp['end_date']   ? esc($sp['end_date'])   : '—';
            $spRoll  = $sp['roll'] ?? ['total'=>0,'done'=>0,'overdue'=>0];
            $spPct   = $sp['pct']  ?? 0;
          ?>
            <tr>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6;"><?= $spName ?></td>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6;"><?= $spLead ?></td>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6;">
                <div style="font-size:11px; margin-bottom:2px;"><?= (float)$spPct ?>%</div>
                <div style="height:6px; background:#e5e7eb; border-radius:999px; overflow:hidden;">
                  <div style="width:<?= (float)$spPct ?>%; height:6px; background:#10b981;"></div>
                </div>
                <div style="font-size:10px; color:#6b7280; margin-top:2px;">
                  <?= (int)$spRoll['done'] ?>/<?= (int)$spRoll['total'] ?> done, <?= (int)$spRoll['overdue'] ?> overdue
                </div>
              </td>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6;"><?= $spStart ?></td>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6;"><?= $spEnd ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>

  <h5 style="margin:8px 0 4px 0; font-size:14px;">Tasks (max 200)</h5>
  <?php if (!$list): ?>
    <div style="font-size:12px; color:#6b7280;">No tasks found for this project.</div>
  <?php else: ?>
    <table style="width:100%; border-collapse:collapse; font-size:12px;">
      <thead>
        <tr style="background:#f9fafb;">
          <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Task</th>
          <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Assignee</th>
          <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Status</th>
          <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Due</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $t):
          $status = $t['status'];
          $badgeColor = '#6b7280';
          if ($status === 'Completed')       $badgeColor = '#16a34a';
          elseif ($status === 'In progress') $badgeColor = '#1d4ed8';
          elseif ($status === 'In review')   $badgeColor = '#f59e0b';
          elseif ($status === 'On hold')     $badgeColor = '#92400e';
        ?>
          <tr>
            <td style="padding:6px; border-bottom:1px solid #f3f4f6;"><?= esc($t['taskTitle']) ?></td>
            <td style="padding:6px; border-bottom:1px solid #f3f4f6;"><?= esc($t['assignee']) ?></td>
            <td style="padding:6px; border-bottom:1px solid #f3f4f6;">
              <span style="display:inline-block; padding:2px 6px; border-radius:999px; font-size:11px; color:#fff; background:<?= $badgeColor ?>;">
                <?= esc($status) ?>
              </span>
            </td>
            <td style="padding:6px; border-bottom:1px solid #f3f4f6;"><?= esc($t['dueDate']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
