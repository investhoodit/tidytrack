<?php
session_start();
require_once __DIR__.'/../dp.php';

// -------- AUTH GUARD (choose who can view) --------
$userId = $_SESSION['user_id'] ?? null;           // must match users.userID
$roleSession = $_SESSION['role'] ?? '';           // e.g., 'admin','manager','leader','member'
$roleNorm = strtolower(trim($roleSession));
$allowed = ['admin'];                             // <- add 'manager' if you want managers to view
if (!$userId || !in_array($roleNorm, $allowed, true)) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

// -------- detect if description column exists (defensive) --------
$hasDescription = false;
if ($res = $conn->query("SHOW COLUMNS FROM user_activity LIKE 'description'")) {
  $hasDescription = $res->num_rows > 0;
  $res->free();
}

// -------- filters --------
$role      = $_GET['role']    ?? '';                 // manager | leader | member | admin
$userQ     = trim($_GET['user']    ?? '');           // partial name/email
$activityQ = trim($_GET['activity']?? '');           // partial activity type
$ipQ       = trim($_GET['ip']      ?? '');           // partial IP
$q         = trim($_GET['q']       ?? '');           // free text (description/UA)
$from      = $_GET['from']    ?? '';
$to        = $_GET['to']      ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;
$offset    = ($page - 1) * $perPage;
$export    = $_GET['export'] ?? '';

// -------- where builder --------
$where=[]; $params=[]; $types='';
if ($role!==''){ $where[]="u.role = ?"; $params[]=$role; $types.='s'; }
if ($userQ!==''){
  // search name or email
  $where[]="(u.name LIKE ? OR u.email LIKE ?)";
  $params[]="%$userQ%"; $params[]="%$userQ%"; $types.='ss';
}
if ($activityQ!==''){ $where[]="a.activity_type LIKE ?"; $params[]="%$activityQ%"; $types.='s'; }
if ($ipQ!==''){ $where[]="a.ip_address LIKE ?"; $params[]="%$ipQ%"; $types.='s'; }
if ($q!==''){
  if ($hasDescription){
    $where[]="(a.description LIKE ? OR a.user_agent LIKE ?)";
    $params[]="%$q%"; $params[]="%$q%"; $types.='ss';
  } else {
    $where[]="a.user_agent LIKE ?";
    $params[]="%$q%"; $types.='s';
  }
}
if ($from!==''){ $where[]="DATE(a.timestamp) >= ?"; $params[]=$from; $types.='s'; }
if ($to!==''){   $where[]="DATE(a.timestamp) <= ?"; $params[]=$to;   $types.='s'; }
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';

// -------- count --------
$sqlCount = "SELECT COUNT(*) c
             FROM user_activity a
             JOIN users u ON u.userID = a.user_id
             $whereSql";
$stmt = $conn->prepare($sqlCount);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
$totalPages = max(1, (int)ceil($total/$perPage));

// -------- list rows --------
$selectFields = "a.id, a.timestamp, a.user_id, u.name, u.email, u.role, a.activity_type, a.ip_address, a.user_agent";
if ($hasDescription) $selectFields .= ", a.description";

$sqlList = "SELECT $selectFields
            FROM user_activity a
            JOIN users u ON u.userID = a.user_id
            $whereSql
            ORDER BY a.timestamp DESC
            LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sqlList);
