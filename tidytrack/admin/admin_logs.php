<?php
session_start();
require_once __DIR__.'/../dp.php';
include '../track.php'; 
// --- AUTH GUARD ---
$userId = $_SESSION['user_id'] ?? null;
$role   = strtolower(trim($_SESSION['role'] ?? ''));
$allowed = ['admin'];
if (!$userId || !in_array($role, $allowed, true)) { http_response_code(403); echo "Forbidden"; exit; }

// Column checks
$hasUserName = false;
if ($res = $conn->query("SHOW COLUMNS FROM users LIKE 'name'")) { $hasUserName = $res->num_rows > 0; $res->free(); }
$hasDescription = false;
if ($res = $conn->query("SHOW COLUMNS FROM user_activity LIKE 'description'")) { $hasDescription = $res->num_rows > 0; $res->free(); }

// Filters
$roleF     = $_GET['role']    ?? '';
$userQ     = trim($_GET['user']    ?? '');
$from      = $_GET['from']    ?? '';
$to        = $_GET['to']      ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;
$offset    = ($page - 1) * $perPage;
$export    = $_GET['export'] ?? '';

// WHERE builder
$where=[]; $params=[]; $types='';
if ($roleF!==''){ $where[]="u.role = ?"; $params[]=$roleF; $types.='s'; }
if ($userQ!==''){ $where[]="(u.name LIKE ? OR u.email LIKE ?)"; $params[]="%$userQ%"; $params[]="%$userQ%"; $types.='ss'; }
if ($from!==''){ $where[]="DATE(a.timestamp) >= ?"; $params[]=$from; $types.='s'; }
if ($to  !==''){ $where[]="DATE(a.timestamp) <= ?"; $params[]=$to;   $types.='s'; }
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';

// Count
$sqlCount = "SELECT COUNT(*) c FROM user_activity a JOIN users u ON u.user_id=a.user_id $whereSql";
$stmt = $conn->prepare($sqlCount); if ($types) $stmt->bind_param($types, ...$params); $stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0); $stmt->close();
$totalPages = max(1, (int)ceil($total/$perPage));

// List
$displayNameSql = $hasUserName ? "u.name" : "u.email";
$fields = "a.id, a.timestamp, u.role, $displayNameSql AS display_name, u.email, a.activity_type, a.ip_address, a.user_agent";
if ($hasDescription) $fields .= ", a.description";
$sql = "SELECT $fields FROM user_activity a JOIN users u ON u.user_id=a.user_id $whereSql ORDER BY a.timestamp DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($types){ $typesWith=$types.'ii'; $paramsWith=array_merge($params, [$perPage,$offset]); $stmt->bind_param($typesWith, ...$paramsWith); }
else { $stmt->bind_param('ii',$perPage,$offset); }
$stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Fetch current logged-in user
$sqlUser = "SELECT profile_picture, status, role FROM users WHERE user_id = ?";
$stmtUser = $conn->prepare($sqlUser);
$stmtUser->bind_param("i", $userId);
$stmtUser->execute();
$result = $stmtUser->get_result();
$currentUser = $result->fetch_assoc();
$stmtUser->close();
$currentProfilePicture = $currentUser['profile_picture'] ?? null;
$currentStatus = $currentUser['status'] ?? 'offline';
$currentRole = $currentUser['role'] ?? 'Admin';
log_activity($userId, "Viewed My Logs");

// Helper for pagination links
function build_qs($merge){ return htmlspecialchars(http_build_query(array_merge($_GET,$merge))); }

// Check dark mode cookie
$darkMode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : '';
?>
<!DOCTYPE html>
<html lang="en-ZA" class="<?php echo $darkMode; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin • Monitoring Logs</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #f6f8fb;
    --card: #fff;
    --ink: #1d3557;
    --muted: #6b7b93;
    --accent: #2a9d8f;
    --line: #e9eef5;
    --input-bg: #fff;
    --input-text: #1d3557;
    --hover: #f1f5f9;
}

