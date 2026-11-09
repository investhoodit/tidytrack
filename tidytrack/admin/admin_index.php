<?php
// admin_index.php
session_start();

// Optional: include a shared profile helper if you have one.
if (file_exists(__DIR__ . '/../profile.php')) {
    include __DIR__ . '/../profile.php';
}

require_once __DIR__ . '/../dp.php';

// ========== SAFE FALLBACKS ==========
$profilePicture = $_SESSION['profile_picture'] ?? null;
$userInitial    = $_SESSION['user_initial'] ?? null;
$userName       = $_SESSION['name'] ?? null;
$currentRole    = $_SESSION['role'] ?? 'Admin';
$statusLabel    = $_SESSION['status_label'] ?? 'Online';
$statusCss      = $_SESSION['status_css'] ?? 'status-ready';

if (!$userName || trim($userName) === '') {
    $userName = 'Admin';
}
if (!$userInitial || trim($userInitial) === '') {
    $userInitial = strtoupper(mb_substr($userName, 0, 1));
}

// AUTH
$userId = $_SESSION['user_id'] ?? null;
$role   = strtolower(trim($_SESSION['role'] ?? ''));

$allowed = ['admin'];
if (!$userId || !in_array($role, $allowed, true)) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

// Display name
$displayName = $userName;
try {
    if ($stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'name'")) {
      $stmt->execute();
      $hasName = $stmt->get_result()->num_rows > 0;
      $stmt->close();

      $sql = $hasName
        ? "SELECT COALESCE(NULLIF(name,''), email) AS d, NULLIF(profile_picture,'') AS profile_picture, COALESCE(NULLIF(role,''), ?) AS role FROM users WHERE user_id = ? LIMIT 1"
        : "SELECT email AS d, NULLIF(profile_picture,'') AS profile_picture, COALESCE(NULLIF(role,''), ?) AS role FROM users WHERE user_id = ? LIMIT 1";

      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('si', $currentRole, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?? [];
        if (!empty($row['d'])) $displayName = $row['d'];
        if (!empty($row['profile_picture'])) $profilePicture = $row['profile_picture'];
        if (!empty($row['role'])) $currentRole = $row['role'];
        $stmt->close();
      }
    }
} catch (Exception $e) {
    error_log("admin_index.php - DB fetch error: " . $e->getMessage());
}

// Normalize profile picture path
$picPathForExists = null;
$profileSrc = null;
if ($profilePicture) {
    if (preg_match('#^https?://#i', $profilePicture)) {
        $profileSrc = $profilePicture;
    } else {
        if (is_file($profilePicture) && is_readable($profilePicture)) {
            $picPathForExists = $profilePicture;
            $profileSrc = $profilePicture;
        } else {
            $try = realpath(__DIR__ . '/../' . ltrim($profilePicture, '/\\'));
            if ($try && is_file($try) && is_readable($try)) {
                $picPathForExists = $try;
                $profileSrc = $profilePicture;
            } else {
                $try2 = realpath(__DIR__ . '/' . ltrim($profilePicture, '/\\'));
                if ($try2 && is_file($try2) && is_readable($try2)) {
                    $picPathForExists = $try2;
                    $profileSrc = $profilePicture;
                }
            }
        }
    }
}

/* ===============================
   DYNAMIC NOTIFICATIONS
================================ */
$notifications = [];
$notifCount = 0;

