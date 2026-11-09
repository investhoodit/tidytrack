<?php
session_start();
include '../dp.php';
if (!isset($_SESSION['developer_id'])) {
    header('Location: index.php');
    exit;
}

// ── HANDLE POST ACTIONS ──
$msg = '';
$msgType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action  = $_POST['action'];
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($user_id > 0) {
        if ($action === 'archive') {
            $check = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
            $check->bind_param("i", $user_id);
            $check->execute();

            if ($check->get_result()->num_rows === 1) {
                $chkArchive = $conn->prepare("SELECT user_id FROM archived_users WHERE user_id = ?");
                $chkArchive->bind_param("i", $user_id);
                $chkArchive->execute();

                if ($chkArchive->get_result()->num_rows > 0) {
                    $conn->query("DELETE FROM archived_users WHERE user_id = $user_id");
                }
                $chkArchive->close();

                $cols = "user_id,email,phone,password,name,role,status,profile_picture,department,bio,attendance,google_user,email_confirmed,confirmation_token,isallowed,appeal";
                $insert = $conn->prepare("INSERT INTO archived_users ($cols, archived_at, archived_by)
                                          SELECT $cols, NOW(), ? FROM users WHERE user_id = ?");
                $dev_id = $_SESSION['developer_id'];
                $insert->bind_param("ii", $dev_id, $user_id);

                if ($insert->execute()) {
                    $conn->query("DELETE FROM users WHERE user_id = $user_id");
                    $msg = "✅ User <strong>archived</strong> successfully.";
                    $msgType = 'success';
                } else {
                    $msg = "❌ Archive failed.";
                    $msgType = 'error';
                }
                $insert->close();
            }
            $check->close();
        }
        elseif ($action === 'restore') {
            $check = $conn->prepare("SELECT user_id FROM archived_users WHERE user_id = ?");
            $check->bind_param("i", $user_id);
            $check->execute();

            if ($check->get_result()->num_rows === 1) {
                $cols = "user_id,email,phone,password,name,role,status,profile_picture,department,bio,attendance,google_user,email_confirmed,confirmation_token,isallowed,appeal";
                $insert = $conn->prepare("INSERT INTO users ($cols) SELECT $cols FROM archived_users WHERE user_id = ?");
                $insert->bind_param("i", $user_id);

                if ($insert->execute()) {
                    $conn->query("DELETE FROM archived_users WHERE user_id = $user_id");
                    $msg = "✅ User <strong>restored</strong> successfully.";
                    $msgType = 'success';
                } else {
                    $msg = "❌ Restore failed (email may already exist).";
                    $msgType = 'error';
                }
                $insert->close();
            }
            $check->close();
        }
        elseif ($action === 'permanent_delete') {
            $fromArchive = ($_POST['from_table'] ?? 'users') === 'archived_users';
            $table = $fromArchive ? 'archived_users' : 'users';

            $cleanup = [
                "DELETE FROM attendance_log WHERE user_id = ?",
                "DELETE FROM contributions WHERE member_id = ?",
                "DELETE FROM daily_report_reminders WHERE user_id = ?",
                "DELETE FROM daily_tasks WHERE user_id = ?",
                "DELETE FROM document_submissions WHERE user_id = ?",
                "DELETE FROM employee_ratings WHERE employee_id = ? OR manager_id = ?",
                "DELETE FROM group_members WHERE user_id = ?",
                "DELETE FROM group_messages WHERE user_id = ?",
                "DELETE FROM leave_requests WHERE employee_id = ? OR supervisor_id = ?",
                "DELETE FROM meeting_logs WHERE user_id = ?",
                "DELETE FROM member_breaks WHERE user_id = ?",
                "DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?",
                "DELETE FROM notifications WHERE user_id = ?",
                "DELETE FROM password_reset_requests WHERE user_id = ?",
                "DELETE FROM post_likes WHERE user_id = ?",
                "DELETE FROM project_defects WHERE assigned_user_id = ? OR created_by = ?",
                "DELETE FROM reminders WHERE user_id = ?",
                "DELETE FROM reminder_tasks WHERE user_id = ?",
                "DELETE FROM sharezone_comments WHERE user_id = ?",
                "DELETE FROM sharezone_data WHERE user_id = ?",
                "DELETE FROM sharezone_likes WHERE user_id = ?",
                "DELETE FROM task_notifications WHERE user_id = ?",
                "DELETE FROM timesheet WHERE user_id = ?",
                "DELETE FROM user_activity WHERE user_id = ?",
                "DELETE FROM user_invites WHERE invited_by = ?",
                "DELETE FROM user_sessions WHERE user_id = ?",
                "DELETE FROM work_wellness WHERE user_id = ?",
                "DELETE FROM tasks WHERE created_by = ?",
                "DELETE FROM member_daily_plans WHERE user_id = ?",
                "DELETE FROM member_breaks WHERE user_id = ?",
                "DELETE FROM password_reset_requests WHERE user_id = ?",
            ];

            $success = true;
            foreach ($cleanup as $sql) {
                $stmt = $conn->prepare($sql);
                if (!$stmt) continue;
                if (substr_count($sql, '?') === 2) {
                    $stmt->bind_param("ii", $user_id, $user_id);
                } else {
                    $stmt->bind_param("i", $user_id);
                }
                if (!$stmt->execute()) $success = false;
                $stmt->close();
            }

            $del = $conn->prepare("DELETE FROM `$table` WHERE user_id = ?");
            $del->bind_param("i", $user_id);
            if ($del->execute() && $success) {
                $msg = "🗑️ User and all related data <strong>permanently deleted</strong>.";
                $msgType = 'success';
            } else {
                $msg = "❌ Some data could not be deleted.";
                $msgType = 'error';
            }
            $del->close();
        }
    }
}