.dark {
    --bg: #0b1220;
    --card: #0f172a;
    --ink: #e5e7eb;
    --muted: #94a3b8;
    --accent: #2a9d8f;
    --line: rgba(255,255,255,0.1);
    --input-bg: #1e293b;
    --input-text: #e5e7eb;
    --hover: #1e293b;
}

*{box-sizing:border-box} 
body{
    margin:0;
    background: var(--bg);
    font-family:Poppins,sans-serif;
    color:var(--ink);
    transition: background 0.25s ease, color 0.25s ease;
}
header{
    position:sticky;
    top:0;
    z-index:10;
    background: var(--card);
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:1rem;
    border-bottom:1px solid var(--line)
}
.wrap{max-width:1200px;margin:1rem auto;padding:0 1rem}

/* Filters */
.filters{
    display:grid;
    grid-template-columns:repeat(6,1fr);
    gap:.75rem;
    background: var(--card);
    border:1px solid var(--line);
    border-radius:12px;
    padding:1rem;
    margin-bottom:1rem
}
.filters input,.filters select{
    width:100%;
    padding:.55rem .6rem;
    border:1px solid var(--line);
    border-radius:8px;
    background: var(--input-bg);
    color: var(--input-text);
}
.filters input:focus, .filters select:focus {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.filters .actions{
    grid-column: span 6;
    display:flex;
    gap:.5rem;
    justify-content:flex-end
}
.btn{
    border:1px solid var(--line);
    background: var(--card);
    border-radius:10px;
    padding:.5rem .8rem;
    color:var(--ink);
    text-decoration:none;
    display:inline-flex;
    gap:.5rem;
    align-items:center;
    cursor: pointer;
    transition: background 0.2s ease;
}
.btn:hover {
    background: var(--hover);
}

/* Table & Cards */
.grid{display:grid;grid-template-columns:1fr;gap:1rem}
.card{
    background: var(--card);
    border:1px solid var(--line);
    border-radius:12px;
    padding:1rem;
    overflow-x:auto
}
table{width:100%;border-collapse:collapse} 
th,td{
    padding:.6rem;
    border-bottom:1px solid var(--line);
    font-size:.92rem;
    vertical-align:top;
    color: var(--ink);
} 
th{
    font-size:.8rem;
    text-transform:uppercase;
    color:var(--muted);
    background: var(--card);
}
td {
    background: var(--card);
}
tr:hover td {
    background: var(--hover);
}
.ua{color:var(--muted)} 
.mono{
    font-family:ui-monospace,Menlo,Consolas,monospace;
    font-size:.85rem;
    color: var(--ink);
}

/* Pagination */
.pagination{
    display:flex;
    gap:.5rem;
    flex-wrap:wrap;
    justify-content:flex-end;
    margin-top:.75rem
}
.pagination a,.pagination span{
    padding:.35rem .6rem;
    border:1px solid var(--line);
    background: var(--card);
    border-radius:8px;
    text-decoration:none;
    color:var(--ink);
    font-size:.9rem;
    transition: background 0.2s ease;
}
.pagination a:hover {
    background: var(--hover);
}
.current{
    background: var(--accent);
    color:#fff;
    border-color:var(--accent);
}
.muted{color:var(--muted)}

/* ---------- MOBILE RESPONSIVE ---------- */
@media(max-width:1000px){.filters{grid-template-columns:repeat(3,1fr)}}
@media(max-width:640px){
  .filters{grid-template-columns:1fr; gap:.5rem}
  .filters .actions{flex-direction:column;align-items:stretch}
  table th,table td{font-size:.78rem;padding:.4rem}
  .mono{font-size:.75rem}
}
@media(max-width:400px){
  body{font-size:.85rem}
}
</style>

<script>
// Dark mode sync with parent
function updateDarkMode(isDark) {
    const html = document.documentElement;
    if (isDark) {
        html.classList.add('dark');
        document.cookie = "dark_mode=enabled; path=/; max-age=31536000";
    } else {
        html.classList.remove('dark');
        document.cookie = "dark_mode=disabled; path=/; max-age=31536000";
    }
}

// Listen for dark mode messages from parent
window.addEventListener('message', (event) => {
    if (event.data.type === 'darkModeUpdate') {
        updateDarkMode(event.data.isDark);
    }
});

// Request dark mode state on load
window.addEventListener('load', () => {
    if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'getDarkModeState' }, '*');
    }
});

