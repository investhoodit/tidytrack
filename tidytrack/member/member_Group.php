<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Member';
$userInitial = strtoupper($userName[0]);

if (!$userId) {
    header("Location: ../login.php");
    exit();
}

// Fetch user status and role
$stmt = $conn->prepare("SELECT status, role, profile_picture FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$currentStatus = $userData['status'] ?? 'offline';
$currentRole = $userData['role'] ?? 'Member';
$profilePicture = $userData['profile_picture'] ?? null;
$stmt->close();

// Fetch user's group requests
$stmt = $conn->prepare("
    SELECT g.group_id, g.group_name, g.group_icon, gm.status, gm.requested_at
    FROM group_members gm
    JOIN groups g ON gm.group_id = g.group_id
    WHERE gm.user_id = ?
    ORDER BY gm.requested_at DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch available groups (exclude groups the user is approved for)
$stmt = $conn->prepare("
    SELECT g.group_id, g.group_name, g.description, g.group_type, g.group_icon,
           (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.group_id AND gm.status = 'approved') as member_count
    FROM groups g
    WHERE g.group_id NOT IN (
        SELECT group_id FROM group_members WHERE user_id = ? AND status = 'approved'
    )
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pendingCount = count(array_filter($requests, fn($req) => $req['status'] === 'pending'));
$conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Messenger - Join Groups</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
    <style>
    :root {
    --accent: #1d3557;
    --bg: #f0f4f9;
    --card: #ffffff;
    --text: #0f172a;
    --border: #e2e8f0;
    --muted: #64748b;
    --success: #059669;
    --danger: #dc2626;
    --warning: #d97706;
}
[data-theme="dark"] {
    --accent: #60a5fa;
    --bg: #0f172a;
    --card: #1e293b;
    --text: #e2e8f0;
    --border: #334155;
    --muted: #94a3b8;
    --success: #34d399;
    --danger: #f87171;
    --warning: #fbbf24;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    color: var(--text);
    display: flex;
    min-height: 100vh;
}

/* ── SIDEBAR (Projects style) ── */
.sidebar {
    width: 260px;
    background: var(--card);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    padding: 18px;
    position: fixed;
    height: 100vh;
    overflow-y: auto;
    z-index: 100;
}
.sidebar .top {
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
}
.sb-avatar {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 15px;
    position: relative; flex-shrink: 0; overflow: hidden;
}
.sb-avatar img { width: 100%; height: 100%; object-fit: cover; }
.sb-avatar .dot {
    position: absolute; bottom: 0; right: 0;
    width: 9px; height: 9px; border-radius: 50%;
    border: 2px solid var(--card);
}
.dot-online, .dot-ready { background: #10b981; }
.dot-offline { background: #9ca3af; }
.dot-sick { background: #ef4444; }
.dot-focusing { background: #8b5cf6; }
.dot-in_meeting { background: #3b82f6; }
.dot-out_of_office { background: #f59e0b; }

.sb-name { font-weight: 700; font-size: 14px; }
.sb-role { font-size: 11px; color: var(--muted); }

.sidebar ul { list-style: none; margin-top: 16px; padding: 0; }
.sidebar ul li { margin-bottom: 4px; }
.sidebar ul li a {
    color: var(--muted);
    text-decoration: none;
    font-size: 13px;
    display: flex; align-items: center; gap: 10px;
    padding: 9px 10px;
    border-radius: 10px;
    transition: all .15s;
    font-weight: 500;
}
.sidebar ul li a:hover,
.sidebar ul li a.active {
    background: #f1f5f9;
    color: var(--accent);
}
[data-theme="dark"] .sidebar ul li a:hover,
[data-theme="dark"] .sidebar ul li a.active {
    background: rgba(255,255,255,0.08);
    color: #fff;
}
.sidebar ul li a i { width: 16px; text-align: center; }

/* ── MAIN ── */
.main-content {
    margin-left: 260px;
    flex: 1;
    padding: 24px;
    min-width: 0;
}

.header {
    background: var(--card);
    padding: 20px 22px;
    border-radius: 16px;
    border: 1px solid var(--border);
    margin-bottom: 20px;
}
.header h1 {
    margin: 0 0 6px 0;
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--text);
    display: flex; align-items: center; gap: 10px;
}
.header p { margin: 0; color: var(--muted); font-size: 13px; }

.my-requests, .available-groups {
    background: var(--card);
    border-radius: 16px;
    border: 1px solid var(--border);
    margin-bottom: 20px;
    overflow: hidden;
}
.section-header {
    background: var(--accent);
    color: #fff;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.section-header h2 {
    margin: 0;
    font-weight: 600;
    font-size: 15px;
    display: flex; align-items: center; gap: 8px;
}

.request-status {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-approved { background: #dcfce7; color: #166534; }
.status-rejected { background: #fee2e2; color: #991b1b; }

.my-request-item {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.my-request-item:last-child { border-bottom: none; }

.request-group-info { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; }
.group-icon {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--accent), #457b9d);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 15px; flex-shrink: 0;
}
.request-details { min-width: 0; }
.request-details h3 {
    margin: 0 0 3px 0;
    font-weight: 600;
    font-size: 14px;
    color: var(--text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.request-details p {
    margin: 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.4;
}

.groups-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    padding: 18px;
}
.group-card {
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 18px;
    transition: all .2s;
    background: var(--card);
}
.group-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
}
.group-card-header {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 10px;
}
.group-avatar {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 15px; font-weight: 700; flex-shrink: 0;
}
.group-card h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
}
.group-card p {
    margin: 0 0 12px 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.5;
}
.group-stats {
    display: flex; flex-wrap: wrap; gap: 10px;
    margin-bottom: 14px;
    font-size: 12px;
    color: var(--muted);
}
.group-stats span {
    display: flex; align-items: center; gap: 4px;
}

.btn {
    padding: 10px 16px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    transition: all .2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    font-family: inherit;
}
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { opacity: .9; transform: translateY(-1px); }
.btn-secondary { background: #94a3b8; color: #fff; }
.btn:disabled {
    background: #e2e8f0;
    color: #94a3b8;
    cursor: not-allowed;
    transform: none;
}
[data-theme="dark"] .btn:disabled {
    background: #334155;
    color: #64748b;
}

.notification {
    position: fixed;
    top: 20px; right: 20px;
    padding: 14px 18px;
    border-radius: 12px;
    color: #fff;
    font-weight: 500;
    font-size: 14px;
    transform: translateX(120%);
    transition: transform .3s ease;
    z-index: 9999;
    max-width: 90vw;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}
.notification.show { transform: translateX(0); }
.notification.success { background: var(--success); }
.notification.error { background: var(--danger); }

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--muted);
}
.empty-state i { font-size: 2.5rem; opacity: .3; margin-bottom: 12px; display: block; }
.empty-state h3 { margin: 0 0 6px 0; color: var(--text); font-size: 16px; }
.empty-state p { margin: 0; font-size: 13px; }

/* Hamburger */
.menu-toggle {
    display: none;
    position: fixed;
    top: 14px; left: 14px;
    z-index: 2000;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 9px 11px;
    font-size: 18px;
    cursor: pointer;
}

/* ── MOBILE ── */
@media (max-width: 768px) {
    .menu-toggle { display: block; }

    .sidebar {
        left: -100%;
        transition: left .25s ease;
        width: 260px;
        max-width: 85vw;
        box-shadow: 0 0 20px rgba(0,0,0,0.15);
    }
    .sidebar.open { left: 0; }

    .main-content {
        margin-left: 0 !important;
        padding: 70px 12px 20px !important;
    }

    .header {
        padding: 16px !important;
    }
    .header h1 { font-size: 1.15rem !important; }

    .section-header {
        padding: 12px 14px !important;
        flex-wrap: wrap;
    }
    .section-header h2 { font-size: 14px !important; }

    .my-request-item {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 10px;
        padding: 14px !important;
    }
    .request-group-info { width: 100%; }
    .request-details h3 {
        white-space: normal;
    }

    .groups-grid {
        grid-template-columns: 1fr !important;
        padding: 14px !important;
        gap: 12px !important;
    }
    .group-card { padding: 14px !important; }

    .notification {
        left: 12px; right: 12px;
        top: auto; bottom: 20px;
        max-width: none;
    }
}

@media (max-width: 480px) {
    .main-content { padding: 65px 10px 16px !important; }
    .header h1 { font-size: 1.05rem !important; }
    .group-stats { gap: 8px; font-size: 11px; }
}
    </style>
</head>
<body>
<button class="menu-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')">
    <i class="fas fa-bars"></i>
</button>

    <!-- === SIDEBAR === -->
  <aside class="sidebar">
    <div class="top">
        <div class="sb-avatar">
            <?php if ($profilePicture && file_exists($profilePicture)): ?>
                <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="">
            <?php else: ?>
                <?php echo htmlspecialchars($userInitial); ?>
            <?php endif; ?>
            <div class="dot dot-<?php echo htmlspecialchars($currentStatus); ?>"></div>
        </div>
        <div>
            <div class="sb-name"><?php echo htmlspecialchars($userName); ?></div>
            <div class="sb-role"><?php echo ucfirst($currentStatus); ?> · <?php echo htmlspecialchars($currentRole); ?></div>
        </div>
    </div>
    <ul>
        <li><a href="member_dashboard.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
        <li><a href="member_tasks.php"><i class="fas fa-tasks"></i><span>My Tasks</span></a></li>
        <li><a href="member_projects.php"><i class="fas fa-briefcase"></i><span>Projects</span></a></li>
        <li><a href="member_messages.php"><i class="fas fa-comments"></i><span>Messages</span></a></li>
        <li><a href="upload_timesheet_progress.php"><i class="fas fa-file-signature"></i><span>Timesheet Submissions</span></a></li>
        <li><a href="member_group.php" class="active"><i class="fas fa-users"></i><span>Groups</span></a></li>
        <li><a href="member_Group_chat.php"><i class="fas fa-envelope"></i><span>Group Messages</span></a></li>
        <li><a href="member_attendance.php"><i class="fas fa-check-circle"></i><span>Attendance</span></a></li>
        <li><a href="leave_application.php"><i class="fa-solid fa-calendar-check"></i><span>Leave Application</span></a></li>
        <li><a href="https://investhoodprojects.com/tidytracker/tidytrack/meeting/member_meetings.php"><i class="fas fa-video"></i><span>Meetings</span></a></li>
        <li><a href="member_help.php"><i class="fas fa-life-ring"></i><span>TSM ASSISTANCE</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        <li style="margin-top:auto;"><a href="profile.php"><i class="fas fa-user-circle"></i><span>Profile Settings</span></a></li>
    </ul>
</aside>

    <!-- === MAIN CONTENT  === -->
    <div class="main-content">
        <div class="header">
            <h1><i class="fas fa-users"></i> Join Groups</h1>
            <p>Request to join groups and manage your pending requests</p>
        </div>

        <div class="my-requests">
            <div class="section-header">
                <h2><i class="fas fa-clock"></i> My Requests</h2>
                <span class="request-status status-<?php echo $pendingCount > 0 ? 'pending' : 'approved'; ?>" id="requestCount">
                    <?php echo $pendingCount > 0 ? "$pendingCount pending" : 'No pending requests'; ?>
                </span>
            </div>
            <div id="myRequestsList">
                <?php if (empty($requests)): ?>
                    <div class="empty-state">
                        <i class="fas fa-info-circle"></i>
                        <h3>No Requests</h3>
                        <p>You haven't sent any group join requests yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($requests as $request): ?>
                        <div class="my-request-item">
                            <div class="request-group-info">
                                <div class="group-icon">
                                    <i class="<?php echo htmlspecialchars($request['group_icon']); ?>"></i>
                                </div>
                                <div class="request-details">
                                    <h3><?php echo htmlspecialchars($request['group_name']); ?></h3>
                                    <p>Requested <?php echo date('M d, Y H:i', strtotime($request['requested_at'])); ?> • 
                                    <?php echo $request['status'] === 'pending' ? 'Waiting for approval' : ($request['status'] === 'approved' ? 'Welcome to the group!' : 'Request declined'); ?></p>
                                </div>
                            </div>
                            <div class="request-status status-<?php echo htmlspecialchars($request['status']); ?>">
                                <?php echo ucfirst($request['status']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="available-groups">
            <div class="section-header">
                <h2><i class="fas fa-search"></i> Available Groups</h2>
            </div>
            <div class="groups-grid">
                <?php if (empty($groups)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No Available Groups</h3>
                        <p>You are a member of all available groups or no groups exist.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($groups as $group): ?>
                        <?php
                        $requestStatus = null;
                        foreach ($requests as $req) {
                            if ($req['group_id'] === $group['group_id']) {
                                $requestStatus = $req['status'];
                                break;
                            }
                        }
                        ?>
                        <div class="group-card">
                            <div class="group-card-header">
                                <div class="group-avatar"><?php echo htmlspecialchars(substr($group['group_name'], 0, 2)); ?></div>
                                <h3><?php echo htmlspecialchars($group['group_name']); ?></h3>
                            </div>
                            <p><?php echo htmlspecialchars($group['description']); ?></p>
                            <div class="group-stats">
                                <span><i class="fas fa-users"></i> <?php echo $group['member_count']; ?> members</span>
                                <span><i class="fas fa-<?php echo $group['group_type'] === 'public' ? 'globe' : 'lock'; ?>"></i> <?php echo ucfirst($group['group_type']); ?></span>
                                <span><i class="fas fa-chart-line"></i> <?php echo $group['member_count'] > 10 ? 'Very Active' : 'Active'; ?></span>
                            </div>
                            <button class="btn <?php echo $requestStatus ? 'btn-secondary' : 'btn-primary'; ?>" 
                                    data-group-id="<?php echo $group['group_id']; ?>" 
                                    data-group-name="<?php echo htmlspecialchars($group['group_name']); ?>" 
                                    data-group-icon="<?php echo htmlspecialchars($group['group_icon']); ?>"
                                    <?php echo $requestStatus ? 'disabled' : ''; ?>>
                                <i class="fas fa-<?php echo $requestStatus ? 'check' : 'paper-plane'; ?>"></i>
                                <?php echo $requestStatus === 'pending' ? 'Request Sent' : ($requestStatus === 'rejected' ? 'Request Rejected' : 'Request to Join'); ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="notification" class="notification"></div>

    <!-- === DARK MODE SCRIPT (Same as dashboard) === -->
    <script src="theme_switcher.js"></script>
    <script>
        // Load saved theme
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);

        function requestJoin(groupId, groupName, groupIcon, button) {
            fetch('join_group.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ groupId: groupId })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    button.disabled = true;
                    button.classList.remove('btn-primary');
                    button.classList.add('btn-secondary');
                    button.innerHTML = '<i class="fas fa-check"></i> Request Sent';
                    addToMyRequests(groupName, groupIcon);
                    showNotification(data.message, 'success');
                    updateRequestCount();
                } else {
                    showNotification(data.error, 'error');
                }
            });
        }

        function addToMyRequests(groupName, groupIcon) {
            const list = document.getElementById('myRequestsList');
            const empty = list.querySelector('.empty-state');
            if (empty) empty.remove();

            const item = document.createElement('div');
            item.className = 'my-request-item';
            item.style.opacity = '0';
            item.innerHTML = `
                <div class="request-group-info">
                    <div class="group-icon"><i class="${groupIcon}"></i></div>
                    <div class="request-details">
                        <h3>${groupName}</h3>
                        <p>Requested just now • Waiting for approval</p>
                    </div>
                </div>
                <div class="request-status status-pending">Pending</div>
            `;
            list.prepend(item);
            setTimeout(() => item.style.opacity = '1', 100);
        }

        function updateRequestCount() {
            const pending = document.querySelectorAll('.status-pending').length;
            const el = document.getElementById('requestCount');
            if (pending === 0) {
                el.textContent = 'No pending requests';
                el.className = 'request-status status-approved';
            } else {
                el.textContent = `${pending} pending`;
                el.className = 'request-status status-pending';
            }
        }

        function showNotification(msg, type) {
            const n = document.getElementById('notification');
            n.textContent = msg;
            n.className = `notification ${type} show`;
            setTimeout(() => n.classList.remove('show'), 4000);
        }

        document.querySelectorAll('.btn-primary').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.groupId;
                const name = btn.dataset.groupName;
                const icon = btn.dataset.groupIcon;
                requestJoin(id, name, icon, btn);
            });
        });
        
  
document.querySelectorAll('.sidebar a').forEach(link => {
    link.addEventListener('click', () => {
        document.querySelector('.sidebar').classList.remove('open');
    });
});

    </script>
</body>
</html>