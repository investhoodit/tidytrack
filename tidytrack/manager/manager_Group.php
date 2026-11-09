<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Manager';
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
$currentRole = $userData['role'] ?? 'Manager';
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

        /* === DARK MODE VARIABLES === */
        :root {
            --accent: #1d3557;
            --bg: #eef2f5;
            --card: #ffffff;
            --text: #1f2937;
            --border: #e0e0e0;
            --muted: #6b7280;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
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

        body { 
            font-family: 'Poppins', sans-serif; 
            margin: 0; 
            background: var(--bg); 
            color: var(--text);
            display: flex; 
            min-height: 100vh; 
        }

        .main-content {
            flex: 1;
            padding: 24px;
        }

        /* HEADER */
        .header {
            background: var(--card);
            padding: 20px 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            position: relative;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header h1 {
            margin: 0 0 8px 0;
            color: var(--accent);
        }
        .header p { margin: 0; color: var(--muted); }

        /* BACK BUTTON */
        .back-btn {
            position: absolute;
            top: 20px;
            right: 24px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--accent);
            color: #fff;
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: 0.3s ease;
        }
        .back-btn:hover {
            background: #0f2a44;
            transform: translateY(-1px);
        }

        /* REQUEST LIST + GROUPS */
        .my-requests, .available-groups {
            background: var(--card);
            border-radius: 12px;
            margin-bottom: 24px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .section-header {
            background: var(--accent);
            color: #fff;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-header h2 { margin: 0; }

        .request-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-pending { background: rgba(255,193,7,0.2); color: #856404; }
        .status-approved { background: rgba(40,167,69,0.2); color: #155724; }
        .status-rejected { background: rgba(220,53,69,0.2); color: #721c24; }

        .my-request-item {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .group-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--accent), #457b9d);
            border-radius: 8px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .groups-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            padding: 24px;
        }
        .group-card {
            background: var(--card);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: 0.3s ease;
        }
        .group-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 18px rgba(0,0,0,0.1);
        }

        .group-avatar {
            width: 48px; height: 48px;
            background: linear-gradient(135deg,#6c5ce7,#a29bfe);
            border-radius: 12px;
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:20px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
            font-weight: 500;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: #0f2a44; }
        .btn-secondary { background: #6c757d; color: #fff; }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px;
            border-radius: 8px;
            color:#fff;
            font-weight:600;
            transform: translateX(400px);
            transition: 0.3s ease;
        }
        .notification.show { transform: translateX(0); }
        .notification.success { background: var(--success); }
        .notification.error { background: var(--danger); }

        .empty-state {
            text-align:center;
            padding: 40px;
            color: var(--muted);
        }

        /* FLOATING DARK MODE BUTTON */
        .dark-toggle {
            position: fixed;
            bottom: 22px;
            right: 22px;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--accent);
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            cursor:pointer;
            font-size:18px;
            box-shadow:0 4px 10px rgba(0,0,0,0.3);
            transition: 0.3s ease;
            z-index:1500;
        }
        .dark-toggle:hover {
            transform: scale(1.1);
        }
    </style>
</head>

<body>

    <div class="main-content">
        <div class="header">
            <h1><i class="fas fa-users"></i> Join Groups</h1>
            <p>Request to join groups and manage your pending requests</p>

            <!-- BACK TO DASHBOARD BUTTON -->
            <a href="manager_index.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
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
                                    <p>Requested <?php echo date('M d, Y H:i', strtotime($request['requested_at'])); ?></p>
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
                        <p>You are a member of all groups or none exist.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($groups as $group): ?>
                        <?php
                        $requestStatus = null;
                        foreach ($requests as $r) {
                            if ($r['group_id'] === $group['group_id']) {
                                $requestStatus = $r['status'];
                                break;
                            }
                        }
                        ?>
                        <div class="group-card">
                            <div class="group-card-header" style="display:flex; align-items:center; gap:12px;">
                                <div class="group-avatar"><?php echo substr($group['group_name'],0,2); ?></div>
                                <h3><?php echo htmlspecialchars($group['group_name']); ?></h3>
                            </div>
                            <p><?php echo htmlspecialchars($group['description']); ?></p>

                            <button class="btn <?php echo $requestStatus ? 'btn-secondary' : 'btn-primary'; ?>"
                                data-group-id="<?php echo $group['group_id']; ?>"
                                data-group-name="<?php echo htmlspecialchars($group['group_name']); ?>"
                                data-group-icon="<?php echo htmlspecialchars($group['group_icon']); ?>"
                                <?php echo $requestStatus ? 'disabled' : ''; ?>>
                                <i class="fas fa-<?php echo $requestStatus ? 'check' : 'paper-plane'; ?>"></i>
                                <?php echo $requestStatus ? ucfirst($requestStatus) : 'Request to Join'; ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Notification Box -->
    <div id="notification" class="notification"></div>

    <!-- DARK MODE FLOATING BUTTON -->
    <div class="dark-toggle" id="darkToggle">
        <i class="fas fa-moon"></i>
    </div>

    <script src="theme_switcher.js"></script>

    <script>
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);

        // Dark Mode Icon Toggle
        function updateDarkIcon() {
            const theme = document.documentElement.getAttribute('data-theme');
            document.getElementById('darkToggle').innerHTML =
                theme === 'dark' ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        }

        document.getElementById('darkToggle').onclick = () => {
            const current = document.documentElement.getAttribute('data-theme');
            const next = current === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
            updateDarkIcon();
        };
        updateDarkIcon();

        // Request to Join
        document.querySelectorAll('.btn-primary').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.groupId;
                const name = btn.dataset.groupName;
                const icon = btn.dataset.groupIcon;
                requestJoin(id, name, icon, btn);
            });
        });

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
                    showNotification(data.message, 'success');
                } else {
                    showNotification(data.error, 'error');
                }
            });
        }

        function showNotification(msg, type) {
            const n = document.getElementById('notification');
            n.textContent = msg;
            n.className = `notification ${type} show`;
            setTimeout(() => n.classList.remove('show'), 4000);
        }

    </script>

</body>
</html>