// Also check cookie directly
document.addEventListener('DOMContentLoaded', () => {
    const hasDarkCookie = document.cookie.includes('dark_mode=enabled');
    if (hasDarkCookie) {
        document.documentElement.classList.add('dark');
    }
});
</script>
</head>
<body>
<div class="wrap">
  <!-- FILTERS -->
  <form class="filters" method="get">
    <select name="role">
      <option value="">All roles</option>
      <option value="admin"   <?= $roleF==='admin'  ?'selected':'' ?>>Admin</option>
      <option value="manager" <?= $roleF==='manager'?'selected':'' ?>>Manager</option>
      <option value="leader"  <?= $roleF==='leader' ?'selected':'' ?>>Leader</option>
      <option value="member"  <?= $roleF==='member' ?'selected':'' ?>>Member</option>
    </select>
    <input type="text" name="user" placeholder="User name or email" value="<?= htmlspecialchars($userQ) ?>">
    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    <input type="date" name="to"   value="<?= htmlspecialchars($to) ?>">
    <div class="actions">
      <button class="btn" type="submit">Apply filters</button>
      <a class="btn" href="admin_logs.php">Reset</a>
      <a class="btn" href="admin_logs_export_pdf.php?<?= http_build_query($_GET) ?>">Export PDF</a>
    </div>
  </form>

  <!-- TABLE -->
  <div class="grid">
    <div class="card">
      <h4 style="color: var(--ink); margin-top: 0;">Activity Logs (<?= $total ?> results)</h4>
      <div>
        <table>
          <thead>
            <tr>
              <th>Time</th><th>User</th><th>Email</th><th>Role</th><th>Activity</th>
              <?php if ($hasDescription): ?><th>Description</th><?php endif; ?>
              <th>IP</th><th>User Agent</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="<?= $hasDescription?8:7 ?>" class="muted">No logs found for the current filters.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td class="mono"><?= htmlspecialchars($r['timestamp']) ?></td>
              <td><?= htmlspecialchars($r['display_name']) ?></td>
              <td class="mono"><?= htmlspecialchars($r['email']) ?></td>
              <td><?= htmlspecialchars($r['role']) ?></td>
              <td><?= htmlspecialchars($r['activity_type']) ?></td>
              <?php if ($hasDescription): ?><td><?= htmlspecialchars($r['description'] ?? '') ?></td><?php endif; ?>
              <td class="mono"><?= htmlspecialchars($r['ip_address']) ?></td>
              <td class="ua"><?= htmlspecialchars(substr($r['user_agent'],0,100)) ?><?= strlen($r['user_agent'])>100?'…':'' ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- PAGINATION -->
      <div class="pagination">
        <?php if ($page>1): ?>
          <a href="?<?= build_qs(['page'=>1]) ?>">&laquo; First</a>
          <a href="?<?= build_qs(['page'=>$page-1]) ?>">&lsaquo; Prev</a>
        <?php else: ?>
          <span class="muted">&laquo; First</span>
          <span class="muted">&lsaquo; Prev</span>
        <?php endif; ?>
        <span class="current"><?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page<$totalPages): ?>
          <a href="?<?= build_qs(['page'=>$page+1]) ?>">Next &rsaquo;</a>
          <a href="?<?= build_qs(['page'=>$totalPages]) ?>">Last &raquo;</a>
        <?php else: ?>
          <span class="muted">Next &rsaquo;</span>
          <span class="muted">Last &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>