// ── HELPERS: tasks / projects / leaders ──
function enrich_user_stats(mysqli $conn, array $row): array {
    $uid  = (int)($row['user_id'] ?? 0);
    $name = trim((string)($row['name'] ?? ''));
    $role = strtolower(trim((string)($row['role'] ?? '')));
    $uidStr = (string)$uid;

    $taskCount = 0;
    $projectCount = 0;
    $leaders = [];

    // Task count: assignTo can be name or id string; also created_by
    if ($name !== '') {
        $tStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM tasks WHERE assignTo = ? OR assignTo = ? OR created_by = ?");
        if ($tStmt) {
            $tStmt->bind_param("ssi", $name, $uidStr, $uid);
            $tStmt->execute();
            $taskCount = (int)($tStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $tStmt->close();
        }
    } else {
        $tStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM tasks WHERE created_by = ? OR assignTo = ?");
        if ($tStmt) {
            $tStmt->bind_param("is", $uid, $uidStr);
            $tStmt->execute();
            $taskCount = (int)($tStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $tStmt->close();
        }
    }

    // Projects + leaders from projects_x
    $hasProjects = false;
    $chk = $conn->query("SHOW TABLES LIKE 'projects_x'");
    if ($chk && $chk->num_rows > 0) $hasProjects = true;
    if ($chk) $chk->free();

    if ($hasProjects && $name !== '') {
        $pq = $conn->prepare("
            SELECT DISTINCT p.project_name,
                   COALESCE(NULLIF(TRIM(l.name), ''), l.email, '') AS leader_name
            FROM tasks t
            JOIN projects_x p ON p.project_id = t.project_id
            LEFT JOIN users l ON l.user_id = p.teamLeadder_id
            WHERE t.assignTo = ? OR t.assignTo = ? OR t.created_by = ?
        ");
        if ($pq) {
            $pq->bind_param("ssi", $name, $uidStr, $uid);
            $pq->execute();
            $pres = $pq->get_result();
            $projs = [];
            while ($pr = $pres->fetch_assoc()) {
                if (!empty($pr['project_name'])) $projs[$pr['project_name']] = true;
                $ln = trim((string)($pr['leader_name'] ?? ''));
                if ($ln !== '') $leaders[$ln] = true;
            }
            $projectCount = count($projs);
            $pq->close();
        }
    }

    // If user is leader/manager/admin, count projects they lead
    if ($hasProjects && in_array($role, ['leader', 'admin', 'manager'], true)) {
        $lq = $conn->prepare("SELECT COUNT(*) AS c FROM projects_x WHERE teamLeadder_id = ?");
        if ($lq) {
            $lq->bind_param("i", $uid);
            $lq->execute();
            $led = (int)($lq->get_result()->fetch_assoc()['c'] ?? 0);
            $lq->close();
            if ($led > $projectCount) $projectCount = $led;
        }
    }

    $row['task_count']    = $taskCount;
    $row['project_count'] = $projectCount;
    $row['leader_names']  = $leaders ? implode(', ', array_keys($leaders)) : 'No leader';

    return $row;
}

function export_user_row(array $row, string $accountStatus): array {
    return [
        'user_id'        => (int)($row['user_id'] ?? 0),
        'name'           => $row['name'] ?? '',
        'email'          => $row['email'] ?? '',
        'role'           => $row['role'] ?? '',
        'department'     => $row['department'] ?? '',
        'status'         => $row['status'] ?? '',
        'phone'          => $row['phone'] ?? '',
        'account_status' => $accountStatus,
        'task_count'     => (int)($row['task_count'] ?? 0),
        'project_count'  => (int)($row['project_count'] ?? 0),
        'leader'         => $row['leader_names'] ?? 'No leader',
        'archived_at'    => $row['archived_at'] ?? '',
    ];
}

// ── EXPORT CSV ──
if (isset($_GET['export'])) {
    $mode = strtolower(trim($_GET['export'])); // all | active | archived
    if (!in_array($mode, ['all', 'active', 'archived'], true)) {
        $mode = 'all';
    }

    $rows = [];

    if ($mode === 'all' || $mode === 'active') {
        $r = $conn->query("SELECT * FROM users ORDER BY role DESC, name ASC");
        if ($r) {
            while ($u = $r->fetch_assoc()) {
                $u = enrich_user_stats($conn, $u);
                $rows[] = export_user_row($u, 'Active');
            }
        }
    }

    if ($mode === 'all' || $mode === 'archived') {
        $r = $conn->query("SELECT * FROM archived_users ORDER BY archived_at DESC");
        if ($r) {
            while ($u = $r->fetch_assoc()) {
                $u = enrich_user_stats($conn, $u);
                $rows[] = export_user_row($u, 'Archived');
            }
        }
    }

    $filename = 'tidytrack_users_' . $mode . '_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Excel UTF-8 BOM
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, [
        'User ID',
        'Name',
        'Email',
        'Role',
        'Department',
        'User Status',
        'Account Status',
        'Tasks',
        'Projects',
        'Leader(s)',
        'Phone',
        'Archived At',
    ]);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['user_id'],
            $row['name'],
            $row['email'],
            $row['role'],
            $row['department'],
            $row['status'],
            $row['account_status'],
            $row['task_count'],
            $row['project_count'],
            $row['leader'],
            $row['phone'],
            $row['archived_at'],
        ]);
    }
    fclose($out);
    exit;
}

