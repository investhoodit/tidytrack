<?php
session_start();
require_once __DIR__ . '/../dp.php';

// AUTH GUARD
if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'admin') {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// CONFIG
$today = date('Y-m-d');
$todayDisplay = date('l, F jS, Y');

// Week calculation
$dayOfWeek = date('w');
if ($dayOfWeek == 6 || $dayOfWeek == 0) {
    $monday = date('Y-m-d', strtotime('monday last week'));
    $friday = date('Y-m-d', strtotime('friday last week'));
} else {
    $monday = date('Y-m-d', strtotime('monday this week'));
    $friday = date('Y-m-d', strtotime('friday this week'));
}
$endDate = min($today, $friday);

$days = [];
$current = strtotime($monday);
while ($current <= strtotime($endDate)) {
    $days[] = date('Y-m-d', $current);
    $current += 86400;
}

// USERS
$stmt = $conn->prepare("SELECT user_id, name, role FROM users ORDER BY role DESC, name ASC");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$leaders = $members = [];
$totalMembers = 0;
foreach ($users as $u) {
    $role = strtolower($u['role']);
    if ($role === 'leader') $leaders[$u['user_id']] = $u + ['progress_score' => 0];
    if ($role === 'member') {
        $members[$u['user_id']] = $u;
        $totalMembers++;
    }
}
$totalMembers += count($leaders); // include leaders in total team size

// TODAY's REPORTS (for column + metrics)
$dailyReports = [];
$todaySubmittedCount = 0;
$stmt = $conn->prepare("SELECT user_id, task, sent_at FROM daily_tasks WHERE sent_date = ?");
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $dailyReports[$row['user_id']] = [
        'task' => $row['task'],
        'sent_at' => $row['sent_at']
    ];
    $todaySubmittedCount++;
}
$stmt->close();

// WEEKLY REPORTS (full content for modal)
$weeklyReports = [];
$stmt = $conn->prepare("SELECT user_id, task, sent_at, sent_date FROM daily_tasks WHERE sent_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $monday, $endDate);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $safe_task = htmlspecialchars($row['task']);
    $weeklyReports[$row['user_id']][$row['sent_date']] = [
        'task_html' => nl2br($safe_task),
        'sent_at' => $row['sent_at']
    ];
}
$stmt->close();

// TASK COUNTS
$names = array_merge(
    array_map(fn($l)=>$l['name'], $leaders),
    array_map(fn($m)=>$m['name'], $members)
);
$taskData = [];
if ($names) {
    $ph = str_repeat('?,', count($names)-1) . '?';
    $stmt = $conn->prepare("
        SELECT assignTo, COUNT(task_id) AS total,
               SUM(CASE WHEN LOWER(status) = 'completed' THEN 1 ELSE 0 END) AS done
        FROM tasks WHERE assignTo IN ($ph) GROUP BY assignTo
    ");
    $stmt->bind_param(str_repeat('s', count($names)), ...$names);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $taskData[$r['assignTo']] = ['total' => (int)$r['total'], 'done' => (int)$r['done']];
    }
    $stmt->close();
}

