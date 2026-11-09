<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Guest';
$userInitial = strtoupper(substr($userName, 0, 1));

if (!$userId) {
    header("Location: ../login.php");
    exit();
}

// Fetch user status, role, and profile picture
$stmt = $conn->prepare("SELECT status, role, profile_picture FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$currentStatus = $userData['status'] ?? 'offline';
$currentRole = $userData['role'] ?? 'Member';
$profilePicture = $userData['profile_picture'] ?? null;
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Group Messages · TidyTrack</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="theme.css">
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
    --success: #25d366;
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
    --success: #00a884;
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

/* ── APP SIDEBAR (nav) ── */
.sidebar {
    width: 260px;
    background: var(--card);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    padding: 18px;
    position: fixed;
    height: 100%;
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

.sidebar ul { list-style: none; margin-top: 16px; }
.sidebar ul li { margin-bottom: 4px; }
.sidebar ul li a {
    color: var(--muted);
    text-decoration: none;
    font-size: 13px;
    display: flex; align-items: center; gap: 10px;
    padding: 9px 10px;
    border-radius: 10px;
    font-weight: 500;
    transition: all .15s;
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

/* ── MAIN LAYOUT ── */
.app-main {
    margin-left: 260px;
    flex: 1;
    display: flex;
    height: 100%;
    min-width: 0;
}

/* ── GROUPS LIST ── */
.groups-col {
    width: 340px;
    background: var(--card);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
}
.groups-top {
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.groups-top h2 {
    font-size: 17px;
    font-weight: 700;
}
.groups-search {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
}
.groups-search input {
    width: 100%;
    padding: 10px 14px 10px 36px;
    border-radius: 10px;
    border: none;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    outline: none;
}
.search-wrap { position: relative; }
.search-wrap i {
    position: absolute; left: 12px; top: 50%;
    transform: translateY(-50%);
    color: var(--muted); font-size: 13px;
}

.groups-list {
    flex: 1;
    overflow-y: auto;
}
.group-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    cursor: pointer;
    transition: background .12s;
    border-bottom: 1px solid var(--border);
}
.group-item:hover { background: var(--bg); }
.group-item.active { background: var(--accent-soft); }

.g-avatar {
    width: 48px; height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #1d3557, #457b9d);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.g-info { flex: 1; min-width: 0; }
.g-name {
    font-weight: 600;
    font-size: 15px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.g-meta {
    font-size: 12px;
    color: var(--muted);
    margin-top: 3px;
}

.groups-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--muted);
}
.groups-empty i { font-size: 2.5rem; opacity: .25; margin-bottom: 12px; display: block; }

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
    display: flex;
    align-items: center;
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

.chat-header .g-avatar { width: 40px; height: 40px; font-size: 15px; border-radius: 10px; }
.chat-title { font-weight: 700; font-size: 15px; }
.chat-sub { font-size: 12px; color: var(--muted); }

.chat-header-right {
    margin-left: auto;
    display: flex;
    gap: 6px;
}
.icon-btn {
    width: 36px; height: 36px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
}
.icon-btn:hover { background: var(--bg); color: var(--text); }

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 12px;
}
.messages-inner {
    max-width: 780px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.message {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    max-width: 75%;
}
.message.own {
    align-self: flex-end;
    flex-direction: row-reverse;
}
.message-avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700;
    flex-shrink: 0;
}
.message.own .message-avatar { background: var(--success); }

.message-body { min-width: 0; }
.message-info {
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 3px;
    padding: 0 4px;
}
.message.own .message-info { text-align: right; }

.message-bubble {
    padding: 8px 12px;
    border-radius: 12px;
    font-size: 14.5px;
    line-height: 1.45;
    word-wrap: break-word;
}
.message.own .message-bubble {
    background: var(--bubble-me);
    border-bottom-right-radius: 4px;
}
.message:not(.own) .message-bubble {
    background: var(--bubble-them);
    border-bottom-left-radius: 4px;
    box-shadow: 0 1px 1px rgba(0,0,0,0.05);
}
.message-time {
    font-size: 10px;
    color: var(--muted);
    margin-top: 3px;
    text-align: right;
}

.system-message {
    text-align: center;
    margin: 12px 0;
}
.system-bubble {
    display: inline-block;
    background: var(--accent-soft);
    color: var(--muted);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
}

.chat-input {
    background: var(--card);
    border-top: 1px solid var(--border);
    padding: 10px 12px;
    display: none;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.chat-input.show { display: flex; }

.message-input-wrap {
    flex: 1;
    display: flex;
    align-items: center;
    background: var(--input-bg);
    border-radius: 24px;
    border: 1px solid var(--border);
    padding: 4px 6px 4px 16px;
}
.message-input-wrap input {
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
}
.send-btn:active { transform: scale(0.92); }

.empty-chat {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--muted);
    text-align: center;
    padding: 40px;
}
.empty-chat i { font-size: 3.5rem; opacity: .2; margin-bottom: 16px; }
.empty-chat p { font-size: 14px; }

/* ── MEMBERS PANEL ── */
.members-panel {
    width: 260px;
    background: var(--card);
    border-left: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
}
.members-header {
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.members-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px 0;
}
.member-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
}
.member-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700;
    position: relative; flex-shrink: 0;
}
.member-avatar .status-dot {
    position: absolute; bottom: 0; right: 0;
    width: 10px; height: 10px; border-radius: 50%;
    border: 2px solid var(--card);
}
.member-name { font-size: 13px; font-weight: 600; }
.member-status { font-size: 11px; color: var(--muted); }

