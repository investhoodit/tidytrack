<?php
session_start();
include '../dp.php';
include '../track.php';

// Access control
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
  http_response_code(401);
  echo '<div style="padding:16px;color:#b91c1c;background:#fff;border-radius:8px">Unauthorized</div>';
  exit;
}

$userID     = $_SESSION['user_id'] ?? 13;
$userName   = $_SESSION['name'] ?? 'Manager';
$userEmail  = $_SESSION['email'] ?? null;

// Log activity
if ($userID) log_activity($userID, "Viewed Dashboard");

// Fetch user data
$stmt = $conn->prepare("SELECT status, profile_picture, role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$currentStatus = $userData['status'] ?? 'offline';

// Auto-update status to "online"
if ($currentStatus === 'offline') {
  $stmt = $conn->prepare("UPDATE users SET status = 'online' WHERE user_id = ?");
  $stmt->bind_param("i", $userID);
  $stmt->execute();
  $currentStatus = 'online';
}
$stmt->close();

// Load projects (all projects, but will filter later where needed)
$projects = [];
$sqlProjects = "SELECT project_id, project_name, end_date, created_by FROM projects_x ORDER BY end_date ASC";
if ($res = $conn->query($sqlProjects)) {
  while ($row = $res->fetch_assoc()) $projects[] = $row;
  $res->free();
}

// Users by role
$roles = ['admin'=>0,'manager'=>0,'leader'=>0,'member'=>0];
if ($st = $conn->prepare("SELECT role, COUNT(*) c FROM users GROUP BY role")) {
  $st->execute(); 
  $rs = $st->get_result();
  while($r = $rs->fetch_assoc()){ 
    $roles[strtolower($r['role'])] = (int)$r['c']; 
  }
  $st->close();
}
$totalUsers = array_sum($roles);

// In-progress tasks
$inprogressCount = $conn->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'In progress'")
                        ->fetch_assoc()['total'];

// Active vs completed projects (still counts ALL projects)
$today = date('Y-m-d');
$activeCount = $completedCount = 0;
foreach ($projects as $p) {
  if (($p['end_date'] ?? '9999-12-31') < $today) $completedCount++;
  else $activeCount++;
}

/*
===============================================================
  NEW: RESTRICT RECENT PROJECT ACTIVITY TO ONLY MANAGER PROJECTS
===============================================================
*/

$recentProjectsStmt = $conn->prepare("
  SELECT 
    p.project_id,
    p.project_name, 
    p.start_date, 
    p.end_date,
    IFNULL(GROUP_CONCAT(DISTINCT u.name SEPARATOR ', '), 'N/A') AS team
  FROM projects_x p
  LEFT JOIN tasks t ON t.project_id = p.project_id
  LEFT JOIN users u ON t.assignTo = u.name
  WHERE p.created_by = ?     -- restrict to manager-created projects
  GROUP BY p.project_id
  ORDER BY p.start_date DESC
  LIMIT 5
");
$recentProjectsStmt->bind_param("i", $userID);
$recentProjectsStmt->execute();
$recentProjects = $recentProjectsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recentProjectsStmt->close();

/*
===============================================================
  TASK AGGREGATES
===============================================================
*/
$agg = ['total'=>0,'done'=>0,'inprog'=>0,'inrev'=>0,'onhold'=>0,'overdue'=>0];
if ($r = $conn->query("
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS done,
    SUM(CASE WHEN status='In progress' THEN 1 ELSE 0 END) AS inprog,
    SUM(CASE WHEN status='In review' THEN 1 ELSE 0 END) AS inrev,
    SUM(CASE WHEN status='On hold' THEN 1 ELSE 0 END) AS onhold,
    SUM(CASE WHEN dueDate < CURDATE() AND status <> 'Completed' THEN 1 ELSE 0 END) AS overdue
  FROM tasks
")) {
  $agg = $r->fetch_assoc();
  $r->free();
}

$done       = (int)$agg['done'];
$inprog     = (int)$agg['inprog'];
$inrev      = (int)$agg['inrev'];
$onhold     = (int)$agg['onhold'];
$overdue    = (int)$agg['overdue'];

?>

<style>
:root {
  --card-bg: #ffffff;
  --text-color: #333333;
  --muted: #6b7280;
}

.stat-cards {
  display: flex; 
  flex-wrap: wrap; 
  gap: 20px; 
  margin-bottom: 25px;
}

.stat-card {
  flex: 1 1 220px; 
  background: var(--card-bg); 
  padding: 20px;
  border-radius: 15px; 
  box-shadow: 0 3px 15px rgba(0,0,0,.1);
  display: flex; 
  flex-direction: column; 
  justify-content: space-between;
  cursor: pointer; 
  transition: .2s;
}

.stat-card:hover { 
  background: rgba(0,0,0,0.05); 
  transform: translateY(-2px);
}

.small-info { 
  font-size: 14px; 
  color: var(--muted); 
  margin-top: 8px;
}

.count {
  font-size: 2rem;
  font-weight: bold;
  color: #3B82F6;
}

.stat-card h4 {
  margin-bottom: 15px;
  color: var(--text-color);
  border-bottom: 1px solid #e5e7eb;
  padding-bottom: 10px;
}

@media (max-width: 600px){ 
  .stat-card{ 
    flex: 1 1 100%; 
  }
  
  .stat-cards {
    gap: 15px;
  }
}

table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
}

th, td {
  padding: 12px;
  text-align: left;
  border-bottom: 1px solid #e5e7eb;
}

th {
  background-color: #f9fafb;
  font-weight: 600;
  color: var(--text-color);
}

tr:hover {
  background-color: #f9fafb;
}
</style>

<h2 style="font-size:24px;font-weight:600;margin-bottom:20px">
  Welcome back, <?= htmlspecialchars($userName) ?>!
</h2>

<!-- STAT CARDS -->
<div class="stat-cards">
  <div class="stat-card" onclick="openDetails('In progress')">
    <h3>In Progress Tasks</h3>
    <div class="count"><?= $inprogressCount ?></div>
    <div class="small-info">Tasks currently being worked on</div>
  </div>

  <div class="stat-card" onclick="openDetails('activeprojects')">
    <h3>Active Projects</h3>
    <div class="count"><?= $activeCount ?></div>
    <div class="small-info">Ongoing projects</div>
  </div>

  <div class="stat-card" onclick="openDetails('completedprojects')">
    <h3>Completed Projects</h3>
    <div class="count"><?= $completedCount ?></div>
    <div class="small-info">Successfully finished projects</div>
  </div>

  <div class="stat-card" onclick="openDetails('users')">
    <h3>Total Users</h3>
    <div class="count"><?= (int) ($totalUsers ?? 0) ?></div>
    <div class="small-info">
      Admin <?= (int) ($roles['admin'] ) ?>  &nbsp;&nbsp;
      Manager <?= (int) ($roles['manager']) ?>  &nbsp;&nbsp;
      Leader <?= (int) ($roles['leader']) ?>  &nbsp;&nbsp;
      Member <?= (int) ($roles['member']) ?>
    </div>
  </div>
</div>

<!-- RECENT PROJECTS -->
<div class="stat-card">
  <h4>Manager Project Activity</h4>
  <table>
    <thead>
      <tr>
        <th>Project</th>
        <th>Team</th>
        <th>Start Date</th>
        <th>End Date</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($recentProjects)): ?>
        <tr>
          <td colspan="4" style="text-align: center; padding: 20px; color: var(--muted);">
            No recent projects found.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($recentProjects as $proj): ?>
          <tr>
            <td><?= htmlspecialchars($proj['project_name']) ?></td>
            <td><?= htmlspecialchars($proj['team']) ?></td>
            <td><?= date("M d, Y", strtotime($proj['start_date'])) ?></td>
            <td><?= date("M d, Y", strtotime($proj['end_date'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- MODAL -->
<div id="detailsModal" style="
  display:none; position:fixed; top:0; left:0; width:100%; height:100%;
  background:rgba(0,0,0,0.5); backdrop-filter:blur(3px);
  justify-content:center; align-items:center; z-index:9999;">
  <div style="background:white;padding:30px;border-radius:12px;width:90%;max-width:900px;max-height:80vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h2 id="modalTitle" style="margin:0"></h2>
      <button onclick="closeModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#666">&times;</button>
    </div>
    <div id="modalContent">Loading...</div>
    <div style="text-align:right;margin-top:20px">
      <button onclick="closeModal()" style="padding:10px 20px;
              background:#444;color:white;border:none;border-radius:6px;cursor:pointer">Close</button>
    </div>
  </div>
</div>

<script>
// MODAL FUNCTIONS
function openDetails(type){
  const modal = document.getElementById("detailsModal");
  const title = document.getElementById("modalTitle");
  const content = document.getElementById("modalContent");

  const titles = {
    inprogress: "In Progress Tasks",
    activeprojects: "Active Projects", 
    completedprojects: "Completed Projects",
    users: "All Users"
  };

  title.innerHTML = titles[type] || "Details";
  content.innerHTML = '<div style="text-align:center;padding:40px">Loading...</div>';
  modal.style.display = "flex";

  fetch("fetch_dashboard_details.php?type=" + type)
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      return response.text();
    })
    .then(html => {
      content.innerHTML = html;
    })
    .catch(error => {
      console.error('Error loading details:', error);
      content.innerHTML = '<div style="text-align:center;padding:40px;color:#ef4444">Error loading details. Please try again.</div>';
    });
}

function closeModal(){
  document.getElementById("detailsModal").style.display = "none";
}
</script>