try {
    $tz = new DateTimeZone('Africa/Johannesburg');
    $yesterday = (new DateTime('now',$tz))->modify('-1 day')->format('Y-m-d H:i:s');
    
    // Recent user activities
    $st = $conn->prepare("
        SELECT u.name AS actor, a.activity_type, a.timestamp, a.details
        FROM user_activity a
        JOIN users u ON u.user_id = a.user_id
        WHERE a.timestamp >= ?
        ORDER BY a.timestamp DESC
        LIMIT 15
    ");
    if ($st) {
        $st->bind_param('s', $yesterday);
        $st->execute();
        $activities = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        foreach($activities as $a){
            $notifications[] = [
                'type' => 'activity',
                'actor' => htmlspecialchars($a['actor']),
                'action' => htmlspecialchars($a['activity_type']),
                'time' => $a['timestamp'],
                'details' => htmlspecialchars($a['details'] ?? 'No details'),
                'icon' => 'fa-user-check'
            ];
        }
    }
    
    // Recent tasks
    if ($stmt = $conn->prepare("SHOW TABLES LIKE 'tasks'")) {
        $stmt->execute();
        $hasTasks = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        
        if ($hasTasks) {
            $st = $conn->prepare("
                SELECT taskTitle, status, assignTo, created_at, description
                FROM tasks
                WHERE created_at >= ?
                ORDER BY created_at DESC
                LIMIT 15
            ");
            if ($st) {
                $st->bind_param('s', $yesterday);
                $st->execute();
                $tasksRecent = $st->get_result()->fetch_all(MYSQLI_ASSOC);
                $st->close();
                
                foreach($tasksRecent as $t){
                    $notifications[] = [
                        'type' => 'task',
                        'title' => htmlspecialchars($t['taskTitle']),
                        'status' => htmlspecialchars($t['status']),
                        'assigned' => htmlspecialchars($t['assignTo'] ?? 'Unassigned'),
                        'time' => $t['created_at'],
                        'details' => htmlspecialchars($t['description'] ?? 'No description'),
                        'icon' => 'fa-tasks'
                    ];
                }
            }
        }
    }
    
    // Recent reports if exists
    if ($stmt = $conn->prepare("SHOW TABLES LIKE 'reports'")) {
        $stmt->execute();
        $hasReports = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        
        if ($hasReports) {
            $st = $conn->prepare("
                SELECT report_title, submitted_by, submitted_at, status
                FROM reports
                WHERE submitted_at >= ?
                ORDER BY submitted_at DESC
                LIMIT 10
            ");
            if ($st) {
                $st->bind_param('s', $yesterday);
                $st->execute();
                $reports = $st->get_result()->fetch_all(MYSQLI_ASSOC);
                $st->close();
                
                foreach($reports as $r){
                    $notifications[] = [
                        'type' => 'report',
                        'title' => htmlspecialchars($r['report_title']),
                        'author' => htmlspecialchars($r['submitted_by']),
                        'time' => $r['submitted_at'],
                        'status' => htmlspecialchars($r['status']),
                        'icon' => 'fa-file-alt'
                    ];
                }
            }
        }
    }
    
    // Sort by time (most recent first)
    usort($notifications, function($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });
    
    // Limit to 20 notifications
    $notifications = array_slice($notifications, 0, 20);
    $notifCount = count($notifications);
} catch (Exception $e) {
    error_log("admin_index.php - Notifications error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en-ZA">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin</title>

  <base target="_self">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root{
      --nav-w: 320px;
      --nav-w-collapsed: 90px;
      --brand: #1d3557;
      --ink: #1f2937;
      --bg: #f8fafc;
      --line: #e6edf3;
      --card: #ffffff;
      --border: #e8e8ee;
      --text: #0f172a;
      --muted: #6b7280;
      --blue: #2563eb;
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      background:var(--bg);
      color:var(--text);
      font-family:Poppins,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      -webkit-font-smoothing:antialiased;
      transition: background .25s ease, color .25s ease;
    }

    /* ============================================
       DARK MODE
    ============================================= */
    .dark {
      --bg:#0b1220;
      --card:#0f172a;
      --border:rgba(255,255,255,.1);
      --text:#e5e7eb;
      --muted:#94a3b8;
      --brand:#a5d8ff;
      --line:#334155;
      --ink:#f1f5f9;
    }

    .dark body,
    .dark .admin-main,
    .dark .nav-list a,
    .dark .profile-popup,
    .dark .topbar-title strong,
    .dark .topbar-title small {
      color:var(--text) !important;
    }

    .dark #adminSidenav{
      background:var(--card);
      border-right:1px solid var(--border);
    }

    .dark .nav-list a{
      color:var(--muted) !important;
    }

    .dark .nav-list a:hover,
    .dark .nav-list a.active{
      background:#334155;
      color:#ffffff !important;
      font-weight:600;
    }

    .dark .profile-popup{
      background:var(--card);
      border:1px solid var(--border);
      color:var(--text) !important;
    }

    .dark .topbar {
      background:rgba(17,24,39,.75);
      border-bottom:1px solid var(--border);
    }

    .dark .icon-btn {
      background:var(--card);
      border-color:var(--border);
      color:var(--text);
    }

    /* NOTIFICATION MODAL */
    .notification-modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 9999;
      backdrop-filter: blur(5px);
    }

    .notification-modal.open {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .notification-modal-content {
      background: var(--card);
      border-radius: 16px;
      width: 90%;
      max-width: 600px;
      max-height: 80vh;
      overflow: hidden;
      border: 1px solid var(--border);
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      animation: modalSlideIn 0.3s ease-out;
    }

    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .notification-modal-header {
      padding: 20px 24px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .notification-modal-body {
      padding: 0;
      overflow-y: auto;
      max-height: calc(80vh - 120px);
    }

    .notification-item-modal {
      padding: 16px 24px;
      border-bottom: 1px solid var(--border);
      transition: background 0.2s ease;
      cursor: pointer;
    }

    .notification-item-modal:hover {
      background: rgba(37, 99, 235, 0.05);
    }

    .notification-item-modal:last-child {
      border-bottom: none;
    }

    .notification-item-modal.unread {
      background: rgba(37, 99, 235, 0.08);
    }

    .notification-item-modal.unread:hover {
      background: rgba(37, 99, 235, 0.12);
    }

    .notification-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: linear-gradient(135deg, var(--blue), #3b82f6);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 18px;
      margin-right: 16px;
      flex-shrink: 0;
    }

    .notification-content {
      flex: 1;
    }

    .notification-time {
      font-size: 12px;
      color: var(--muted);
      margin-top: 4px;
    }

    .notification-details {
      font-size: 13px;
      color: var(--muted);
      margin-top: 6px;
      line-height: 1.4;
    }

    .notification-status {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 600;
      margin-left: 8px;
    }

    .status-pending { background: #fef3c7; color: #92400e; }
    .status-completed { background: #d1fae5; color: #065f46; }
    .status-in-progress { background: #dbeafe; color: #1e40af; }

    .empty-notifications {
      text-align: center;
      padding: 60px 20px;
      color: var(--muted);
    }

    .empty-notifications i {
      font-size: 48px;
      margin-bottom: 16px;
      opacity: 0.5;
    }

    /* Dark mode for modal */
    .dark .notification-modal-content {
      background: var(--card);
      border-color: var(--border);
    }

    .dark .notification-item-modal:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .dark .notification-item-modal.unread {
      background: rgba(59, 130, 246, 0.15);
    }

    .dark .status-pending { background: #422006; color: #fbbf24; }
    .dark .status-completed { background: #064e3b; color: #34d399; }
    .dark .status-in-progress { background: #1e3a8a; color: #93c5fd; }

    /* TOPBAR */
    .topbar{
      position:fixed;
      top:0;
      left:var(--nav-w);
      right:0;
      height:72px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      padding:0 20px;
      background:rgba(255,255,255,.85);
      backdrop-filter:blur(10px);
      border-bottom:1px solid var(--border);
      z-index:10;
      transition: left .25s ease, background .25s ease, border-color .25s ease;
    }

    .topbar-title strong {
      font-size: 18px;
      font-weight: 700;
    }

    .topbar-title small {
      font-size: 13px;
      color: var(--muted);
    }

    .topbar-controls {
      display:flex;
      gap:12px;
      align-items:center;
      position:relative;
    }

    .icon-btn{
      width:44px;
      height:44px;
      border-radius:12px;
      border:1px solid var(--border);
      background:var(--card);
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      transition: all .25s ease;
    }

    .icon-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    /* Notifications */
    .notif-badge{
      position:absolute;
      top:6px;
      right:6px;
      background:#ef4444;
      color:#fff;
      font-size:11px;
      font-weight:700;
      padding:2px 6px;
      border-radius:999px;
    }

    .notif-panel{
      position:absolute;
      top:58px;
      right:0;
      width:280px;
      background:var(--card);
      border:1px solid var(--border);
      border-radius:14px;
      box-shadow:0 20px 40px rgba(0,0,0,.15);
      display:none;
      z-index:100;
      overflow:hidden;
    }

    .notif-panel.open{
      display:block;
    }

    .notif-head{
      padding:12px 14px;
      font-weight:800;
      border-bottom:1px solid var(--border);
    }

    .notif-item{
      padding:12px 14px;
      border-bottom:1px solid var(--border);
      font-size:14px;
      cursor:pointer;
    }

    .notif-item:hover{
      background:rgba(37,99,235,.08);
    }

    .notif-empty{
      padding:12px 14px;
      text-align:center;
      color:var(--muted);
      font-size:13px;
    }

    /* SIDEBAR AND LAYOUT */
    #adminSidenav{
      width:var(--nav-w);
      background:var(--card);
      border-right:1px solid var(--border);
      display:flex;
      flex-direction:column;
      padding:18px;
      position:fixed;
      height:100vh;
      overflow-y:auto;
      z-index:20;
      transition: background .25s ease, border-color .25s ease;
    }

    .admin-profile-box{
      display:flex;
      align-items:center;
      gap:12px;
      padding-bottom:14px;
      border-bottom:1px solid var(--border);
      position:relative;
    }

    .admin-profile-box .me{
      width:48px;
      height:48px;
      border-radius:50%;
      background:var(--brand);
      color:#fff;
      display:flex;
      align-items:center;
      justify-content:center;
      font-weight:700;
      position:relative;
      cursor:pointer;
    }

    .admin-profile-box .me img{
      width:100%;
      height:100%;
      object-fit:cover;
      border-radius:50%;
      display:block;
    }

    .admin-profile-box .status-indicator{
      position:absolute;
      bottom:0;
      right:0;
      width:12px;
      height:12px;
      border-radius:50%;
      border:2px solid #fff;
    }

    .profile-popup{
      display:none;
      position:absolute;
      top:72px;
      left:50%;
      transform:translateX(-50%);
      background:var(--card);
      padding:10px 12px;
      border-radius:8px;
      box-shadow:0 6px 18px rgba(0,0,0,0.08);
      text-align:center;
      z-index:1000;
      width:220px;
    }

    .admin-profile-box .me:hover .profile-popup{
      display:block;
    }

    .nav-list{
      list-style:none;
      padding:16px 0 0;
      margin:0;
      display:flex;
      flex-direction:column;
      gap:6px;
    }

    .nav-list a{
      display:flex;
      align-items:center;
      gap:12px;
      padding:10px 12px;
      color:var(--text);
      border-radius:8px;
      text-decoration:none;
      transition: background .25s ease, color .25s ease;
    }

    .nav-list a:hover,
    .nav-list a.active{
      background:rgba(37,99,235,.1);
      color:var(--blue) !important;
    }

    .admin-main{
      margin-left:var(--nav-w);
      padding-top:72px;
      min-height:100vh;
      transition: margin-left .25s ease;
    }

    .viewport iframe{
      width:100%;
      height:calc(100vh - 72px);
      border:0;
      transition: background .25s ease, color .25s ease;
    }

    .status-ready{ background:#00d4aa; box-shadow:0 0 12px rgba(0,212,170,.28); }
    .status-out_of_office{ background:#ffa726; box-shadow:0 0 12px rgba(255,167,38,.22); }
    .status-sick{ background:#e94560; box-shadow:0 0 12px rgba(233,69,96,.22); }
    .status-focusing{ background:#9c27b0; box-shadow:0 0 12px rgba(156,39,176,.18); }
    .status-in_meeting{ background:#2196f3; box-shadow:0 0 12px rgba(33,150,243,.18); }
    .status-offline{ background:#cbd5db; box-shadow:0 0 12px rgba(203,213,219,.18); }
    
    /* DARK MODE BUTTON */
    .darkmode-btn {
      padding: 8px 16px;
      background: linear-gradient(135deg, var(--brand), #274c77);
      color: #fff;
      border: none;
      border-radius: 25px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 3px 10px rgba(0,0,0,0.15);
      transition: all .25s ease;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .darkmode-btn:hover {
      background: linear-gradient(135deg, #274c77, var(--brand));
      transform: translateY(-2px);
      box-shadow: 0 5px 14px rgba(0,0,0,0.20);
    }

    .darkmode-btn:active {
      transform: scale(0.96);
    }

    .dark .darkmode-btn {
      background: linear-gradient(135deg, #334155, #1e293b);
      color: #f1f5f9;
    }

    .dark .darkmode-btn:hover {
      background: linear-gradient(135deg, #1e293b, #334155);
    }

    /* RESPONSIVE */
    @media(max-width:992px){
      #adminSidenav{
        transform:translateX(-100%);
        position:fixed;
        left:0;
        top:0;
        height:100vh;
        transition:transform .25s ease;
      }
      #adminSidenav.open{ transform:translateX(0); }
      .admin-main{
        margin-left:0;
        padding-top:72px;
      }
      .topbar{
        left:0;
      }
      .notification-modal-content {
        width: 95%;
        max-height: 90vh;
      }
    }
    /* Overlay when sidebar open */
.sidebar-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.4);
  z-index: 15;
  display: none;
}

.sidebar-overlay.show {
  display: block;
}

  </style>
</head>
<body>

  <!-- Notification Modal -->
  <div class="notification-modal" id="notificationModal">
    <div class="notification-modal-content">
      <div class="notification-modal-header">
        <h3 style="margin:0;font-size:18px;">Notifications</h3>
        <button type="button" class="icon-btn" id="closeModalBtn" style="width:36px;height:36px;">
          <i class="fa-solid fa-times"></i>
        </button>
      </div>
      <div class="notification-modal-body" id="notificationModalBody">
        <?php if($notifCount > 0): ?>
          <?php foreach($notifications as $index => $n): ?>
            <div class="notification-item-modal <?= $index < 3 ? 'unread' : '' ?>" data-notification-type="<?= $n['type'] ?>">
              <div style="display:flex;align-items:flex-start;">
                <div class="notification-icon">
                  <i class="fas <?= $n['icon'] ?>"></i>
                </div>
                <div class="notification-content">
                  <div style="font-weight:600;font-size:14px;">
                    <?php if($n['type'] == 'activity'): ?>
                      <?= $n['actor'] ?> performed <?= $n['action'] ?>
                    <?php elseif($n['type'] == 'task'): ?>
                      Task "<?= $n['title'] ?>"
                      <span class="notification-status status-<?= strtolower(str_replace(' ', '-', $n['status'])) ?>">
                        <?= $n['status'] ?>
                      </span>
                    <?php elseif($n['type'] == 'report'): ?>
                      Report "<?= $n['title'] ?>" submitted
                    <?php endif; ?>
                  </div>
                  <?php if(isset($n['details'])): ?>
                    <div class="notification-details">
                      <?= $n['details'] ?>
                    </div>
                  <?php endif; ?>
                  <div class="notification-time">
                    <i class="far fa-clock"></i> <?= date('M j, g:i A', strtotime($n['time'])) ?>
                    <?php if(isset($n['assigned'])): ?>
                      • Assigned to: <?= $n['assigned'] ?>
                    <?php endif; ?>
                    <?php if(isset($n['author'])): ?>
                      • By: <?= $n['author'] ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-notifications">
            <i class="far fa-bell"></i>
            <h4 style="margin:10px 0 5px;">No notifications</h4>
            <p style="font-size:14px;color:var(--muted);">You're all caught up!</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="admin-layout">
    <aside id="adminSidenav" class="admin-sidenav">

      <div class="admin-profile-box">
        <div class="me" aria-hidden="true">
          <?php if (!empty($profileSrc)): ?>
            <img src="<?php echo htmlspecialchars($profileSrc, ENT_QUOTES); ?>" alt="Profile">
          <?php else: ?>
            <span style="font-size:18px;"><?php echo htmlspecialchars($userInitial, ENT_QUOTES); ?></span>
          <?php endif; ?>

          <div class="status-indicator <?php echo htmlspecialchars($statusCss, ENT_QUOTES); ?>"></div>

          <div class="profile-popup">
            <div class="popup-role"><?php echo htmlspecialchars($currentRole, ENT_QUOTES); ?></div>
            <div style="margin-top:6px;font-size:13px;color:var(--muted);"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?></div>
          </div>
        </div>

        <div>
          <div style="font-weight:700;"><?php echo htmlspecialchars($displayName, ENT_QUOTES); ?></div>
          <div style="font-size:12px;color:var(--muted);"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?> • <?php echo htmlspecialchars($currentRole, ENT_QUOTES); ?></div>
        </div>
      </div>

      <!-- DARK MODE BUTTON -->
      <div style="margin-top:15px; margin-bottom:10px; display:flex; align-items:center; justify-content:space-between;">
        <span style="font-size:14px; font-weight:600;"></span>
        <button id="darkModeBtn" class="darkmode-btn">
          <i class="fa-solid fa-moon"></i> Dark Mode
        </button>
      </div>

      <nav class="nav-list">
        <a href="admin_dasboard.php" data-view="dashboard" class="active" target="adminView">
          <i class="fa-solid fa-house"></i> <span>Dashboard</span>
        </a>

        <a href="admin_logs.php" data-view="logs" target="adminView">
          <i class="fa-solid fa-clipboard-list"></i> <span>Monitoring Logs</span>
        </a>

        <a href="https://investhoodprojects.com/tidytracker/tidytrack/meeting/member_meetings.php">
          <i class="fas fa-video"></i> <span>Meetings</span>
        </a>
        
      <a href="admin_attendaceM.php" class="sidebar-link">
    <i class="fas fa-users"></i> <span>Meeting Attendance</span>
</a>
        <a href="admin_users.php" data-view="users" target="adminView">
          <i class="fa-solid fa-users"></i> <span>Manage Users</span>
        </a>
        
        <a href="admin_daily_reports.php" data-view="daily-reports" target="adminView">
          <i class="fa-solid fa-file-invoice"></i> <span>Daily Reports</span>
        </a>
<a href="defect_tracker.php" class="nav-link">
        <i class="fa-solid fa-bug"></i>
        <span>Defects</span>
    </a>
        <a href="Project_viewing.php">
          <i class="fa-solid fa-folder-open"></i> <span>Projects</span>
        </a>

        <a href="admin_contributions.php" data-view="contrib" target="adminView">
          <i class="fa-solid fa-chart-line"></i> <span>Contributions</span>
        </a>

        <a href="admin_leave_form.php" data-view="leave-form" target="adminView">
          <i class="fas fa-clipboard-list"></i> <span>Leave Form</span>
        </a>

        <a href="admin_leave_requests.php" data-view="leave-requests" target="adminView">
          <i class="fas fa-calendar-check"></i> <span>Leave Requests</span>
        </a>

    <!--  <a href="admin_weekly_report.php" data-view="reports" target="adminView">
          <i class="fa-solid fa-file-lines"></i> <span>Weekly Project Reports</span>
        </a> -->

    <li class="nav-item">
  <a href="upload_timesheet_progress.php" class="nav-link">
    <i class="fas fa-file-signature"></i>
    <span>Timesheet Submissions</span>
  </a>
</li>


 <li style="margin-top:auto"><a href="profile.php"><i class="fas fa-user-circle"></i> Profile Settings</a></li>
        <a href="../logout.php">
          <i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span>
        </a>
      </nav>
    </aside>
    <div id="sidebarOverlay" class="sidebar-overlay"></div>
    
    <header class="topbar">
  
  <!-- LEFT SIDE -->
  <div style="display:flex;align-items:center;gap:12px;">
    
    <!-- HAMBURGER BUTTON -->
    <button id="menuToggle" class="icon-btn d-lg-none">
      <i class="fa-solid fa-bars"></i>
    </button>

    <div class="topbar-title">
      <strong>Admin Panel</strong>
      <small>Welcome back</small>
    </div>
  </div>

  <!-- RIGHT SIDE -->
  <div class="topbar-controls">
    <button id="notifBtn" class="icon-btn">
      <i class="fa-solid fa-bell"></i>
      <?php if($notifCount > 0): ?>
        <span class="notif-badge"><?= $notifCount ?></span>
      <?php endif; ?>
    </button>
  </div>

</header>

    <main class="admin-main" id="mainContent">
      <div class="viewport">
        <iframe id="adminView" name="adminView" src="admin_dasboard.php" title="Admin View"></iframe>
      </div>
    </main>
  </div>
  

<script>
// ============================
// NOTIFICATION MODAL
// ============================
document.addEventListener("DOMContentLoaded", () => {
  const notifBtn = document.getElementById("notifBtn");
  const notificationModal = document.getElementById("notificationModal");
  const closeModalBtn = document.getElementById("closeModalBtn");
  const notificationModalBody = document.getElementById("notificationModalBody");
  
  // Open modal
  notifBtn.onclick = (e) => {
    e.stopPropagation();
    notificationModal.classList.add("open");
    document.body.style.overflow = "hidden"; // Prevent scrolling
  };
  
  // Close modal
  const closeModal = () => {
    notificationModal.classList.remove("open");
    document.body.style.overflow = ""; // Restore scrolling
  };
  
  closeModalBtn.onclick = closeModal;
  
  // Close modal when clicking outside
  notificationModal.onclick = (e) => {
    if (e.target === notificationModal) {
      closeModal();
    }
  };
  
  // Close modal with Escape key
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && notificationModal.classList.contains("open")) {
      closeModal();
    }
  });
  
  // Mark notifications as read when clicked
  notificationModalBody.addEventListener("click", (e) => {
    const notificationItem = e.target.closest(".notification-item-modal");
    if (notificationItem) {
      // Remove unread class
      notificationItem.classList.remove("unread");
      
      // Optionally update badge count
      const unreadCount = document.querySelectorAll(".notification-item-modal.unread").length;
      const badge = document.querySelector(".notif-badge");
      if (badge) {
        if (unreadCount > 0) {
          badge.textContent = unreadCount;
        } else {
          badge.remove();
        }
      }
      
      // Optional: Mark as read in database via AJAX
      const notificationType = notificationItem.dataset.notificationType;
      // You could add AJAX call here to mark notification as read in database
    }
  });
  
  // Refresh notifications periodically (every 60 seconds)
  setInterval(() => {
    fetchNotifications();
  }, 60000);
  
  // Function to fetch new notifications via AJAX
  async function fetchNotifications() {
    try {
      const response = await fetch('?get_notifications=1&_=' + Date.now());
      const data = await response.json();
      
      if (data.count > 0) {
        // Update badge
        const badge = document.querySelector(".notif-badge") || createBadge();
        badge.textContent = data.count;
        
        // Update modal content if open
        if (notificationModal.classList.contains("open")) {
          renderNotifications(data.notifications);
        }
      }
    } catch (error) {
      console.error("Error fetching notifications:", error);
    }
  }
  
  function createBadge() {
    const notifBtn = document.getElementById("notifBtn");
    const badge = document.createElement("span");
    badge.className = "notif-badge";
    notifBtn.appendChild(badge);
    return badge;
  }
  
  function renderNotifications(notifications) {
    if (notifications.length === 0) {
      notificationModalBody.innerHTML = `
        <div class="empty-notifications">
          <i class="far fa-bell"></i>
          <h4 style="margin:10px 0 5px;">No notifications</h4>
          <p style="font-size:14px;color:var(--muted);">You're all caught up!</p>
        </div>
      `;
      return;
    }
    
    let html = '';
    notifications.forEach((n, index) => {
      html += `
        <div class="notification-item-modal ${index < 3 ? 'unread' : ''}" data-notification-type="${n.type}">
          <div style="display:flex;align-items:flex-start;">
            <div class="notification-icon">
              <i class="fas ${n.icon}"></i>
            </div>
            <div class="notification-content">
              <div style="font-weight:600;font-size:14px;">
                ${n.type === 'activity' ? `${n.actor} performed ${n.action}` : 
                  n.type === 'task' ? `Task "${n.title}" 
                    <span class="notification-status status-${n.status.toLowerCase().replace(' ', '-')}">
                      ${n.status}
                    </span>` :
                  n.type === 'report' ? `Report "${n.title}" submitted` : ''}
              </div>
              ${n.details ? `<div class="notification-details">${n.details}</div>` : ''}
              <div class="notification-time">
                <i class="far fa-clock"></i> ${formatTime(n.time)}
                ${n.assigned ? `• Assigned to: ${n.assigned}` : ''}
                ${n.author ? `• By: ${n.author}` : ''}
              </div>
            </div>
          </div>
        </div>
      `;
    });
    
    notificationModalBody.innerHTML = html;
  }
  
  function formatTime(timeStr) {
    const date = new Date(timeStr);
    return date.toLocaleDateString('en-US', { 
      month: 'short', 
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true 
    });
  }
});

// ============================
// MOBILE SIDEBAR
// ============================
(function(){
  const sidenav = document.getElementById('adminSidenav');
  const topbar = document.querySelector('.topbar');

  function closeIfMobile() {
    if (window.innerWidth <= 992 && sidenav.classList.contains('open')) {
      sidenav.classList.remove('open');
    }
  }

  function updateTopbarPosition() {
    if (window.innerWidth <= 992 && sidenav.classList.contains('open')) {
      topbar.style.left = 'var(--nav-w)';
    } else {
      topbar.style.left = window.innerWidth <= 992 ? '0' : 'var(--nav-w)';
    }
  }

  window.addEventListener('resize', updateTopbarPosition);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeIfMobile();
  });

  document.getElementById('mainContent').addEventListener('click', closeIfMobile);
})();

// ============================
// NAV LINKS INSIDE IFRAME
// ============================
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('#adminSidenav a[data-view]').forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      document.querySelectorAll('#adminSidenav a').forEach(a => a.classList.remove('active'));
      link.classList.add('active');
      document.getElementById('adminView').src = link.getAttribute('href');
    });
  });
});

// ============================
// DARK MODE TOGGLE
// ============================
// ============================
// DARK MODE TOGGLE with IFRAME SUPPORT
// ============================
document.addEventListener("DOMContentLoaded", () => {
    const darkModeBtn = document.getElementById('darkModeBtn');
    const btnDark = document.getElementById('btnDark');
    const body = document.body;
    const DK = 'admin_dark_mode';

    // Function to apply dark mode to iframes
    function applyDarkModeToIframes(isDark) {
        // Apply to main iframe
        const iframe = document.getElementById('adminView');
        if (iframe && iframe.contentWindow) {
            try {
                iframe.contentWindow.postMessage({
                    type: 'darkModeUpdate',
                    isDark: isDark
                }, '*');
                
                // Also try to apply directly
                setTimeout(() => {
                    const iframeDoc = iframe.contentDocument;
                    if (iframeDoc) {
                        const iframeBody = iframeDoc.body;
                        const iframeHtml = iframeDoc.documentElement;
                        
                        if (isDark) {
                            iframeBody.classList.add('dark');
                            iframeHtml.classList.add('dark');
                        } else {
                            iframeBody.classList.remove('dark');
                            iframeHtml.classList.remove('dark');
                        }
                    }
                }, 100);
            } catch (e) {
                console.log('Could not apply dark mode to iframe:', e.message);
            }
        }
        
        // Apply to all other potential iframes on the page
        document.querySelectorAll('iframe').forEach(frame => {
            if (frame !== iframe && frame.contentWindow) {
                try {
                    frame.contentWindow.postMessage({
                        type: 'darkModeUpdate',
                        isDark: isDark
                    }, '*');
                } catch (e) {
                    // Silent fail for cross-origin iframes
                }
            }
        });
    }

    // Load saved preference and apply to iframes
    function loadDarkMode() {
        const isDark = localStorage.getItem(DK) === 'enabled';
        
        if (isDark) {
            body.classList.add('dark');
            darkModeBtn.innerHTML = '<i class="fa-solid fa-sun"></i> Light Mode';
        } else {
            body.classList.remove('dark');
            darkModeBtn.innerHTML = '<i class="fa-solid fa-moon"></i> Dark Mode';
        }
        
        // Apply to iframes
        applyDarkModeToIframes(isDark);
    }

    // Initialize
    loadDarkMode();

    // Dark mode toggle from sidebar button
    darkModeBtn.addEventListener('click', () => {
        const isDark = !body.classList.contains('dark');
        
        if (isDark) {
            body.classList.add('dark');
            localStorage.setItem(DK, 'enabled');
            darkModeBtn.innerHTML = '<i class="fa-solid fa-sun"></i> Light Mode';
        } else {
            body.classList.remove('dark');
            localStorage.setItem(DK, 'disabled');
            darkModeBtn.innerHTML = '<i class="fa-solid fa-moon"></i> Dark Mode';
        }
        
        applyDarkModeToIframes(isDark);
    });

    // Dark mode toggle from topbar button
    if (btnDark) {
        btnDark.addEventListener('click', () => {
            const isDark = !body.classList.contains('dark');
            
            if (isDark) {
                body.classList.add('dark');
                localStorage.setItem(DK, 'enabled');
                darkModeBtn.innerHTML = '<i class="fa-solid fa-sun"></i> Light Mode';
            } else {
                body.classList.remove('dark');
                localStorage.setItem(DK, 'disabled');
                darkModeBtn.innerHTML = '<i class="fa-solid fa-moon"></i> Dark Mode';
            }
            
            applyDarkModeToIframes(isDark);
        });
    }

    // Re-apply dark mode when iframe loads
    const iframe = document.getElementById('adminView');
    if (iframe) {
        iframe.addEventListener('load', () => {
            setTimeout(() => {
                const isDark = body.classList.contains('dark');
                applyDarkModeToIframes(isDark);
            }, 300); // Wait a bit for iframe to fully load
        });
    }

    // Apply dark mode when navigating to new pages in iframe
    document.querySelectorAll('#adminSidenav a[data-view]').forEach(link => {
        link.addEventListener('click', () => {
            setTimeout(() => {
                const isDark = body.classList.contains('dark');
                applyDarkModeToIframes(isDark);
            }, 500);
        });
    });
});
// ============================
// MOBILE SIDEBAR WITH OVERLAY
// ============================
document.addEventListener("DOMContentLoaded", () => {

  const menuToggle = document.getElementById("menuToggle");
  const sidenav = document.getElementById("adminSidenav");

  // Create overlay dynamically (since you didn't add it in HTML)
  const overlay = document.createElement("div");
  overlay.className = "sidebar-overlay";
  document.body.appendChild(overlay);

  if (menuToggle) {
    menuToggle.addEventListener("click", () => {
      sidenav.classList.toggle("open");
      overlay.classList.toggle("show");
    });
  }

  overlay.addEventListener("click", () => {
    sidenav.classList.remove("open");
    overlay.classList.remove("show");
  });

});

</script>

</body>
</html>