.notification {
    position: fixed;
    bottom: 24px; right: 24px;
    padding: 12px 16px;
    border-radius: 12px;
    color: #fff;
    font-weight: 500;
    font-size: 14px;
    z-index: 9999;
    transform: translateY(80px);
    opacity: 0;
    transition: all .3s;
    max-width: 90vw;
}
.notification.show { transform: translateY(0); opacity: 1; }
.notification.success { background: var(--success); }
.notification.error { background: #dc2626; }

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
@media (max-width: 1024px) {
    .members-panel { display: none; }
}
@media (max-width: 900px) {
    .menu-toggle { display: block; }
    .sidebar {
        left: -100%;
        transition: left .25s;
    }
    .sidebar.open { left: 0; }
    .app-main { margin-left: 0; }
}
@media (max-width: 768px) {
    .groups-col {
        width: 100%;
        position: absolute;
        inset: 0;
        z-index: 10;
        transition: transform .25s;
    }
    .groups-col.hidden { transform: translateX(-100%); }

    .chat-area {
        width: 100%;
        position: absolute;
        inset: 0;
        z-index: 5;
    }
    .back-mobile { display: flex !important; }
    .message { max-width: 85%; }
    .notification { left: 12px; right: 12px; bottom: 16px; max-width: none; }
}
</style>
</head>
<body>

<button class="menu-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')">
    <i class="fas fa-bars"></i>
</button>

<!-- NAV SIDEBAR -->
<aside class="sidebar">
    <div class="top">
        <div class="sb-avatar">
            <?php if ($profilePicture && file_exists($profilePicture)): ?>
                <img src="<?= htmlspecialchars($profilePicture) ?>" alt="">
            <?php else: ?>
                <?= htmlspecialchars($userInitial) ?>
            <?php endif; ?>
            <div class="dot dot-<?= htmlspecialchars($currentStatus) ?>"></div>
        </div>
        <div>
            <div class="sb-name"><?= htmlspecialchars($userName) ?></div>
            <div class="sb-role"><?= ucfirst($currentStatus) ?> · <?= htmlspecialchars($currentRole) ?></div>
        </div>
    </div>
    <ul>
        <li><a href="member_dashboard.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
        <li><a href="member_tasks.php"><i class="fas fa-tasks"></i><span>My Tasks</span></a></li>
        <li><a href="member_projects.php"><i class="fas fa-briefcase"></i><span>Projects</span></a></li>
        <li><a href="member_messages.php"><i class="fas fa-comments"></i><span>Messages</span></a></li>
        <li><a href="upload_timesheet_progress.php"><i class="fas fa-file-signature"></i><span>Timesheet</span></a></li>
        <li><a href="member_Group.php"><i class="fas fa-users"></i><span>Groups</span></a></li>
        <li><a href="member_Group_chat.php" class="active"><i class="fas fa-envelope"></i><span>Group Messages</span></a></li>
        <li><a href="member_attendance.php"><i class="fas fa-check-circle"></i><span>Attendance</span></a></li>
        <li><a href="leave_application.php"><i class="fa-solid fa-calendar-check"></i><span>Leave Application</span></a></li>
        <li><a href="https://investhoodprojects.com/tidytracker/tidytrack/meeting/member_meetings.php"><i class="fas fa-video"></i><span>Meetings</span></a></li>
        <li><a href="member_help.php"><i class="fas fa-life-ring"></i><span>TSM ASSISTANCE</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        <li style="margin-top:auto;"><a href="profile.php"><i class="fas fa-user-circle"></i><span>Profile</span></a></li>
    </ul>
</aside>

<div class="app-main">
    <!-- GROUPS LIST -->
    <div class="groups-col" id="groupsCol">
        <div class="groups-top">
            <h2>Active Groups</h2>
            <a href="member_Group.php" class="icon-btn" title="Join groups"><i class="fas fa-plus"></i></a>
        </div>
        <div class="groups-search">
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="searchGroups" placeholder="Search groups..." autocomplete="off">
            </div>
        </div>
        <div class="groups-list" id="groupsList">
            <div class="groups-empty">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading groups...</p>
            </div>
        </div>
    </div>

    <!-- CHAT -->
    <div class="chat-area" id="chatArea">
        <div class="chat-header">
            <button class="back-mobile" id="backBtn"><i class="fas fa-arrow-left"></i></button>
            <div class="g-avatar" id="groupAvatar"><i class="fas fa-users"></i></div>
            <div style="min-width:0;flex:1;">
                <div class="chat-title" id="groupName">Select a group</div>
                <div class="chat-sub" id="groupMembers">Choose a group to start chatting</div>
            </div>
            <div class="chat-header-right">
                <button class="icon-btn" id="toggleMembers" title="Members" style="display:none;">
                    <i class="fas fa-user-friends"></i>
                </button>
            </div>
        </div>

        <div class="chat-messages" id="chatMessages">
            <div class="empty-chat" id="emptyChat">
                <i class="fas fa-comments"></i>
                <p>Select an active group<br>from the list to start messaging</p>
            </div>
            <div class="messages-inner" id="messagesInner" style="display:none;"></div>
        </div>

        <div class="chat-input" id="chatInput">
            <div class="message-input-wrap">
                <input type="text" id="messageInput" placeholder="Type a message..." autocomplete="off">
            </div>
            <button class="send-btn" id="sendBtn"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>

    <!-- MEMBERS -->
    <div class="members-panel" id="membersPanel">
        <div class="members-header">
            <i class="fas fa-users"></i> Members
        </div>
        <div class="members-list" id="membersList">
            <div class="groups-empty">
                <i class="fas fa-users"></i>
                <p>Select a group</p>
            </div>
        </div>
    </div>
</div>

<div id="notification" class="notification"></div>

<script src="theme_switcher.js"></script>
<script>
const userId = <?= json_encode($userId) ?>;
const userName = <?= json_encode($userName) ?>;
let currentGroupId = null;
let allGroups = [];

const groupsCol = document.getElementById('groupsCol');
const groupsList = document.getElementById('groupsList');
const chatMessages = document.getElementById('chatMessages');
const messagesInner = document.getElementById('messagesInner');
const emptyChat = document.getElementById('emptyChat');
const messageInput = document.getElementById('messageInput');
const chatInput = document.getElementById('chatInput');
const groupNameEl = document.getElementById('groupName');
const groupMembersEl = document.getElementById('groupMembers');
const groupAvatarEl = document.getElementById('groupAvatar');
const membersList = document.getElementById('membersList');
const backBtn = document.getElementById('backBtn');

document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    fetchGroups();
});

