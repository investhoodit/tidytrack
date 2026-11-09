<?php
// member_messages.php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Leader';
$userInitial = strtoupper($userName[0]);

if (!$userId) die("Unauthorized access. Please log in.");

$stmt = $conn->prepare("SELECT profile_picture, status, role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$currentProfilePicture = $currentUser['profile_picture'] ?? null;
$currentStatus = $currentUser['status'] ?? 'offline';
$currentRole = $currentUser['role'] ?? 'Leader';
$stmt->close();

$status_info = [
    'online' => ['name' => 'Online'],
    'out_of_office' => ['name' => 'Out of Office'],
    'sick' => ['name' => 'Sick'],
    'focusing' => ['name' => 'Focusing'],
    'in_meeting' => ['name' => 'In Meeting'],
    'offline' => ['name' => 'Offline']
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<title>Messages · TidyTrack</title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --accent: #1d3557;
    --accent-soft: #e8f0fe;
    --bubble-me: #dcf8c6;
    --bubble-them: #ffffff;
    --bg: #f0f2f5;
    --card: #ffffff;
    --text: #111b21;
    --muted: #667781;
    --border: #e9edef;
    --unread: #25d366;
    --input-bg: #ffffff;
}
[data-theme="dark"] {
    --accent: #00a884;
    --accent-soft: #1f2c34;
    --bubble-me: #005c4b;
    --bubble-them: #202c33;
    --bg: #0b141a;
    --card: #111b21;
    --text: #e9edef;
    --muted: #8696a0;
    --border: #2a3942;
    --unread: #00a884;
    --input-bg: #2a3942;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    height: 100vh;
    height: 100dvh;
    display: flex;
    overflow: hidden;
}

/* ── USERS COLUMN ── */
.users-col {
    width: 380px;
    max-width: 100%;
    background: var(--card);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
}
.users-top {
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid var(--border);
}
.me-avatar {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 16px;
    position: relative; overflow: hidden; flex-shrink: 0;
}
.me-avatar img { width: 100%; height: 100%; object-fit: cover; }
.status-dot {
    position: absolute; bottom: 0; right: 0;
    width: 11px; height: 11px; border-radius: 50%;
    border: 2px solid var(--card);
}
.dot-online, .dot-ready { background: #00a884; }
.dot-offline { background: #8696a0; }
.dot-sick { background: #ef4444; }
.dot-focusing { background: #a855f7; }
.dot-in_meeting { background: #3b82f6; }
.dot-out_of_office { background: #f59e0b; }

.me-info { flex: 1; min-width: 0; }
.me-name { font-weight: 700; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.me-role { font-size: 12px; color: var(--muted); }

.top-actions { display: flex; gap: 6px; }
.icon-btn {
    width: 36px; height: 36px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
    transition: background .15s;
}
.icon-btn:hover { background: var(--bg); color: var(--text); }

.search-wrap {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
}
.search-wrap input {
    width: 100%;
    padding: 10px 14px 10px 38px;
    border-radius: 10px;
    border: none;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    outline: none;
}
.search-box { position: relative; }
.search-box i {
    position: absolute; left: 12px; top: 50%;
    transform: translateY(-50%);
    color: var(--muted); font-size: 13px;
}

.user-list { flex: 1; overflow-y: auto; }
.user-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px;
    cursor: pointer;
    transition: background .12s;
    position: relative;
}
.user-item:hover { background: var(--bg); }
.user-item.active { background: var(--accent-soft); }

.avatar {
    width: 48px; height: 48px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 17px;
    position: relative; flex-shrink: 0; overflow: hidden;
}
.avatar img { width: 100%; height: 100%; object-fit: cover; }

.user-info { flex: 1; min-width: 0; }
.user-info .name { font-weight: 600; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-info .last { font-size: 12px; color: var(--muted); margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.unread-count {
    background: var(--unread);
    color: #fff;
    font-size: 11px; font-weight: 700;
    min-width: 20px; height: 20px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 6px;
}

/* ── CHAT AREA ── */
.chat-area {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    background: var(--bg);
}
.chat-header {
    height: 64px;
    background: var(--card);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center;
    padding: 0 16px;
    gap: 12px;
    flex-shrink: 0;
}
.back-mobile {
    display: none;
    width: 36px; height: 36px;
    border: none; background: transparent;
    color: var(--text); font-size: 18px;
    cursor: pointer; border-radius: 50%;
}
.back-mobile:hover { background: var(--bg); }

.chat-header .avatar { width: 42px; height: 42px; font-size: 15px; }
.chat-title { font-weight: 700; font-size: 15px; }
.chat-sub { font-size: 12px; color: var(--muted); }

.chat-header-right { margin-left: auto; display: flex; gap: 6px; align-items: center; }

.chat-window {
    flex: 1;
    overflow-y: auto;
    padding: 16px 12px;
    background: var(--bg);
}
.messages {
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-width: 780px;
    margin: 0 auto;
}
.bubble {
    padding: 8px 12px;
    border-radius: 12px;
    max-width: 75%;
    font-size: 14.5px;
    line-height: 1.45;
    word-wrap: break-word;
    position: relative;
}
.bubble.me {
    align-self: flex-end;
    background: var(--bubble-me);
    border-bottom-right-radius: 4px;
}
.bubble.them {
    align-self: flex-start;
    background: var(--bubble-them);
    border-bottom-left-radius: 4px;
    box-shadow: 0 1px 1px rgba(0,0,0,0.06);
}
.bubble small {
    display: block;
    text-align: right;
    color: var(--muted);
    font-size: 11px;
    margin-top: 4px;
}

.input-row {
    background: var(--card);
    border-top: 1px solid var(--border);
    padding: 10px 12px;
    display: flex; align-items: center; gap: 10px;
    flex-shrink: 0;
}
.message-input {
    flex: 1;
    display: flex; align-items: center;
    background: var(--input-bg);
    border-radius: 24px;
    padding: 4px 6px 4px 16px;
    border: 1px solid var(--border);
}
.message-input input {
    flex: 1;
    border: none;
    background: transparent;
    outline: none;
    padding: 10px 8px;
    font-size: 15px;
    color: var(--text);
}
.send-btn {
    width: 46px; height: 46px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    transition: transform .15s;
}
.send-btn:active { transform: scale(0.92); }

.empty-state {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    height: 100%;
    color: var(--muted);
    text-align: center;
    padding: 40px;
}
.empty-state i { font-size: 3.5rem; opacity: .25; margin-bottom: 16px; }
.empty-state p { font-size: 15px; }

/* Notification toast */
.notification {
    position: fixed; bottom: 24px; right: 24px;
    background: var(--card);
    color: var(--text);
    padding: 12px 16px;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.18);
    display: flex; align-items: center; gap: 12px;
    max-width: 320px; z-index: 9999;
    border: 1px solid var(--border);
    animation: slideIn .3s ease;
}
@keyframes slideIn {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* ── MOBILE ── */
@media (max-width: 768px) {
    .users-col {
        width: 100%;
        position: absolute;
        inset: 0;
        z-index: 10;
        transition: transform .25s ease;
    }
    .users-col.hidden {
        transform: translateX(-100%);
    }
    .chat-area {
        width: 100%;
        position: absolute;
        inset: 0;
        z-index: 5;
    }
    .back-mobile { display: flex; }
    .chat-header-right .desktop-only { display: none; }
    .bubble { max-width: 85%; }
    .notification { left: 12px; right: 12px; max-width: none; bottom: 16px; }
}

@media (min-width: 769px) {
    .back-mobile { display: none !important; }
}
</style>
</head>
<body>

<!-- LEFT: users -->
<div class="users-col" id="usersCol">
    <div class="users-top">
        <div class="me-avatar">
            <?php if ($currentProfilePicture && file_exists($currentProfilePicture)): ?>
                <img src="<?= htmlspecialchars($currentProfilePicture) ?>" alt="">
            <?php else: ?>
                <?= htmlspecialchars($userInitial) ?>
            <?php endif; ?>
            <div class="status-dot dot-<?= htmlspecialchars($currentStatus) ?>"></div>
        </div>
        <div class="me-info">
            <div class="me-name"><?= htmlspecialchars($userName) ?></div>
            <div class="me-role"><?= htmlspecialchars($status_info[$currentStatus]['name'] ?? 'Offline') ?> · <?= htmlspecialchars($currentRole) ?></div>
        </div>
        <div class="top-actions">
            <button class="icon-btn" id="themeToggle" title="Toggle theme"><i class="fas fa-moon" id="themeIcon"></i></button>
            <a href="member_dashboard.php" class="icon-btn" title="Dashboard"><i class="fas fa-home"></i></a>
        </div>
    </div>

    <div class="search-wrap">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input id="searchUsers" placeholder="Search people..." autocomplete="off">
        </div>
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
            $initial = strtoupper($u['name'][0] ?? '?');
            $color = substr(md5($uid), 0, 6);
            $pp = $u['profile_picture'] ?? null;
            $status = $u['status'] ?? 'offline';
            $role = htmlspecialchars($u['role'] ?? 'Member');
            $unread = (int)$u['unread_count'];
            echo "<div class='user-item' data-id='{$uid}' data-name='{$name}' data-role='{$role}' data-status='{$status}'>
                    <div class='avatar' style='background:#{$color}'>";
            if ($pp && file_exists($pp)) {
                echo "<img src='".htmlspecialchars($pp)."' alt=''>";
            } else {
                echo $initial;
            }
            echo "  <div class='status-dot dot-{$status}'></div>
                    </div>
                    <div class='user-info'>
                        <div class='name'>{$name}</div>
                        <div class='last'>".htmlspecialchars($status_info[$status]['name'] ?? 'Offline')." · {$role}</div>
                    </div>";
            if ($unread > 0) echo "<div class='unread-count'>{$unread}</div>";
            echo "</div>";
        }
        $stmt->close();
        $conn->close();
        ?>
    </div>
</div>

<!-- RIGHT: chat -->
<div class="chat-area" id="chatArea">
    <div class="chat-header" id="chatHeader">
        <button class="back-mobile" id="backBtn"><i class="fas fa-arrow-left"></i></button>
        <div class="avatar" id="chatAvatar" style="background:#1d3557">?</div>
        <div style="min-width:0;flex:1;">
            <div class="chat-title" id="chatName">Select a chat</div>
            <div class="chat-sub" id="chatSubtitle">Choose someone to start messaging</div>
        </div>
        <div class="chat-header-right">
            <a href="member_dashboard.php" class="icon-btn desktop-only" title="Dashboard"><i class="fas fa-home"></i></a>
        </div>
    </div>

    <div class="chat-window" id="chatWindow">
        <div class="empty-state" id="emptyState">
            <i class="fas fa-comments"></i>
            <p>Select a person from the list<br>to start a conversation</p>
        </div>
        <div class="messages" id="messages" style="display:none;"></div>
    </div>

    <div class="input-row" id="inputRow" style="display:none;">
        <div class="message-input">
            <input id="textInput" placeholder="Type a message..." autocomplete="off">
        </div>
        <button class="send-btn" id="sendBtn"><i class="fas fa-paper-plane"></i></button>
    </div>
</div>

<script>
let selectedUserId = null;
let lastMessageCount = {};

const usersCol = document.getElementById('usersCol');
const messagesBox = document.getElementById('messages');
const emptyState = document.getElementById('emptyState');
const inputRow = document.getElementById('inputRow');
const chatName = document.getElementById('chatName');
const chatAvatar = document.getElementById('chatAvatar');
const chatSubtitle = document.getElementById('chatSubtitle');
const textInput = document.getElementById('textInput');
const sendBtn = document.getElementById('sendBtn');
const backBtn = document.getElementById('backBtn');

const statusInfo = {
    'online': 'Online', 'out_of_office': 'Out of Office', 'sick': 'Sick',
    'focusing': 'Focusing', 'in_meeting': 'In Meeting', 'offline': 'Offline', 'ready': 'Online'
};

if (Notification.permission !== 'granted' && Notification.permission !== 'denied') {
    Notification.requestPermission();
}

// Mobile back
backBtn.addEventListener('click', () => {
    usersCol.classList.remove('hidden');
    selectedUserId = null;
    emptyState.style.display = 'flex';
    messagesBox.style.display = 'none';
    inputRow.style.display = 'none';
    chatName.textContent = 'Select a chat';
    chatSubtitle.textContent = 'Choose someone to start messaging';
    chatAvatar.innerHTML = '?';
    document.querySelectorAll('.user-item').forEach(x => x.classList.remove('active'));
});

// Select user
document.getElementById('userList').addEventListener('click', e => {
    const item = e.target.closest('.user-item');
    if (!item) return;

    document.querySelectorAll('.user-item').forEach(x => x.classList.remove('active'));
    item.classList.add('active');

    selectedUserId = item.dataset.id;
    const name = item.dataset.name;
    const role = item.dataset.role;
    const status = item.dataset.status || 'offline';

    chatName.textContent = name;
    chatSubtitle.textContent = `${statusInfo[status] || 'Offline'} · ${role}`;

    chatAvatar.innerHTML = '';
    const img = item.querySelector('.avatar img');
    if (img) {
        const clone = document.createElement('img');
        clone.src = img.src;
        chatAvatar.appendChild(clone);
        chatAvatar.style.background = 'transparent';
    } else {
        chatAvatar.textContent = name[0].toUpperCase();
        chatAvatar.style.background = item.querySelector('.avatar').style.background;
    }

    // Mobile: hide list
    if (window.innerWidth <= 768) {
        usersCol.classList.add('hidden');
    }

    emptyState.style.display = 'none';
    messagesBox.style.display = 'flex';
    inputRow.style.display = 'flex';

    const badge = item.querySelector('.unread-count');
    if (badge) badge.remove();

    markMessagesAsRead();
    loadMessages();
    textInput.focus();
});

// Search
document.getElementById('searchUsers').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.user-item').forEach(it => {
        it.style.display = it.dataset.name.toLowerCase().includes(q) ? '' : 'none';
    });
});

function loadMessages() {
    if (!selectedUserId) return;
    fetch('load_messages.php?user_id=' + selectedUserId)
        .then(r => r.text())
        .then(html => {
            messagesBox.innerHTML = html;
            const win = document.getElementById('chatWindow');
            win.scrollTop = win.scrollHeight;
            checkForNewMessages();
        });
}

function markMessagesAsRead() {
    if (!selectedUserId) return;
    fetch('mark_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
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
                const uid = item.dataset.id;
                const existing = item.querySelector('.unread-count');
                const msgData = data.find(m => m.sender_id == uid);
                const count = msgData ? msgData.count : 0;

                if (!lastMessageCount[uid]) lastMessageCount[uid] = 0;
                if (count > lastMessageCount[uid] && uid != selectedUserId) {
                    showNotification(msgData.sender_name, msgData.sender_avatar || '');
                    lastMessageCount[uid] = count;
                }
                if (existing) existing.remove();
                if (count > 0) {
                    const b = document.createElement('div');
                    b.className = 'unread-count';
                    b.textContent = count;
                    item.appendChild(b);
                }
            });
        });
}

function showNotification(name, avatar) {
    if (Notification.permission === 'granted') {
        new Notification('New message from ' + name, { body: 'You have a new message' });
    }
    const n = document.createElement('div');
    n.className = 'notification';
    n.innerHTML = `<div class="avatar" style="width:36px;height:36px;background:#1d3557;font-size:14px;">${name[0]}</div>
                   <div><strong>${name}</strong><br><span style="font-size:13px;color:var(--muted)">New message</span></div>`;
    document.body.appendChild(n);
    setTimeout(() => n.remove(), 4500);
}

function sendMessage() {
    if (!selectedUserId) return;
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
                setTimeout(loadMessages, 400);
            }
        });
}

function appendMyBubble(text) {
    const div = document.createElement('div');
    div.className = 'bubble me';
    const t = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    div.innerHTML = `<div>${escapeHtml(text)}</div><small>${t}</small>`;
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

// Dark mode
(function () {
    const root = document.documentElement;
    const btn = document.getElementById('themeToggle');
    const icon = document.getElementById('themeIcon');

    function apply(theme) {
        root.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
        icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    const saved = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    apply(saved);
    btn.addEventListener('click', () => {
        apply(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    });
})();
</script>
</body>
</html>