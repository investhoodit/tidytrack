<?php
session_start();
include '../dp.php';
include '../track.php';
@include __DIR__ . '/../profile.php';

$userName      = $userName ?? ($_SESSION['name'] ?? 'Manager');
$userID        = $_SESSION['user_id'] ?? null;
$currentRole   = $currentRole ?? ($_SESSION['role'] ?? 'manager');
$userInitial   = $userInitial ?? strtoupper(substr($userName, 0, 1));
$currentStatus = $currentStatus ?? 'ready';

if (!$userID || $currentRole !== 'manager') {
    die("Unauthorized. Access restricted to managers.");
}

$status_info = $status_info ?? [
  'ready'          => ['name' => 'Ready'],
  'out_of_office'  => ['name' => 'Out of Office'],
  'sick'           => ['name' => 'Sick'],
  'focusing'       => ['name' => 'Focusing'],
  'in_meeting'     => ['name' => 'In Meeting'],
  'offline'        => ['name' => 'Offline'],
];
$statusLabel = $status_info[$currentStatus]['name'] ?? ucfirst(str_replace('_', ' ', $currentStatus));

// ==== Access control ====
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
    header('Location: login.php');
    exit();
}

// ==== CONFIG ====
$recordsPerPage = 10;
$page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
$offset = ($page - 1) * $recordsPerPage;
$userFilter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$clientIp = $_SERVER['REMOTE_ADDR'];

