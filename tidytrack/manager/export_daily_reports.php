<?php
session_start();
require_once __DIR__ . '/../dp.php';   // adjust path to your database connection file

// ---------- Authentication (same as original) ----------
$isTokenAccess = false;

function validateManagerAccessToken(mysqli $conn, string $token, string $pageName = 'manager_daily_reports'): ?array {
    $stmt = $conn->prepare("
        SELECT t.manager_user_id, u.name, u.email, u.role
        FROM manager_page_tokens t
        JOIN users u ON u.user_id = t.manager_user_id
        WHERE t.token = ?
          AND t.page_name = ?
          AND t.expires_at >= NOW()
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("ss", $token, $pageName);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row && strtolower(trim($row['role'] ?? '')) === 'manager') {
        return $row;
    }
    return null;
}

if (!empty($_GET['access_token'])) {
    $tokenUser = validateManagerAccessToken($conn, trim($_GET['access_token']), 'manager_daily_reports');
    if (!$tokenUser) {
        http_response_code(403);
        echo "Invalid or expired access link.";
        exit;
    }
    $isTokenAccess = true;
} elseif (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'manager') {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// ---------- Week range (Monday to Friday, same logic as original) ----------
$today = date('Y-m-d');
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
$weekDays = array_map(function($d) {
    return date('l, M j', strtotime($d));
}, $days);

// ---------- Fetch all users (leaders + members) ----------
$users = [];
$leaders = [];
$members = [];

$stmt = $conn->prepare("SELECT user_id, name, role FROM users ORDER BY role DESC, name ASC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[$row['user_id']] = $row;
    if (strtolower($row['role']) === 'leader') {
        $leaders[$row['user_id']] = $row;
    } elseif (strtolower($row['role']) === 'member') {
        $members[$row['user_id']] = $row;
    }
}
$stmt->close();

// ---------- Weekly reports (full content for each day) ----------
$weeklyReports = [];
$stmt = $conn->prepare("SELECT user_id, task, sent_at, sent_date FROM daily_tasks WHERE sent_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $monday, $endDate);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $userId = $row['user_id'];
    $date = $row['sent_date'];
    $weeklyReports[$userId][$date] = [
        'task'     => $row['task'],
        'sent_at'  => $row['sent_at']
    ];
}
$stmt->close();

// ---------- Task counts per user (total / completed) ----------
$taskCounts = [];
$names = array_column($users, 'name');
if (!empty($names)) {
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $conn->prepare("
        SELECT assignTo, COUNT(task_id) AS total,
               SUM(CASE WHEN LOWER(status) = 'completed' THEN 1 ELSE 0 END) AS done
        FROM tasks
        WHERE assignTo IN ($placeholders)
        GROUP BY assignTo
    ");
    $stmt->bind_param(str_repeat('s', count($names)), ...$names);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $taskCounts[$row['assignTo']] = [
            'total' => (int)$row['total'],
            'done'  => (int)$row['done']
        ];
    }
    $stmt->close();
}

// ---------- Leader per member (from projects_x + tasks) ----------
$memberLeader = []; // member_id => leader_name
$stmt = $conn->prepare("
    SELECT DISTINCT p.teamLeadder_id AS lid, um.user_id AS mid, um.name AS mname, l.name AS leadername
    FROM projects_x p
    JOIN tasks t ON t.project_id = p.project_id
    JOIN users um ON t.assignTo = um.name AND um.role = 'member'
    JOIN users l ON l.user_id = p.teamLeadder_id
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $memberLeader[$row['mid']] = $row['leadername'];
}
$stmt->close();

// ---------- Projects per leader (for the extra info) ----------
$leaderProjects = [];
$projStmt = $conn->prepare("
    SELECT DISTINCT p.project_name
    FROM projects_x p
    JOIN tasks t ON t.project_id = p.project_id
    JOIN users u ON t.assignTo = u.name
    WHERE p.teamLeadder_id = ?
");
foreach ($leaders as $lid => $leader) {
    $projStmt->bind_param("i", $lid);
    $projStmt->execute();
    $res = $projStmt->get_result();
    $projects = [];
    while ($row = $res->fetch_assoc()) {
        $projects[] = $row['project_name'];
    }
    $leaderProjects[$lid] = $projects;
}
$projStmt->close();
$conn->close();

// ---------- Helper to get report cell content for a specific user + day ----------
function getReportCell($userId, $date, $weeklyReports) {
    if (!isset($weeklyReports[$userId][$date])) {
        return '<span style="color:#e74c3c; font-weight:500;">❌ Pending</span>';
    }
    $report = $weeklyReports[$userId][$date];
    $taskText = nl2br(htmlspecialchars($report['task']));
    $time = date('H:i', strtotime($report['sent_at']));
    return "
        <div style='margin:4px 0;'>
            <span style='color:#27ae60; font-weight:600;'>✓ Submitted at {$time}</span>
            <div style='margin-top:6px; font-size:11px; color:#2c3e50; max-width:400px; white-space:pre-wrap;'>{$taskText}</div>
        </div>
    ";
}

// ---------- Build export data rows ----------
$exportRows = [];

// 1) Leaders
foreach ($leaders as $lid => $leader) {
    $row = [
        'type'   => 'leader',
        'name'   => $leader['name'],
        'role'   => 'Team Leader',
        'leader' => '—',
        'tasks'  => $taskCounts[$leader['name']] ?? ['total'=>0, 'done'=>0],
        'userId' => $lid,
        'projects' => isset($leaderProjects[$lid]) ? implode(', ', $leaderProjects[$lid]) : '—'
    ];
    $exportRows[] = $row;
}

// 2) Members (with their leader)
foreach ($members as $mid => $member) {
    $leaderName = $memberLeader[$mid] ?? 'Unassigned';
    $row = [
        'type'   => 'member',
        'name'   => $member['name'],
        'role'   => 'Team Member',
        'leader' => $leaderName,
        'tasks'  => $taskCounts[$member['name']] ?? ['total'=>0, 'done'=>0],
        'userId' => $mid,
        'projects' => '—'
    ];
    $exportRows[] = $row;
}

// Optional: sort by leader name then member name
usort($exportRows, function($a, $b) {
    if ($a['leader'] === $b['leader']) return strcmp($a['name'], $b['name']);
    return strcmp($a['leader'], $b['leader']);
});

// ---------- Output as Excel (HTML with .xls extension) ----------
$filename = 'daily_reports_' . date('Y-m-d') . '.xls';
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo '<!DOCTYPE html>';
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>Investhood Daily Reports Export</title>';
echo '<style>
        body { font-family: "Segoe UI", Arial, sans-serif; margin:20px; }
        h1 { color:#2c3e50; border-bottom:2px solid #8e44ad; padding-bottom:8px; }
        .summary { background:#f2f2f2; padding:12px; margin-bottom:20px; border-radius:6px; }
        table { border-collapse: collapse; width:100%; margin-bottom:30px; }
        th { background:#8e44ad; color:white; padding:10px 8px; border:1px solid #ddd; font-size:12px; }
        td { border:1px solid #ddd; padding:8px; vertical-align:top; font-size:11px; }
        tr.leader-row { background:#f9f2fc; }
        tr.member-row { background:#ffffff; }
        .submitted { color:#27ae60; font-weight:bold; }
        .pending { color:#e74c3c; }
        .task-count { font-weight:600; }
        .leader-name { font-weight:700; }
     </style>';
echo '</head>';
echo '<body>';

// Title & metadata
echo '<h1>📊 Investhood – Weekly Daily Reports</h1>';
echo '<div class="summary">';
echo '<strong>Week:</strong> ' . date('M j, Y', strtotime($monday)) . ' – ' . date('M j, Y', strtotime($endDate)) . '<br>';
echo '<strong>Generated:</strong> ' . date('l, F j, Y \a\t H:i') . '<br>';
echo '<strong>Total Team Members:</strong> ' . count($users);
echo '</div>';

// Build table headers
echo '<table>';
echo '<thead>';
echo '<tr>';
echo '<th>Name</th>';
echo '<th>Role</th>';
echo '<th>Team Leader</th>';
echo '<th>Projects (Leader view)</th>';
echo '<th>Total Tasks</th>';
echo '<th>Completed</th>';
foreach ($weekDays as $index => $dayLabel) {
    echo '<th style="min-width:180px;">' . htmlspecialchars($dayLabel) . '<br><span style="font-size:9px;">' . $days[$index] . '</span></th>';
}
echo '</tr>';
echo '</thead>';
echo '<tbody>';

foreach ($exportRows as $row) {
    $rowClass = ($row['type'] === 'leader') ? 'leader-row' : 'member-row';
    echo '<tr class="' . $rowClass . '">';
    echo '<td><strong>' . htmlspecialchars($row['name']) . '</strong></td>';
    echo '<td>' . htmlspecialchars($row['role']) . '</td>';
    echo '<td>' . htmlspecialchars($row['leader']) . '</td>';
    echo '<td>' . htmlspecialchars($row['projects']) . '</td>';
    echo '<td class="task-count">' . $row['tasks']['total'] . '</td>';
    echo '<td class="task-count" style="color:#27ae60;">' . $row['tasks']['done'] . '</td>';
    
    foreach ($days as $day) {
        echo '<td>';
        echo getReportCell($row['userId'], $day, $weeklyReports);
        echo '</td>';
    }
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '<p style="font-size:10px; color:#7f8c8d;">* Report content includes the full daily task description submitted by the user.</p>';
echo '</body>';
echo '</html>';