<?php
session_start();
require_once __DIR__ . '/../dp.php';

if (strtolower(trim($_SESSION['role'] ?? '')) !== 'manager') {
  exit('Unauthorized');
}

date_default_timezone_set('Africa/Johannesburg');

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['name'] ?? 'Manager';

/* Normalised status expression, so 'In Progress', 'inprogress', 'Done', etc. all bucket the same way */
const ST = "LOWER(REPLACE(TRIM(status),' ',''))";

/* ===============================
   DATA - LIMITED TO 10 ITEMS
================================ */

/* IN PROGRESS TASKS (in progress + pending) */
$inprogressTasks = [];
$inprogressTotal = 0;

$q = $conn->query("SELECT COUNT(*) AS total FROM tasks WHERE " . ST . " IN ('inprogress','pending')") or die($conn->error);
if ($r = $q->fetch_assoc()) $inprogressTotal = (int)$r['total'];

$q = $conn->query("
  SELECT
    t.task_id,
    t.taskTitle AS task,
    COALESCE(creator.name, CONCAT('User #', t.created_by)) AS created_by_name,
    t.assignTo AS assignee,
    t.dueDate AS due,
    t.status
  FROM tasks t
  LEFT JOIN users creator ON creator.user_id = t.created_by
  WHERE LOWER(REPLACE(TRIM(t.status),' ','')) IN ('inprogress','pending')
  ORDER BY t.dueDate IS NULL, t.dueDate DESC
  LIMIT 10
") or die($conn->error);
while ($r = $q->fetch_assoc()) $inprogressTasks[] = $r;

/* COMPLETED TASKS */
$completedTasks = [];
$completedTotal = 0;

$q = $conn->query("SELECT COUNT(*) AS total FROM tasks WHERE " . ST . " IN ('completed','done')") or die($conn->error);
if ($r = $q->fetch_assoc()) $completedTotal = (int)$r['total'];

$q = $conn->query("
  SELECT
    t.task_id,
    t.taskTitle AS task,
    COALESCE(creator.name, CONCAT('User #', t.created_by)) AS created_by_name,
    t.assignTo AS assignee,
    t.dueDate AS due,
    t.status
  FROM tasks t
  LEFT JOIN users creator ON creator.user_id = t.created_by
  WHERE LOWER(REPLACE(TRIM(t.status),' ','')) IN ('completed','done')
  ORDER BY t.dueDate IS NULL, t.dueDate DESC
  LIMIT 10
") or die($conn->error);
while ($r = $q->fetch_assoc()) $completedTasks[] = $r;

/* ACTIVE PROJECTS */
$activeProjects = [];
$activeTotal = 0;

$q = $conn->query("SELECT COUNT(*) AS total FROM projects_x WHERE end_date >= CURDATE()") or die($conn->error);
if ($r = $q->fetch_assoc()) $activeTotal = (int)$r['total'];

$q = $conn->query("
  SELECT p.project_id,
         p.project_name AS project,
         u.name AS leader,
         p.start_date AS start,
         p.end_date AS end,
         (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.project_id) AS tasks
  FROM projects_x p
  LEFT JOIN users u ON u.user_id=p.teamLeadder_id
  WHERE p.end_date >= CURDATE()
  ORDER BY p.end_date ASC
  LIMIT 10
") or die($conn->error);
while ($r = $q->fetch_assoc()) $activeProjects[] = $r;

/* COMPLETED PROJECTS */
$completedProjects = [];
$completedProjectsTotal = 0;

$q = $conn->query("SELECT COUNT(*) AS total FROM projects_x WHERE end_date < CURDATE()") or die($conn->error);
if ($r = $q->fetch_assoc()) $completedProjectsTotal = (int)$r['total'];

$q = $conn->query("
  SELECT p.project_id,
         p.project_name AS project,
         u.name AS leader,
         p.start_date AS start,
         p.end_date AS end,
         (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.project_id) AS tasks,
         (SELECT COUNT(*) FROM sub_projects s WHERE s.project_id=p.project_id) AS subs
  FROM projects_x p
  LEFT JOIN users u ON u.user_id=p.teamLeadder_id
  WHERE p.end_date < CURDATE()
  ORDER BY p.end_date DESC
  LIMIT 10
") or die($conn->error);
while ($r = $q->fetch_assoc()) $completedProjects[] = $r;

/* USERS SUMMARY */
$userSummary = [];
$q = $conn->query("SELECT role, COUNT(*) total FROM users GROUP BY role") or die($conn->error);
while ($r = $q->fetch_assoc()) {
  $userSummary[] = ['role' => ucfirst($r['role']) . 's', 'count' => (int)$r['total']];
}

/* USERS DATA FOR MODAL */
$usersData = [];
$q = $conn->query("
  SELECT user_id, name, email, role, department, status,
    (SELECT COUNT(*) FROM tasks t WHERE t.created_by=users.user_id OR t.created_by=users.name) AS task_count,
    (SELECT COUNT(*) FROM projects_x p WHERE p.teamLeadder_id=users.user_id) AS project_count
  FROM users
  ORDER BY name ASC
  LIMIT 10
") or die($conn->error);
while ($r = $q->fetch_assoc()) $usersData[] = $r;

/* MANAGER ACTIVITY */
$managerActivities = [];
$q = $conn->query("
  SELECT project_name AS project, 'Project Team' AS team, start_date AS start, end_date AS end
  FROM projects_x
  WHERE created_by = $userId
  ORDER BY end_date DESC
  LIMIT 10
") or die($conn->error);
while ($r = $q->fetch_assoc()) $managerActivities[] = $r;

/* ===============================
   STAT CARD SUB-LINES
================================ */
$startedThisMonth = $endedThisMonth = $endingSoon = $pendingTotal = 0;

$q = $conn->query("
  SELECT
    SUM(start_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')) AS started_m,
    SUM(end_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') AND end_date < CURDATE()) AS ended_m,
    SUM(end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS ending_soon
  FROM projects_x
") or die($conn->error);
if ($r = $q->fetch_assoc()) {
  $startedThisMonth = (int)$r['started_m'];
  $endedThisMonth   = (int)$r['ended_m'];
  $endingSoon       = (int)$r['ending_soon'];
}

/* ===============================
   PROJECT PROGRESS (task buckets)
================================ */
$taskAll = $taskDone = $taskProg = $taskPend = 0;
$q = $conn->query("
  SELECT COUNT(*) AS total,
    SUM(" . ST . " IN ('completed','done')) AS done,
    SUM(" . ST . " = 'inprogress') AS prog,
    SUM(" . ST . " = 'pending') AS pend
  FROM tasks
") or die($conn->error);
if ($r = $q->fetch_assoc()) {
  $taskAll  = (int)$r['total'];
  $taskDone = (int)$r['done'];
  $taskProg = (int)$r['prog'];
  $taskPend = (int)$r['pend'];
}
$pendingTotal = $taskPend;
$taskOther    = max(0, $taskAll - $taskDone - $taskProg - $taskPend);
$donePct      = $taskAll > 0 ? (int)round($taskDone * 100 / $taskAll) : 0;

/* ===============================
   PROJECT ANALYTICS (tasks due per day, this week Sun–Sat)
================================ */
$today     = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('-' . date('w') . ' days'));
$weekRaw   = [];

$stmt = $conn->prepare("
  SELECT DATE(dueDate) AS d, COUNT(*) AS total,
    SUM(" . ST . " IN ('completed','done')) AS done
  FROM tasks
  WHERE dueDate IS NOT NULL AND dueDate <> ''
    AND DATE(dueDate) BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY)
  GROUP BY DATE(dueDate)
");
if ($stmt) {
  $stmt->bind_param('ss', $weekStart, $weekStart);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $weekRaw[$r['d']] = $r;
  $stmt->close();
}

$weekDays = [];
$weekDue = $weekDoneTotal = $maxDay = 0;
for ($i = 0; $i < 7; $i++) {
  $d     = date('Y-m-d', strtotime("$weekStart +$i days"));
  $total = (int)($weekRaw[$d]['total'] ?? 0);
  $done  = (int)($weekRaw[$d]['done'] ?? 0);
  $weekDays[] = [
    'date'  => $d,
    'label' => substr(date('D', strtotime($d)), 0, 1),
    'total' => $total,
    'done'  => $done,
    'pct'   => $total > 0 ? (int)round($done * 100 / $total) : 0,
    'state' => $d < $today ? 'past' : ($d === $today ? 'today' : 'future'),
  ];
  $weekDue += $total;
  $weekDoneTotal += $done;
  $maxDay = max($maxDay, $total);
}

/* ===============================
   REMINDER (nearest upcoming deadline)
================================ */
$reminder = null;

$q = $conn->query("
  SELECT project_id, project_name, end_date
  FROM projects_x
  WHERE end_date >= CURDATE()
  ORDER BY end_date ASC
  LIMIT 1
") or die($conn->error);
if ($r = $q->fetch_assoc()) {
  $reminder = ['kind' => 'project', 'id' => (int)$r['project_id'], 'title' => $r['project_name'], 'date' => $r['end_date']];
}

$q = $conn->query("
  SELECT task_id, taskTitle, dueDate
  FROM tasks
  WHERE dueDate IS NOT NULL AND dueDate <> ''
    AND DATE(dueDate) >= CURDATE()
    AND " . ST . " NOT IN ('completed','done')
  ORDER BY dueDate ASC
  LIMIT 1
") or die($conn->error);
if ($r = $q->fetch_assoc()) {
  if (!$reminder || strtotime($r['dueDate']) < strtotime($reminder['date'])) {
    $reminder = ['kind' => 'task', 'id' => (int)$r['task_id'], 'title' => $r['taskTitle'] ?: 'Task', 'date' => $r['dueDate']];
  }
}

/* ===============================
   TEAM COLLABORATION (latest task per member)
================================ */
$teamRows = [];
$q = $conn->query("
  SELECT t.task_id, t.taskTitle, t.status,
         COALESCE(NULLIF(a.name,''), t.assignTo) AS member
  FROM tasks t
  LEFT JOIN users a ON a.user_id = t.assignTo
  WHERE t.assignTo IS NOT NULL AND t.assignTo <> ''
  ORDER BY t.task_id DESC
  LIMIT 40
") or die($conn->error);
$seen = [];
while ($r = $q->fetch_assoc()) {
  $key = strtolower(trim((string)$r['member']));
  if ($key === '' || isset($seen[$key])) continue;
  $seen[$key] = true;
  $teamRows[] = $r;
  if (count($teamRows) >= 4) break;
}

/* ===============================
   CALENDAR EVENTS
================================ */
$calendarEvents = [];

$q = $conn->query("
  SELECT task_id, taskTitle, dueDate, status
  FROM tasks
  WHERE dueDate IS NOT NULL AND dueDate <> ''
  ORDER BY dueDate ASC
  LIMIT 50
") or die($conn->error);
while ($r = $q->fetch_assoc()) {
  $st = strtolower(trim((string)$r['status']));
  $calendarEvents[] = [
    'id'    => 't' . $r['task_id'],
    'title' => 'Task: ' . ($r['taskTitle'] ?: 'Task'),
    'start' => $r['dueDate'],
    'color' => ($st === 'completed' || $st === 'done') ? '#2f9e6b' : '#1b6b45',
  ];
}

$q = $conn->query("
  SELECT project_id, project_name, end_date
  FROM projects_x
  WHERE end_date IS NOT NULL AND end_date <> ''
  ORDER BY end_date ASC
  LIMIT 50
") or die($conn->error);
while ($r = $q->fetch_assoc()) {
  $calendarEvents[] = [
    'id'    => 'p' . $r['project_id'],
    'title' => 'Project End: ' . ($r['project_name'] ?: 'Project'),
    'start' => $r['end_date'],
    'color' => '#0f3d2a',
  ];
}

/* Summary */
$totalProjects = $activeTotal + $completedProjectsTotal;
$totalUsers    = array_sum(array_column($userSummary, 'count'));

/* ===============================
   VIEW HELPERS
================================ */
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDate($d, $fmt = 'M d, Y'){ return !empty($d) && strtotime($d) ? date($fmt, strtotime($d)) : '—'; }
function statusKey($s){ return strtolower(str_replace(' ', '', trim((string)$s))); }
function pillClass($s){
  $k = statusKey($s);
  if ($k === 'completed' || $k === 'done') return 'dz-pill-done';
  if ($k === 'inprogress') return 'dz-pill-prog';
  if ($k === 'pending')    return 'dz-pill-pend';
  return 'dz-pill-neutral';
}
function initials($name){
  $parts = preg_split('/\s+/', trim((string)$name));
  $out = '';
  foreach (array_slice($parts, 0, 2) as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
  return $out ?: '?';
}
function avatarHue($name){ return abs(crc32((string)$name)) % 360; }

$J = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

$projectIcons = [
  ['fa-code', '#3b5bdb'], ['fa-arrows-spin', '#0ca678'], ['fa-cubes', '#f59f00'],
  ['fa-bolt', '#e8590c'], ['fa-globe', '#7048e8'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manager Dashboard</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <style>
  /* Everything is scoped under .dz so nothing leaks into manager_index.php when this page is injected */
  .dz{
    --dz-bg:#f4f6f4; --dz-card:#ffffff; --dz-soft:#f1f4f2; --dz-border:#e6ebe7;
    --dz-text:#111a14; --dz-muted:#7b867f;
    --g900:#0f3d2a; --g700:#1b6b45; --g500:#2f9e6b; --g300:#7fd1a8; --g100:#e3f4ea;
    --dz-amber:#c27c0e; --dz-amber-bg:#fff4dc; --dz-red:#c92a2a; --dz-red-bg:#ffe8e8;
    --dz-radius:22px; --dz-radius-sm:14px;
    --dz-shadow:0 1px 2px rgba(16,40,26,.04),0 8px 24px rgba(16,40,26,.05);
    --dz-ease:all .22s cubic-bezier(.2,.75,.25,1);
    color:var(--dz-text); font-family:inherit; line-height:1.45;
  }
  body.dark .dz{
    --dz-bg:#0f172a; --dz-card:#1e293b; --dz-soft:#273449; --dz-border:#334155;
    --dz-text:#f1f5f9; --dz-muted:#94a3b8; --g100:rgba(47,158,107,.18);
    --dz-amber-bg:rgba(245,159,0,.15); --dz-red-bg:rgba(240,62,62,.15);
  }
  .dz *,.dz *::before,.dz *::after{box-sizing:border-box}
  .dz h1,.dz h2,.dz h3,.dz p{margin:0}
  .dz a{color:inherit;text-decoration:none}
  .dz button{font-family:inherit}

  /* ---------- Header ---------- */
  .dz-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:22px}
  .dz-head h1{font-size:clamp(26px,3vw,38px);font-weight:600;letter-spacing:-.03em}
  .dz-head p{color:var(--dz-muted);font-size:14px;margin-top:4px}
  .dz-actions{display:flex;gap:10px;flex-wrap:wrap}
  .dz-btn{display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 20px;border-radius:999px;font-size:14px;font-weight:500;cursor:pointer;transition:var(--dz-ease);border:1.5px solid transparent;white-space:nowrap}
  .dz-btn-primary{background:linear-gradient(135deg,var(--g700),var(--g900));color:#fff!important}
  .dz-btn-primary:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(15,61,42,.25)}
  .dz-btn-ghost{background:transparent;border-color:var(--g700);color:var(--g700)!important}
  body.dark .dz .dz-btn-ghost{border-color:var(--g300);color:var(--g300)!important}
  .dz-btn-ghost:hover{background:var(--g100)}
  .dz-btn-sm{height:32px;padding:0 14px;font-size:12.5px}

  /* ---------- Cards ---------- */
  .dz-card{background:var(--dz-card);border-radius:var(--dz-radius);padding:20px 22px;box-shadow:var(--dz-shadow);border:1px solid var(--dz-border);min-width:0;position:relative}
  .dz-card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}
  .dz-card-head h3{font-size:16px;font-weight:500}
  .dz-sub{font-size:12px;color:var(--dz-muted)}
  .dz-empty{color:var(--dz-muted);font-size:13px;padding:18px 0;text-align:center}

  /* ---------- Stat cards ---------- */
  .dz-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:16px}
  .dz-stat{cursor:pointer;transition:var(--dz-ease)}
  .dz-stat:hover{transform:translateY(-2px)}
  .dz-stat-top{display:flex;justify-content:space-between;align-items:center}
  .dz-stat-label{font-size:15px;font-weight:500}
  .dz-arrow{width:34px;height:34px;border-radius:50%;border:1.5px solid var(--dz-text);display:grid;place-items:center;font-size:12px;transform:rotate(-45deg);background:transparent;color:inherit;transition:var(--dz-ease)}
  .dz-stat:hover .dz-arrow{transform:rotate(0)}
  .dz-stat-value{font-size:46px;font-weight:600;letter-spacing:-.03em;line-height:1.1;margin:10px 0 8px}
  .dz-stat-foot{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--g500)}
  .dz-stat-foot .dz-chip{border:1px solid currentColor;border-radius:5px;padding:0 4px;font-size:10px;line-height:15px}
  .dz-stat--hero{background:linear-gradient(140deg,var(--g700) 0%,var(--g900) 100%);color:#fff;border-color:transparent}
  .dz-stat--hero .dz-arrow{border-color:#fff;background:#fff;color:var(--g900)}
  .dz-stat--hero .dz-stat-foot{color:#c8f0d9}

  /* ---------- Bento grid ---------- */
  .dz-bento{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px;margin-bottom:16px}
  .dz-an{grid-column:span 5}.dz-re{grid-column:span 3}.dz-pr{grid-column:span 4}
  .dz-tm{grid-column:span 4}.dz-pg{grid-column:span 4}.dz-tt{grid-column:span 4}

  /* Analytics pill bars */
  .dz-bars{display:grid;grid-template-columns:repeat(7,1fr);gap:10px;height:170px;align-items:end;padding-top:24px}
  .dz-bar-col{display:flex;flex-direction:column;align-items:center;gap:8px;height:100%}
  .dz-bar-track{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center}
  .dz-bar{width:100%;max-width:46px;border-radius:999px;position:relative;transition:height .7s cubic-bezier(.2,.75,.25,1)}
  .dz-bar--past{background:var(--g500)}
  .dz-bar--today{background:var(--g900)}
  .dz-bar--future,.dz-bar--empty{background:repeating-linear-gradient(135deg,transparent 0 5px,#9aa59e 5px 7px);border:1.5px solid #9aa59e}
  .dz-bar-label{font-size:12px;color:var(--dz-muted)}
  .dz-bar-col.is-today .dz-bar-label{color:var(--dz-text);font-weight:600}
  .dz-bar-tip{position:absolute;left:50%;top:-28px;transform:translateX(-50%);font-size:11px;padding:2px 7px;border-radius:7px;background:var(--dz-card);border:1px solid var(--dz-border);white-space:nowrap;color:var(--dz-text);opacity:0;transition:opacity .2s}
  .dz-bar-tip::after{content:"";position:absolute;left:50%;bottom:-6px;width:8px;height:8px;border-radius:50%;background:var(--dz-card);border:2px solid var(--g500);transform:translateX(-50%)}
  .dz-bar-col:hover .dz-bar-tip,.dz-bar-col.is-today .dz-bar-tip{opacity:1}

  /* Reminder */
  .dz-re{display:flex;flex-direction:column}
  .dz-rem-title{font-size:22px;font-weight:500;color:var(--g700);line-height:1.2;letter-spacing:-.01em;margin-bottom:8px;word-break:break-word}
  body.dark .dz .dz-rem-title{color:var(--g300)}
  .dz-rem-kind{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--dz-muted);margin-bottom:4px}
  .dz-rem-time{font-size:13px;color:var(--dz-muted)}
  .dz-re .dz-btn{margin-top:auto;justify-content:center;width:100%}

  /* Project list */
  .dz-plist{list-style:none;margin:0;padding:0;display:grid;gap:12px}
  .dz-plist li{display:flex;gap:12px;align-items:center;cursor:pointer;border-radius:10px;padding:2px;transition:var(--dz-ease)}
  .dz-plist li:hover{background:var(--dz-soft)}
  .dz-picon{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-size:14px;flex-shrink:0}
  .dz-pname{font-size:14px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

  /* Team */
  .dz-team{list-style:none;margin:0;padding:0;display:grid;gap:12px}
  .dz-team li{display:flex;align-items:center;gap:12px;cursor:pointer;border-radius:12px;padding:2px;transition:var(--dz-ease)}
  .dz-team li:hover{background:var(--dz-soft)}
  .dz-avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;font-weight:600;font-size:14px;flex-shrink:0}
  .dz-team-main{flex:1;min-width:0}
  .dz-team-name{font-size:14px;font-weight:500}
  .dz-team-task{font-size:12px;color:var(--dz-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .dz-team-task b{color:var(--dz-text);font-weight:500}
  .dz-pill{font-size:11px;padding:3px 8px;border-radius:6px;white-space:nowrap;border:1px solid transparent;text-transform:capitalize}
  .dz-pill-done{background:var(--g100);color:var(--g700);border-color:rgba(47,158,107,.3)}
  .dz-pill-prog{background:var(--dz-amber-bg);color:var(--dz-amber);border-color:rgba(194,124,14,.25)}
  .dz-pill-pend{background:var(--dz-red-bg);color:var(--dz-red);border-color:rgba(201,42,42,.2)}
  .dz-pill-neutral{background:var(--dz-soft);color:var(--dz-muted)}
  body.dark .dz .dz-pill-done{color:var(--g300)}

  /* Progress gauge */
  .dz-gauge{position:relative;height:170px;margin:0 auto;max-width:300px}
  .dz-gauge-center{position:absolute;left:0;right:0;bottom:6px;text-align:center;pointer-events:none}
  .dz-gauge-center strong{display:block;font-size:42px;font-weight:600;letter-spacing:-.03em;line-height:1}
  .dz-gauge-center span{font-size:12px;color:var(--dz-muted)}
  .dz-legend{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:14px;font-size:12px;color:var(--dz-muted)}
  .dz-legend i{display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:5px;vertical-align:-2px}

  /* Time tracker */
  .dz-tt{color:#fff;border-color:transparent;overflow:hidden;
    background:
      repeating-radial-gradient(circle at 110% 120%,rgba(255,255,255,.07) 0 2px,transparent 2px 16px),
      radial-gradient(circle at 80% 100%,#2f9e6b 0%,transparent 60%),
      linear-gradient(150deg,#14482f,#082417)}
  .dz-tt h3{font-size:16px;font-weight:500}
  .dz-tt-time{font-size:clamp(34px,3.2vw,44px);font-weight:600;letter-spacing:.01em;text-align:center;margin:18px 0;font-variant-numeric:tabular-nums}
  .dz-tt-ctrl{display:flex;justify-content:center;gap:14px}
  .dz-tt-btn{width:48px;height:48px;border-radius:50%;border:none;background:#fff;color:var(--g900);display:grid;place-items:center;font-size:16px;cursor:pointer;transition:var(--dz-ease)}
  .dz-tt-btn:hover{transform:scale(1.07)}
  .dz-tt-btn.stop{color:#e03131}
  .dz-tt-note{text-align:center;font-size:11.5px;color:#b9dcc8;margin-top:12px}

  /* ---------- Lower: detail tables + calendar ---------- */
  .dz-lower{display:grid;grid-template-columns:minmax(0,7fr) minmax(0,5fr);gap:16px}
  .dz-search{position:relative}
  .dz-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--dz-muted);font-size:13px}
  .dz-search input{height:40px;width:260px;max-width:100%;border-radius:999px;border:1px solid var(--dz-border);background:var(--dz-soft);color:var(--dz-text);padding:0 14px 0 36px;font:inherit;font-size:13px;outline:none}
  .dz-search input:focus{border-color:var(--g500);box-shadow:0 0 0 3px var(--g100)}

  .dz .section{border-top:1px solid var(--dz-border)}
  .dz .section-head{padding:14px 4px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;user-select:none;gap:12px}
  .dz .section-title{display:flex;align-items:center;gap:12px;min-width:0}
  .dz .section-title > i{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:var(--g100);color:var(--g700);flex-shrink:0}
  body.dark .dz .section-title > i{color:var(--g300)}
  .dz .section-name{font-size:14.5px;font-weight:500}
  .dz .section-subtitle{font-size:12px;color:var(--dz-muted)}
  .dz .section-icon{color:var(--dz-muted);transition:transform .3s}
  .dz .section.open .section-icon{transform:rotate(180deg)}
  .dz .section-body{max-height:0;overflow:hidden;opacity:0;transition:max-height .35s ease,opacity .3s}
  .dz .section.open .section-body{max-height:1400px;opacity:1}
  .dz .section-badge{display:flex;align-items:center;gap:4px}
  .dz .badge-count{font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;background:var(--g100);color:var(--g700)}
  body.dark .dz .badge-count{color:var(--g300)}
  .dz .badge-total{font-size:11px;color:var(--dz-muted)}
  .dz .table-wrap{overflow:auto;max-height:420px;border:1px solid var(--dz-border);border-radius:var(--dz-radius-sm);margin:0 0 14px}
  .dz .table{width:100%;border-collapse:collapse;font-size:13px}
  .dz .table th{position:sticky;top:0;background:var(--dz-soft);text-align:left;padding:11px 14px;font-weight:500;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--dz-muted);white-space:nowrap;z-index:1}
  .dz .table td{padding:11px 14px;border-top:1px solid var(--dz-border)}
  .dz .table tbody tr:hover{background:var(--dz-soft)}
  .dz .highlight{background:var(--g100)!important}
  .dz .highlight-pulse{animation:dzPulse 1s ease}
  .dz .view-more-btn{margin:0 0 14px}
  .dz .icon-btn{width:32px;height:32px;border-radius:9px;border:1px solid var(--dz-border);background:var(--dz-card);color:var(--dz-text);cursor:pointer;display:grid;place-items:center}
  .dz .icon-btn:hover{background:var(--g700);color:#fff;border-color:var(--g700)}

  .dz-cal .fc{font-size:12px}
  .dz-cal .fc .fc-toolbar-title{font-size:15px;font-weight:600}
  .dz-cal .fc .fc-button-primary{background:var(--g700);border-color:var(--g700);border-radius:999px;padding:4px 10px;font-size:12px;text-transform:capitalize}
  .dz-cal .fc .fc-button-primary:not(:disabled):hover,.dz-cal .fc .fc-button-primary:not(:disabled).fc-button-active{background:var(--g900);border-color:var(--g900)}
  .dz-cal .fc .fc-daygrid-day.fc-day-today{background:var(--g100)}
  .dz-cal .fc-theme-standard td,.dz-cal .fc-theme-standard th,.dz-cal .fc-theme-standard .fc-scrollgrid{border-color:var(--dz-border)}
  .dz-cal .fc .fc-event{border:none;border-radius:5px;padding:1px 4px;cursor:pointer}

  /* ---------- Modals ---------- */
  .dz .modal{display:none;position:fixed;inset:0;background:rgba(8,20,14,.5);z-index:9999;backdrop-filter:blur(2px)}
  .dz .modal-content{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--dz-card);color:var(--dz-text);border-radius:var(--dz-radius);box-shadow:0 30px 70px rgba(0,0,0,.25);width:92%;max-width:920px;max-height:86vh;overflow:hidden;display:flex;flex-direction:column}
  .dz .modal-header{padding:18px 22px;border-bottom:1px solid var(--dz-border);display:flex;justify-content:space-between;align-items:center}
  .dz .modal-title{font-size:17px;font-weight:600;display:flex;align-items:center;gap:10px}
  .dz .modal-close{background:none;border:none;font-size:22px;color:var(--dz-muted);cursor:pointer;width:36px;height:36px;border-radius:10px}
  .dz .modal-close:hover{background:var(--dz-soft);color:var(--dz-text)}
  .dz .modal-body{overflow:auto;flex:1}
  .dz .modal-table{width:100%;border-collapse:collapse;font-size:13px}
  .dz .modal-table th{position:sticky;top:0;background:var(--dz-soft);text-align:left;padding:12px 14px;font-weight:500;color:var(--dz-muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.05em}
  .dz .modal-table td{padding:12px 14px;border-top:1px solid var(--dz-border)}
  .dz .modal-pagination{padding:14px 20px;border-top:1px solid var(--dz-border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
  .dz .pagination-info{font-size:13px;color:var(--dz-muted)}
  .dz .pagination-controls,.dz .pagination-numbers{display:flex;align-items:center;gap:6px}
  .dz .pagination-btn,.dz .page-number{min-width:34px;height:34px;padding:0 10px;border-radius:10px;border:1px solid var(--dz-border);background:var(--dz-card);color:var(--dz-text);cursor:pointer;font:inherit;font-size:13px;display:inline-flex;align-items:center;justify-content:center;gap:6px}
  .dz .pagination-btn:hover:not(:disabled),.dz .page-number:hover{border-color:var(--g500)}
  .dz .pagination-btn:disabled{opacity:.45;cursor:not-allowed}
  .dz .page-number.active{background:var(--g700);color:#fff;border-color:var(--g700)}
  .dz .page-ellipsis{padding:0 4px;color:var(--dz-muted)}
  .dz .td-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
  .dz .td-box{border:1px solid var(--dz-border);border-radius:var(--dz-radius-sm);padding:14px 16px;margin-bottom:12px;font-size:13.5px}
  .dz .td-box h4{margin:0 0 8px;font-size:14px;font-weight:600}
  .dz .td-box textarea{width:100%;min-height:96px;border-radius:12px;border:1px solid var(--dz-border);background:var(--dz-soft);color:var(--dz-text);padding:12px;font:inherit;font-size:13px;resize:vertical;outline:none}
  .dz .td-box textarea:focus{border-color:var(--g500)}

  .dz-toast{position:fixed;top:20px;right:20px;z-index:10000;background:var(--dz-card,#fff);color:var(--dz-text,#111);padding:12px 18px;border-radius:12px;box-shadow:0 14px 40px rgba(0,0,0,.18);border-left:4px solid #2f9e6b;font-size:13.5px;animation:dzSlideIn .3s ease}

  .dz-reveal{animation:dzRise .5s cubic-bezier(.2,.75,.25,1) backwards}
  @keyframes dzRise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
  @keyframes dzSlideIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:none}}
  @keyframes dzPulse{0%{box-shadow:0 0 0 0 rgba(47,158,107,.45)}70%{box-shadow:0 0 0 12px rgba(47,158,107,0)}100%{box-shadow:none}}

  /* ---------- Responsive ---------- */
  @media (max-width:1280px){
    .dz-an{grid-column:span 7}.dz-re{grid-column:span 5}.dz-pr{grid-column:span 6}
    .dz-tm{grid-column:span 6}.dz-pg{grid-column:span 6}.dz-tt{grid-column:span 6}
    .dz-lower{grid-template-columns:1fr}
  }
  @media (max-width:900px){
    .dz-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    .dz-bento > *{grid-column:1 / -1}
    .dz .td-grid{grid-template-columns:1fr}
  }
  @media (max-width:520px){
    .dz-stats{grid-template-columns:1fr}
    .dz-stat-value{font-size:38px}
    .dz-actions,.dz-actions .dz-btn{width:100%;justify-content:center}
    .dz-search input{width:100%}
  }
  @media (prefers-reduced-motion:reduce){.dz *{animation:none!important;transition:none!important}}
  @media print{.dz-actions,.dz-tt,.dz-search{display:none!important}}
  </style>
</head>

<body>
<div class="dz" id="dzRoot">

  <!-- ===== Header ===== -->
  <div class="dz-head">
    <div>
      <h1>Dashboard</h1>
      <p>Welcome back, <?= esc($userName) ?>. Plan, prioritise and track your team's work.</p>
    </div>
    <div class="dz-actions">
      <a href="managerProjects.php" data-page="managerProjects.php" class="dz-btn dz-btn-primary"><i class="fas fa-plus"></i> Add Project</a>
      <a href="leader_create-task.php" data-page="leader_create-task.php" class="dz-btn dz-btn-ghost"><i class="fas fa-list-check"></i> Create Task</a>
    </div>
  </div>

  <!-- ===== Stat cards ===== -->
  <div class="dz-stats">
    <div class="dz-card dz-stat dz-stat--hero" onclick="focusSection('activeProjects')">
      <div class="dz-stat-top"><span class="dz-stat-label">Total Projects</span><span class="dz-arrow"><i class="fas fa-arrow-right"></i></span></div>
      <div class="dz-stat-value"><?= (int)$totalProjects ?></div>
      <div class="dz-stat-foot"><span class="dz-chip"><?= (int)$startedThisMonth ?></span> started this month</div>
    </div>
    <div class="dz-card dz-stat" onclick="focusSection('completedProjects')">
      <div class="dz-stat-top"><span class="dz-stat-label">Ended Projects</span><span class="dz-arrow"><i class="fas fa-arrow-right"></i></span></div>
      <div class="dz-stat-value"><?= (int)$completedProjectsTotal ?></div>
      <div class="dz-stat-foot"><span class="dz-chip"><?= (int)$endedThisMonth ?></span> ended this month</div>
    </div>
    <div class="dz-card dz-stat" onclick="focusSection('activeProjects')">
      <div class="dz-stat-top"><span class="dz-stat-label">Running Projects</span><span class="dz-arrow"><i class="fas fa-arrow-right"></i></span></div>
      <div class="dz-stat-value"><?= (int)$activeTotal ?></div>
      <div class="dz-stat-foot"><span class="dz-chip"><?= (int)$endingSoon ?></span> ending within 7 days</div>
    </div>
    <div class="dz-card dz-stat" onclick="focusSection('inProgress')">
      <div class="dz-stat-top"><span class="dz-stat-label">Pending Tasks</span><span class="dz-arrow"><i class="fas fa-arrow-right"></i></span></div>
      <div class="dz-stat-value"><?= (int)$pendingTotal ?></div>
      <div class="dz-stat-foot" style="color:var(--dz-muted)"><?= (int)$taskProg ?> more in progress</div>
    </div>
  </div>

  <!-- ===== Bento grid ===== -->
  <div class="dz-bento">

    <!-- Project analytics -->
    <section class="dz-card dz-an">
      <div class="dz-card-head">
        <h3>Project Analytics</h3>
        <span class="dz-sub"><?= (int)$weekDue ?> due this week · <?= (int)$weekDoneTotal ?> done</span>
      </div>
      <div class="dz-bars">
        <?php foreach ($weekDays as $d):
          $h = $maxDay > 0 ? 30 + (int)round(70 * $d['total'] / $maxDay) : 30;
          $cls = ($d['state'] === 'past' && $d['total'] === 0) ? 'empty' : $d['state'];
          $tip = $d['total'] > 0 ? $d['pct'] . '%' : '0';
        ?>
        <div class="dz-bar-col<?= $d['state'] === 'today' ? ' is-today' : '' ?>"
             title="<?= esc(date('D, M d', strtotime($d['date']))) ?>: <?= (int)$d['total'] ?> due, <?= (int)$d['done'] ?> done">
          <div class="dz-bar-track">
            <div class="dz-bar dz-bar--<?= $cls ?>" style="height:<?= $h ?>%"><span class="dz-bar-tip"><?= esc($tip) ?></span></div>
          </div>
          <span class="dz-bar-label"><?= esc($d['label']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Reminder -->
    <section class="dz-card dz-re">
      <div class="dz-card-head"><h3>Reminders</h3></div>
      <?php if ($reminder):
        $daysLeft = (int)round((strtotime(date('Y-m-d', strtotime($reminder['date']))) - strtotime($today)) / 86400);
        $whenTxt  = $daysLeft === 0 ? 'today' : ($daysLeft === 1 ? 'tomorrow' : "in $daysLeft days");
      ?>
        <div class="dz-rem-kind"><?= $reminder['kind'] === 'task' ? 'Task due' : 'Project deadline' ?></div>
        <div class="dz-rem-title"><?= esc($reminder['title']) ?></div>
        <div class="dz-rem-time">Due: <?= esc(fmtDate($reminder['date'], 'D, M d')) ?> · <?= esc($whenTxt) ?></div>
        <div style="height:18px"></div>
        <?php if ($reminder['kind'] === 'task'): ?>
          <button type="button" class="dz-btn dz-btn-primary" onclick="openTaskModal(<?= (int)$reminder['id'] ?>,'inprogress')"><i class="fas fa-eye"></i> Open Task</button>
        <?php else: ?>
          <button type="button" class="dz-btn dz-btn-primary" onclick="focusSection('activeProjects')"><i class="fas fa-folder-open"></i> View Project</button>
        <?php endif; ?>
      <?php else: ?>
        <div class="dz-empty"><i class="fas fa-mug-hot"></i><br>No upcoming deadlines.</div>
      <?php endif; ?>
    </section>

    <!-- Project list -->
    <section class="dz-card dz-pr">
      <div class="dz-card-head">
        <h3>Project</h3>
        <a href="managerProjects.php" data-page="managerProjects.php" class="dz-btn dz-btn-ghost dz-btn-sm"><i class="fas fa-plus"></i> New</a>
      </div>
      <?php if ($activeProjects): ?>
        <ul class="dz-plist">
          <?php foreach (array_slice($activeProjects, 0, 5) as $i => $p): [$ic, $col] = $projectIcons[$i % count($projectIcons)]; ?>
            <li onclick="focusSection('activeProjects')">
              <span class="dz-picon" style="color:<?= $col ?>;background:<?= $col ?>1a"><i class="fas <?= $ic ?>"></i></span>
              <div style="min-width:0">
                <div class="dz-pname"><?= esc($p['project']) ?></div>
                <div class="dz-sub">Due date: <?= esc(fmtDate($p['end'])) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="dz-empty">No running projects.</div>
      <?php endif; ?>
    </section>

    <!-- Team collaboration -->
    <section class="dz-card dz-tm">
      <div class="dz-card-head">
        <h3>Team Collaboration</h3>
        <button type="button" class="dz-btn dz-btn-ghost dz-btn-sm" onclick="openModal('users')"><i class="fas fa-users"></i> View Team</button>
      </div>
      <?php if ($teamRows): ?>
        <ul class="dz-team">
          <?php foreach ($teamRows as $m): $hue = avatarHue($m['member']); ?>
            <li onclick="openTaskModal(<?= (int)$m['task_id'] ?>,'<?= in_array(statusKey($m['status']), ['completed','done'], true) ? 'completed' : 'inprogress' ?>')">
              <span class="dz-avatar" style="background:hsl(<?= $hue ?>,70%,88%);color:hsl(<?= $hue ?>,45%,30%)"><?= esc(initials($m['member'])) ?></span>
              <div class="dz-team-main">
                <div class="dz-team-name"><?= esc($m['member']) ?></div>
                <div class="dz-team-task">Working on <b><?= esc($m['taskTitle'] ?: 'Task') ?></b></div>
              </div>
              <span class="dz-pill <?= pillClass($m['status']) ?>"><?= esc($m['status'] ?: '—') ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="dz-empty">No assigned tasks yet.</div>
      <?php endif; ?>
    </section>

    <!-- Project progress -->
    <section class="dz-card dz-pg">
      <div class="dz-card-head"><h3>Project Progress</h3><span class="dz-sub"><?= (int)$taskAll ?> tasks</span></div>
      <div class="dz-gauge">
        <canvas id="dzGauge"></canvas>
        <div class="dz-gauge-center"><strong><?= (int)$donePct ?>%</strong><span>Tasks completed</span></div>
      </div>
      <div class="dz-legend">
        <span><i style="background:var(--g500)"></i>Completed (<?= (int)$taskDone ?>)</span>
        <span><i style="background:var(--g900)"></i>In Progress (<?= (int)$taskProg ?>)</span>
        <span><i style="background:repeating-linear-gradient(135deg,#9aa59e 0 2px,transparent 2px 4px);border:1px solid #9aa59e"></i>Pending (<?= (int)($taskPend + $taskOther) ?>)</span>
      </div>
    </section>

    <!-- Time tracker -->
    <section class="dz-card dz-tt">
      <h3>Time Tracker</h3>
      <div class="dz-tt-time" id="dzTimer">00:00:00</div>
      <div class="dz-tt-ctrl">
        <button type="button" class="dz-tt-btn" id="dzTimerToggle" title="Start"><i class="fas fa-play"></i></button>
        <button type="button" class="dz-tt-btn stop" id="dzTimerStop" title="Stop and log time"><i class="fas fa-stop"></i></button>
      </div>
      <div class="dz-tt-note">Stop to log this session in Work Log</div>
    </section>
  </div>

  <!-- ===== Detail tables + calendar ===== -->
  <div class="dz-lower">
    <section class="dz-card">
      <div class="dz-card-head" style="flex-wrap:wrap">
        <h3>Details</h3>
        <div style="display:flex;gap:8px;align-items:center">
          <div class="dz-search"><i class="fas fa-search"></i><input type="text" id="dzSearch" placeholder="Search tasks, projects, people…"></div>
          <button type="button" class="icon-btn" onclick="window.location.href='ratings.php'" title="Team ratings"><i class="fas fa-star"></i></button>
          <button type="button" class="icon-btn" onclick="window.print()" title="Print report"><i class="fas fa-print"></i></button>
        </div>
      </div>
      <?php
      function enhancedSection($id, $title, $subtitle, $total, $headers, $rows, $formatRowFn, $icon){
        $shown = count($rows);
        echo '<div class="section" id="' . esc($id) . '">';
        echo '  <div class="section-head" onclick="toggleSection(\'' . esc($id) . '\')">';
        echo '    <div class="section-title"><i class="' . esc($icon) . '"></i><div>';
        echo '      <div class="section-name">' . esc($title) . '</div>';
        echo '      <div class="section-subtitle">' . esc($subtitle) . '</div>';
        echo '    </div></div>';
        echo '    <div style="display:flex;align-items:center;gap:12px">';
        echo '      <div class="section-badge"><span class="badge-count">' . (int)$shown . '</span>';
        if ($total > $shown) echo '<span class="badge-total">of ' . (int)$total . '</span>';
        echo '      </div><i class="fas fa-chevron-down section-icon"></i>';
        echo '    </div>';
        echo '  </div>';
        echo '  <div class="section-body" id="body_' . esc($id) . '">';
        if ($shown > 0) {
          echo '<div class="table-wrap"><table class="table"><thead><tr>';
          foreach ($headers as $h) echo '<th>' . esc($h) . '</th>';
          echo '</tr></thead><tbody class="data-rows" data-section="' . esc($id) . '">';
          foreach ($rows as $r) {
            echo '<tr class="data-row">';
            foreach ($formatRowFn($r) as $c) echo '<td>' . $c . '</td>';
            echo '</tr>';
          }
          echo '</tbody></table></div>';
          if ($total > $shown) {
            echo '<button type="button" class="dz-btn dz-btn-ghost dz-btn-sm view-more-btn" onclick="openModal(\'' . esc($id) . '\')"><i class="fas fa-eye"></i> View all ' . (int)$total . '</button>';
          }
        } else {
          echo '<div class="dz-empty">No data available for this section</div>';
        }
        echo '  </div>';
        echo '</div>';
      }

      $taskRow = function($mode){
        return function($t) use ($mode){
          return [
            '<span style="font-weight:500">' . esc($t['task'] ?? '') . '</span>',
            esc($t['assignee'] ?? '—'),
            esc(fmtDate($t['due'] ?? '')),
            '<span class="dz-pill ' . pillClass($t['status'] ?? '') . '">' . esc($t['status'] ?? '') . '</span>',
            '<button type="button" class="icon-btn" title="View task" onclick="openTaskModal(' . (int)$t['task_id'] . ',\'' . $mode . '\');event.stopPropagation();"><i class="fas fa-eye"></i></button>',
          ];
        };
      };

      enhancedSection('inProgress', 'In Progress Tasks', 'Tasks currently being worked on', $inprogressTotal,
        ['Task','Assignee','Due Date','Status','Action'], $inprogressTasks, $taskRow('inprogress'), 'fas fa-spinner');

      enhancedSection('completedTasks', 'Completed Tasks', 'Tasks your team has finished', $completedTotal,
        ['Task','Assignee','Due Date','Status','Action'], $completedTasks, $taskRow('completed'), 'fas fa-check');

      enhancedSection('activeProjects', 'Running Projects', 'Ongoing projects, nearest deadline first', $activeTotal,
        ['Project','Leader','Start','End','Tasks'], $activeProjects, function($p){
          return [
            '<span style="font-weight:500">' . esc($p['project'] ?? '') . '</span>',
            esc($p['leader'] ?? '—'),
            esc(fmtDate($p['start'] ?? '')),
            esc(fmtDate($p['end'] ?? '')),
            (int)($p['tasks'] ?? 0),
          ];
        }, 'fas fa-rocket');

      enhancedSection('completedProjects', 'Ended Projects', 'Finished projects with statistics', $completedProjectsTotal,
        ['Project','Leader','Start','End','Tasks','Sub Projects'], $completedProjects, function($p){
          return [
            '<span style="font-weight:500">' . esc($p['project'] ?? '') . '</span>',
            esc($p['leader'] ?? '—'),
            esc(fmtDate($p['start'] ?? '')),
            esc(fmtDate($p['end'] ?? '')),
            (int)($p['tasks'] ?? 0),
            (int)($p['subs'] ?? 0),
          ];
        }, 'fas fa-flag-checkered');

      enhancedSection('users', 'Users Summary', 'System users by role', $totalUsers,
        ['Role','Total'], $userSummary, function($u){
          return ['<span style="font-weight:500">' . esc($u['role'] ?? '') . '</span>', (int)($u['count'] ?? 0)];
        }, 'fas fa-users');

      enhancedSection('managerActivity', 'My Projects', 'Projects created by you', count($managerActivities),
        ['Project','Team','Start','End'], $managerActivities, function($m){
          return [
            '<span style="font-weight:500">' . esc($m['project'] ?? '') . '</span>',
            esc($m['team'] ?? '—'),
            esc(fmtDate($m['start'] ?? '')),
            esc(fmtDate($m['end'] ?? '')),
          ];
        }, 'fas fa-user-tie');
      ?>
    </section>

    <section class="dz-card dz-cal">
      <div class="dz-card-head"><h3>Project Calendar</h3><span class="dz-sub">Task due dates &amp; project ends</span></div>
      <div id="dashCalendar"></div>
    </section>
  </div>

  <!-- ===== View All modal ===== -->
  <div id="viewAllModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title"><i class="fas fa-list"></i><span id="modalTitle">View All Items</span></div>
        <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
      </div>
      <div class="modal-body">
        <table class="modal-table"><thead id="modalHeaders"></thead><tbody id="modalBody"></tbody></table>
      </div>
      <div class="modal-pagination" id="modalPagination" style="display:none;">
        <div class="pagination-info" id="paginationInfo"></div>
        <div class="pagination-controls">
          <button type="button" class="pagination-btn" id="firstPage" onclick="goToPage(1)" title="First"><i class="fas fa-angle-double-left"></i></button>
          <button type="button" class="pagination-btn" id="prevPage" onclick="prevPage()" title="Previous"><i class="fas fa-angle-left"></i></button>
          <div class="pagination-numbers" id="pageNumbers"></div>
          <button type="button" class="pagination-btn" id="nextPage" onclick="nextPage()" title="Next"><i class="fas fa-angle-right"></i></button>
          <button type="button" class="pagination-btn" id="lastPage" onclick="goToPage(0)" title="Last"><i class="fas fa-angle-double-right"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== Task details modal ===== -->
  <div id="taskModal" class="modal">
    <div class="modal-content" style="max-width:980px;">
      <div class="modal-header">
        <div class="modal-title"><i class="fas fa-clipboard-list"></i><span id="taskModalTitle">Task Details</span></div>
        <button type="button" class="modal-close" onclick="closeTaskModal()">&times;</button>
      </div>
      <div class="modal-body" style="padding:18px 22px;">
        <div id="taskModalLoading" style="padding:30px;text-align:center;color:var(--dz-muted);">Loading task…</div>
        <div id="taskModalContent" style="display:none;">
          <div class="td-grid">
            <div class="td-box" style="margin:0">
              <h4>Task Info</h4>
              <div><b>Title:</b> <span id="td_title"></span></div>
              <div><b>Status:</b> <span id="td_status"></span></div>
              <div><b>Due Date:</b> <span id="td_due"></span></div>
              <div><b>Project:</b> <span id="td_project"></span></div>
            </div>
            <div class="td-box" style="margin:0">
              <h4>People</h4>
              <div><b>Created By:</b> <span id="td_created_by"></span></div>
              <div><b>Member:</b> <span id="td_member"></span></div>
              <div><b>Member Email:</b> <span id="td_member_email"></span></div>
            </div>
          </div>
          <div class="td-box"><h4>Description</h4><div id="td_description"></div></div>
          <div class="td-box">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <h4 style="margin:0">Submission / Evidence</h4>
              <span id="td_submission_badge" class="dz-pill dz-pill-done" style="display:none;">Submission found</span>
            </div>
            <div style="margin-top:10px;display:grid;gap:6px">
              <div><b>Submitted Document:</b> <span id="td_doc"></span></div>
              <div><b>Submitted Code:</b> <span id="td_code"></span></div>
              <div><b>Daily Summary:</b> <span id="td_summary"></span></div>
            </div>
          </div>
          <div class="td-box"><h4>Reminder History</h4><div id="td_reminders" style="color:var(--dz-muted);">No reminders yet.</div></div>
          <div class="td-box">
            <h4>Send Reminder Email</h4>
            <textarea id="reminderMessage" placeholder="Type a reminder message to the member…"></textarea>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px">
              <button type="button" class="dz-btn dz-btn-ghost dz-btn-sm" onclick="closeTaskModal()">Close</button>
              <button type="button" class="dz-btn dz-btn-primary dz-btn-sm" onclick="sendReminderEmail()"><i class="fas fa-paper-plane"></i> Send Email</button>
            </div>
            <div id="reminderResult" style="margin-top:10px;font-size:13px;color:var(--dz-muted);"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
/*
 * Wrapped in an IIFE: manager_index.php re-executes this script every time the
 * Dashboard link is clicked, and top-level const/let would throw "already declared".
 * Functions used from inline onclick handlers (and by the shell) are exported on window.
 */
(function(){
  const root = document.getElementById('dzRoot');
  if (!root) return;

  const SERVER = {
    events:   <?= json_encode($calendarEvents, $J) ?>,
    progress: <?= json_encode(['done' => $taskDone, 'prog' => $taskProg, 'pend' => $taskPend + $taskOther], $J) ?>,
  };

  const modalData = {
    inProgress:        { title: 'All In Progress Tasks',  headers: ['Task','Assignee','Due Date','Status','Action'] },
    completedTasks:    { title: 'All Completed Tasks',    headers: ['Task','Assignee','Due Date','Status','Action'] },
    activeProjects:    { title: 'All Running Projects',   headers: ['Project','Leader','Start','End','Tasks'] },
    completedProjects: { title: 'All Ended Projects',     headers: ['Project','Leader','Start','End','Tasks','Sub Projects'] },
    users:             { title: 'All Users',              headers: ['Name','Email','Role','Department','Status','Tasks','Projects'] },
  };

  /* ---------- Utils ---------- */
  const $ = (id) => document.getElementById(id);
  function escapeHtml(text){ const d = document.createElement('div'); d.textContent = text ?? ''; return d.innerHTML; }
  function formatDate(s){ if (!s) return '—'; const d = new Date(s); return isNaN(d) ? s : d.toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'}); }
  function pillClass(status){
    const s = String(status || '').toLowerCase().replace(/\s+/g,'');
    if (s === 'completed' || s === 'done') return 'dz-pill-done';
    if (s === 'inprogress') return 'dz-pill-prog';
    if (s === 'pending') return 'dz-pill-pend';
    return 'dz-pill-neutral';
  }
  function cssVar(name){ return getComputedStyle(root).getPropertyValue(name).trim(); }

  function showToast(message, type){
    document.querySelectorAll('.dz-toast').forEach(t => t.remove());
    const colors = { success:'#2f9e6b', warning:'#f59f00', danger:'#e03131', info:'#1b6b45' };
    const t = document.createElement('div');
    t.className = 'dz-toast';
    t.style.borderLeftColor = colors[type] || colors.info;
    t.textContent = message;
    root.appendChild(t);
    setTimeout(() => t.remove(), 3200);
  }

  function loadScript(globalName, src){
    return new Promise(resolve => {
      if (window[globalName]) return resolve();
      const s = document.createElement('script');
      s.src = src; s.onload = resolve; s.onerror = resolve;
      document.head.appendChild(s);
    });
  }

  /* ---------- Sections ---------- */
  function toggleSection(id){
    const section = $(id);
    if (section) section.classList.toggle('open');
  }
  function focusSection(id){
    const section = $(id);
    if (!section) return;
    root.querySelectorAll('.section.open').forEach(s => { if (s.id !== id) s.classList.remove('open'); });
    section.classList.add('open');
    section.scrollIntoView({ behavior:'smooth', block:'start' });
    section.classList.add('highlight-pulse');
    setTimeout(() => section.classList.remove('highlight-pulse'), 1000);
  }

  /* ---------- Search ---------- */
  let searchTimeout;
  const search = $('dzSearch');
  search.addEventListener('input', (e) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      const q = e.target.value.toLowerCase().trim();
      let visible = 0;
      root.querySelectorAll('.data-row').forEach(row => {
        const match = !q || row.textContent.toLowerCase().includes(q);
        row.style.display = match ? '' : 'none';
        row.classList.toggle('highlight', !!q && match);
        if (match && q) {
          visible++;
          row.closest('.section')?.classList.add('open');
        }
      });
      if (q) showToast(`Found ${visible} match${visible === 1 ? '' : 'es'} for "${q}"`, 'info');
    }, 300);
  });

  /* ---------- View-all modal with server pagination (fetch_all.php) ---------- */
  let currentSection = '', currentPage = 1, totalPages = 1, totalItems = 0;
  const itemsPerPage = 20;

  function taskCells(item, mode){
    const tid = Number(item.task_id ?? item.id ?? item.taskId ?? 0);
    return `
      <td><strong>${escapeHtml(item.task || '')}</strong></td>
      <td>${escapeHtml(item.assignee || '—')}</td>
      <td>${formatDate(item.due)}</td>
      <td><span class="dz-pill ${pillClass(item.status)}">${escapeHtml(item.status || '')}</span></td>
      <td><button type="button" class="icon-btn" title="View task" onclick="closeModal(); openTaskModal(${tid}, '${mode}');"><i class="fas fa-eye"></i></button></td>`;
  }

  function rowCells(sectionId, item){
    switch (sectionId) {
      case 'inProgress':     return taskCells(item, 'inprogress');
      case 'completedTasks': return taskCells(item, 'completed');
      case 'activeProjects':
        return `<td><strong>${escapeHtml(item.project || '')}</strong></td><td>${escapeHtml(item.leader || '—')}</td>
                <td>${formatDate(item.start)}</td><td>${formatDate(item.end)}</td><td>${escapeHtml(item.tasks || '0')}</td>`;
      case 'completedProjects':
        return `<td><strong>${escapeHtml(item.project || '')}</strong></td><td>${escapeHtml(item.leader || '—')}</td>
                <td>${formatDate(item.start)}</td><td>${formatDate(item.end)}</td><td>${escapeHtml(item.tasks || '0')}</td><td>${escapeHtml(item.subs || '0')}</td>`;
      case 'users': {
        const role = item.role ? item.role.charAt(0).toUpperCase() + item.role.slice(1) : 'N/A';
        return `<td><strong>${escapeHtml(item.name || 'N/A')}</strong><div style="font-size:11px;color:var(--dz-muted)">ID: ${escapeHtml(item.user_id || '')}</div></td>
                <td>${escapeHtml(item.email || 'N/A')}</td><td>${escapeHtml(role)}</td><td>${escapeHtml(item.department || 'N/A')}</td>
                <td><span class="dz-pill ${String(item.status||'').toLowerCase()==='active' ? 'dz-pill-done' : 'dz-pill-neutral'}">${escapeHtml(item.status || 'N/A')}</span></td>
                <td>${escapeHtml(item.task_count || '0')}</td><td>${escapeHtml(item.project_count || '0')}</td>`;
      }
    }
    return '';
  }

  function renderPagination(){
    const pag = $('modalPagination'), nums = $('pageNumbers');
    if (totalPages <= 1) { pag.style.display = 'none'; return; }
    pag.style.display = 'flex';
    const start = (currentPage - 1) * itemsPerPage + 1;
    $('paginationInfo').textContent = `Showing ${start}-${Math.min(currentPage * itemsPerPage, totalItems)} of ${totalItems}`;
    $('firstPage').disabled = $('prevPage').disabled = currentPage === 1;
    $('nextPage').disabled  = $('lastPage').disabled = currentPage === totalPages;

    nums.innerHTML = '';
    let s = Math.max(1, currentPage - 2), e = Math.min(totalPages, s + 4);
    if (e - s < 4) s = Math.max(1, e - 4);
    const btn = (n) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'page-number' + (n === currentPage ? ' active' : ''); b.textContent = n; b.onclick = () => goToPage(n); nums.appendChild(b); };
    const dots = () => { const d = document.createElement('span'); d.className = 'page-ellipsis'; d.textContent = '…'; nums.appendChild(d); };
    if (s > 1) { btn(1); if (s > 2) dots(); }
    for (let i = s; i <= e; i++) btn(i);
    if (e < totalPages) { if (e < totalPages - 1) dots(); btn(totalPages); }
  }

  function loadModalData(sectionId, page){
    const modal = $('viewAllModal'), title = $('modalTitle'), head = $('modalHeaders'), body = $('modalBody');
    title.textContent = 'Loading…';
    head.innerHTML = '';
    body.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:40px;">Loading data…</td></tr>';
    $('modalPagination').style.display = 'none';
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';

    fetch(`fetch_all.php?type=${encodeURIComponent(sectionId)}&page=${page}`, { credentials:'include' })
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(({ data, total, pages, currentPage: serverPage }) => {
        currentSection = sectionId;
        totalItems = total || 0;
        totalPages = pages || 1;
        currentPage = serverPage || page;
        title.textContent = `${modalData[sectionId]?.title || 'All Items'} (${totalItems})`;
        head.innerHTML = '<tr>' + (modalData[sectionId]?.headers || ['Item']).map(h => `<th>${escapeHtml(h)}</th>`).join('') + '</tr>';
        body.innerHTML = (data && data.length)
          ? data.map(item => `<tr>${rowCells(sectionId, item)}</tr>`).join('')
          : '<tr><td colspan="10" style="text-align:center;padding:40px;color:var(--dz-muted);">No data available</td></tr>';
        renderPagination();
      })
      .catch(err => {
        console.error('fetch_all.php failed:', err);
        title.textContent = 'Error';
        body.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:40px;color:#e03131;">Error loading data. Check the console for details.</td></tr>';
      });
  }

  function openModal(sectionId){ currentPage = 1; loadModalData(sectionId, 1); }
  function closeModal(){ $('viewAllModal').style.display = 'none'; document.body.style.overflow = ''; }
  function goToPage(p){ if (p === 0) p = totalPages; if (p < 1 || p > totalPages || p === currentPage) return; loadModalData(currentSection, p); }
  function prevPage(){ if (currentPage > 1) goToPage(currentPage - 1); }
  function nextPage(){ if (currentPage < totalPages) goToPage(currentPage + 1); }

  /* ---------- Task details modal (manager_task_details.php / manager_send_reminder.php) ---------- */
  let currentTaskId = null;

  function openTaskModal(taskId, mode){
    taskId = Number(taskId || 0);
    if (!taskId) { showToast('Task ID missing. fetch_all.php must return task_id.', 'danger'); return; }
    currentTaskId = taskId;

    $('taskModalTitle').textContent = mode === 'completed' ? 'Completed Task Details' : 'Task Details';
    $('taskModalLoading').style.display = 'block';
    $('taskModalLoading').textContent = 'Loading task…';
    $('taskModalContent').style.display = 'none';
    $('reminderResult').textContent = '';
    $('reminderMessage').value = '';
    $('taskModal').style.display = 'block';
    document.body.style.overflow = 'hidden';

    fetch(`manager_task_details.php?task_id=${encodeURIComponent(taskId)}`, { credentials:'include' })
      .then(async r => {
        const text = await r.text();
        let res;
        try { res = JSON.parse(text); } catch (e) { console.error('Non-JSON response:', text); throw new Error('Endpoint returned invalid JSON'); }
        if (!r.ok || !res.ok) throw new Error(res.error || `HTTP ${r.status}`);
        return res;
      })
      .then(res => {
        const t = res.task ?? res;
        $('td_title').textContent = t.title || '—';
        $('td_due').textContent = t.due ? formatDate(t.due) : '—';
        $('td_project').textContent = t.project || '—';
        $('td_status').innerHTML = `<span class="dz-pill ${pillClass(t.status)}">${escapeHtml(t.status || '—')}</span>`;
        $('td_created_by').textContent = t.created_by || '—';
        $('td_member').textContent = t.member_name || '—';
        $('td_member_email').textContent = t.member_email || '—';
        $('td_description').textContent = t.description || '—';
        $('td_submission_badge').style.display = (t.submitted_doc || t.submitted_code || t.daily_summary) ? 'inline-block' : 'none';
        $('td_doc').innerHTML = t.submitted_doc ? `<a href="${escapeHtml(t.submitted_doc)}" target="_blank" rel="noopener" style="color:var(--g500);font-weight:600;">View / Download</a>` : '—';
        $('td_code').textContent = t.submitted_code || '—';
        $('td_summary').textContent = t.daily_summary || '—';

        const box = $('td_reminders');
        box.innerHTML = (res.reminders && res.reminders.length)
          ? res.reminders.map(x => `<div style="padding:10px;border:1px solid var(--dz-border);border-radius:10px;margin-bottom:8px;">
                <div style="font-weight:600;color:var(--dz-text)">${escapeHtml(x.sent_by || 'Leader')} <span style="font-weight:400;color:var(--dz-muted);">· ${x.sent_at ? formatDate(x.sent_at) : '—'}</span></div>
                <div style="margin-top:4px;color:var(--dz-text);">${escapeHtml(x.message || '')}</div></div>`).join('')
          : 'No reminders yet.';

        $('taskModalLoading').style.display = 'none';
        $('taskModalContent').style.display = 'block';
      })
      .catch(err => {
        console.error(err);
        $('taskModalLoading').innerHTML = `<span style="color:#e03131;">Failed to load task details: ${escapeHtml(err.message)}</span>`;
      });
  }

  function closeTaskModal(){ $('taskModal').style.display = 'none'; document.body.style.overflow = ''; currentTaskId = null; }

  function sendReminderEmail(){
    if (!currentTaskId) return;
    const msg = $('reminderMessage').value.trim();
    if (!msg) { showToast('Please type a reminder message.', 'warning'); return; }
    const fd = new FormData();
    fd.append('task_id', currentTaskId);
    fd.append('message', msg);
    $('reminderResult').textContent = 'Sending…';
    fetch('manager_send_reminder.php', { method:'POST', body:fd, credentials:'include' })
      .then(r => r.json())
      .then(res => {
        if (!res.ok) throw new Error(res.error || 'Failed');
        showToast('Reminder sent successfully!', 'success');
        openTaskModal(currentTaskId, 'inprogress');
      })
      .catch(err => {
        console.error(err);
        showToast('Failed to send reminder.', 'danger');
        $('reminderResult').textContent = 'Failed to send.';
      });
  }

  // Close modals on backdrop click
  ['viewAllModal','taskModal'].forEach(id => {
    $(id).addEventListener('click', (e) => { if (e.target.id === id) (id === 'taskModal' ? closeTaskModal : closeModal)(); });
  });

  /* ---------- Time tracker (persists across page loads via localStorage) ---------- */
  const TT_KEY = 'manager_time_tracker';
  const ttEl = $('dzTimer'), ttToggle = $('dzTimerToggle');
  let tt = { elapsed: 0, startedAt: null };
  try { tt = Object.assign(tt, JSON.parse(localStorage.getItem(TT_KEY) || '{}')); } catch (e) {}
  const ttSave = () => { try { localStorage.setItem(TT_KEY, JSON.stringify(tt)); } catch (e) {} };
  const ttNow = () => tt.elapsed + (tt.startedAt ? Date.now() - tt.startedAt : 0);
  const ttFmt = (ms) => { const s = Math.floor(ms / 1000); return [Math.floor(s / 3600), Math.floor(s / 60) % 60, s % 60].map(n => String(n).padStart(2, '0')).join(':'); };
  function ttRender(){
    ttEl.textContent = ttFmt(ttNow());
    ttToggle.innerHTML = tt.startedAt ? '<i class="fas fa-pause"></i>' : '<i class="fas fa-play"></i>';
    ttToggle.title = tt.startedAt ? 'Pause' : 'Start';
  }
  ttToggle.addEventListener('click', () => {
    if (tt.startedAt) { tt.elapsed += Date.now() - tt.startedAt; tt.startedAt = null; }
    else tt.startedAt = Date.now();
    ttSave(); ttRender();
  });
  $('dzTimerStop').addEventListener('click', () => {
    const total = ttNow();
    tt = { elapsed: 0, startedAt: null };
    ttSave(); ttRender();
    if (total < 1000) return;
    showToast(`Session of ${ttFmt(total)} stopped. Opening Work Log…`, 'success');
    const link = document.querySelector('#manager-sidenav [data-page="create_timesheet.php"]');
    setTimeout(() => link ? link.click() : (window.location.href = 'create_timesheet.php'), 900);
  });
  if (window.__dzTimerInt) clearInterval(window.__dzTimerInt);
  window.__dzTimerInt = setInterval(() => { if (document.body.contains(ttEl)) ttRender(); else clearInterval(window.__dzTimerInt); }, 1000);
  ttRender();

  /* ---------- Motion: stagger cards, count up stat values ---------- */
  root.querySelectorAll('.dz-card').forEach((el, i) => { el.style.animationDelay = `${Math.min(i * 40, 400)}ms`; el.classList.add('dz-reveal'); });
  root.querySelectorAll('.dz-stat-value').forEach(el => {
    const target = Number(el.textContent.trim());
    if (!Number.isFinite(target) || target <= 0) return;
    const t0 = performance.now();
    const tick = (now) => {
      const p = Math.min((now - t0) / 700, 1);
      el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))).toLocaleString();
      if (p < 1) requestAnimationFrame(tick);
    };
    el.textContent = '0';
    requestAnimationFrame(tick);
  });
  const firstSection = root.querySelector('.section');
  if (firstSection) firstSection.classList.add('open');

  /* ---------- Charts + calendar (libs come from manager_index.php; loaded here if opened standalone) ---------- */
  Promise.all([
    loadScript('Chart', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'),
    loadScript('FullCalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'),
  ]).then(() => {
    if (!document.body.contains(root)) return;

    if (window.Chart) {
      const canvas = $('dzGauge');
      const hatch = (() => {
        const c = document.createElement('canvas'); c.width = c.height = 8;
        const x = c.getContext('2d'); x.strokeStyle = '#9aa59e'; x.lineWidth = 2;
        x.beginPath(); x.moveTo(0, 8); x.lineTo(8, 0); x.stroke();
        return x.createPattern(c, 'repeat');
      })();
      const p = SERVER.progress;
      const empty = (p.done + p.prog + p.pend) === 0;
      new Chart(canvas, {
        type: 'doughnut',
        data: {
          labels: ['Completed', 'In Progress', 'Pending'],
          datasets: [{
            data: empty ? [0, 0, 1] : [p.done, p.prog, p.pend],
            backgroundColor: [cssVar('--g500'), cssVar('--g900'), hatch],
            borderWidth: 0, borderRadius: 6, spacing: 2,
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          rotation: -90, circumference: 180, cutout: '68%',
          layout: { padding: { bottom: 4 } },
          plugins: { legend: { display: false }, tooltip: { enabled: !empty } },
        }
      });
    }

    if (window.FullCalendar) {
      const cal = new FullCalendar.Calendar($('dashCalendar'), {
        initialView: 'dayGridMonth',
        height: 'auto',
        events: SERVER.events,
        dayMaxEvents: 2,
        headerToolbar: { left: 'prev,next', center: 'title', right: 'today' },
        eventClick(info) {
          info.jsEvent.preventDefault();
          const id = info.event.id || '';
          if (id.startsWith('t')) { openTaskModal(id.slice(1), 'inprogress'); return; }
          search.value = info.event.title.replace('Project End: ', '');
          search.dispatchEvent(new Event('input'));
          focusSection(info.event.start && info.event.start < new Date() ? 'completedProjects' : 'activeProjects');
        }
      });
      cal.render();
      window.dashCalendar = cal;
    }
  });

  Object.assign(window, {
    toggleSection, focusSection, openModal, closeModal, goToPage, prevPage, nextPage,
    openTaskModal, closeTaskModal, sendReminderEmail, showToast,
  });
})();
</script>
</body>
</html>