// Mobile back
backBtn.addEventListener('click', () => {
    groupsCol.classList.remove('hidden');
    currentGroupId = null;
    resetChat();
    document.querySelectorAll('.group-item').forEach(g => g.classList.remove('active'));
});

async function fetchGroups() {
    try {
        const res = await fetch('fetch_user_groups.php');
        const result = await res.json();
        if (result.success) {
            allGroups = result.groups || [];
            renderGroupsList(allGroups);
        } else {
            groupsList.innerHTML = `<div class="groups-empty"><i class="fas fa-exclamation-circle"></i><p>${result.error || 'Failed to load'}</p></div>`;
        }
    } catch (e) {
        groupsList.innerHTML = `<div class="groups-empty"><i class="fas fa-exclamation-circle"></i><p>Failed to load groups</p></div>`;
    }
}

function renderGroupsList(groups) {
    if (!groups.length) {
        groupsList.innerHTML = `
            <div class="groups-empty">
                <i class="fas fa-users"></i>
                <p>No active groups yet</p>
                <a href="member_Group.php" style="color:var(--accent);font-size:13px;margin-top:8px;display:inline-block;">Join a group →</a>
            </div>`;
        return;
    }
    groupsList.innerHTML = '';
    groups.forEach(g => {
        const item = document.createElement('div');
        item.className = 'group-item';
        item.dataset.id = g.group_id;
        item.dataset.name = g.group_name;
        const icon = g.group_icon || 'fas fa-users';
        item.innerHTML = `
            <div class="g-avatar"><i class="${icon}"></i></div>
            <div class="g-info">
                <div class="g-name">${escapeHtml(g.group_name)}</div>
                <div class="g-meta">${g.member_count ? g.member_count + ' members' : 'Active group'}</div>
            </div>`;
        item.addEventListener('click', () => selectGroup(g.group_id, g.group_name, icon));
        groupsList.appendChild(item);
    });
}