// LEADER–MEMBER + TOP LEADER
$membersByLeader = $leaderScores = $assigned = [];
$stmt = $conn->prepare("
    SELECT p.teamLeadder_id AS lid, um.user_id AS mid, um.name AS mname, t.status
    FROM projects_x p JOIN tasks t ON t.project_id = p.project_id
    JOIN users um ON t.assignTo = um.name AND um.role = 'member'
    WHERE p.teamLeadder_id IS NOT NULL
");
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($data as $r) {
    $lid = $r['lid']; $mid = $r['mid'];
    $membersByLeader[$lid][$mid] = $r['mname'];
    $assigned[$mid] = true;
    if (strtolower($r['status']) === 'completed') $leaderScores[$lid] = ($leaderScores[$lid] ?? 0) + 1;
}

$topLeader = null; $maxScore = -1;
foreach ($leaders as $id => &$l) {
    $score = $leaderScores[$id] ?? 0;
    $l['progress_score'] = $score;
    if ($score > $maxScore) { $maxScore = $score; $topLeader = $l; }
}
unset($l);

// DISPLAY DATA
$leaderDisplay = [];
foreach ($leaders as $lid => $l) {
    $ms = $membersByLeader[$lid] ?? [];
    $leaderDisplay[$lid] = [
        'name' => $l['name'],
        'has_members' => !empty($ms),
        'members' => array_map(fn($mid,$name)=>[
            'id'=>$mid, 'name'=>$name,
            'tasks' => $taskData[$name] ?? ['total'=>0,'done'=>0]
        ], array_keys($ms), $ms),
        'self_tasks' => $taskData[$l['name']] ?? ['total'=>0,'done'=>0]
    ];
}

$unassignedDisplay = [];
foreach ($members as $mid => $m) {
    if (!isset($assigned[$mid])) {
        $unassignedDisplay[] = [
            'id' => $mid,
            'name' => $m['name'],
            'tasks' => $taskData[$m['name']] ?? ['total'=>0,'done'=>0]
        ];
    }
}
// --- FETCH PROJECTS PER LEADER ---
$leaderProjects = [];
$projectQuery = $conn->prepare("
    SELECT DISTINCT p.project_name
    FROM projects_x p
    JOIN tasks t ON t.project_id = p.project_id
    JOIN users u ON t.assignTo = u.name
    WHERE p.teamLeadder_id = ?
");
foreach ($leaders as $lid => $leader) {
    $projectQuery->bind_param("i", $lid);
    $projectQuery->execute();
    $res = $projectQuery->get_result();
    $projects = [];
    while ($row = $res->fetch_assoc()) {
        $projects[] = $row['project_name'];
    }
    $leaderProjects[$lid] = $projects;
}
$projectQuery->close();
// TEAM METRICS (simple calculations)
$todaySubmissionRate = $totalMembers > 0 ? round(($todaySubmittedCount / $totalMembers) * 100, 1) : 0;

// Count leaders with full team reporting today
$leadersWithFullTeamToday = 0;
foreach ($leaderDisplay as $lid => $group) {
    if (!$group['has_members']) continue;
    $allSubmitted = true;
    foreach ($group['members'] as $m) {
        if (!isset($dailyReports[$m['id']])) {
            $allSubmitted = false;
            break;
        }
    }
    if ($allSubmitted && isset($dailyReports[$lid])) $leadersWithFullTeamToday++;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en-ZA">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin • Oversight</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg:#f8f9fc; --card:white; --text:#2c3e50; --muted:#7f8c8d;
            --green:#27ae60; --purple:#8e44ad; --red:#e74c3c; --border:#dfe6e9;
        }
        body {margin:0; font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text);}
        .container {max-width:1280px; margin:2rem auto; padding:0 1.5rem;}
        h1 {margin:0; font-size:2.2rem;}
        h2 {margin:2.5rem 0 1rem; font-size:1.6rem; border-bottom:2px solid var(--border); padding-bottom:0.6rem;}
        .export-btn {
            background:var(--purple); color:white; border:none; padding:0.9rem 1.6rem;
            border-radius:6px; cursor:pointer; font-weight:600; display:inline-flex;
            align-items:center; gap:8px; margin:1rem 0 2rem;
        }
        .export-btn:hover {background:#732d91;}
        .metrics-grid {
            display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
            gap:1.2rem; margin:1.5rem 0;
        }
        .metric-card {
            background:var(--card); border:1px solid var(--border); border-radius:10px;
            padding:1.4rem; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,0.05);
        }
        .metric-value {font-size:2.1rem; font-weight:700; color:var(--purple);}
        .metric-label {color:var(--muted); margin-top:0.5rem; font-size:1rem;}
        .card {background:var(--card); border:1px solid var(--border); border-radius:10px;
               margin-bottom:2rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.04);}
        .table-header {
            display:grid; grid-template-columns:3fr 120px 120px 180px 110px;
            font-weight:600; background:#f1f5f9; padding:0.9rem 1.2rem;
            border-radius:6px; margin-bottom:0.6rem;
        }
        .row {
            display:grid; grid-template-columns:3fr 120px 120px 180px 110px;
            align-items:center; padding:0.9rem 1.2rem; border-bottom:1px solid #eee;
        }
        .row:last-child {border-bottom:none;}
        .name {font-weight:600;}
        .badge {padding:6px 14px; border-radius:6px; font-size:0.9rem; font-weight:600;}
        .badge.pending {background:#fcebeb; color:var(--red);}
        .badge.submitted {background:#e6f3e6; color:var(--green);}
        .view-btn {
            background:var(--purple); color:white; border:none; padding:7px 14px;
            border-radius:6px; cursor:pointer; font-size:0.9rem;
        }
        .view-btn:hover {background:#732d91;}
        .modal {display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6);
                z-index:1000; overflow-y:auto; padding:3rem 1rem;}
        .modal-box {background:white; max-width:760px; margin:auto; border-radius:10px;
                    padding:2rem; position:relative;}
        .close {position:absolute; right:1.8rem; top:1.2rem; font-size:2.2rem; cursor:pointer; color:#888;}
        .day {margin:1.5rem 0; padding:1rem; background:#f8f9fa; border-radius:8px;}
        .day-title {font-weight:600; margin-bottom:0.6rem; font-size:1.1rem;}
        .report-text {white-space:pre-wrap; line-height:1.5;}
        .top-leader {
            background:linear-gradient(135deg,var(--purple),#732d91); color:white;
            padding:2.2rem; border-radius:12px; text-align:center; margin:1.5rem 0;
        }
        .top-leader h3 {margin:0 0 1rem; font-size:1.5rem;}
        .top-leader .name {font-size:2.6rem; font-weight:700;}
        .top-leader .score {font-size:1.9rem; margin-top:0.8rem;}
    </style>
</head>
<body>

<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.8rem;">
        <h1>Team Oversight</h1>
        <div style="color:var(--muted); font-weight:500;"><?= $todayDisplay ?></div>
    </div>

    <button class="export-btn" onclick="exportCSV()">
        <i class="fas fa-file-csv"></i> Export to Excel (CSV)
    </button>

    <h2>Team Performance Snapshot</h2>
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-value"><?= $totalMembers ?></div>
            <div class="metric-label">Total Team Members</div>
        </div>
        <div class="metric-card">
            <div class="metric-value"><?= $todaySubmittedCount ?> / <?= $totalMembers ?></div>
            <div class="metric-label">Today's Reports Submitted</div>
        </div>
        <div class="metric-card">
            <div class="metric-value"><?= $todaySubmissionRate ?>%</div>
            <div class="metric-label">Today's Submission Rate</div>
        </div>
        <div class="metric-card">
            <div class="metric-value"><?= $leadersWithFullTeamToday ?></div>
            <div class="metric-label">Leaders with Full Team Reporting Today</div>
        </div>
    </div>

    <h2>Top Progress Leader</h2>
    <?php if ($topLeader): ?>
        <div class="top-leader">
            <h3>Highest Completed Tasks</h3>
            <div class="name"><?= htmlspecialchars($topLeader['name']) ?></div>
            <div class="score"><?= $topLeader['progress_score'] ?> tasks completed</div>
        </div>
    <?php else: ?>
        <p style="text-align:center; color:var(--muted);">No progress data available yet.</p>
    <?php endif; ?>

    <h2>Teams</h2>
    <?php foreach ($leaderDisplay as $lid => $group): ?>
        <div class="card">
            <h3 style="margin:0 0 1.3rem; font-size:1.45rem;">
                <i class="fas fa-user-tie"></i> <?= htmlspecialchars($group['name']) ?>
            </h3>
            
             <!-- NEW: Projects line -->
    <div style="margin-bottom:1.2rem; color:var(--muted); font-size:0.95rem;">
        <i class="fas fa-folder-open" style="margin-right:6px;"></i>
        <strong>Projects:</strong> 
        <?php
        $projs = $leaderProjects[$lid] ?? [];
        if (!empty($projs)) {
            echo implode(', ', array_map('htmlspecialchars', $projs));
        } else {
            echo '<em>No projects assigned</em>';
        }
        ?>
    </div>

            <div class="table-header">
                <div>Name</div>
                <div>Total Tasks</div>
                <div>Completed Tasks</div>
                <div>Daily Report Today</div>
                <div>Action</div>
            </div>

            <!-- Leader -->
            <div class="row">
                <div class="name"><?= htmlspecialchars($group['name']) ?> <small>(Leader)</small></div>
                <div><?= $group['self_tasks']['total'] ?></div>
                <div style="color:var(--green);"><?= $group['self_tasks']['done'] ?></div>
                <div>
                    <?php $has = isset($dailyReports[$lid]); ?>
                    <span class="badge <?= $has ? 'submitted' : 'pending' ?>">
                        <?= $has ? 'Submitted' : 'Pending' ?>
                    </span>
                    <?php if ($has): ?>
                        <br><small><?= date('H:i', strtotime($dailyReports[$lid]['sent_at'])) ?></small>
                    <?php endif; ?>
                </div>
                <div>
                    <button class="view-btn" onclick="showWeekly(<?= $lid ?>, '<?= addslashes(htmlspecialchars($group['name'])) ?> (Leader)')">View Weekly</button>
                </div>
            </div>

            <?php foreach ($group['members'] as $m): ?>
                <div class="row">
                    <div class="name"><?= htmlspecialchars($m['name']) ?></div>
                    <div><?= $m['tasks']['total'] ?></div>
                    <div style="color:var(--green);"><?= $m['tasks']['done'] ?></div>
                    <div>
                        <?php $has = isset($dailyReports[$m['id']]); ?>
                        <span class="badge <?= $has ? 'submitted' : 'pending' ?>">
                            <?= $has ? 'Submitted' : 'Pending' ?>
                        </span>
                        <?php if ($has): ?>
                            <br><small><?= date('H:i', strtotime($dailyReports[$m['id']]['sent_at'])) ?></small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <button class="view-btn" onclick="showWeekly(<?= $m['id'] ?>, '<?= addslashes(htmlspecialchars($m['name'])) ?>')">View Weekly</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <!-- Unassigned section remains the same as before -->
    <h2>Unassigned Members</h2>
    <?php if (empty($unassignedDisplay)): ?>
        <p style="color:var(--muted); text-align:center; padding:2rem 0;">No unassigned members found.</p>
    <?php else: ?>
        <div class="card">
            <div class="table-header">
                <div>Name</div>
                <div>Total Tasks</div>
                <div>Completed Tasks</div>
                <div>Daily Report Today</div>
                <div>Action</div>
            </div>
            <?php foreach ($unassignedDisplay as $m): ?>
                <div class="row">
                    <div class="name"><?= htmlspecialchars($m['name']) ?></div>
                    <div><?= $m['tasks']['total'] ?></div>
                    <div style="color:var(--green);"><?= $m['tasks']['done'] ?></div>
                    <div>
                        <?php $has = isset($dailyReports[$m['id']]); ?>
                        <span class="badge <?= $has ? 'submitted' : 'pending' ?>">
                            <?= $has ? 'Submitted' : 'Pending' ?>
                        </span>
                        <?php if ($has): ?>
                            <br><small><?= date('H:i', strtotime($dailyReports[$m['id']]['sent_at'])) ?></small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <button class="view-btn" onclick="showWeekly(<?= $m['id'] ?>, '<?= addslashes(htmlspecialchars($m['name'])) ?>')">View Weekly</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="modal" class="modal">
    <div class="modal-box">
        <span class="close" onclick="document.getElementById('modal').style.display='none'">×</span>
        <h2 id="modalTitle"></h2>
        <div id="modalContent"></div>
    </div>
</div>

<script>
const days = <?= json_encode($days) ?>;
const reports = <?= json_encode($weeklyReports) ?>;

function showWeekly(userId, name) {
    document.getElementById('modalTitle').textContent = `Weekly Reports – ${name}`;
    let html = '';
    days.forEach(day => {
        const d = new Date(day);
        const label = d.toLocaleDateString('en-ZA', {weekday:'long', month:'short', day:'numeric'});
        const report = reports[userId]?.[day];
        const status = report ? 'Submitted' : 'Pending';
        const time = report ? report.sent_at.slice(11,16) : null;

        html += `
            <div class="day">
                <div class="day-title">${label} 
                    <span style="color:${report ? 'var(--green)' : 'var(--red)'}; font-weight:600;">
                        ${status}${time ? ' • ' + time : ''}
                    </span>
                </div>
                ${report ? `
                    <div class="report-text">${report.task_html || '<em>(empty report)</em>'}</div>
                ` : '<p style="color:#888; font-style:italic; margin:0.8rem 0;">No report submitted for this day.</p>'}
            </div>
        `;
    });
    document.getElementById('modalContent').innerHTML = html;
    document.getElementById('modal').style.display = 'block';
}

function exportCSV() {
    let csv = 'Name,Role,Team Leader,Total Tasks,Completed Tasks,Daily Report Today';
    days.forEach(d => csv += ',' + new Date(d).toLocaleDateString('en-ZA', {weekday:'short'}));
    csv += '\n';

    <?php foreach ($leaderDisplay as $lid => $g): ?>
        csv += `"<?= addslashes($g['name']) ?>","Leader","-",<?= $g['self_tasks']['total'] ?>,<?= $g['self_tasks']['done'] ?>,"<?= isset($dailyReports[$lid]) ? 'Submitted' : 'Pending' ?>"`<?php
        foreach ($days as $d) {
            echo ',"' . (isset($weeklyReports[$lid][$d]) ? 'Submitted' : 'Pending') . '"';
        }
        ?> + '\n';
    <?php endforeach; ?>

    <?php foreach ($leaderDisplay as $g): ?>
        <?php foreach ($g['members'] as $m): ?>
            csv += `"<?= addslashes($m['name']) ?>","Member","<?= addslashes($g['name']) ?>",<?= $m['tasks']['total'] ?>,<?= $m['tasks']['done'] ?>,"<?= isset($dailyReports[$m['id']]) ? 'Submitted' : 'Pending' ?>"`<?php
            foreach ($days as $d) {
                echo ',"' . (isset($weeklyReports[$m['id']][$d]) ? 'Submitted' : 'Pending') . '"';
            }
            ?> + '\n';
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php foreach ($unassignedDisplay as $m): ?>
        csv += `"<?= addslashes($m['name']) ?>","Member","Unassigned",<?= $m['tasks']['total'] ?>,<?= $m['tasks']['done'] ?>,"<?= isset($dailyReports[$m['id']]) ? 'Submitted' : 'Pending' ?>"`<?php
        foreach ($days as $d) {
            echo ',"' . (isset($weeklyReports[$m['id']][$d]) ? 'Submitted' : 'Pending') . '"';
        }
        ?> + '\n';
    <?php endforeach; ?>

    const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'team-status-<?= date('Y-m-d') ?>.csv';
    link.click();
}
</script>
</body>
</html>  