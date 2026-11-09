<?php
// member_messages.php
session_start();
include '../dp.php'; // your DB connect

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Leader';
$userInitial = strtoupper($userName[0]);

if (!$userId) die("Unauthorized access. Please log in.");
// Fetch current user
$stmt = $conn->prepare("SELECT profile_picture, status, role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$currentUser = $result->fetch_assoc();
$currentProfilePicture = $currentUser['profile_picture'] ?? null;
$currentStatus = $currentUser['status'] ?? 'offline';
$currentRole = $currentUser['role'] ?? 'Leader';
$stmt->close();

$status_info = [
    'online' => ['name' => 'Online', 'color' => 'status-online'],
    'out_of_office' => ['name' => 'Out of Office', 'color' => 'status-out_of_office'],
    'sick' => ['name' => 'Sick', 'color' => 'status-sick'],
    'focusing' => ['name' => 'Focusing', 'color' => 'status-focusing'],
    'in_meeting' => ['name' => 'In Meeting', 'color' => 'status-in_meeting'],
    'offline' => ['name' => 'Offline', 'color' => 'status-offline']
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <title>Messages - INVESTHOOD_TSM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
    /* ===== Theme Toggle Button (Same Behaviour as Back Button) ===== */

.theme-btn {
    margin-left:auto;
    background: var(--bg);
    color: var(--accent);
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all .2s ease-in-out;
    border: none;
    cursor: pointer;
}

.theme-btn:hover {
    background: var(--accent);
    color: #fff;
}
        /* === THEME VARIABLES === */
        :root {
            --accent: #1d3557;
            --bubble-me: #d9fdd3;
            --bubble-them: #ffffff;
            --bg: #eef2f5;
            --card: #ffffff;
            --text: #1f2937;
            --border: #e0e0e0;
            --muted: #6b7280;
            --unread-bg: #e94560;
        }
       [data-theme="dark"] {
    --accent: #60a5fa;
    --bubble-me: #3b82f6;       /* brighter for contrast */
    --bubble-them: #1e293b;
    --bg: #0f172a;
    --card: #1e293b;
    --text: #e2e8f0;
    --border: #334155;
    --muted: #94a3b8;
    --unread-bg: #f87171;
}

        * { box-sizing: border-box; }
        body { 
            font-family: 'Poppins', sans-serif; 
            margin: 0; 
            height: 100vh; 
            background: var(--bg); 
            color: var(--text);
            display: flex; 
            overflow: hidden; 
        }

        /* Users column */
        .users-col { 
            width: 320px; 
            background: var(--card); 
            border-right: 1px solid var(--border); 
            display: flex; 
            flex-direction: column; 
        }
        .users-col .top { 
            padding: 18px; 
            border-bottom: 1px solid var(--border); 
            display: flex; 
            align-items: center; 
            gap: 12px; 
        }
        .users-col .top .me { 
            width: 44px; 
            height: 44px; 
            border-radius: 50%; 
            background: var(--accent); 
            color: #fff; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: 700; 
            position: relative;
        }
        .users-col .top .me img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .users-col .search { 
            padding: 12px; 
            border-bottom: 1px solid var(--border); 
        }
        .users-col .search input { 
            width: 100%; 
            padding: 10px; 
            border-radius: 8px; 
            border: 1px solid var(--border); 
            background: var(--card); 
            color: var(--text);
        }
        .user-list { 
            flex: 1; 
            overflow: auto; 
        }
        .user-item { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            padding: 12px 16px; 
            border-bottom: 1px solid var(--border); 
            cursor: pointer; 
            position: relative; 
        }
        .user-item:hover { 
            background: var(--bg); 
        }
        .user-item.active { 
            background: #f6fbf6; 
        }
        .avatar { 
            width: 48px; 
            height: 48px; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: #fff; 
            font-weight: 700; 
            position: relative;
        }
        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .user-info { 
            flex: 1;
        }
        .user-info .name { 
            font-weight: 600; 
        }
        .user-info .last { 
            font-size: 12px; 
            color: var(--muted); 
            margin-top: 4px; 
        }
        .unread-count {
            position: absolute;
            top: 12px;
            right: 16px;
            background: var(--unread-bg);
            color: white;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 12px;
            min-width: 20px;
            text-align: center;
        }
        .status-indicator {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 2px solid var(--card);
        }
        .status-ready { background-color: #00d4aa; box-shadow: 0 0 15px rgba(0, 212, 170, 0.4); }
        .status-out_of_office { background-color: #ffa726; box-shadow: 0 0 15px rgba(255, 167, 38, 0.4); }
        .status-sick { background-color: #e94560; box-shadow: 0 0 15px rgba(233, 69, 96, 0.4); }
        .status-focusing { background-color: #9c27b0; box-shadow: 0 0 15px rgba(156, 39, 176, 0.4); }
        .status-in_meeting { background-color: #2196f3; box-shadow: 0 0 15px rgba(33, 150, 243, 0.4); }
        .status-offline { background-color: #cccccc; box-shadow: 0 0 15px rgba(204, 204, 204, 0.4); }

        .profile-popup {
            display: none;
            position: absolute;
            top: 60px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--card);
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            text-align: center;
            width: 200px;
        }
        .user-item:hover .profile-popup,
        .top:hover .profile-popup,
        .chat-header:hover .profile-popup {
            display: block;
        }
        .popup-avatar {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: linear-gradient(135deg, #e94560, #f39c12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.5rem;
            font-weight: 800;
            color: white;
            position: relative;
            overflow: hidden;
            border: 4px solid #e94560;
            box-shadow: 0 0 30px rgba(233, 69, 96, 0.3), inset 0 0 20px rgba(0, 0, 0, 0.2);
            margin: 0 auto;
        }
        .popup-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .popup-status {
            margin-top: 0.5rem;
            font-size: 1rem;
            color: var(--accent);
            font-weight: 500;
        }
        .popup-status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        .popup-role {
            font-size: 0.9rem;
            color: var(--muted);
            margin-top: 0.2rem;
        }

        /* Chat area */
        .chat-area { 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            min-width: 0; 
        }
        .chat-header { 
            height: 72px; 
            background: var(--card); 
            border-bottom: 1px solid var(--border); 
            display: flex; 
            align-items: center; 
            padding: 12px 18px; 
            gap: 14px; 
            position: relative; 
        }
        .chat-header .avatar { 
            width: 48px; 
            height: 48px; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: 700; 
            color: #fff; 
        }
        .chat-header .title { 
            font-weight: 700; 
        }
        .chat-window { 
    flex: 1; 
    overflow: auto; 
    padding: 20px; 
    background: var(--bg);
}
        .messages { 
            display: flex; 
            flex-direction: column; 
            gap: 10px; 
            max-width: 900px; 
            margin: 0 auto; 
        }
.bubble { 
    padding: 10px 14px; 
    border-radius: 14px; 
    max-width: 70%; 
    font-size: 14px; 
    line-height: 1.4; 
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    color: var(--text);
}

.bubble.me { 
    align-self: flex-end; 
    background: var(--bubble-me); 
}

.bubble.them { 
    align-self: flex-start; 
    background: var(--bubble-them); 
}
        .bubble small { 
            display: block; 
            color: var(--muted); 
            margin-top: 6px; 
            font-size: 12px; 
        }
        .input-row { 
            height: 78px; 
            background: var(--card); 
            border-top: 1px solid var(--border); 
            display: flex; 
            align-items: center; 
            padding: 10px 14px; 
            gap: 10px; 
        }
        .message-input { 
            flex: 1; 
            display: flex; 
            align-items: center; 
            padding: 8px 12px; 
            border-radius: 30px; 
            border: 1px solid var(--border); 
            background: var(--bg); 
        }
        .message-input input { 
            flex: 1; 
            border: 0; 
            background: transparent; 
            outline: none; 
            padding: 8px 10px; 
            font-size: 15px; 
            color: var(--text);
        }
        .send-btn { 
            width: 44px; 
            height: 44px; 
            border-radius: 50%; 
            background: var(--accent); 
            color: #fff; 
            border: none; 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }

        /* Notification */
        .notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--accent);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 300px;
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
        }
        .notification .avatar { width: 40px; height: 40px; }
        .notification-text { flex: 1; font-size: 14px; }
        .notification-text strong { font-weight: 600; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        /* Back Button */
        .back-btn {
            margin-left: auto;
            background: var(--bg);
            color: var(--accent);
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all .2s;
        }
        .back-btn:hover {
            background: var(--accent);
            color: #fff;
        }

        @media (max-width: 900px) { 
            .users-col { width: 90px; } 
            .user-item .user-info { display: none; } 
            .chat-header .title { font-size: 14px; } 
            .notification { max-width: 80%; }
            .unread-count { right: 8px; top: 8px; }
            .back-btn { font-size: 13px; padding: 6px 12px; }
        }
    </style>
</head>
<body>

    <!-- LEFT: users list -->
    <div class="users-col">
        <div class="top">
            <div class="me">
                <?php if ($currentProfilePicture && file_exists($currentProfilePicture)): ?>
                    <img src="<?php echo htmlspecialchars($currentProfilePicture); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo htmlspecialchars($userInitial); ?>
                <?php endif; ?>
                <div class="status-indicator status-<?php echo htmlspecialchars($currentStatus); ?>"></div>
                <div class="profile-popup">
                    <div class="popup-avatar">
                        <?php if ($currentProfilePicture && file_exists($currentProfilePicture)): ?>
                            <img src="<?php echo htmlspecialchars($currentProfilePicture); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo htmlspecialchars($userInitial); ?>
                        <?php endif; ?>
                    </div>
                    <div class="popup-status">
                        <span class="popup-status-indicator status-<?php echo htmlspecialchars($currentStatus); ?>"></span>
                        <?php echo htmlspecialchars($status_info[$currentStatus]['name'] ?? 'Unknown'); ?>
                    </div>
                    <div class="popup-role"><?php echo htmlspecialchars($currentRole); ?></div>
                </div>
            </div>
            <div>
                <div style="font-weight:700"><?php echo htmlspecialchars($userName); ?></div>
                <div style="font-size:12px;color:#777"><?php echo htmlspecialchars($status_info[$currentStatus]['name'] ?? 'Unknown'); ?> | <?php echo htmlspecialchars($currentRole); ?></div>
            </div>
        </div>

        <div class="search">
            <input id="searchUsers" placeholder="Search users..." />
        </div>

        <div class="user-list" id="userList">
            <?php
            $stmt = $conn->prepare("
                SELECT u.user_id, u.name, u.profile_picture, u.status, u.role, 
                       (SELECT COUNT(*) FROM messages m WHERE m.receiver_id = ? AND m.sender_id = u.user_id AND m.is_read = 0) AS unread_count
                FROM users u 
                WHERE u.user_id != ? 
                ORDER BY u.name ASC
            ");
            $stmt->bind_param("ii", $userId, $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($u = $res->fetch_assoc()) {
                $uid = (int)$u['user_id'];
                $name = htmlspecialchars($u['name']);
                $initial = !empty($u['name']) ? strtoupper($u['name'][0]) : '?';
                $color = substr(md5($uid), 0, 6);
                $profile_picture = $u['profile_picture'] ?? null;
                $status = $u['status'] ?? 'offline';
                $role = $u['role'] ?? 'Leader';
                $unread_count = (int)$u['unread_count'];
                echo "<div class='user-item' data-id='{$uid}' data-name='{$name}' data-role='{$role}'>
                        <div class='avatar' style='background:#{$color}'>";
                if ($profile_picture && file_exists($profile_picture)) {
                    echo "<img src='" . htmlspecialchars($profile_picture) . "' alt='Profile'>";
                } else {
                    echo $initial;
                }
                echo "</div>
                        <div class='user-info'>
                            <div class='name'>{$name}</div>
                            <div class='last'>" . htmlspecialchars($status_info[$status]['name'] ?? 'Unknown') . " | {$role}</div>
                        </div>
                        <div class='status-indicator status-" . htmlspecialchars($status) . "'></div>";
                if ($unread_count > 0) {
                    echo "<div class='unread-count'>{$unread_count}</div>";
                }
                echo "<div class='profile-popup'>
                            <div class='popup-avatar'>";
                if ($profile_picture && file_exists($profile_picture)) {
                    echo "<img src='" . htmlspecialchars($profile_picture) . "' alt='Profile'>";
                } else {
                    echo $initial;
                }
                echo "</div>
                            <div class='popup-status'>
                                <span class='popup-status-indicator status-" . htmlspecialchars($status) . "'></span>
                                " . htmlspecialchars($status_info[$status]['name'] ?? 'Unknown') . "
                            </div>
                            <div class='popup-role'>{$role}</div>
                        </div>
                    </div>";
            }
            $stmt->close();
            $conn->close();
            ?>
        </div>
    </div>

    <!-- RIGHT: chat area -->
    <div class="chat-area">
        <div class="chat-header" id="chatHeader">
            <div class="avatar" id="chatAvatar">?</div>
            <div>
                <div class="title" id="chatName">Select a chat</div>
                <div style="font-size:13px;color:#666" id="chatSubtitle">Select a user</div>
            </div>
            <div class="status-indicator" id="chatStatusIndicator"></div>
            <div class="profile-popup" id="chatPopup" style="display: none;">
                <div class="popup-avatar" id="chatPopupAvatar">?</div>
                <div class="popup-status" id="chatPopupStatus">
                    <span class="popup-status-indicator"></span>
                    Select a user
                </div>
                <div class="popup-role" id="chatPopupRole"></div>
            </div>
             <button id="themeToggle" style="
    margin-left:auto;
    padding:8px 14px;
    border-radius:20px;
    border:1px solid var(--border);
    background:var(--card);
    color:var(--text);
    cursor:pointer;
    font-weight:600;
">
    <span id="themeLabel">Dark mode</span>
</button>

<a href="leader_index.php" class="back-btn">
    <i class="fas fa-arrow-left"></i> Back to Dashboard
</a>
        </div>

        <div class="chat-window" id="chatWindow">
            <div class="messages" id="messages"></div>
        </div>

        <div class="input-row">
            <div class="message-input">
                <input id="textInput" placeholder="Type a message" autocomplete="off"/>
            </div>
            <button class="send-btn" id="sendBtn" title="Send"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>

    <script>
        // === DARK MODE ===
      

        let selectedUserId = null;
        let lastMessageCount = {};

        const userList = document.getElementById('userList');
        const messagesBox = document.getElementById('messages');
        const chatName = document.getElementById('chatName');
        const chatAvatar = document.getElementById('chatAvatar');
        const chatSubtitle = document.getElementById('chatSubtitle');
        const chatStatusIndicator = document.getElementById('chatStatusIndicator');
        const chatPopup = document.getElementById('chatPopup');
        const chatPopupAvatar = document.getElementById('chatPopupAvatar');
        const chatPopupStatus = document.getElementById('chatPopupStatus');
        const chatPopupRole = document.getElementById('chatPopupRole');
        const textInput = document.getElementById('textInput');
        const sendBtn = document.getElementById('sendBtn');

         const statusInfo = {
            'online': { name: 'Online', class: 'status-online' },
            'out_of_office': { name: 'Out of Office', class: 'status-out_of_office' },
            'sick': { name: 'Sick', class: 'status-sick' },
            'focusing': { name: 'Focusing', class: 'status-focusing' },
            'in_meeting': { name: 'In Meeting', class: 'status-in_meeting' },
            'offline': { name: 'Offline', class: 'status-offline' }
        };

        if (Notification.permission !== 'granted' && Notification.permission !== 'denied') {
            Notification.requestPermission();
        }

        function timeNow() {
            const d = new Date();
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        userList.addEventListener('click', (e) => {
            const item = e.target.closest('.user-item');
            if (!item) return;
            document.querySelectorAll('.user-item').forEach(x => x.classList.remove('active'));
            item.classList.add('active');
            selectedUserId = item.dataset.id;
            const name = item.dataset.name;
            const role = item.dataset.role;

            chatName.textContent = name;
            chatAvatar.innerHTML = '';
            const avatarImg = item.querySelector('.avatar img');
            if (avatarImg) {
                const img = document.createElement('img');
                img.src = avatarImg.src;
                img.alt = 'Profile';
                chatAvatar.appendChild(img);
            } else {
                chatAvatar.textContent = name[0].toUpperCase();
                chatAvatar.style.background = item.querySelector('.avatar').style.background;
            }

            const statusKey = item.querySelector('.status-indicator').classList[1]?.replace('status-', '') || 'offline';
            chatSubtitle.textContent = `${statusInfo[statusKey].name} | ${role}`;
            chatStatusIndicator.className = 'status-indicator status-' + statusKey;

            // Popup
            chatPopupAvatar.innerHTML = avatarImg ? `<img src="${avatarImg.src}" alt="Profile">` : name[0].toUpperCase();
            chatPopupStatus.innerHTML = `<span class="popup-status-indicator status-${statusKey}"></span>${statusInfo[statusKey].name}`;
            chatPopupRole.textContent = role;

            // === REMOVE UNREAD BADGE + MARK READ ===
            const badge = item.querySelector('.unread-count');
            if (badge) badge.remove();
            markMessagesAsRead();
            loadMessages();
        });

        document.getElementById('searchUsers').addEventListener('input', function() {
            const q = this.value.toLowerCase();
            document.querySelectorAll('.user-item').forEach(it => {
                const name = it.dataset.name.toLowerCase();
                it.style.display = name.includes(q) ? '' : 'none';
            });
        });

        function loadMessages() {
            if (!selectedUserId) {
                checkForNewMessages();
                return;
            }
            fetch('load_messages.php?user_id=' + selectedUserId)
                .then(r => r.text())
                .then(html => {
                    messagesBox.innerHTML = html;
                    document.getElementById('chatWindow').scrollTop = document.getElementById('chatWindow').scrollHeight;
                    checkForNewMessages();
                });
        }

        function markMessagesAsRead() {
            if (!selectedUserId) return;
            fetch('mark_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                //body: JSON.stringify({ sender_id: selectedUserId })
                body: JSON.stringify({ sender_id: parseInt(selectedUserId) })
            }).then(r => r.json()).then(resp => {
                if (resp.success) {
                    const badge = document.querySelector(`.user-item[data-id="${selectedUserId}"] .unread-count`);
                    if (badge) badge.remove();
                }
            });
        }

        function checkForNewMessages() {
            fetch('check_messages.php')
                .then(r => r.json())
                .then(data => {
                    document.querySelectorAll('.user-item').forEach(item => {
                        const userId = item.dataset.id;
                        const badge = item.querySelector('.unread-count');
                        const msgData = data.find(msg => msg.sender_id == userId);
                        const count = msgData ? msgData.count : 0;

                        if (!lastMessageCount[userId]) lastMessageCount[userId] = 0;
                        if (count > lastMessageCount[userId] && userId != selectedUserId) {
                            showNotification(msgData.sender_name, msgData.sender_avatar);
                            lastMessageCount[userId] = count;
                        }

                        if (badge) badge.remove();
                        if (count > 0) {
                            const newBadge = document.createElement('div');
                            newBadge.className = 'unread-count';
                            newBadge.textContent = count;
                            item.appendChild(newBadge);
                        }
                    });
                });
        }

        function showNotification(name, avatar) {
            if (Notification.permission === 'granted') {
                new Notification('New Message from ' + name, { body: 'You have a new message!', icon: avatar.startsWith('Uploads/profiles/') ? avatar : undefined });
            }
            const notif = document.createElement('div');
            notif.className = 'notification';
            notif.innerHTML = `
                <div class="avatar" style="${avatar.startsWith('Uploads/profiles/') ? '' : 'background:#' + (Math.random().toString(16).slice(2, 8))}">
                    ${avatar.startsWith('Uploads/profiles/') ? `<img src="${avatar}" alt="Profile">` : name[0]}
                </div>
                <div class="notification-text">
                    <strong>${name}</strong><br>New message received
                </div>`;
            document.body.appendChild(notif);
            setTimeout(() => notif.remove(), 5000);
        }

        function sendMessage() {
            if (!selectedUserId) return alert('Select a user');
            const text = textInput.value.trim();
            if (!text) return;

            const fd = new FormData();
            fd.append('receiver_id', selectedUserId);
            fd.append('message', text);

            fetch('send_message.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(resp => {
                    if (resp.success) {
                        appendMyBubble(text);
                        textInput.value = '';
                        setTimeout(loadMessages, 500);
                    }
                });
        }

        function appendMyBubble(text) {
            const div = document.createElement('div');
            div.className = 'bubble me';
            div.innerHTML = `<div>${escapeHtml(text)}</div><small>${timeNow()}</small>`;
            messagesBox.appendChild(div);
            document.getElementById('chatWindow').scrollTop = document.getElementById('chatWindow').scrollHeight;
        }

        textInput.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        sendBtn.addEventListener('click', sendMessage);

        function escapeHtml(s) {
            return (s || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
        }

        setInterval(() => {
            if (selectedUserId) loadMessages();
            else checkForNewMessages();
        }, 4000);
        /* ===== DARK MODE TOGGLE ===== */
/* ===== DARK MODE ENGINE (FINAL PRODUCTION VERSION) ===== */
(function(){
    const root = document.documentElement;
    const btn = document.getElementById("themeToggle");
    const label = document.getElementById("themeLabel");

    function getPreferredTheme(){
        const saved = localStorage.getItem("theme");

        const prefersDark =
            window.matchMedia &&
            window.matchMedia("(prefers-color-scheme: dark)").matches;

        return saved ? saved : (prefersDark ? "dark" : "light");
    }

    function applyTheme(theme){
        root.setAttribute("data-theme", theme);
        localStorage.setItem("theme", theme);

        if(label){
            label.textContent = theme === "dark"
                ? "Light mode"
                : "Dark mode";
        }
    }

    function toggleTheme(){
        const current = root.getAttribute("data-theme") || "light";
        applyTheme(current === "dark" ? "light" : "dark");
    }

    // Initialize theme on load
    applyTheme(getPreferredTheme());

    if(btn){
        btn.addEventListener("click", toggleTheme);
    }
})();
    </script>
</body>
</html>