// ==== USER FILTER ====
$whereClauses = [];
if ($userFilter > 0) {
    $whereClauses[] = "ua.user_id = $userFilter";
}
if ($searchTerm !== '') {
    $escapedSearch = $conn->real_escape_string($searchTerm);
    $whereClauses[] = "(ua.activity_type LIKE '%$escapedSearch%' OR ua.ip_address LIKE '%$escapedSearch%')";
}
$whereSQL = count($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// ==== TOTAL RECORDS ====
$countSql = "SELECT COUNT(*) AS total FROM user_activity ua $whereSQL";
$countResult = $conn->query($countSql);
$totalRow = $countResult->fetch_assoc();
$totalRecords = $totalRow['total'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// ==== FETCH USERS ====
$usersResult = $conn->query("SELECT user_id, name FROM users ORDER BY name");

// ==== FETCH LOGS ====
$sql = "
    SELECT ua.*, u.name 
    FROM user_activity ua
    JOIN users u ON ua.user_id = u.user_id
    $whereSQL
    ORDER BY ua.timestamp DESC
    LIMIT $offset, $recordsPerPage
";
$result = $conn->query($sql);

$userName = htmlspecialchars($_SESSION['name'] ?? 'Manager');
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>User Activity Log</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <style>
    :root { 
      --accent: #1d3557; 
      --bg: #eef2f5; 
      --card-bg: #fff;
      --border: #ddd;
      --text: #333;
      --text-muted: #666;
    }
    * { box-sizing: border-box; }
    body { 
      font-family: 'Poppins', sans-serif; 
      margin: 0; 
      background: var(--bg);
      color: var(--text);
      display: flex; 
      min-height: 100vh; 
      transition: background 0.3s, color 0.3s;
    }

    /* Sidebar */
    #manager-sidenav{
      width:300px;
      background:var(--card-bg);
      border-right:1px solid var(--border);
      display:flex;
      flex-direction:column;
      padding:18px;
      position:fixed;
      inset:0 auto 0 0;
      height:100vh;
      overflow-y:auto;
      z-index:100;
      transition:transform .3s ease, background .3s, color .3s;
    }
    #manager-sidenav .top{display:flex;align-items:center;gap:12px;padding-bottom:18px;border-bottom:1px solid #eee;}
    #manager-sidenav .me{width:44px;height:44px;border-radius:50%;background:var(--accent);color:#fff;
      display:flex;align-items:center;justify-content:center;font-weight:700;position:relative;}
    .status-indicator{position:absolute;bottom:0;right:0;width:10px;height:10px;border-radius:50%;border:2px solid #fff;}
    .status-ready{background:#00d4aa}.status-out_of_office{background:#ffa726}.status-sick{background:#e94560}
    .status-focusing{background:#9c27b0}.status-in_meeting{background:#2196f3}.status-offline{background:#ccc}
    #manager-sidenav ul{list-style:none;margin:20px 0 0;padding:0;}
    #manager-sidenav a{
      color:var(--text-muted); text-decoration:none;font-size:15px;display:flex;align-items:center;gap:12px;padding:10px;
      border-radius:8px;transition:background .2s,color .2s;background:none;border:none;cursor:pointer;width:100%;text-align:left;
    }
    #manager-sidenav a:hover,#manager-sidenav a.active{background:#f6fbf6;color:var(--accent);}

    /* Mobile menu */
    .mobile-menu-toggle{display:none;position:fixed;top:12px;left:12px;z-index:110;width:42px;height:42px;border:0;border-radius:10px;background:var(--accent);color:#fff;font-size:18px;}
    @media(max-width:768px){
      .mobile-menu-toggle{display:flex;align-items:center;justify-content:center;}
      #manager-sidenav{transform:translateX(-100%);width:260px;}
      #manager-sidenav.active{transform:translateX(0);}
      #main-content{margin-left:0;padding:1rem;}
    }

    /* Main dashboard */
    .dashboard-container {
      flex: 1;
      padding: 20px;
      width: 95%;
      max-width: 1200px;
      margin-left: 300px; /* sidebar width */
    }

    .dashboard-welcome { 
      font-size: 24px; 
      font-weight: 600; 
      color: var(--text); 
      margin-bottom: 20px; 
    }

    .stat-card { 
      background: var(--card-bg);
      border-radius: 8px; 
      box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
      margin-bottom: 20px;
      padding: 20px;
      transition: background 0.3s, box-shadow 0.3s;
    }

    .stat-card h4 { font-size:16px;color:var(--text);margin-bottom:8px; }
    .stat-card p { font-size:14px;color:var(--text);margin:6px 0; }
    .stat-card p strong { color: var(--accent); }

    .filter-form {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      align-items: center;
    }

    select, input[type="submit"], input[type="text"] {
      padding: 0.5rem;
      font-family: 'Poppins', sans-serif;
      font-size: 14px;
      border: 1px solid #ccc;
      border-radius: 6px;
      transition: border-color 0.2s, background 0.3s, color 0.3s;
    }

    select:focus, input[type="text"]:focus { border-color: var(--accent); outline: none; }
    input[type="submit"] { background: var(--accent); color: white; border: none; cursor: pointer; font-weight: 600; transition: background-color 0.2s; }
    input[type="submit"]:hover { background: #144a7e; }

    .pagination {
      display: flex;
      gap: 8px;
      justify-content: center;
      flex-wrap: wrap;
      font-size: 15px;
    }

    .pagination a, .pagination strong, .pagination .dots, .pagination .disabled {
      padding: 6px 12px;
      border-radius: 6px;
      border: 1px solid var(--accent);
      text-decoration: none;
      transition: background-color 0.3s, color 0.3s;
    }

    .pagination a:hover {
      background-color: var(--accent);
      color: #fff;
    }

    .pagination strong {
      background-color: #ddd;
      border-color: #bbb;
      color: #555;
    }

    .pagination .dots {
      border: none;
      background: none;
      color: var(--text-muted);
      cursor: default;
    }

    .pagination .disabled {
      border: 1px solid #ccc;
      background: #f0f0f0;
      color: #aaa;
      cursor: default;
    }

    .status-notification { display: flex; align-items: center; gap: 10px; color: #999; font-style: italic; padding: 20px; justify-content: center; }
    .btn { background: none; color: var(--accent); font-weight: 600; text-decoration: none; font-size: 14px; }
    .btn:hover { text-decoration: underline; }

    @media (max-width: 768px) {
      .dashboard-container { margin-left: 0; padding: 15px; }
      .filter-form { flex-direction: column; align-items: stretch; }
      select, input[type="submit"], input[type="text"] { width: 100%; }
      .stat-card { padding: 15px; }
      .pagination { font-size: 14px; gap: 6px; }
      .pagination a, .pagination strong { padding: 6px 10px; font-size: 14px; }
      .dashboard-welcome { font-size: 20px; text-align: center; }
      .btn { display: block; margin: 0 auto 1rem auto; text-align: center; font-size: 14px; }
      .status-notification { font-size: 14px; flex-direction: column; text-align: center; }
    }

    /* Dark Mode Styles */
    body.dark-mode {
      --bg: #1e1e2f;
      --card-bg: #2b2b3c;
      --border: #444;
      --text: #eee;
      --text-muted: #aaa;
    }
    body.dark-mode #manager-sidenav {
      background: var(--card-bg);
      border-color: var(--border);
    }
    body.dark-mode a, body.dark-mode .btn { color: #eee; }
    body.dark-mode a:hover, body.dark-mode a.active, body.dark-mode .btn:hover { color: #4425cf; background: #333; }
    body.dark-mode input, body.dark-mode select {
      background: #3b3b4d;
      border: 1px solid #555;
      color: #eee;
    }
    body.dark-mode .pagination a { border-color: #4425cf; color: #4425cf; }
    body.dark-mode .pagination a:hover { background-color: #4425cf; color: #fff; }
    body.dark-mode .pagination strong { background-color: #555; border-color: #444; color: #eee; }
    body.dark-mode .pagination .disabled { border-color: #444; background: #2b2b3c; color: #666; }
    /* Dark mode – activity log text colors */
body.dark-mode .stat-card p {
  color: #d6d6e0; /* softer light text */
}

body.dark-mode .stat-card p strong {
  color: #4425cf; /* highlight labels */
}

    body.dark-mode .stat-card { box-shadow: 0 2px 4px rgba(0,0,0,0.5); }
  </style>
</head>
<body>
<!-- Dark Mode Toggle -->
<button id="darkModeToggle" title="Toggle Dark Mode" 
        style="position:fixed;top:12px;right:12px;z-index:120;width:40px;height:40px;border:none;border-radius:8px;background:var(--accent);color:#fff;cursor:pointer;">
    <i class="fas fa-moon"></i>
</button>

<!-- Mobile Toggle -->
<button class="mobile-menu-toggle" id="mobileMenuToggle"><i class="fas fa-bars"></i></button>

<div class="dashboard-container">
  <a href="manager_index.php" class="btn" 
     style="display:inline-block; background:var(--accent); color:#fff; padding:10px 16px; border-radius:6px; text-decoration:none; font-weight:600; margin-bottom:15px;">
     <i class="fas fa-arrow-left"></i> Back to Dashboard
  </a>

  <div class="stat-card">
    <form method="GET" action="" class="filter-form">
      <select name="user_id">
        <option value="0">-- All Users --</option>
        <?php while ($user = $usersResult->fetch_assoc()): ?>
          <option value="<?= $user['user_id'] ?>" <?= ($userFilter == $user['user_id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($user['name']) ?>
          </option>
        <?php endwhile; ?>
      </select>
      <input type="text" name="search" placeholder="Search activity or IP" value="<?= htmlspecialchars($searchTerm) ?>" />
      <input type="submit" value="Filter" />
    </form>
  </div>

  <?php if ($result->num_rows > 0): ?>
    <?php while ($row = $result->fetch_assoc()): ?>
      <div class="stat-card">
        <p><strong>User:</strong> <?= htmlspecialchars($row['name']) ?></p>
        <p><strong>Activity Type:</strong> <?= htmlspecialchars($row['activity_type']) ?></p>
        <p><strong>IP Address:</strong> <?= htmlspecialchars($row['ip_address']) ?></p>
        <p><strong>Timestamp:</strong> <?= htmlspecialchars($row['timestamp']) ?></p>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
    <div class="status-notification">
      <i class="fas fa-info-circle"></i> No activity found.
    </div>
  <?php endif; ?>

  <!-- Pagination -->
  <div class="stat-card pagination">
  <?php
    $adjacents = 2;
    $start = max($page - $adjacents, 1);
    $end = min($page + $adjacents, $totalPages);

    function page_link($i, $page, $userFilter, $searchTerm, $label = null, $disabled = false) {
        $link = "?page=$i";
        if ($userFilter > 0) $link .= "&user_id=$userFilter";
        if ($searchTerm !== '') $link .= "&search=" . urlencode($searchTerm);
        if ($disabled) return "<span class='disabled'>$label</span>";
        if ($i == $page && $label === null) return "<strong>$i</strong>";
        return "<a href='$link'>" . ($label ?? $i) . "</a>";
    }

    // Previous button
    echo $page > 1 ? page_link($page - 1, $page, $userFilter, $searchTerm, 'Previous') : "<span class='disabled'>Previous</span>";

    // First page
    if ($start > 1) {
        echo page_link(1, $page, $userFilter, $searchTerm);
        if ($start > 2) echo "<span class='dots'>...</span>";
    }

    // Pages around current
    for ($i = $start; $i <= $end; $i++) {
        echo page_link($i, $page, $userFilter, $searchTerm);
    }

    // Last page
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) echo "<span class='dots'>...</span>";
        echo page_link($totalPages, $page, $userFilter, $searchTerm);
    }

    // Next button
    echo $page < $totalPages ? page_link($page + 1, $page, $userFilter, $searchTerm, 'Next') : "<span class='disabled'>Next</span>";
  ?>
  </div>
</div>

<script>
  // Mobile sidebar toggle
  const mobileToggle = document.getElementById('mobileMenuToggle');
  const sidenav = document.getElementById('manager-sidenav');
  mobileToggle.addEventListener('click', () => {
    sidenav.classList.toggle('active');
  });

  // Dark Mode Toggle
  const darkModeToggle = document.getElementById('darkModeToggle');
  const body = document.body;

  if(localStorage.getItem('darkMode') === 'enabled') {
      body.classList.add('dark-mode');
      darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
  }

  darkModeToggle.addEventListener('click', () => {
      body.classList.toggle('dark-mode');
      if(body.classList.contains('dark-mode')) {
          darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
          localStorage.setItem('darkMode', 'enabled');
      } else {
          darkModeToggle.innerHTML = '<i class="fas fa-moon"></i>';
          localStorage.setItem('darkMode', 'disabled');
      }
  });
</script>
</body>
</html>