// Search
$search = trim($_GET['search'] ?? '');

// ── FETCH ACTIVE USERS ──
$activeUsers = [];
$sql = "SELECT * FROM users";
if ($search !== '') {
    $sql .= " WHERE (name LIKE ? OR email LIKE ?)";
}
$sql .= " ORDER BY role DESC, name ASC";

$stmt = $conn->prepare($sql);
if ($search !== '') {
    $s = "%$search%";
    $stmt->bind_param("ss", $s, $s);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $activeUsers[] = enrich_user_stats($conn, $row);
}
$stmt->close();

// ── FETCH ARCHIVED USERS ──
$archivedUsers = [];
$sql = "SELECT * FROM archived_users";
if ($search !== '') {
    $sql .= " WHERE (name LIKE ? OR email LIKE ?)";
}
$sql .= " ORDER BY archived_at DESC";

$stmt = $conn->prepare($sql);
if ($search !== '') {
    $s = "%$search%";
    $stmt->bind_param("ss", $s, $s);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $archivedUsers[] = enrich_user_stats($conn, $row);
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delete Users · Dev Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --bg:#0c0e13; --surface:#101318; --card:#161a23; --card-h:#1c2132;
    --border:rgba(255,255,255,0.06); --border-h:rgba(255,255,255,0.13);
    --text:#dde1eb; --sub:#52596e;
    --blue:#4f7ef5; --violet:#7c5af5; --green:#10c97a;
    --amber:#f5a623; --rose:#f54f6b; --cyan:#0ac8e0;
    --r:16px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{
    font-family:'Syne',sans-serif;
    background:var(--bg); color:var(--text);
    min-height:100vh; overflow-x:hidden;
}
body::before{
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:radial-gradient(circle,rgba(79,126,245,.055) 1px,transparent 1px);
    background-size:28px 28px;
}
.page{position:relative;z-index:1;max-width:1300px;margin:0 auto;padding:36px 28px;}

/* TOPBAR - exactly like dashboard */
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:52px;}
.tb-brand{display:flex;align-items:center;gap:14px;}
.tb-logo{
    width:42px;height:42px;border-radius:12px;flex-shrink:0;
    background:linear-gradient(135deg,var(--blue),var(--violet));
    display:flex;align-items:center;justify-content:center;
    font-size:1rem;font-weight:800;color:#fff;
    box-shadow:0 0 22px rgba(79,126,245,.35);
}
.tb-name{font-size:1.1rem;font-weight:800;letter-spacing:-.2px;}
.tb-meta{font-size:10.5px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-top:2px;}
.tb-right{display:flex;align-items:center;gap:10px;}
.tb-clock{
    padding:7px 14px;border-radius:10px;
    background:var(--card);border:1px solid var(--border);
    font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--sub);
}
.btn-out{
    padding:8px 20px;border-radius:10px;font-size:12px;font-weight:700;
    font-family:'Syne',sans-serif;text-decoration:none;cursor:pointer;
    background:rgba(245,79,107,.1);color:var(--rose);
    border:1px solid rgba(245,79,107,.2);transition:all .2s;
}
.btn-out:hover{background:rgba(245,79,107,.2);border-color:rgba(245,79,107,.45);}