document.getElementById('searchGroups').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.group-item').forEach(it => {
        it.style.display = (it.dataset.name || '').toLowerCase().includes(q) ? '' : 'none';
    });
});

async function selectGroup(groupId, groupName, icon) {
    currentGroupId = groupId;

    document.querySelectorAll('.group-item').forEach(g => g.classList.remove('active'));
    const el = document.querySelector(`.group-item[data-id="${groupId}"]`);
    if (el) el.classList.add('active');

    groupNameEl.textContent = groupName;
    groupAvatarEl.innerHTML = `<i class="${icon || 'fas fa-users'}"></i>`;

    if (window.innerWidth <= 768) {
        groupsCol.classList.add('hidden');
    }

    emptyChat.style.display = 'none';
    messagesInner.style.display = 'flex';
    chatInput.classList.add('show');

    await loadGroupData(groupId);
    messageInput.focus();
}

async function loadGroupData(groupId) {
    try {
        const msgRes = await fetch(`fetch_group_messages.php?group_id=${groupId}`);
        const msgResult = await msgRes.json();
        if (msgResult.success) {
            renderMessages(msgResult.messages || []);
        } else {
            showNotification(msgResult.error || 'Error loading messages', 'error');
        }

        const groupRes = await fetch(`fetch_group_members.php?group_id=${groupId}`);
        const groupResult = await groupRes.json();
        if (groupResult.success) {
            groupNameEl.textContent = groupResult.group.group_name;
            groupMembersEl.textContent = `${groupResult.group.member_count} members`;
            if (groupResult.group.group_icon) {
                groupAvatarEl.innerHTML = `<i class="${groupResult.group.group_icon}"></i>`;
            }
            renderMembers(groupResult.members || []);
        }
    } catch (e) {
        showNotification('Failed to load group data', 'error');
    }
}