if ($types){
  $typesWith = $types.'ii';
  $paramsWith = array_merge($params, [$perPage, $offset]);
  $stmt->bind_param($typesWith, ...$paramsWith);
} else {
  $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -------- stats: counts by role --------
$sqlStats = "SELECT u.role, COUNT(*) cnt
             FROM user_activity a
             JOIN users u ON u.userID = a.user_id
             $whereSql
             GROUP BY u.role";
$stmt = $conn->prepare($sqlStats);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$stats=[]; $rs=$stmt->get_result();
while($r=$rs->fetch_assoc()) $stats[$r['role']] = (int)$r['cnt'];
$stmt->close();

// -------- top actors --------
$sqlTop = "SELECT u.name, u.role, COUNT(*) cnt
           FROM user_activity a
           JOIN users u ON u.userID = a.user_id
           $whereSql
           GROUP BY a.user_id
           ORDER BY cnt DESC
           LIMIT 5";
$stmt = $conn->prepare($sqlTop);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$topActors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -------- export CSV --------
if ($export==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=audit_logs.csv');
  $out=fopen('php://output','w');
  $header=['ID','Timestamp','User','Email','Role','Activity'];
  if ($hasDescription) $header[]='Description';
  $header[]='IP'; $header[]='User Agent';
  fputcsv($out,$header);

  $selectExport = "a.id, a.timestamp, u.name, u.email, u.role, a.activity_type";
  if ($hasDescription) $selectExport .= ", a.description";
  $selectExport .= ", a.ip_address, a.user_agent";

  $sqlAll = "SELECT $selectExport
             FROM user_activity a
             JOIN users u ON u.userID = a.user_id
             $whereSql
             ORDER BY a.timestamp DESC
             LIMIT 10000";
  $stmt = $conn->prepare($sqlAll);
  if ($types) $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  while($r=$res->fetch_assoc()){
    $row = [$r['id'],$r['timestamp'],$r['name'],$r['email'],$r['role'],$r['activity_type']];
    if ($hasDescription) $row[] = $r['description'];
    $row[] = $r['ip_address'];
    $row[] = $r['user_agent'];
    fputcsv($out,$row);
  }
  $stmt->close();
  fclose($out);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en-ZA">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin • Monitoring Logs</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
<style>
  :root{--bg:#f6f8fb;--card:#fff;--ink:#1d3557;--muted:#6b7b93;--accent:#2a9d8f;--line:#e9eef5;}
  *{box-sizing:border-box} body{margin:0;background:var(--bg);font-family:Poppins,sans-serif;color:var(--ink)}
  header{position:sticky;top:0;z-index:10;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between}
  header .brand{font-weight:700}
  .wrap{max-width:1200px;margin:1rem auto;padding:0 1rem}
  .filters{display:grid;grid-template-columns:repeat(6,1fr);gap:.75rem;background:var(--card);padding:1rem;border-radius:12px;border:1px solid var(--line)}
  .filters input,.filters select{width:100%;padding:.55rem .6rem;border:1px solid var(--line);border-radius:8px;font-family:inherit}
  .filters .actions{grid-column: span 6;display:flex;gap:.5rem;justify-content:flex-end}
  .btn{border:0;border-radius:10px;padding:.6rem .9rem;cursor:pointer;font-weight:600}
  .btn-primary{background:var(--accent);color:#fff}
  .btn-outline{background:#fff;border:1px solid var(--line)}
  .grid{display:grid;grid-template-columns:1fr;gap:1rem;margin-top:1rem}
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:1rem}
  .card h4{margin:.2rem 0 .75rem}
  table{width:100%;border-collapse:collapse}
  th,td{padding:.6rem;border-bottom:1px solid var(--line);font-size:.92rem;vertical-align:top}
  th{font-size:.8rem;text-transform:uppercase;color:var(--muted);letter-spacing:.03em;text-align:left}
  .pill{display:inline-block;padding:.2rem .5rem;border-radius:999px;font-size:.72rem;border:1px solid var(--line);color:var(--muted)}
  .ua{color:var(--muted)} .mono{font-family:ui-monospace, Menlo, Consolas, monospace;font-size:.85rem}
  .pagination{display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem}
  .pagination a,.pagination span{padding:.35rem .6rem;border:1px solid var(--line);background:#fff;border-radius:8px;text-decoration:none;color:var(--ink);font-size:.9rem}
  .pagination .current{background:var(--accent);color:#fff;border-color:var(--accent)}
  .muted{color:var(--muted)}
</style>
</head>
<body>
<header>
  <div class="brand">INVESTHOOD_TSM • Admin Logs</div>
  <div class="muted">Africa/Johannesburg</div>
</header>

<div class="wrap">
  <!-- FILTERS -->
  <form class="filters" method="get">
    <select name="role">
      <option value="">All roles</option>
      <option value="admin"   <?= $role==='admin'  ?'selected':'' ?>>Admin</option>
      <option value="manager" <?= $role==='manager'?'selected':'' ?>>Manager</option>
      <option value="leader"  <?= $role==='leader' ?'selected':'' ?>>Leader</option>
      <option value="member"  <?= $role==='member' ?'selected':'' ?>>Member</option>
    </select>
    <input type="text" name="user" placeholder="User name or email" value="<?= htmlspecialchars($userQ) ?>">
    <input type="text" name="activity" placeholder="Activity (e.g., Login, Task.Update)" value="<?= htmlspecialchars($activityQ) ?>">
    <input type="text" name="ip" placeholder="IP (e.g., 105. or 192.168.1.10)" value="<?= htmlspecialchars($ipQ) ?>">
    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    <input type="text" name="q" placeholder="Search description / user agent" value="<?= htmlspecialchars($q) ?>" style="grid-column: span 6;">
    <div class="actions">
      <button class="btn btn-outline" type="submit">Apply filters</button>
      <a class="btn btn-outline" href="admin_logs.php">Reset</a>
      <button class="btn btn-primary" name="export" value="csv" type="submit">Export CSV</button>
    </div>
  </form>

  <!-- STATS -->
  <div class="stats">
    <div class="card"><h4>Events (Admin)</h4><div class="big"><?= $stats['admin'] ?? 0 ?></div></div>
    <div class="card"><h4>Events (Manager)</h4><div class="big"><?= $stats['manager'] ?? 0 ?></div></div>
    <div class="card"><h4>Events (Leader)</h4><div class="big"><?= $stats['leader'] ?? 0 ?></div></div>
    <div class="card"><h4>Events (Member)</h4><div class="big"><?= $stats['member'] ?? 0 ?></div></div>
    <div class="card">
      <h4>Top Actors</h4>
      <?php if ($topActors): ?>
        <ol style="margin:0;padding-left:1.25rem">
          <?php foreach ($topActors as $t): ?>
            <li><?= htmlspecialchars($t['name']) ?> <span class="pill"><?= htmlspecialchars($t['role']) ?></span> — <?= (int)$t['cnt'] ?></li>
          <?php endforeach; ?>
        </ol>
      <?php else: ?><div class="muted">No data</div><?php endif; ?>
    </div>
  </div>

  <!-- TABLE -->
  <div class="grid">
    <div class="card">
      <h4>Activity Logs (<?= $total ?> results)</h4>
      <div style="overflow:auto;">
        <table>
          <thead>
            <tr>
              <th>Time</th>
              <th>User</th>
              <th>Email</th>
              <th>Role</th>
              <th>Activity</th>
              <?php if ($hasDescription): ?><th>Description</th><?php endif; ?>
              <th>IP</th>
              <th>User Agent</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="<?= $hasDescription?8:7 ?>" class="muted">No logs found for the current filters.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td class="mono"><?= htmlspecialchars($r['timestamp']) ?></td>
              <td><?= htmlspecialchars($r['name']) ?></td>
              <td class="mono"><?= htmlspecialchars($r['email']) ?></td>
              <td><?= htmlspecialchars($r['role']) ?></td>
              <td><?= htmlspecialchars($r['activity_type']) ?></td>
              <?php if ($hasDescription): ?>
                <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
              <?php endif; ?>
              <td class="mono"><?= htmlspecialchars($r['ip_address']) ?></td>
              <td class="ua"><?= htmlspecialchars(substr($r['user_agent'],0,100)) ?><?= strlen($r['user_agent'])>100?'…':'' ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- PAGINATION -->
      <div class="pagination" style="margin-top:.6rem;display:flex;gap:.5rem;justify-content:flex-end;">
        <?php
        function qmerge($arr){ return htmlspecialchars(http_build_query(array_merge($_GET,$arr))); }
        ?>
        <?php if ($page>1): ?>
          <a href="?<?= qmerge(['page'=>1]) ?>">&laquo; First</a>
          <a href="?<?= qmerge(['page'=>$page-1]) ?>">&lsaquo; Prev</a>
        <?php else: ?>
          <span class="muted">&laquo; First</span>
          <span class="muted">&lsaquo; Prev</span>
        <?php endif; ?>
        <span class="current" style="padding:.35rem .6rem;border:1px solid var(--line);border-radius:8px;background:#fff;"><?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page<$totalPages): ?>
          <a href="?<?= qmerge(['page'=>$page+1]) ?>">Next &rsaquo;</a>
          <a href="?<?= qmerge(['page'=>$totalPages]) ?>">Last &raquo;</a>
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
