<?php
session_start();
require_once __DIR__ . '/../dp.php';

/* ===============================
   AUTH
================================ */
$userId = $_SESSION['user_id'] ?? null;
$role   = strtolower(trim($_SESSION['role'] ?? ''));
if (!$userId || $role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

/* ===============================
   AJAX HANDLERS
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    // Mark single notification as read
    if ($action === 'mark_read' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
        $stmt->bind_param('ii', $id, $userId);
        $success = $stmt->execute();
        echo json_encode(['success' => $success]);
        exit;
    }
    
    // Mark all notifications as read
    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
        $stmt->bind_param('i', $userId);
        $success = $stmt->execute();
        echo json_encode(['success' => $success, 'affected' => $stmt->affected_rows]);
        exit;
    }
    
    // Send reminder to inactive user
    if ($action === 'send_reminder' && isset($_POST['user_id'])) {
        $targetUserId = (int)$_POST['user_id'];
        
        // Check if user exists and is inactive (no activity in last 30 days)
        $fromTs = date('Y-m-d H:i:s', strtotime('-29 days'));
        $toTs = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM user_activity WHERE user_id = ? AND timestamp BETWEEN ? AND ?");
        $stmt->bind_param('iss', $targetUserId, $fromTs, $toTs);
        $stmt->execute();
        $result = $stmt->get_result();
        $activityCount = $result->fetch_assoc()['cnt'];
        $stmt->close();
        
        if ($activityCount > 0) {
            echo json_encode(['success' => false, 'error' => 'User is active, cannot send reminder']);
            exit;
        }
        
        // Insert notification for the user
        $message = "Admin reminder: Please log in and perform your pending tasks. Your account has been inactive for 30 days.";
        $type = 'reminder';
        
        if (tableExists($conn, 'notifications')) {
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, type, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
            $stmt->bind_param('iss', $targetUserId, $message, $type);
            $notifSuccess = $stmt->execute();
            $stmt->close();
        } else {
            $notifSuccess = false;
        }
        
        // Log admin action in user_activity
        $activityType = 'sent_reminder';
        $stmt = $conn->prepare("INSERT INTO user_activity (user_id, activity_type, timestamp) VALUES (?, ?, NOW())");
        $stmt->bind_param('is', $userId, $activityType);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode(['success' => true, 'notification_stored' => $notifSuccess]);
        exit;
    }
    
    // Send custom message to any user
    if ($action === 'send_message' && isset($_POST['user_id']) && isset($_POST['message'])) {
        $targetUserId = (int)$_POST['user_id'];
        $message = trim($_POST['message']);
        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
            exit;
        }
        
        if (tableExists($conn, 'notifications')) {
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, type, is_read, created_at) VALUES (?, ?, 'message', 0, NOW())");
            $stmt->bind_param('is', $targetUserId, $message);
            $notifSuccess = $stmt->execute();
            $stmt->close();
        } else {
            $notifSuccess = false;
        }
        
        // Log admin action
        $activityType = 'sent_message';
        $stmt = $conn->prepare("INSERT INTO user_activity (user_id, activity_type, timestamp) VALUES (?, ?, NOW())");
        $stmt->bind_param('is', $userId, $activityType);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode(['success' => true, 'notification_stored' => $notifSuccess]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

/* ===============================
   HELPERS
================================ */
function tableExists(mysqli $c, string $t): bool {
    $r = $c->query("SHOW TABLES LIKE '".$c->real_escape_string($t)."'");
    $ok = $r && $r->num_rows > 0;
    if ($r) $r->free();
    return $ok;
}
function colExists(mysqli $c, string $t, string $col): bool {
    $r = $c->query("SHOW COLUMNS FROM `$t` LIKE '".$c->real_escape_string($col)."'");
    $ok = $r && $r->num_rows > 0;
    if ($r) $r->free();
    return $ok;
}
function esc($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function n($x){ return number_format((int)$x); }

/* ===============================
   USERS PK DETECT
================================ */
$usersPk = 'user_id';
foreach(['user_id','userID','id'] as $c){
    if(colExists($conn,'users',$c)){ $usersPk=$c; break; }
}
$nameExpr = colExists($conn,'users','name') ? "COALESCE(u.name,u.email)" : "u.email";

/* ===============================
   DATE RANGE
================================ */
$tz = new DateTimeZone('Africa/Johannesburg');
$to = new DateTime('today',$tz);
$from = (clone $to)->modify('-29 days');
$fromTs = $from->format('Y-m-d').' 00:00:00';
$toTs   = $to->format('Y-m-d').' 23:59:59';

/* ===============================
   DATA
================================ */

/* Users by role */
$roles = ['admin'=>0,'manager'=>0,'leader'=>0,'member'=>0];
$q = $conn->query("SELECT role,COUNT(*) c FROM users GROUP BY role");
while($r=$q->fetch_assoc()){
    $roles[strtolower($r['role'])]=(int)$r['c'];
}
$totalUsers = array_sum($roles);

/* Activity trend */
$days=[]; $counts=[]; $eventsTotal=0;
$st = $conn->prepare("
  SELECT DATE(timestamp)d,COUNT(*)c
  FROM user_activity
  WHERE timestamp BETWEEN ? AND ?
  GROUP BY d ORDER BY d
");
$st->bind_param('ss',$fromTs,$toTs);
$st->execute();
$rs=$st->get_result();
while($r=$rs->fetch_assoc()){
    $days[]=$r['d'];
    $counts[]=(int)$r['c'];
    $eventsTotal+=(int)$r['c'];
}
$st->close();

/* Active users (users with activity in last 30 days) */
$st=$conn->prepare("
  SELECT COUNT(DISTINCT user_id) au
  FROM user_activity
  WHERE timestamp BETWEEN ? AND ?
");
$st->bind_param('ss',$fromTs,$toTs);
$st->execute();
$activeUsers=(int)$st->get_result()->fetch_assoc()['au'];
$st->close();

/* Inactive users (users with NO activity in last 30 days) */
$allUserIds = [];
$q = $conn->query("SELECT $usersPk FROM users");
while($row = $q->fetch_assoc()) {
    $allUserIds[] = $row[$usersPk];
}

$activeUserIds = [];
$st = $conn->prepare("
  SELECT DISTINCT user_id 
  FROM user_activity 
  WHERE timestamp BETWEEN ? AND ?
");
$st->bind_param('ss',$fromTs,$toTs);
$st->execute();
$result = $st->get_result();
while($row = $result->fetch_assoc()) {
    $activeUserIds[] = $row['user_id'];
}
$st->close();

$inactiveUsers = array_diff($allUserIds, $activeUserIds);
$inactiveCount = count($inactiveUsers);

$inactiveUserDetails = [];
if($inactiveCount > 0) {
    $inactiveIdsStr = implode(',', array_map('intval', $inactiveUsers));
    
    $st = $conn->prepare("
      SELECT u.$usersPk as user_id, $nameExpr as actor, u.role
      FROM users u
      WHERE u.$usersPk IN ($inactiveIdsStr)
      ORDER BY u.role, $nameExpr
      LIMIT 100
    ");
    $st->execute();
    $inactiveUserDetails = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

/* Top actors with user IDs */
$topActors=[];
$st=$conn->prepare("
  SELECT u.$usersPk as user_id, $nameExpr actor,u.role,COUNT(*) cnt
  FROM user_activity a
  JOIN users u ON u.$usersPk=a.user_id
  WHERE a.timestamp BETWEEN ? AND ?
  GROUP BY a.user_id
  ORDER BY cnt DESC
  LIMIT 8
");
$st->bind_param('ss',$fromTs,$toTs);
$st->execute();
$topActors=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* Activity types */
$types=[];
$st=$conn->prepare("
  SELECT activity_type,COUNT(*) c
  FROM user_activity
  WHERE timestamp BETWEEN ? AND ?
  GROUP BY activity_type
  ORDER BY c DESC
  LIMIT 8
");
$st->bind_param('ss',$fromTs,$toTs);
$st->execute();
$types=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* Tasks by status */
$taskStatus=[];
if(tableExists($conn,'tasks')){
    $q=$conn->query("SELECT status,COUNT(*) c FROM tasks GROUP BY status");
    while($r=$q->fetch_assoc()) $taskStatus[$r['status']]=$r['c'];
}

/* Fetch notifications from DB */
$notifications = [];
if(tableExists($conn,'notifications')){
    $st=$conn->prepare("
      SELECT id, message, type, is_read, created_at 
      FROM notifications 
      WHERE user_id = ? OR user_id IS NULL
      ORDER BY created_at DESC
      LIMIT 20
    ");
    $st->bind_param('i',$userId);
    $st->execute();
    $notifications=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// Fallback: generate from recent activity if no notifications table
if(empty($notifications)){
    $st=$conn->prepare("
      SELECT a.id, $nameExpr as actor, a.activity_type, a.timestamp
      FROM user_activity a
      JOIN users u ON u.$usersPk=a.user_id
      WHERE a.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
      ORDER BY a.timestamp DESC
      LIMIT 10
    ");
    $st->execute();
    $recentActivity=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    
    foreach($recentActivity as $act){
        $notifications[] = [
            'id' => $act['id'],
            'title' => ucfirst(str_replace('_',' ',$act['activity_type'])),
            'message' => $act['actor'] . ' performed ' . $act['activity_type'],
            'type' => 'activity',
            'is_read' => 0,
            'created_at' => $act['timestamp']
        ];
    }
}

$unreadCount = 0;
foreach($notifications as $n){
    if(!$n['is_read']) $unreadCount++;
}

/* Merge all users for enhanced frontend (top actors + inactive users) */
$allCombined = array_merge($topActors, $inactiveUserDetails);
foreach($allCombined as &$u){ 
    if(!isset($u['cnt'])) $u['cnt'] = 0; 
}

/* ===============================
   JS EXPORT
================================ */
$js_days=json_encode($days);
$js_counts=json_encode($counts);
$js_roles_lbl=json_encode(array_map('ucfirst',array_keys($roles)));
$js_roles_val=json_encode(array_values($roles));
$js_types_lbl=json_encode(array_column($types,'activity_type'));
$js_types_val=json_encode(array_column($types,'c'));
$js_notifications=json_encode($notifications);
$js_combined_users = json_encode($allCombined);
$js_inactive_users = json_encode($inactiveUserDetails);
$current_time = date('Y-m-d H:i:s');
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard | System Analytics</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --bg: #f8fafc;
    --card: #ffffff;
    --border: #e2e8f0;
    --text: #0f172a;
    --text-secondary: #475569;
    --muted: #64748b;
    --blue: #2563eb;
    --blue-hover: #1d4ed8;
    --blue-soft: rgba(37,99,235,0.1);
    --green: #10b981;
    --green-soft: rgba(16,185,129,0.1);
    --red: #ef4444;
    --red-soft: rgba(239,68,68,0.1);
    --purple: #8b5cf6;
    --purple-soft: rgba(139,92,246,0.1);
    --amber: #f59e0b;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
    --radius: 1rem;
}

body {
    font-family: 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
}

/* Custom scrollbar */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: var(--border); border-radius: 10px; }
::-webkit-scrollbar-thumb { background: var(--muted); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: var(--blue); }

/* Layout */
.container {
    padding: 88px 24px 32px;
    max-width: 1440px;
    margin: 0 auto;
}

/* Topbar */
.topbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 68px;
    background: var(--card);
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 24px;
    z-index: 50;
    box-shadow: var(--shadow-sm);
}

.logo-area h2 {
    font-size: 1.35rem;
    font-weight: 700;
    background: linear-gradient(135deg, var(--blue), var(--purple));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
.last-updated {
    font-size: 0.7rem;
    color: var(--muted);
    margin-left: 12px;
}

.topbar-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.icon-btn {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg);
    border: 1px solid var(--border);
    cursor: pointer;
    transition: all 0.2s;
    color: var(--text-secondary);
}
.icon-btn:hover {
    background: var(--blue);
    border-color: var(--blue);
    color: white;
    transform: translateY(-2px);
}

/* Notification Dropdown */
.notification-container {
    position: relative;
}
.notification-dropdown {
    position: absolute;
    top: 52px;
    right: 0;
    width: 380px;
    max-height: 460px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: var(--shadow-lg);
    display: none;
    overflow: hidden;
    z-index: 200;
    animation: fadeSlide 0.2s ease;
}
.notification-dropdown.show { display: block; }
@keyframes fadeSlide {
    from { opacity: 0; transform: translateY(-12px); }
    to { opacity: 1; transform: translateY(0); }
}
.notif-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.notif-list { max-height: 380px; overflow-y: auto; }
.notif-item {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 14px;
    cursor: pointer;
    transition: background 0.2s;
}
.notif-item.unread { background: var(--blue-soft); }
.notif-item:hover { background: var(--border); }
.notif-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.notif-icon.activity { background: var(--blue-soft); color: var(--blue); }
.notif-icon.alert { background: var(--red-soft); color: var(--red); }
.notif-icon.success { background: var(--green-soft); color: var(--green); }

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
    margin-bottom: 1.5rem;
}
.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.25rem;
    transition: all 0.25s;
    cursor: pointer;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-lg);
    border-color: var(--blue);
}
.stat-header {
    display: flex;
    align-items: center;
    gap: 12px;
}
.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}
.stat-icon.users { background: var(--blue-soft); color: var(--blue); }
.stat-icon.events { background: var(--green-soft); color: var(--green); }
.stat-icon.active { background: var(--purple-soft); color: var(--purple); }
.stat-icon.roles { background: var(--amber); color: white; background: rgba(245,158,11,0.15); color: var(--amber); }
.stat-value {
    font-size: 1.9rem;
    font-weight: 800;
    line-height: 1.2;
}
.stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
    color: var(--muted);
}
.stat-details {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
    display: none;
}
.stat-details.show { display: block; animation: fade 0.2s; }
@keyframes fade { from { opacity: 0; } to { opacity: 1; } }

/* Charts grid */
.charts-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.25rem;
    margin-bottom: 1.5rem;
}
.chart-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem;
}
.chart-card h3 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 0.75rem;
}
canvas { max-height: 260px; width: 100%; }

/* Table section */
.user-section {
    background: var(--card);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
}
.section-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.search-sort {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}
.search-input {
    padding: 8px 14px;
    border-radius: 40px;
    border: 1px solid var(--border);
    background: var(--bg);
    color: var(--text);
    width: 240px;
    font-size: 0.85rem;
}
.filter-chip {
    padding: 6px 14px;
    border-radius: 40px;
    background: var(--bg);
    border: 1px solid var(--border);
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s;
}
.filter-chip.active {
    background: var(--blue);
    border-color: var(--blue);
    color: white;
}
.table-wrapper {
    overflow-x: auto;
}
.user-table {
    width: 100%;
    border-collapse: collapse;
}
.user-table th {
    text-align: left;
    padding: 1rem 1rem;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    user-select: none;
}
.user-table td {
    padding: 0.9rem 1rem;
    border-bottom: 1px solid var(--border);
    color: var(--text-secondary);
}
.user-row {
    transition: background 0.2s;
    cursor: pointer;
}
.user-row:hover { background: var(--blue-soft); }
.role-badge {
    padding: 4px 10px;
    border-radius: 30px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}
.role-badge.admin { background: var(--red-soft); color: var(--red); }
.role-badge.manager { background: var(--blue-soft); color: var(--blue); }
.role-badge.leader { background: var(--purple-soft); color: var(--purple); }
.role-badge.member { background: var(--green-soft); color: var(--green); }
.action-btns {
    display: flex;
    gap: 6px;
}
.action-icon {
    padding: 6px;
    border-radius: 8px;
    background: transparent;
    border: none;
    cursor: pointer;
    color: var(--muted);
    transition: all 0.2s;
}
.action-icon:hover { background: var(--border); color: var(--blue); }

/* Modal */
.modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}
.modal.show { display: flex; }
.modal-content {
    background: var(--card);
    border-radius: 28px;
    width: 90%;
    max-width: 550px;
    max-height: 85vh;
    overflow-y: auto;
    padding: 1.5rem;
    animation: slideUp 0.25s;
}
@keyframes slideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 1rem;
}
/* Toast */
.toast-container {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 1100;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.toast {
    background: var(--card);
    border-left: 4px solid var(--blue);
    padding: 12px 20px;
    border-radius: 14px;
    box-shadow: var(--shadow-lg);
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideInRight 0.3s;
}
@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@media (max-width: 1024px) {
    .stats-grid, .charts-grid { grid-template-columns: repeat(2,1fr); }
}
@media (max-width: 640px) {
    .stats-grid, .charts-grid { grid-template-columns: 1fr; }
    .container { padding: 80px 16px 20px; }
}
</style>
</head>
<body>

<div class="topbar">
    <div class="logo-area">
        <h2>✨ Investhood IT Admin</h2>
        <span class="last-updated">Updated: <?= esc($current_time) ?></span>
    </div>
    <div class="topbar-actions">
        <div class="icon-btn" id="refreshBtn" title="Refresh Data">
            <i class="fas fa-sync-alt"></i>
        </div>
        <div class="notification-container">
            <div class="icon-btn" id="notificationBtn">
                <i class="fas fa-bell"></i>
                <?php if($unreadCount > 0): ?>
                    <span class="notification-badge" style="position:absolute; top:-2px; right:-4px; background:var(--red); color:white; font-size:10px; border-radius:20px; padding:2px 6px;"><?= $unreadCount ?></span>
                <?php endif; ?>
            </div>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notif-header">
                    <strong>Notifications</strong>
                    <span id="markAllReadBtn" style="cursor:pointer; font-size:12px; color:var(--blue);">Mark all read</span>
                </div>
                <div class="notif-list" id="notifList"></div>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <!-- STAT CARDS -->
    <div class="stats-grid">
        <div class="stat-card" data-card="users">
            <div class="stat-header">
                <div class="stat-icon users"><i class="fas fa-users"></i></div>
                <div><div class="stat-value"><?= n($totalUsers) ?></div><div class="stat-label">Total Users</div></div>
            </div>
            <div class="stat-details" id="usersDetail">
                <?php foreach($roles as $roleName => $count): ?>
                <div class="stat-details-row" style="display:flex; justify-content:space-between; font-size:13px; margin:4px 0">
                    <span><?= ucfirst($roleName) ?></span><span><?= n($count) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="stat-card" data-card="events">
            <div class="stat-header">
                <div class="stat-icon events"><i class="fas fa-bolt"></i></div>
                <div><div class="stat-value"><?= n($eventsTotal) ?></div><div class="stat-label">Events (30d)</div></div>
            </div>
            <div class="stat-details">Daily avg: <?= round($eventsTotal/30) ?></div>
        </div>
        <div class="stat-card" data-card="active">
            <div class="stat-header">
                <div class="stat-icon active"><i class="fas fa-user-check"></i></div>
                <div><div class="stat-value"><?= n($activeUsers) ?></div><div class="stat-label">Active Users</div></div>
            </div>
            <div class="stat-details">Inactive: <?= n($inactiveCount) ?> users</div>
        </div>
        <div class="stat-card" data-card="roles">
            <div class="stat-header">
                <div class="stat-icon roles"><i class="fas fa-diagram-project"></i></div>
                <div><div class="stat-value"><?= count($roles) ?></div><div class="stat-label">Roles</div></div>
            </div>
            <div class="stat-details"></div>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="charts-grid">
        <div class="chart-card"><h3>📈 Activity Trend</h3><canvas id="trendChart"></canvas></div>
        <div class="chart-card"><h3>👥 Users by Role</h3><canvas id="rolesChart"></canvas></div>
        <div class="chart-card"><h3>🎯 Activity Types</h3><canvas id="typesChart"></canvas></div>
    </div>

    <!-- USER MANAGEMENT TABLE -->
    <div class="user-section">
        <div class="section-header">
            <strong><i class="fas fa-users"></i> User Activity Overview</strong>
            <div class="search-sort">
                <input type="text" id="searchUserInput" placeholder="Search by name, role..." class="search-input">
                <div id="roleFilterGroup" style="display:flex; gap:6px;">
                    <span class="filter-chip active" data-filter="all">All</span>
                    <span class="filter-chip" data-filter="admin">Admin</span>
                    <span class="filter-chip" data-filter="manager">Manager</span>
                    <span class="filter-chip" data-filter="leader">Leader</span>
                    <span class="filter-chip" data-filter="member">Member</span>
                    <span class="filter-chip" data-filter="inactive">Inactive</span>
                </div>
                <button id="exportCsvBtn" class="action-icon" style="border:1px solid var(--border); padding:6px 12px; border-radius:30px;"><i class="fas fa-download"></i> CSV</button>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="user-table" id="userTable">
                <thead>
                    <tr><th data-sort="name">Name ↕</th><th data-sort="role">Role ↕</th><th data-sort="events">Events ↕</th><th>Actions</th></tr>
                </thead>
                <tbody id="userTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL -->
<div id="userModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3 id="modalUserName">User Details</h3><span id="closeModal" style="cursor:pointer; font-size:28px;">&times;</span></div>
        <div id="modalBodyContent">Loading...</div>
    </div>
</div>
<div id="toastContainer" class="toast-container"></div>

<script>
// DATA injected from PHP
const trendLabels = <?= $js_days ?>;
const trendData = <?= $js_counts ?>;
const rolesLabels = <?= $js_roles_lbl ?>;
const rolesValues = <?= $js_roles_val ?>;
const typesLabels = <?= $js_types_lbl ?>;
const typesValues = <?= $js_types_val ?>;
let allUsersData = <?= $js_combined_users ?>;  // merged top+inactive with cnt field
let notificationsData = <?= $js_notifications ?>;

let charts = {};
let currentRoleFilter = "all";
let currentSearchTerm = "";
let sortColumn = "events";
let sortDirection = "desc";

// Helper: toast
function showToast(message, type = "info") {
    const container = document.getElementById("toastContainer");
    const toast = document.createElement("div");
    toast.className = "toast";
    toast.style.borderLeftColor = type === "success" ? "#10b981" : type === "error" ? "#ef4444" : "#2563eb";
    toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-info-circle'}"></i><span>${message}</span><i class="fas fa-times" style="margin-left:12px; cursor:pointer;" onclick="this.parentElement.remove()"></i>`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

// Mark single notification as read via AJAX
async function markNotificationRead(id, element) {
    try {
        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('id', id);
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            if (element) element.classList.remove('unread');
            // Update badge count
            const badge = document.querySelector('#notificationBtn .notification-badge');
            if (badge) {
                let count = parseInt(badge.innerText) - 1;
                if (count <= 0) badge.remove();
                else badge.innerText = count;
            }
            showToast('Notification marked as read', 'success');
        } else {
            showToast('Failed to mark as read', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

// Mark all as read
async function markAllRead() {
    try {
        const formData = new FormData();
        formData.append('action', 'mark_all_read');
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            document.querySelectorAll('.notif-item').forEach(item => item.classList.remove('unread'));
            const badge = document.querySelector('#notificationBtn .notification-badge');
            if (badge) badge.remove();
            showToast('All notifications marked as read', 'success');
        } else {
            showToast('Failed to mark all as read', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

// Send reminder to inactive user (backend AJAX)
async function sendReminder(userId) {
    const user = allUsersData.find(u => u.user_id == userId);
    if (!user) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_reminder');
        formData.append('user_id', userId);
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showToast(`Reminder sent to ${user.actor}`, 'success');
        } else {
            showToast(result.error || 'Failed to send reminder', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

// Send custom message to any user
async function sendMessage(userId) {
    const user = allUsersData.find(u => u.user_id == userId);
    if (!user) return;
    const msg = prompt(`Enter message to send to ${user.actor}:`);
    if (!msg || msg.trim() === '') return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('user_id', userId);
        formData.append('message', msg.trim());
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showToast(`Message sent to ${user.actor}`, 'success');
        } else {
            showToast(result.error || 'Failed to send message', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

// Render user table with filters, search, sort
function renderUserTable() {
    let filtered = [...allUsersData];
    if (currentRoleFilter !== "all") {
        if (currentRoleFilter === "inactive") {
            filtered = filtered.filter(u => u.cnt === 0);
        } else {
            filtered = filtered.filter(u => u.role?.toLowerCase() === currentRoleFilter);
        }
    }
    if (currentSearchTerm.trim() !== "") {
        const term = currentSearchTerm.toLowerCase();
        filtered = filtered.filter(u => u.actor?.toLowerCase().includes(term) || u.role?.toLowerCase().includes(term));
    }
    // sorting
    filtered.sort((a,b) => {
        let aVal, bVal;
        if (sortColumn === "name") { aVal = a.actor || ""; bVal = b.actor || ""; }
        else if (sortColumn === "role") { aVal = a.role || ""; bVal = b.role || ""; }
        else { aVal = a.cnt || 0; bVal = b.cnt || 0; }
        if (typeof aVal === "string") {
            return sortDirection === "asc" ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
        } else {
            return sortDirection === "asc" ? aVal - bVal : bVal - aVal;
        }
    });
    const tbody = document.getElementById("userTableBody");
    tbody.innerHTML = "";
    filtered.forEach(user => {
        const row = tbody.insertRow();
        row.className = "user-row";
        row.setAttribute("data-id", user.user_id);
        const nameCell = row.insertCell(0);
        nameCell.innerText = user.actor || "Anonymous";
        const roleCell = row.insertCell(1);
        const roleClass = (user.role || "member").toLowerCase();
        roleCell.innerHTML = `<span class="role-badge ${roleClass}">${user.role || "member"}</span>`;
        const eventsCell = row.insertCell(2);
        eventsCell.innerText = user.cnt || 0;
        const actionsCell = row.insertCell(3);
        actionsCell.className = "action-btns";
        actionsCell.innerHTML = `
            <button class="action-icon view-user" data-id="${user.user_id}" title="View Details"><i class="fas fa-eye"></i></button>
            <button class="action-icon msg-user" data-id="${user.user_id}" title="Send message"><i class="fas fa-envelope"></i></button>
            ${user.cnt === 0 ? `<button class="action-icon remind-user" data-id="${user.user_id}" title="Send reminder"><i class="fas fa-bell"></i></button>` : ''}
        `;
    });
    document.querySelectorAll(".view-user").forEach(btn => btn.addEventListener("click", (e) => { e.stopPropagation(); showUserModal(btn.dataset.id); }));
    document.querySelectorAll(".msg-user").forEach(btn => btn.addEventListener("click", (e) => { e.stopPropagation(); sendMessage(btn.dataset.id); }));
    document.querySelectorAll(".remind-user").forEach(btn => btn.addEventListener("click", (e) => { e.stopPropagation(); sendReminder(btn.dataset.id); }));
}

function showUserModal(userId) {
    const user = allUsersData.find(u => u.user_id == userId);
    if(!user) return;
    document.getElementById("modalUserName").innerHTML = user.actor;
    document.getElementById("modalBodyContent").innerHTML = `
        <div style="background:var(--bg); padding:16px; border-radius:20px; margin: 12px 0;">
            <div><strong>Role:</strong> ${user.role}</div>
            <div><strong>User ID:</strong> ${user.user_id}</div>
            <div><strong>Activity (30d):</strong> ${user.cnt || 0} events</div>
            <div><strong>Status:</strong> ${user.cnt > 0 ? '<span style="color:var(--green)">Active</span>' : '<span style="color:var(--red)">Inactive</span>'}</div>
        </div>
        <div style="display:flex; gap:12px; margin-top:12px;">
            <button class="action-icon" onclick="sendMessage(${user.user_id})"><i class="fas fa-envelope"></i> Message</button>
            ${user.cnt === 0 ? `<button class="action-icon" onclick="sendReminder(${user.user_id})"><i class="fas fa-bell"></i> Send Reminder</button>` : ''}
            <button class="action-icon" onclick="exportUserData(${user.user_id})"><i class="fas fa-download"></i> Export</button>
        </div>
    `;
    document.getElementById("userModal").classList.add("show");
}
function exportUserData(uid) { showToast(`Exporting user #${uid} (demo)`, "info"); }

// charts init
function initCharts() {
    charts.trend = new Chart(document.getElementById("trendChart"), {
        type: "line", data: { labels: trendLabels, datasets: [{ label: "Events", data: trendData, borderColor: "#2563eb", backgroundColor: "rgba(37,99,235,0.1)", fill: true, tension: 0.3 }] },
        options: { responsive: true, maintainAspectRatio: true, onClick: (e,active) => { if(active.length) showToast(`Date: ${trendLabels[active[0].index]} → ${trendData[active[0].index]} events`, "info"); } }
    });
    charts.roles = new Chart(document.getElementById("rolesChart"), {
        type: "doughnut", data: { labels: rolesLabels, datasets: [{ data: rolesValues, backgroundColor: ["#2563eb","#10b981","#8b5cf6","#f59e0b"] }] },
        options: { onClick: (e,active) => { if(active.length) applyRoleFilter(rolesLabels[active[0].index].toLowerCase()); } }
    });
    charts.types = new Chart(document.getElementById("typesChart"), {
        type: "bar", data: { labels: typesLabels, datasets: [{ data: typesValues, backgroundColor: "#2563eb" }] }
    });
}

function applyRoleFilter(role) {
    currentRoleFilter = role;
    document.querySelectorAll(".filter-chip").forEach(chip => { chip.classList.remove("active"); if(chip.dataset.filter === role) chip.classList.add("active"); });
    renderUserTable();
    showToast(`Filter: ${role === "inactive" ? "Inactive users" : role}`, "info");
}

// Render notifications with AJAX mark-read binding
function renderNotifications() {
    const container = document.getElementById("notifList");
    if(!notificationsData.length) { container.innerHTML = "<div style='padding:20px;text-align:center'>No notifications</div>"; return; }
    container.innerHTML = "";
    notificationsData.forEach(notif => {
        const div = document.createElement("div");
        div.className = `notif-item ${!notif.is_read ? "unread" : ""}`;
        div.setAttribute("data-id", notif.id);
        div.innerHTML = `
            <div class="notif-icon activity"><i class="fas fa-bell"></i></div>
            <div style="flex:1">
                <div><strong>${notif.title || "Activity"}</strong></div>
                <small>${notif.message}</small>
                <div style="font-size:10px; color:var(--muted)">${notif.created_at || "recent"}</div>
            </div>
        `;
        // Click to mark as read
        div.addEventListener("click", (e) => {
            e.stopPropagation();
            if (!notif.is_read) {
                markNotificationRead(notif.id, div);
                notif.is_read = 1;
            }
        });
        container.appendChild(div);
    });
}

// Event Listeners
document.getElementById("searchUserInput")?.addEventListener("input", (e) => { currentSearchTerm = e.target.value; renderUserTable(); });
document.querySelectorAll(".filter-chip").forEach(chip => {
    chip.addEventListener("click", () => { applyRoleFilter(chip.dataset.filter); });
});
document.querySelectorAll(".stat-card").forEach(card => {
    card.addEventListener("click", (e) => {
        const details = card.querySelector(".stat-details");
        if(details) details.classList.toggle("show");
    });
});
document.getElementById("exportCsvBtn")?.addEventListener("click", () => {
    let csvRows = [["Name","Role","Events"]];
    allUsersData.forEach(u => { csvRows.push([u.actor, u.role, u.cnt || 0]); });
    const csv = csvRows.map(row => row.join(",")).join("\n");
    const blob = new Blob([csv], {type: "text/csv"});
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "users_report.csv";
    a.click();
    showToast("CSV exported", "success");
});
document.getElementById("refreshBtn")?.addEventListener("click", () => location.reload());
document.getElementById("notificationBtn")?.addEventListener("click", () => document.getElementById("notificationDropdown")?.classList.toggle("show"));
document.getElementById("markAllReadBtn")?.addEventListener("click", markAllRead);
document.getElementById("closeModal")?.addEventListener("click", () => document.getElementById("userModal").classList.remove("show"));
window.addEventListener("click", (e) => { if(e.target === document.getElementById("userModal")) document.getElementById("userModal").classList.remove("show"); });

// Sorting table headers
document.querySelectorAll("[data-sort]").forEach(th => {
    th.addEventListener("click", () => {
        const col = th.dataset.sort;
        if(sortColumn === col) sortDirection = sortDirection === "asc" ? "desc" : "asc";
        else { sortColumn = col; sortDirection = "desc"; }
        renderUserTable();
    });
});

// Initial render
renderUserTable();
initCharts();
renderNotifications();
</script>
</body>
</html>