/* GREETING + HEADER */
.greet{margin-bottom:44px;}
.greet-hi{font-size:2.3rem;font-weight:800;letter-spacing:-.6px;line-height:1.1;}
.greet-hi em{font-style:normal;color:var(--blue);}
.greet-meta{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--sub);margin-top:8px;}

/* SECTION LABEL */
.sh{
    display:flex;align-items:center;gap:10px;
    font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;
    color:var(--sub);font-family:'JetBrains Mono',monospace;margin-bottom:18px;
}
.sh::after{content:'';flex:1;height:1px;background:var(--border);}

/* USER CARDS */
.users-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(300px,1fr));
    gap:16px;
    margin-bottom:60px;
}
.user-card{
    background:var(--card);border:1px solid var(--border);border-radius:var(--r);
    padding:24px;position:relative;overflow:hidden;
    transition:transform .22s,background .22s,border-color .22s;
}
.user-card:hover{background:var(--card-h);border-color:var(--border-h);transform:translateY(-4px);}
.user-card::before{
    content:'';position:absolute;top:0;left:0;right:0;height:3px;
    background:linear-gradient(90deg,var(--blue),var(--violet));
    transform:scaleX(0);transition:transform .3s;
}
.user-card:hover::before{transform:scaleX(1);}

.profile-area{display:flex;align-items:center;gap:16px;margin-bottom:16px;}
.profile-pic{
    width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid var(--border);
    flex-shrink:0;
}
.avatar-placeholder{
    width:64px;height:64px;border-radius:50%;
    background:linear-gradient(135deg,var(--blue),var(--violet));
    display:flex;align-items:center;justify-content:center;
    font-size:28px;font-weight:800;color:#fff;
    flex-shrink:0;
}
.user-info{flex:1;}
.user-name{font-size:1.25rem;font-weight:700;line-height:1;}
.user-email{font-size:12.5px;font-family:'JetBrains Mono',monospace;color:var(--sub);margin-top:2px;}
.role-pill{
    display:inline-flex;align-items:center;gap:6px;
    padding:4px 12px;border-radius:30px;font-size:11px;font-weight:700;
    font-family:'JetBrains Mono',monospace;margin-top:8px;
}
.role-pill.admin{background:rgba(124,90,245,.15);color:#a48af7;}
.role-pill.manager{background:rgba(245,166,35,.15);color:var(--amber);}
.role-pill.leader{background:rgba(16,201,122,.15);color:var(--green);}
.role-pill.member{background:rgba(79,126,245,.15);color:var(--blue);}

.stats-row{
    display:flex;gap:12px;margin:20px 0;
    font-size:13px;font-family:'JetBrains Mono',monospace;
}
.stat-item{
    background:var(--surface);border-radius:8px;padding:8px 14px;flex:1;
    text-align:center;
}
.stat-num{font-size:1.4rem;font-weight:800;color:var(--text);}
.stat-lbl{font-size:10px;color:var(--sub);text-transform:uppercase;}

.action-buttons{
    display:flex;gap:10px;margin-top:8px;
}
.btn-action{
    flex:1;padding:12px 16px;border-radius:12px;font-weight:700;font-size:13px;
    cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;
    border:none;
}
.btn-archive{background:rgba(16,201,122,.9);color:#fff;}
.btn-archive:hover{background:#10c97a;}
.btn-permanent{background:rgba(245,79,107,.9);color:#fff;}
.btn-permanent:hover{background:#f54f6b;}
.btn-restore{background:rgba(79,126,245,.9);color:#fff;}
.btn-restore:hover{background:#4f7ef5;}

/* MODAL - same style as attendance */
.modal-bg{
    display:none;position:fixed;inset:0;z-index:9999;
    background:rgba(0,0,0,.75);backdrop-filter:blur(6px);
    align-items:center;justify-content:center;padding:20px;
}
.modal-bg.open{display:flex;}
.modal{
    background:var(--card);border:1px solid var(--border-h);
    border-radius:20px;width:100%;max-width:520px;
    animation:popUp .3s cubic-bezier(.34,1.56,.64,1) both;
}
.modal-head{
    padding:24px 28px;border-bottom:1px solid var(--border);
    display:flex;justify-content:space-between;align-items:center;
}
.modal-title{font-size:1.3rem;font-weight:800;}
.modal-body{padding:28px;}
.modal-footer{
    padding:0 28px 28px;display:flex;gap:12px;
}
.modal-btn{
    flex:1;padding:14px;border-radius:12px;font-weight:700;cursor:pointer;
}
.modal-btn.cancel{background:var(--surface);color:var(--text);border:1px solid var(--border);}
.modal-btn.confirm{background:linear-gradient(135deg,var(--blue),var(--violet));color:#fff;border:none;}

.warning-box{
    background:rgba(245,79,107,.1);border:1px solid rgba(245,79,107,.3);
    padding:16px;border-radius:12px;margin:20px 0;font-size:13px;color:var(--rose);
}

@keyframes popUp{from{opacity:0;transform:scale(.9);}to{opacity:1;transform:scale(1);}}

@media(max-width:700px){
    .users-grid{grid-template-columns:1fr;}
    .greet-hi{font-size:1.9rem;}
}
</style>
</head>
<body>
<div class="page">

    <!-- TOPBAR (exact same as dashboard) -->
    <div class="topbar">
        <div class="tb-brand">
            <div class="tb-logo">T</div>
            <div>
                <div class="tb-name">TidyTrack Dev Portal</div>
                <div class="tb-meta">investh2_tidytrack 路 User Management</div>
            </div>
        </div>
        <div class="tb-right">
            <div class="tb-clock" id="devClock">--:--:--</div>
            <a href="index.php" class="btn-out"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>

    <!-- GREETING -->
    <div class="greet">
        <div class="greet-hi">Manage Users <em>🗑️</em></div>
        <div class="greet-meta">// Archive or permanently delete. History is preserved in the archive table.</div>
    </div>

    <?php if ($msg): ?>
    <div class="approve-msg <?= $msgType ?>" style="margin-bottom:28px;padding:16px 20px;border-radius:12px;font-size:14px;">
        <?= $msg ?>
    </div>
    <?php endif; ?>
    
    <!-- SEARCH BAR -->
<div style="margin-bottom:28px;">
    <form method="GET" style="max-width:420px;">
        <div style="display:flex;gap:10px;">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                   placeholder="Search by name or email..." 
                   style="flex:1;padding:14px 18px;background:var(--surface);border:1px solid var(--border);border-radius:12px;color:var(--text);font-size:15px;">
            <button type="submit" style="padding:0 24px;border-radius:12px;background:var(--blue);color:#fff;border:none;cursor:pointer;">
                <i class="fas fa-search"></i>
            </button>
        </div>
        
        <!-- EXPORT -->
<div style="margin-bottom:28px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
    <span style="font-size:11px;font-family:'JetBrains Mono',monospace;color:var(--sub);text-transform:uppercase;letter-spacing:1px;">Export</span>
    <a href="?export=all" class="btn-out" style="background:rgba(79,126,245,.12);color:var(--blue);border-color:rgba(79,126,245,.25);">
        <i class="fas fa-file-csv"></i> All (active + archived)
    </a>
    <a href="?export=active" class="btn-out" style="background:rgba(16,201,122,.12);color:var(--green);border-color:rgba(16,201,122,.25);">
        <i class="fas fa-users"></i> Active only
    </a>
    <a href="?export=archived" class="btn-out" style="background:rgba(245,166,35,.12);color:var(--amber);border-color:rgba(245,166,35,.25);">
        <i class="fas fa-archive"></i> Archived only
    </a>
</div>
    </form>
</div>

    <!-- ACTIVE USERS -->
    <div class="sh"><span>ACTIVE USERS (<?= count($activeUsers) ?>)</span></div>
    <div class="users-grid">
        <?php foreach ($activeUsers as $u): ?>
        <div class="user-card">
            <div class="profile-area">
                <?php if (!empty($u['profile_picture'])): ?>
                    <img src="<?= htmlspecialchars($u['profile_picture']) ?>" class="profile-pic" alt="">
                <?php else: ?>
                    <div class="avatar-placeholder">
                        <?= strtoupper(substr($u['name'] ?? $u['email'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($u['name'] ?? 'No Name') ?></div>
                    <div class="user-email"><?= htmlspecialchars($u['email']) ?></div>
                    <div class="role-pill <?= strtolower($u['role']) ?>">
                        <i class="fas fa-<?= $u['role']==='admin'?'shield-halved':($u['role']==='leader'?'user-tie':'user') ?>"></i>
                        <?= strtoupper($u['role']) ?>
                    </div>
                </div>
            </div>

            <div class="stats-row">
    <div class="stat-item">
        <div class="stat-num"><?= (int)$u['task_count'] ?></div>
        <div class="stat-lbl">Tasks</div>
    </div>
    <div class="stat-item">
        <div class="stat-num"><?= (int)($u['project_count'] ?? 0) ?></div>
        <div class="stat-lbl">Projects</div>
    </div>
</div>
<div style="font-size:12px;color:var(--sub);margin-bottom:12px;font-family:'JetBrains Mono',monospace;">
    <i class="fas fa-user-tie" style="margin-right:6px;opacity:.7;"></i>
    Leader: <strong style="color:var(--text);"><?= htmlspecialchars($u['leader_names'] ?? 'No leader') ?></strong>
</div>

            <div class="action-buttons">
                <button onclick="showConfirmModal(<?= $u['user_id'] ?>, 'archive', '<?= htmlspecialchars(addslashes($u['name'] ?? $u['email'])) ?>', 'active')" 
                        class="btn-action btn-archive">
                    <i class="fas fa-archive"></i> ARCHIVE
                </button>
                <button onclick="showConfirmModal(<?= $u['user_id'] ?>, 'permanent_delete', '<?= htmlspecialchars(addslashes($u['name'] ?? $u['email'])) ?>', 'active')" 
                        class="btn-action btn-permanent">
                    <i class="fas fa-trash-alt"></i> DELETE FOREVER
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($activeUsers)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--sub);">
            No active users found.
        </div>
        <?php endif; ?>
    </div>

    <!-- ARCHIVED USERS -->
    <div class="sh"><span>ARCHIVED USERS (<?= count($archivedUsers) ?>)</span></div>
    <div class="users-grid">
        <?php foreach ($archivedUsers as $u): ?>
        <div class="user-card" style="opacity:0.9;">
            <div class="profile-area">
                <?php if (!empty($u['profile_picture'])): ?>
                    <img src="<?= htmlspecialchars($u['profile_picture']) ?>" class="profile-pic" alt="">
                <?php else: ?>
                    <div class="avatar-placeholder">
                        <?= strtoupper(substr($u['name'] ?? $u['email'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($u['name'] ?? 'No Name') ?></div>
                    <div class="user-email"><?= htmlspecialchars($u['email']) ?> <span style="font-size:10px;background:#f5a623;color:#000;padding:1px 6px;border-radius:4px;">ARCHIVED</span></div>
                    <div class="role-pill <?= strtolower($u['role']) ?>">
                        <?= strtoupper($u['role']) ?>
                    </div>
                    <div style="font-size:10px;color:var(--sub);margin-top:6px;font-family:'JetBrains Mono',monospace;">
                        Archived: <?= date('d M Y', strtotime($u['archived_at'])) ?>
                    </div>
                </div>
            </div>

           <div class="stats-row">
    <div class="stat-item">
        <div class="stat-num"><?= (int)$u['task_count'] ?></div>
        <div class="stat-lbl">Tasks</div>
    </div>
    <div class="stat-item">
        <div class="stat-num"><?= (int)($u['project_count'] ?? 0) ?></div>
        <div class="stat-lbl">Projects</div>
    </div>
</div>
<div style="font-size:12px;color:var(--sub);margin-bottom:12px;font-family:'JetBrains Mono',monospace;">
    <i class="fas fa-user-tie" style="margin-right:6px;opacity:.7;"></i>
    Leader: <strong style="color:var(--text);"><?= htmlspecialchars($u['leader_names'] ?? 'No leader') ?></strong>
</div>

            <div class="action-buttons">
                <button onclick="showConfirmModal(<?= $u['user_id'] ?>, 'restore', '<?= htmlspecialchars(addslashes($u['name'] ?? $u['email'])) ?>', 'archived')" 
                        class="btn-action btn-restore">
                    <i class="fas fa-undo"></i> RESTORE
                </button>
                <button onclick="showConfirmModal(<?= $u['user_id'] ?>, 'permanent_delete', '<?= htmlspecialchars(addslashes($u['name'] ?? $u['email'])) ?>', 'archived')" 
                        class="btn-action btn-permanent">
                    <i class="fas fa-trash-alt"></i> DELETE FOREVER
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($archivedUsers)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--sub);">
            No archived users yet.
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- CONFIRMATION MODAL -->
<div class="modal-bg" id="confirmModal">
    <div class="modal">
        <div class="modal-head">
            <div>
                <div class="modal-title" id="modalTitle">Confirm Action</div>
                <div id="modalSubtitle" style="font-size:13px;color:var(--sub);margin-top:4px;"></div>
            </div>
            <button onclick="hideModal()" class="modal-close" style="background:none;border:none;font-size:24px;color:var(--sub);cursor:pointer;">×</button>
        </div>
        <div class="modal-body">
            <p id="modalBodyText" style="line-height:1.5;"></p>
            <div id="warningBox" class="warning-box" style="display:none;">
                <strong>⚠️ This action cannot be undone!</strong><br>
                All tasks, attendance records, daily reports and any other data linked to this user will be <strong>permanently deleted</strong>.
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="hideModal()" class="modal-btn cancel">Cancel</button>
            <button onclick="confirmAction()" id="confirmBtn" class="modal-btn confirm">YES, PROCEED</button>
        </div>
    </div>
</div>

<!-- Hidden form for actions -->
<form id="actionForm" method="POST" style="display:none;">
    <input type="hidden" name="action" id="formAction">
    <input type="hidden" name="user_id" id="formUserId">
    <input type="hidden" name="from_table" id="formFromTable">
</form>

<script>
function tick(){
    document.getElementById('devClock').textContent = new Date().toLocaleTimeString('en-ZA',{
        hour:'2-digit',minute:'2-digit',second:'2-digit',timeZone:'Africa/Johannesburg'
    });
}
setInterval(tick,1000); tick();

let currentAction = '';
let currentUserId = 0;
let currentFromTable = '';

function showConfirmModal(userId, action, userName, fromTable) {
    currentAction = action;
    currentUserId = userId;
    currentFromTable = fromTable;

    const modal = document.getElementById('confirmModal');
    const title = document.getElementById('modalTitle');
    const subtitle = document.getElementById('modalSubtitle');
    const body = document.getElementById('modalBodyText');
    const warning = document.getElementById('warningBox');
    const confirmBtn = document.getElementById('confirmBtn');

    if (action === 'archive') {
        title.innerHTML = `Archive <em>${userName}</em>?`;
        subtitle.textContent = 'Move to archived table';
        body.innerHTML = `This user will be <strong>removed from the main users table</strong> and will no longer appear in stats, attendance, or task lists.<br><br>History (tasks, reports, etc.) will still be preserved.`;
        warning.style.display = 'none';
        confirmBtn.style.background = 'linear-gradient(135deg,#10c97a,#0ac8e0)';
        confirmBtn.textContent = 'YES, ARCHIVE';
    }
    else if (action === 'restore') {
        title.innerHTML = `Restore <em>${userName}</em>?`;
        subtitle.textContent = 'Move back to active users';
        body.innerHTML = `User will be returned to the main <strong>users table</strong> and will appear in all stats again.`;
        warning.style.display = 'none';
        confirmBtn.style.background = 'linear-gradient(135deg,#4f7ef5,#7c5af5)';
        confirmBtn.textContent = 'YES, RESTORE';
    }
    else if (action === 'permanent_delete') {
        title.innerHTML = `PERMANENTLY DELETE <em>${userName}</em>?`;
        subtitle.textContent = 'This is irreversible';
        body.innerHTML = `User + <strong>ALL related data</strong> (tasks, sessions, reports, etc.) will be completely removed from the database.`;
        warning.style.display = 'block';
        confirmBtn.style.background = 'linear-gradient(135deg,#f54f6b,#f5a623)';
        confirmBtn.textContent = 'YES, DELETE FOREVER';
    }

    modal.classList.add('open');
}

function hideModal() {
    const modal = document.getElementById('confirmModal');
    modal.classList.remove('open');
}

function confirmAction() {
    // Fill hidden form and submit
    document.getElementById('formAction').value = currentAction;
    document.getElementById('formUserId').value = currentUserId;
    document.getElementById('formFromTable').value = currentFromTable;
    document.getElementById('actionForm').submit();
    hideModal();
}

// Close modal when clicking background
document.getElementById('confirmModal').addEventListener('click', function(e){
    if (e.target.id === 'confirmModal') hideModal();
});
</script>
</body>
</html>