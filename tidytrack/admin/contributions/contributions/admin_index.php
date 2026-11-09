<?php
session_start();
require_once __DIR__ . '/../dp.php';

// --- AUTH GUARD ---
$userId = $_SESSION['user_id'] ?? null;
$role   = strtolower(trim($_SESSION['role'] ?? ''));
$allowed = ['admin'];
if (!$userId || !in_array($role, $allowed, true)) {
  http_response_code(403); echo "Forbidden"; exit;
}

// Display name (uses name if present, else email)
$displayName = 'Admin';
if ($stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'name'")) {
  $stmt->execute();
  $hasName = $stmt->get_result()->num_rows > 0;
  $stmt->close();

  $sql = $hasName
    ? "SELECT COALESCE(name, email) AS d FROM users WHERE user_id = ?"
    : "SELECT email AS d FROM users WHERE user_id = ?";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $displayName = ($stmt->get_result()->fetch_assoc()['d'] ?? 'Admin');
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en-ZA">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>INVESTHOOD_TSM • Admin</title>
  <base target="_self">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
  <style>
    :root{--bg:#f6f8fb;--card:#fff;--ink:#1d3557;--muted:#6b7b93;--accent:#2a9d8f;--danger:#e63946;--line:#e9eef5;--shadow:0 8px 24px rgba(0,0,0,.06);}
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--ink);font-family:Poppins,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
    .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
    .sidebar{position:sticky;top:0;height:100vh;padding:1rem;background:var(--card);border-right:1px solid var(--line)}
    .brand{display:flex;align-items:center;gap:.6rem;font-weight:700;font-size:1.1rem;padding:.5rem .75rem}
    .brand i{color:var(--accent)}
    .nav{margin-top:1rem;display:flex;flex-direction:column;gap:.25rem}
    .nav a{display:flex;align-items:center;gap:.7rem;padding:.6rem .75rem;text-decoration:none;color:var(--ink);border-radius:10px;border:1px solid transparent;cursor:pointer}
    .nav a:hover{background:rgba(42,157,143,.08);border-color:var(--line)}
    .nav a.active{background:rgba(42,157,143,.12);border-color:var(--accent)}
    .sidebar-footer{margin-top:auto;padding:.75rem .75rem 0;color:var(--muted);font-size:.9rem}
    .main{display:flex;flex-direction:column;min-width:0}
    .topbar{position:sticky;top:0;z-index:10;background:var(--card);display:flex;gap:1rem;align-items:center;justify-content:space-between;padding:.8rem 1rem;border-bottom:1px solid var(--line);box-shadow:var(--shadow)}
    .wrap{max-width:1280px;margin:1rem auto;padding:0 1rem}
    .hero{display:flex;gap:1rem;flex-wrap:wrap;align-items:center;justify-content:space-between;background:linear-gradient(125deg, rgba(42,157,143,.14), transparent 60%);border:1px solid var(--line);border-radius:16px;padding:1rem}
    .hero h1{font-size:1.35rem;margin:.1rem 0}
    .btn{border:1px solid var(--line);background:#fff;border-radius:10px;padding:.5rem .8rem;color:var(--ink);text-decoration:none;display:inline-flex;gap:.5rem;align-items:center}
    .btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}
    .filters{display:grid;grid-template-columns:repeat(12,1fr);gap:.5rem;background:#fff;border:1px solid var(--line);border-radius:16px;padding:.8rem 1rem;margin-top:1rem}
    .filters input,.filters select{width:100%;padding:.55rem .7rem;border:1px solid var(--line);border-radius:10px;font-family:inherit}
    .filters .actions{grid-column:span 12;display:flex;gap:.5rem;justify-content:flex-end}
    .viewport{margin-top:1rem}
  
.main{display:flex;flex-direction:column;min-width:0; padding:0;}
.viewport{margin:0;}
.viewport iframe{
  display:block;
  width:100%;
  border:0;
  border-radius:0;      /* no rounded corners */
  box-shadow:none;      /* no card shadow */
  background:transparent;
  height:100vh;         /* fallback height; JS below will auto-resize */
}

    @media(max-width:900px){.layout{grid-template-columns:1fr}.viewport iframe{height:70vh}}
  </style>
</head>
<body>
  <div class="layout">
    <aside class="sidebar">
      <div class="brand"><i class="fa-solid fa-shield-halved"></i> • Admin</div>
      <nav class="nav">
        <!-- Home == Dashboard -->
        <a href="admin_dasboard.php" data-view="dashboard" class="active" target="adminView">
          <i class="fa-solid fa-house"></i> Dashboard
        </a>
        <a href="admin_logs.php" data-view="logs" target="adminView">
          <i class="fa-solid fa-clipboard-list"></i> Monitoring Logs
        </a>
		<!-- Users Management -->
  <a href="admin_users.php" data-view="users" target="adminView">
    <i class="fa-solid fa-users"></i> Manage Users
  </a>

  <!-- Projects -->
  <a href="admin_projects.php" data-view="projects" target="adminView">
    <i class="fa-solid fa-folder-open"></i> Projects
  </a>
   
  <!-- contributions  -->
  <a href="admin_contributions.php" data-view="contributions" target="adminView">
    <i class="fa-solid fa-folder-open"></i> Contributions
  </a>

  

  <!-- Reports -->
  <a href="admin_reports.php" data-view="reports" target="adminView">
    <i class="fa-solid fa-chart-line"></i> Reports
  </a>

  <!-- Settings -->
  <a href="admin_settings.php" data-view="settings" target="adminView">
    <i class="fa-solid fa-gear"></i> Settings
  </a>
		
		
        <a href="../signup.html"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
      </nav>
      <div class="sidebar-footer">
        <div>Welcome, <?= htmlspecialchars($displayName) ?></div>
        <div><small>Africa/Johannesburg • <span id="clock">--:--:--</span></small></div>
      </div>
    </aside>

    <section class="main">
 

     

        <!-- Quick Filters load Logs into the viewport -->
    

        <!-- Viewport keeps sidebar + header; shows Dashboard or Logs -->
        <div class="viewport">
          <iframe id="adminView" name="adminView" src="admin_dashboard.php" title="Admin View"></iframe>
        </div>
      </div>
    </section>
  </div>

  <script>
    // Live SA clock
    const clock = document.getElementById('clock');
    function tick(){ clock.textContent = new Date().toLocaleTimeString('en-ZA', {hour12:false, timeZone:'Africa/Johannesburg'}); }
    setInterval(tick, 1000); tick();

    // Inner page polish (hide inner header to avoid double header)
    const frame = document.getElementById('adminView');
    frame.addEventListener('load', function(){
      try {
        const doc = frame.contentDocument;
        const innerHeader = doc.querySelector('header');
        if (innerHeader) innerHeader.style.display = 'none';
        doc.body.style.background = 'transparent';
      } catch(e) {}
    });

    // Keep active state in sidebar
    document.querySelectorAll('[data-view]').forEach(a=>{
      a.addEventListener('click', ()=>{
        document.querySelectorAll('.nav a').forEach(n=>n.classList.remove('active'));
        const match = document.querySelector(`.nav a[data-view="${a.dataset.view}"]`);
        if (match) match.classList.add('active');
      });
    });

    // Quick date buttons load Logs into the viewport
    const ymd = d => d.toISOString().slice(0,10);

    document.getElementById('todayBtn').addEventListener('click', e=>{
      e.preventDefault();
      const s = ymd(new Date());
      frame.src = `admin_logs.php?from=${encodeURIComponent(s)}&to=${encodeURIComponent(s)}`;
      setActive('logs');
    });

    document.getElementById('last7Btn').addEventListener('click', e=>{
      e.preventDefault();
      const to = new Date(); const from = new Date(); from.setDate(from.getDate()-6);
      frame.src = `admin_logs.php?from=${encodeURIComponent(ymd(from))}&to=${encodeURIComponent(ymd(to))}`;
      setActive('logs');
    });

    // Reset to Dashboard
    document.getElementById('resetBtn').addEventListener('click', e=>{
      e.preventDefault();
      document.getElementById('filterForm').reset();
      frame.src = 'admin_dashboard.php';
      setActive('dashboard');
    });

    function setActive(view){
      document.querySelectorAll('.nav a').forEach(n=>n.classList.remove('active'));
      const link = document.querySelector(`.nav a[data-view="${view}"]`);
      if (link) link.classList.add('active');
    }
  </script>
</body>
</html>