function resetChat() {
    groupNameEl.textContent = 'Select a group';
    groupMembersEl.textContent = 'Choose a group to start chatting';
    groupAvatarEl.innerHTML = '<i class="fas fa-users"></i>';
    emptyChat.style.display = 'flex';
    messagesInner.style.display = 'none';
    messagesInner.innerHTML = '';
    chatInput.classList.remove('show');
    membersList.innerHTML = `<div class="groups-empty"><i class="fas fa-users"></i><p>Select a group</p></div>`;
}

function renderMessages(messages) {
    messagesInner.innerHTML = '';
    if (!messages.length) {
        messagesInner.innerHTML = `
            <div class="system-message">
                <div class="system-bubble"><i class="fas fa-info-circle"></i> No messages yet. Start the conversation!</div>
            </div>`;
        return;
    }
    messages.forEach(msg => {
        const isOwn = msg.user_id == userId;
        const initial = (msg.sender_name || '?').charAt(0).toUpperCase();
        const time = new Date(msg.sent_at).toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' });
        const div = document.createElement('div');
        div.className = `message ${isOwn ? 'own' : ''}`;
        div.innerHTML = `
            <div class="message-avatar">${initial}</div>
            <div class="message-body">
                <div class="message-info"><strong>${isOwn ? 'You' : escapeHtml(msg.sender_name)}</strong></div>
                <div class="message-bubble">${escapeHtml(msg.message_text)}<div class="message-time">${time}</div></div>
            </div>`;
        messagesInner.appendChild(div);
    });
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function renderMembers(members) {
    membersList.innerHTML = '';
    if (!members.length) {
        membersList.innerHTML = `<div class="groups-empty"><i class="fas fa-users"></i><p>No members</p></div>`;
        return;
    }
    members.forEach(m => {
        const initial = (m.name || '?').charAt(0).toUpperCase();
        const status = m.status || 'offline';
        const div = document.createElement('div');
        div.className = 'member-item';
        div.innerHTML = `
            <div class="member-avatar">
                ${initial}
                <div class="status-dot dot-${status}"></div>
            </div>
            <div>
                <div class="member-name">${escapeHtml(m.name)}${m.user_id == userId ? ' (You)' : ''}</div>
                <div class="member-status">${status.charAt(0).toUpperCase() + status.slice(1)}</div>
            </div>`;
        membersList.appendChild(div);
    });
}

async function sendMessage() {
    const message = messageInput.value.trim();
    if (!message || !currentGroupId) return;

    try {
        const res = await fetch('send_group_message.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ groupId: currentGroupId, messageText: message, messageType: 'text' })
        });
        const result = await res.json();
        if (result.success) {
            messageInput.value = '';
            await loadGroupData(currentGroupId);
        } else {
            showNotification(result.error || 'Send failed', 'error');
        }
    } catch (e) {
        showNotification('Failed to send message', 'error');
    }
}

function showNotification(msg, type) {
    const n = document.getElementById('notification');
    n.textContent = msg;
    n.className = `notification ${type} show`;
    setTimeout(() => n.classList.remove('show'), 4000);
}

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}

messageInput.addEventListener('keypress', e => { if (e.key === 'Enter') sendMessage(); });
document.getElementById('sendBtn').addEventListener('click', sendMessage);

// Auto-refresh messages when in a group
setInterval(() => {
    if (currentGroupId) loadGroupData(currentGroupId);
}, 5000);

document.querySelectorAll('.sidebar a').forEach(link => {
    link.addEventListener('click', () => document.querySelector('.sidebar')?.classList.remove('open'));
});
</script>
</body>
</html>