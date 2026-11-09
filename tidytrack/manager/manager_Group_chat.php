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
$currentRole = $userData['role'] ?? 'Manager';
$profilePicture = $userData['profile_picture'] ?? null;
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Group Chat</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #1d3557;
            --bubble-me: #d9fdd3;
            --bubble-them: #ffffff;
            --bg: #eef2f5;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            background: var(--bg);
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 320px;
            background: #fff;
            border-right: 1px solid #e0e0e0;
            display: flex;
            flex-direction: column;
            padding: 18px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar .top {
            padding-bottom: 18px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .top .me {
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
            transition: transform 0.3s ease;
        }

        .sidebar .top .me:hover {
            transform: scale(1.1);
        }

        .sidebar .top .me img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .status-indicator {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid #fff;
        }

        .status-ready { background: #00d4aa; }
        .status-out_of_office { background: #ffa726; }
        .status-sick { background: #e94560; }
        .status-focusing { background: #9c27b0; }
        .status-in_meeting { background: #2196f3; }
        .status-offline { background: #6c757d; }

        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 20px 0 0 0;
            flex: 1;
        }

        .sidebar ul li {
            margin-bottom: 8px;
        }

        .sidebar ul li a {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            text-decoration: none;
            color: #666;
            border-radius: 8px;
            transition: all 0.3s ease;
            gap: 12px;
        }

        .sidebar ul li a:hover,
        .sidebar ul li a.active {
            background: var(--accent);
            color: #fff;
        }

        .sidebar ul li a i {
            width: 20px;
            text-align: center;
        }

        /* Chat Layout */
        .chat-container {
            margin-left: 320px;
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        /* Chat Header */
        .chat-header {
            background: #fff;
            padding: 16px 24px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .group-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .group-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--accent), #457b9d);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 20px;
            font-weight: 600;
        }

        .group-details h1 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .group-details p {
            margin: 0;
            font-size: 12px;
            color: #666;
        }

        .chat-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .group-selector {
            padding: 8px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        /* Removed .jitsi-link-btn styles */

        /* Chat Messages */
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: var(--bg);
        }

        .message {
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .message.own {
            flex-direction: row-reverse;
        }

        .message-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }

        .message.own .message-avatar {
            background: var(--success);
        }

        .message-content {
            max-width: 70%;
        }

        .message-bubble {
            background: var(--bubble-them);
            padding: 12px 16px;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            word-wrap: break-word;
        }

        .message.own .message-bubble {
            background: var(--bubble-me);
        }

        .message-info {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
            font-size: 12px;
            color: #666;
        }

        .message.own .message-info {
            justify-content: flex-end;
        }

        .message-time {
            font-size: 11px;
            color: #999;
            margin-top: 4px;
        }

        .message.own .message-time {
            text-align: right;
        }

        /* System Messages */
        .system-message {
            text-align: center;
            margin: 20px 0;
            color: #666;
            font-size: 14px;
        }

        .system-message .system-bubble {
            background: rgba(29, 53, 87, 0.1);
            padding: 8px 16px;
            border-radius: 20px;
            display: inline-block;
        }

        /* Chat Input */
        .chat-input {
            background: #fff;
            padding: 16px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 12px;
            align-items: center;
            display: none; /* Hidden by default */
        }

        .input-container {
            flex: 1;
            position: relative;
        }

        .message-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 25px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s ease;
        }

        .message-input:focus {
            border-color: var(--accent);
        }

        .send-btn {
            width: 44px;
            height: 44px;
            background: var(--accent);
            border: none;
            border-radius: 50%;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .send-btn:hover {
            background: #0f2a44;
            transform: scale(1.1);
        }

        /* Members Panel */
        .members-panel {
            width: 280px;
            background: #fff;
            border-left: 1px solid #e0e0e0;
            padding: 20px;
            overflow-y: auto;
        }

        .members-header {
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee;
        }

        .members-header h3 {
            margin: 0;
            color: #333;
            font-size: 16px;
            font-weight: 600;
        }

        .member-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
        }

        .member-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 600;
            font-size: 12px;
            position: relative;
        }

        .member-info {
            flex: 1;
        }

        .member-name {
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }

        .member-status {
            font-size: 12px;
            color: #666;
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 8px;
            color: #fff;
            font-weight: 500;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background: var(--success);
        }

        .notification.error {
            background: var(--danger);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .chat-container {
                margin-left: 0;
            }

            .members-panel {
                display: none;
            }

            .chat-actions {
                flex-direction: column;
                gap: 8px;
            }
        }
        
        /* Added for empty state in members panel */
        .empty-state {
            text-align: center;
            padding: 20px;
            color: #999;
        }
        .empty-state i {
            font-size: 40px;
            margin-bottom: 10px;
        }
        .empty-state h3 {
            margin: 0 0 5px 0;
            color: #666;
            font-weight: 500;
        }
        .empty-state p {
            margin: 0;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="top">
            <div class="me">
                <?php if ($profilePicture && file_exists($profilePicture)): ?>
                    <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo htmlspecialchars($userInitial); ?>
                <?php endif; ?>
                <div class="status-indicator status-<?php echo htmlspecialchars($currentStatus); ?>"></div>
            </div>
            <div>
                <div style="font-weight:700"><?php echo htmlspecialchars($userName); ?></div>
                <div style="font-size:12px;color:#777"><?php echo ucfirst($currentStatus); ?> | <?php echo htmlspecialchars($currentRole); ?></div>
            </div>
        </div>
        <ul>
            <li><a href="manager_index.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
            <li><a href="manager_messages.php"><i class="fas fa-comments"></i> <span>Chats</span></a></li>
            <li><a href="manager_Group.php"><i class="fas fa-users"></i> <span>Groups</span></a></li>
            <li><a href="manager_Group_chat.php" class="active"><i class="fas fa-envelope"></i> <span>Group Messages</span></a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
        </ul>
    </aside>

    <div class="chat-container">
        <div class="chat-header">
            <div class="group-info">
                <div class="group-avatar" id="groupAvatar">
                    <i class="fas fa-users"></i>
                </div>
                <div class="group-details">
                    <h1 id="groupName">Select a Group</h1>
                    <p id="groupMembers">No group selected</p>
                </div>
            </div>
            <div class="chat-actions">
                <select id="groupSelector" class="group-selector">
                    <option value="">Select a group</option>
                </select>
                </div>
        </div>

        <div class="chat-messages" id="chatMessages">
            <div class="system-message">
                <div class="system-bubble">
                    <i class="fas fa-info-circle"></i> Select a group to start chatting
                </div>
            </div>
        </div>

        <div class="chat-input" id="chatInput">
            <div class="input-container">
                <input type="text" class="message-input" id="messageInput" placeholder="Type a message...">
            </div>
            <button class="send-btn" onclick="sendMessage()">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>

    <div class="members-panel" id="membersPanel">
        <div class="members-header">
            <h3><i class="fas fa-users"></i> Members</h3>
        </div>
        <div id="membersList">
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <h3>No Group Selected</h3>
                <p>Select a group to view members</p>
            </div>
        </div>
    </div>

    <div id="notification" class="notification"></div>

    <script>
        const userId = <?php echo json_encode($userId); ?>;
        const userName = <?php echo json_encode($userName); ?>;
        let currentGroupId = null;

        document.addEventListener('DOMContentLoaded', () => {
            const groupSelector = document.getElementById('groupSelector');
            const chatMessages = document.getElementById('chatMessages');
            const messageInput = document.getElementById('messageInput');
            const groupNameEl = document.getElementById('groupName');
            const groupMembersEl = document.getElementById('groupMembers');
            const groupAvatarEl = document.getElementById('groupAvatar');
            const chatInput = document.getElementById('chatInput');
            const membersList = document.getElementById('membersList');
            // const jitsiLinkBtn = document.getElementById('jitsiLinkBtn'); // Removed

            // Fetch user groups (approved only)
            async function fetchGroups() {
                try {
                    const response = await fetch('fetch_user_groups.php');
                    const result = await response.json();
                    if (result.success) {
                        groupSelector.innerHTML = '<option value="">Select a group</option>';
                        result.groups.forEach(group => {
                            const option = document.createElement('option');
                            option.value = group.group_id;
                            option.textContent = group.group_name;
                            groupSelector.appendChild(option);
                        });
                    } else {
                        showNotification(`Error: ${result.error}`, 'error');
                    }
                } catch (error) {
                    console.error('Error fetching groups:', error);
                    showNotification('Failed to load groups', 'error');
                }
            }

            // Handle group selection
            groupSelector.addEventListener('change', async (e) => {
                currentGroupId = e.target.value;
                if (currentGroupId) {
                    await loadGroupData(currentGroupId);
                    chatInput.style.display = 'flex';
                    // jitsiLinkBtn.style.display = 'flex'; // Removed
                } else {
                    resetChatInterface();
                }
            });

            // Load group data (messages and members)
            async function loadGroupData(groupId) {
                try {
                    const msgResponse = await fetch(`fetch_group_messages.php?group_id=${groupId}`);
                    const msgResult = await msgResponse.json();
                    if (msgResult.success) {
                        renderMessages(msgResult.messages);
                    } else {
                        showNotification(`Error: ${msgResult.error}`, 'error');
                        return;
                    }

                    const groupResponse = await fetch(`fetch_group_members.php?group_id=${groupId}`);
                    const groupResult = await groupResponse.json();
                    if (groupResult.success) {
                        groupNameEl.textContent = groupResult.group.group_name;
                        groupMembersEl.textContent = `${groupResult.group.member_count} members`;
                        groupAvatarEl.innerHTML = `<i class="${groupResult.group.group_icon}"></i>`;
                        renderMembers(groupResult.members);
                    } else {
                        showNotification(`Error: ${groupResult.error}`, 'error');
                    }
                } catch (error) {
                    console.error('Error loading group data:', error);
                    showNotification('Failed to load group data', 'error');
                }
            }

            // Reset chat interface
            function resetChatInterface() {
                groupNameEl.textContent = 'Select a Group';
                groupMembersEl.textContent = 'No group selected';
                groupAvatarEl.innerHTML = '<i class="fas fa-users"></i>';
                chatMessages.innerHTML = `
                    <div class="system-message">
                        <div class="system-bubble">
                            <i class="fas fa-info-circle"></i> Select a group to start chatting
                        </div>
                    </div>`;
                membersList.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No Group Selected</h3>
                        <p>Select a group to view members</p>
                    </div>`;
                chatInput.style.display = 'none';
                // jitsiLinkBtn.style.display = 'none'; // Removed
            }

            // Render messages
            function renderMessages(messages) {
                chatMessages.innerHTML = '';
                if (messages.length === 0) {
                    chatMessages.innerHTML = `
                        <div class="system-message">
                            <div class="system-bubble">
                                <i class="fas fa-info-circle"></i> No messages yet. Start the conversation!
                            </div>
                        </div>`;
                    return;
                }

                messages.forEach(msg => {
                    const isOwn = msg.user_id == userId;
                    const initial = msg.sender_name.charAt(0).toUpperCase();
                    
                    // Removed meeting message handling logic
                    
                    const messageDiv = document.createElement('div');
                    messageDiv.className = `message ${isOwn ? 'own' : ''}`;
                    messageDiv.innerHTML = `
                        <div class="message-avatar">${initial}</div>
                        <div class="message-content">
                            <div class="message-info">
                                <strong>${isOwn ? 'You' : msg.sender_name}</strong>
                                <span>${new Date(msg.sent_at).toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' })}</span>
                            </div>
                            <div class="message-bubble">${msg.message_text}</div>
                        </div>
                    `;
                    chatMessages.appendChild(messageDiv);
                });
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            // Render members
            function renderMembers(members) {
                membersList.innerHTML = '';
                if (members.length === 0) {
                    membersList.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No Members</h3>
                            <p>No members found in this group</p>
                        </div>`;
                    return;
                }

                members.forEach(member => {
                    const memberDiv = document.createElement('div');
                    memberDiv.className = 'member-item';
                    const initial = member.name.charAt(0).toUpperCase();
                    memberDiv.innerHTML = `
                        <div class="member-avatar">
                            ${initial}
                            <div class="status-indicator status-${member.status}"></div>
                        </div>
                        <div class="member-info">
                            <div class="member-name">${member.name}${member.user_id == userId ? ' (You)' : ''}</div>
                            <div class="member-status">${member.status.charAt(0).toUpperCase() + member.status.slice(1)}</div>
                        </div>
                    `;
                    membersList.appendChild(memberDiv);
                });
            }

            // Send message
            async function sendMessage() {
                const message = messageInput.value.trim();
                if (!message || !currentGroupId) return;

                try {
                    const response = await fetch('send_group_message.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        // messageType is fixed to 'text' as meeting functionality is removed
                        body: JSON.stringify({ groupId: currentGroupId, messageText: message, messageType: 'text' })
                    });
                    const result = await response.json();
                    if (result.success) {
                        messageInput.value = '';
                        await loadGroupData(currentGroupId);
                    } else {
                        showNotification(`Error: ${result.error}`, 'error');
                    }
                } catch (error) {
                    console.error('Error sending message:', error);
                    showNotification('Failed to send message', 'error');
                }
            }

            // Removed createJitsiMeeting function

            // Removed copyToClipboard function

            // Show notification
            function showNotification(message, type) {
                const notification = document.getElementById('notification');
                notification.textContent = message;
                notification.className = `notification ${type}`;
                notification.classList.add('show');
                setTimeout(() => {
                    notification.classList.remove('show');
                }, 4000);
            }

            // Handle Enter key
            messageInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    sendMessage();
                }
            });

            // Removed event listener for Jitsi link button

            // Expose sendMessage to global scope for the button's onclick
            window.sendMessage = sendMessage;
            
            // Initial fetch
            fetchGroups();
            resetChatInterface();
        });
    </script>
</body>
</html>