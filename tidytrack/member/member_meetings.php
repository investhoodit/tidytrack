<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Member';
$userEmail = $_SESSION['email'] ?? null;
$userInitial = strtoupper(substr($userName, 0, 1));

if (!$userId) { die("Unauthorized access"); }

$stmt = $conn->prepare("SELECT status, profile_picture, role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$currentStatus = $userData['status'] ?? 'online';
$profilePicture = $userData['profile_picture'] ?? null;
$currentRole = $userData['role'] ?? 'Member';
$stmt->close();

// Fetch users and groups for invite modal
$users = [];
$stmt = $conn->prepare("SELECT user_id, name, role FROM users WHERE user_id != ? ORDER BY name");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $users[] = $row;
$stmt->close();

$groups = [];
$stmt = $conn->prepare("SELECT g.group_id, g.group_name FROM groups g JOIN group_members gm ON g.group_id = gm.group_id WHERE gm.user_id = ? AND gm.status = 'approved'");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $groups[] = $row;
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSM Meeting</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
    <style>
        :root {
            --bg: #f8fafc; --panel: #ffffff; --muted: #e2e8f0; --accent: #4361ee; --accent-hover: #3f37c9;
            --text: #1e293b; --sub: #64748b; --border: #cbd5e1; --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); --radius: 16px; --transition: all 0.25s ease;
        }
        [data-theme="dark"] {
            --bg: #0f172a; --panel: #1e293b; --muted: #334155; --accent: #60a5fa; --accent-hover: #3b82f6;
            --text: #e2e8f0; --sub: #94a3b8; --border: #334155; --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow: 0 10px 25px -5px rgba(0,0,0,0.4), 0 10px 10px -5px rgba(0,0,0,0.2);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; background: var(--bg); font-family: 'Inter', sans-serif; color: var(--text); }

    /* HOME ICON */
    .home-icon {
        position: fixed; top: 20px; left: 20px; z-index: 9999; background: var(--panel); width: 48px; height: 48px;
        border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);
        cursor: pointer; transition: var(--transition); font-size: 1.5rem; color: var(--accent);
    }
    .home-icon:hover { transform: scale(1.1); box-shadow: var(--shadow); }

    .loading-screen { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: var(--panel); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 9999; animation: fadeOut 0.6s ease forwards 2.5s; }
    .loading-screen h1 { font-size: 2.8rem; font-weight: 700; margin-bottom: 1rem; color: var(--accent); }
    .loader { width: 120px; height: 120px; border: 8px solid var(--muted); border-top: 8px solid var(--accent); border-radius: 50%; animation: spin 1.2s linear infinite; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    @keyframes fadeOut { to { opacity: 0; visibility: hidden; } }

    .invite-alert { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: var(--accent); color: #fff; padding: 12px 28px; border-radius: 12px; box-shadow: var(--shadow); z-index: 999; display: none; animation: slideDown 0.4s ease; }
    @keyframes slideDown { from { transform: translateX(-50%) translateY(-20px); opacity: 0; } to { transform: translateX(-50%) translateY(0); opacity: 1; } }

    .window { max-width: 1100px; margin: 80px auto 40px; background: var(--panel); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; position: relative; }
    .chrome { position: absolute; top: 16px; left: 20px; display: flex; gap: 12px; z-index: 10; }
    .circle { width: 14px; height: 14px; border-radius: 50%; }
    .c-red { background: #ff5f57; } .c-yellow { background: #febc2e; } .c-green { background: #28c840; }

    .header { display: flex; justify-content: space-between; align-items: center; padding: 24px 32px 16px; background: var(--panel); border-bottom: 1px solid var(--border); }
    .title { font-size: 2.2rem; font-weight: 700; }
    .room-code { font-size: 1.1rem; color: var(--sub); margin-left: 12px; background: var(--muted); padding: 4px 12px; border-radius: 8px; }
    .join-box { display: flex; gap: 12px; align-items: center; }
    .join-box input { padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px; width: 260px; font-size: 1rem; transition: var(--transition); background: var(--panel); color: var(--text); }
    .join-box input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(67,97,238,0.2); }
    .join-box button { padding: 12px 24px; background: var(--accent); color: #fff; border: none; border-radius: 12px; cursor: pointer; font-weight: 600; transition: var(--transition); }
    .join-box button:hover { background: var(--accent-hover); transform: translateY(-1px); }

    .video-area { background: var(--muted); border-radius: 0 0 var(--radius) var(--radius); min-height: 560px; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; padding: 24px; position: relative; }
    .participant { background: var(--panel); border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-sm); transition: var(--transition); display: flex; flex-direction: column; justify-content: center; align-items: center; position: relative; }
    .participant:hover { transform: translateY(-4px); box-shadow: var(--shadow); }
    .avatar-initial { width: 120px; height: 120px; border-radius: 50%; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 3rem; font-weight: 700; box-shadow: 0 8px 20px rgba(67,97,238,0.3); }
    .participant img, .participant video { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; box-shadow: 0 8px 20px rgba(0,0,0,0.2); }
    .participant-name { margin-top: 12px; font-weight: 600; font-size: 1.1rem; }
    .participant-role { font-size: 0.9rem; color: var(--sub); }

    .toolbar { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--panel); border-radius: 50px; padding: 12px 20px; box-shadow: var(--shadow); display: flex; gap: 16px; align-items: center; z-index: 100; }
    .tool { background: transparent; border: none; color: var(--text); font-size: 2.4rem; cursor: pointer; transition: var(--transition); position: relative; }
    .tool:hover { transform: scale(1.15); }
    .tool.active { color: var(--accent); }
    .tool.recording::after { content: '●'; color: red; font-size: 0.8rem; position: absolute; top: -8px; right: -8px; animation: blink 1s infinite; }
    @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }
    .tool-end { color: #ef4444; }
    .tool-end:hover { color: #dc2626; }

    .reaction { position: fixed; font-size: 3rem; pointer-events: none; animation: reactFloat 1.8s ease forwards; z-index: 50; }
    @keyframes reactFloat { 0% { transform: translateY(0) scale(1); opacity: 1; } 100% { transform: translateY(-180px) scale(1.4); opacity: 0; } }

    #participantsPanel, #chatPanel { position: fixed; right: 20px; background: var(--panel); border-radius: var(--radius); box-shadow: var(--shadow); z-index: 100; display: none; flex-direction: column; }
    #participantsPanel { top: 120px; width: 300px; max-height: calc(100vh - 240px); }
    #participantsPanel h3, #chatPanel h3 { padding: 16px; border-bottom: 1px solid var(--border); font-weight: 600; }
    #participantsList, #chatMessages { flex: 1; overflow-y: auto; padding: 12px; }
    .participant-item { display: flex; align-items: center; gap: 12px; padding: 8px 12px; border-bottom: 1px solid var(--border); }
    .participant-item:last-child { border-bottom: none; }
    .participant-item .avatar-initial { width: 40px; height: 40px; font-size: 1.2rem; }

    #chatPanel { bottom: 100px; width: 340px; height: 500px; }
    #chatPanel h3 { display: flex; justify-content: space-between; align-items: center; }
    #chatInput { flex: 1; padding: 10px; border: 1px solid var(--border); border-radius: 12px; }
    #chatSendBtn { padding: 10px 16px; background: var(--accent); color: #fff; border: none; border-radius: 12px; cursor: pointer; }

    #inviteModal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); align-items: center; justify-content: center; z-index: 1000; }
    .modal-content { background: var(--panel); border-radius: var(--radius); width: 90%; max-width: 560px; padding: 28px; box-shadow: var(--shadow); }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .modal-title { font-size: 1.6rem; font-weight: 700; }
    .close-modal { font-size: 2rem; cursor: pointer; color: var(--sub); }
    .tab-buttons { display: flex; gap: 8px; margin-bottom: 20px; }
    .tab-btn { padding: 10px 20px; border-radius: 12px; background: var(--muted); cursor: pointer; transition: var(--transition); }
    .tab-btn.active { background: var(--accent); color: #fff; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .search-box { margin-bottom: 16px; }
    .search-box input { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--panel); color: var(--text); }
    .user-list, .group-list { max-height: 320px; overflow-y: auto; border: 1px solid var(--border); border-radius: 12px; }
    .user-item, .group-item { padding: 12px 16px; display: flex; align-items: center; gap: 12px; cursor: pointer; transition: var(--transition); border-bottom: 1px solid var(--border); }
    .user-item:last-child, .group-item:last-child { border-bottom: none; }
    .user-item:hover, .group-item:hover { background: #f1f5f9; }
    .user-item input[type="checkbox"], .group-item input[type="checkbox"] { transform: scale(1.3); }
    .selected-count { margin: 16px 0; font-weight: 600; color: var(--accent); }
    .modal-actions { display: flex; gap: 12px; justify-content: flex-end; }
    .copy-btn { background: var(--accent); color: #fff; padding: 12px 24px; border: none; border-radius: 12px; cursor: pointer; font-weight: 600; }
    .copy-btn:hover { background: var(--accent-hover); }

    #emojiPicker { display: none; position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%); background: var(--panel); border-radius: 16px; padding: 12px; box-shadow: var(--shadow); z-index: 200; }
    #emojiPicker div { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; font-size: 2.2rem; cursor: pointer; }

    @media (max-width: 768px) {
        .window { margin: 70px 16px 40px; }
        .video-area { grid-template-columns: 1fr; gap: 12px; padding: 16px; }
        .toolbar { gap: 12px; padding: 10px 16px; }
        .tool { font-size: 2rem; }
        #chatPanel, #participantsPanel { width: 100%; right: 0; left: 0; bottom: 80px; border-radius: 16px 16px 0 0; }
        .home-icon { top: 16px; left: 16px; width: 42px; height: 42px; font-size: 1.3rem; }
    }
</style></head>
<body>

<!-- HOME ICON -->
<a href="member_dashboard.php" class="home-icon" title="Back to Dashboard">
    <i class="fas fa-home"></i>
</a>

<div class="loading-screen" id="loading">
    <h1>TSM Discussion</h1>
    <div class="loader"></div>
</div>
<div class="invite-alert" id="inviteAlert">Copied!</div>

<div class="window">
    <div class="chrome">
        <div class="circle c-red"></div><div class="circle c-yellow"></div><div class="circle c-green"></div>
    </div>

    <header class="header">
        <div style="display:flex;align-items:center;">
            <div class="title">Meeting</div>
            <div class="room-code" id="roomCodeDisplay">No room yet</div>
        </div>
        <div class="join-box">
            <input type="text" id="meetingCode" placeholder="Enter Code">
            <button id="joinBtn">Join Meeting</button>
        </div>
    </header>

    <section class="video-area" id="videoArea">
        <div class="participant" id="localParticipant">
            <div id="localStaticContent">
                <?php if ($profilePicture && file_exists($profilePicture)): ?>
                    <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="You">
                <?php else: ?>
                    <div class="avatar-initial"><?php echo $userInitial; ?></div>
                <?php endif; ?>
                <div class="participant-name"><?php echo htmlspecialchars($userName); ?> (You)</div>
                <div class="participant-role"><?php echo htmlspecialchars($currentRole); ?></div>
            </div>
        </div>
    </section>
</div>

<!-- PARTICIPANTS LIST -->
<div id="participantsPanel">
    <h3>Participants <span id="participantCount">1</span></h3>
    <div id="participantsList"></div>
</div>

<!-- CHAT PANEL -->
<div id="chatPanel">
    <h3>Meeting Chat <button onclick="toggleChat()" style="background:none;border:none;font-size:1.2rem;cursor:pointer;">X</button></h3>
    <div id="chatMessages"></div>
    <div style="padding:12px;border-top:1px solid var(--border);display:flex;gap:8px;">
        <input type="text" id="chatInput" placeholder="Type a message..." onkeypress="if(event.key==='Enter') sendChat()">
        <button id="chatSendBtn">Send</button>
    </div>
</div>

<!-- TOOLBAR -->
<nav class="toolbar">
    <button class="tool active" id="micBtn" title="Mute/Unmute"><i class="fas fa-microphone"></i></button>
    <button class="tool" id="screenBtn" title="Share Screen"><i class="fas fa-desktop"></i></button>
    <button class="tool" id="recordBtn" title="Record"><i class="fas fa-circle"></i></button>
    <button class="tool" id="reactBtn" title="React"><i class="far fa-smile"></i></button>
    <button class="tool" id="chatBtn" title="Chat"><i class="fas fa-comment"></i></button>
    <button class="tool" id="participantsBtn" title="Participants"><i class="fas fa-users"></i></button>
    <button class="tool" id="inviteBtn" title="Invite"><i class="fas fa-link"></i></button>
    <button class="tool tool-end" id="endBtn" title="End Meeting"><i class="fas fa-phone-slash"></i></button>
</nav>

<!-- EMOJI PICKER -->
<div id="emojiPicker">
    <div id="emojiPickerContent">
    <span class="emoji" data-emoji="thumbs up">👍</span>
    <span class="emoji" data-emoji="heart">❤️</span>
    <span class="emoji" data-emoji="laughing face">😂</span>
    <span class="emoji" data-emoji="party popper">🎉</span>
    <span class="emoji" data-emoji="fire">🔥</span>
    <span class="emoji" data-emoji="rocket">🚀</span>
    </div>
</div>

<!-- INVITE MODAL -->
<div id="inviteModal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">Invite to Meeting</div>
            <span class="close-modal" onclick="closeInviteModal()">X</span>
        </div>
        <div class="tab-buttons">
            <div class="tab-btn active" data-tab="code">Copy Code</div>
            <div class="tab-btn" data-tab="users">Select Users</div>
            <div class="tab-btn" data-tab="groups">Select Groups</div>
        </div>
        <div class="tab-content active" id="code">
            <p>Share this code:</p>
            <h2 id="modalCode" style="color:var(--accent);letter-spacing:2px;">-</h2>
            <button class="copy-btn" id="copyCodeBtn">Copy Code</button>
        </div>
        <div class="tab-content" id="users">
            <div class="search-box"><input type="text" placeholder="Search users..." onkeyup="filterList('user')"></div>
            <div class="user-list">
                <?php foreach ($users as $u): ?>
                <label class="user-item">
                    <input type="checkbox" value="<?php echo $u['user_id']; ?>">
                    <span><?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['role']); ?>)</span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="selected-count">Selected: <span id="userCount">0</span></div>
            <div class="modal-actions">
                <button class="copy-btn" id="inviteUsersBtn">Invite Selected</button>
            </div>
        </div>
        <div class="tab-content" id="groups">
            <div class="search-box"><input type="text" placeholder="Search groups..." onkeyup="filterList('group')"></div>
            <div class="group-list">
                <?php foreach ($groups as $g): ?>
                <label class="group-item">
                    <input type="checkbox" value="<?php echo $g['group_id']; ?>">
                    <span><?php echo htmlspecialchars($g['group_name']); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="selected-count">Selected: <span id="groupCount">0</span></div>
            <div class="modal-actions">
                <button class="copy-btn" id="inviteGroupsBtn">Invite Selected Groups</button>
            </div>
        </div>
    </div>
</div>

<script src="theme_switcher.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", () => {
        // --- UI Elements ---
        const videoArea = document.getElementById("videoArea");
        const localParticipant = document.getElementById("localParticipant");
        const localStaticContent = document.getElementById("localStaticContent");
        const roomCodeDisplay = document.getElementById("roomCodeDisplay");
        const modalCode = document.getElementById("modalCode");
        const inviteModal = document.getElementById("inviteModal");
        const inviteAlert = document.getElementById("inviteAlert");
        const micBtn = document.getElementById("micBtn");
        const screenBtn = document.getElementById("screenBtn");
        const recordBtn = document.getElementById("recordBtn");
        const reactBtn = document.getElementById("reactBtn");
        const emojiPicker = document.getElementById("emojiPicker");
        const inviteBtn = document.getElementById("inviteBtn");
        const joinBtn = document.getElementById("joinBtn");
        const meetingCodeInput = document.getElementById("meetingCode");
        const endBtn = document.getElementById("endBtn");
        const chatBtn = document.getElementById("chatBtn");
        const chatPanel = document.getElementById("chatPanel");
        const chatMessages = document.getElementById("chatMessages");
        const chatInput = document.getElementById("chatInput");
        const participantsBtn = document.getElementById("participantsBtn");
        const participantsPanel = document.getElementById("participantsPanel");
        const participantsList = document.getElementById("participantsList");
        const participantCount = document.getElementById("participantCount");

        // --- Global State ---
        let roomCode = "";
        let isHost = false;   // ← THIS IS THE ONLY NEW LINE (tracks who created the room)
        const userId = <?php echo json_encode($_SESSION['user_id'] ?? 0); ?>;
        let localStream = null;
        let screenStream = null;
        let recorder = null;
        let isMicOn = true;
        let isRecording = false;
        
        // --- WebRTC State ---
        const peers = {};
        const remoteAudios = {};
        const iceQueue = {};
        const configuration = { 
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' }
            ] 
        };

        // AUTO-REJOIN FROM URL
        const urlParams = new URLSearchParams(window.location.search);
        const codeFromUrl = urlParams.get("code");
        if (codeFromUrl) {
            roomCode = codeFromUrl.toUpperCase();
            roomCodeDisplay.textContent = roomCode;
            meetingCodeInput.value = codeFromUrl;
            meetingCodeInput.disabled = true;
            joinBtn.disabled = true;
            isHost = false; // If joining via link, not host
            initLocalStream().then(startPolling);
        }
        
        // --- CORE FUNCTIONS (INIT) ---
        async function initLocalStream() {
            try {
                localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
                isMicOn = true;
            } catch (err) {
                console.error("Failed to get local stream (mic access):", err);
                alert("Microphone access denied. Please allow it to join the meeting.");
                isMicOn = false;
                micBtn.classList.remove("active");
                micBtn.innerHTML = '<i class="fas fa-microphone-slash"></i>';
                localStream = new MediaStream();
            }
        }

        function startPolling() {
            if (!localStream) return;
            pollParticipants(); pollSignals(); pollChat(); pollReactions();
            setInterval(pollParticipants, 3000);
            setInterval(pollSignals, 1500);
            setInterval(pollChat, 2000);
            setInterval(pollReactions, 1000);
            setInterval(renegotiateAll, 40000); 
        }
        
        // --- WEBRTC ---
        function createPeerConnection(uid) {
            if (peers[uid]) return peers[uid];

            const pc = new RTCPeerConnection(configuration);
            peers[uid] = pc;

            if (localStream) {
                localStream.getTracks().forEach(track => {
                    pc.addTrack(track, localStream);
                });
            }

            pc.ontrack = e => {
                let audioEl = remoteAudios[uid];
                if (!audioEl) {
                    audioEl = document.createElement("audio");
                    audioEl.autoplay = true; 
                    audioEl.id = "audio-" + uid; 
                    document.body.appendChild(audioEl);
                    remoteAudios[uid] = audioEl;
                }
                audioEl.srcObject = e.streams[0];
                audioEl.play().catch(err => console.warn("Audio auto-play blocked for user", uid, err));
            };

            pc.onicecandidate = e => {
                if (e.candidate) sendSignal({ type: 'candidate', candidate: e.candidate, to: uid });
            };

            pc.onconnectionstatechange = () => {
                if (pc.connectionState === 'failed' || pc.connectionState === 'closed' || pc.connectionState === 'disconnected') {
                    delete peers[uid];
                }
            };
            
            callUser(uid); 
            return pc;
        }

        async function callUser(uid) {
            const pc = peers[uid];
            if (!pc) return;

            try {
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                sendSignal({ type: 'offer', sdp: pc.localDescription, to: uid });
            } catch (err) { console.error("Error creating/sending offer:", err); }
        }

        async function handleSignal(data) {
            let pc = peers[data.from];
            if (!pc) {
                pc = createPeerConnection(data.from);
            }
            if (!pc) return console.error(`Failed to find or create peer for ${data.from}`);

            try {
                if (data.type === 'offer') {
                    await pc.setRemoteDescription(new RTCSessionDescription(data.sdp));
                    const answer = await pc.createAnswer();
                    await pc.setLocalDescription(answer);
                    sendSignal({ type: 'answer', sdp: pc.localDescription, to: data.from });
                } else if (data.type === 'answer') {
                    await pc.setRemoteDescription(new RTCSessionDescription(data.sdp));
                    if (iceQueue[data.from]) {
                        iceQueue[data.from].forEach(candidate => {
                            pc.addIceCandidate(new RTCIceCandidate(candidate)).catch(e => console.error("Error adding queued ICE candidate:", e));
                        });
                        delete iceQueue[data.from];
                    }
                } else if (data.type === 'candidate') {
                    const candidate = new RTCIceCandidate(data.candidate);
                    if (pc.remoteDescription) {
                        await pc.addIceCandidate(candidate);
                    } else {
                        iceQueue[data.from] = iceQueue[data.from] || [];
                        iceQueue[data.from].push(candidate);
                    }
                }
            } catch (err) { console.error(`Error handling signal ${data.type} from ${data.from}:`, err); }
        }

        async function sendSignal(sig) {
            const payload = {
                room: roomCode,
                signal: JSON.stringify({
                    type: sig.type,
                    sdp: sig.sdp || null,
                    candidate: sig.candidate || null,
                    to: sig.to || null,
                    from: userId
                })
            };
            await fetch("signal_meeting.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            });
        }
        
        function renegotiateAll() {
            Object.keys(peers).forEach(id => callUser(parseInt(id)));
        }

        async function pollSignals() {
            if (!roomCode) return;
            const res = await fetch("get_signals.php?room=" + roomCode + "&t=" + Date.now());
            const signals = await res.json();
            for (const sig of signals) {
                if (sig.from != userId) {
                    handleSignal(sig);
                }
            }
        }

        // --- PARTICIPANT MANAGEMENT ---
        async function pollParticipants() {
            if (!roomCode) return;
            const res = await fetch("participants_meeting.php?room=" + roomCode);
            const users = await res.json();
            const activeIds = users.map(u => u.id).filter(id => id != userId);

            Object.keys(peers).forEach(id => {
                const uId = parseInt(id);
                if (!activeIds.includes(uId)) {
                    if (peers[uId]) peers[uId].close();
                    delete peers[uId];
                    if (remoteAudios[uId]) remoteAudios[uId].remove();
                    delete remoteAudios[uId];
                    const el = document.getElementById("part-" + uId);
                    if (el) el.remove();
                }
            });

            videoArea.innerHTML = ""; 
            videoArea.appendChild(localParticipant);
            participantsList.innerHTML = ""; 
            participantCount.textContent = users.length;

            users.forEach(u => {
                if (u.id == userId) {
                    const item = document.createElement("div"); item.className = "participant-item";
                    item.innerHTML = `<div class="avatar-initial"><?php echo $userInitial; ?></div><div><strong><?php echo htmlspecialchars($userName); ?> (You)</strong><small><?php echo htmlspecialchars($currentRole); ?></small></div>`;
                    participantsList.appendChild(item);
                    return;
                }

                let div = document.getElementById("part-" + u.id);
                if (!div) {
                    div = document.createElement("div"); div.className = "participant"; div.id = "part-" + u.id;
                    const avatarHtml = u.profile_picture ? `<img src="${u.profile_picture}" alt="${u.name[0]}">` : `<div class="avatar-initial">${u.name[0]}</div>`;
                    div.innerHTML = `${avatarHtml}<div class="participant-name">${u.name}</div><div class="participant-role">${u.role}</div>`;
                    videoArea.appendChild(div);
                }
                
                createPeerConnection(u.id);
                
                const item = document.createElement("div"); item.className = "participant-item";
                item.innerHTML = `<div class="avatar-initial">${u.name[0]}</div><div><strong>${u.name}</strong><small>${u.role}</small></div>`;
                participantsList.appendChild(item);
            });
        }

        // --- MIC CONTROL ---
        micBtn.addEventListener("click", async () => {
            if (!localStream) await initLocalStream(); 
            if (!localStream) return;

            isMicOn = !isMicOn;
            localStream.getAudioTracks().forEach(track => track.enabled = isMicOn);
            
            micBtn.classList.toggle("active", isMicOn);
            micBtn.innerHTML = isMicOn ? '<i class="fas fa-microphone"></i>' : '<i class="fas fa-microphone-slash"></i>';
            renegotiateAll();
        });

        // --- SCREEN SHARE ---
        screenBtn.addEventListener("click", async () => {
            if (!localStream) await initLocalStream();

            if (screenStream) {
                screenStream.getTracks().forEach(t => t.stop());
                screenStream = null;
                screenBtn.classList.remove("active");
                screenBtn.innerHTML = '<i class="fas fa-desktop"></i>';
                replaceLocalTracks(localStream, false); 
                return;
            }

            try {
                const displayMediaStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
                screenStream = displayMediaStream;
                
                screenStream.getVideoTracks()[0].onended = () => screenBtn.click();

                const mergedStream = new MediaStream([
                    ...screenStream.getVideoTracks(),
                    ...(localStream.getAudioTracks().length ? localStream.getAudioTracks() : [])
                ]);

                replaceLocalTracks(mergedStream, true);
                
                screenBtn.classList.add("active");
                screenBtn.innerHTML = '<i class="fas fa-stop"></i>';
                
            } catch (err) { 
                console.error("Error starting screen share:", err); 
            }
        });

        function replaceLocalTracks(newStream, isScreenShare = false) {
            const newAudioTrack = newStream.getAudioTracks()[0];
            const newVideoTrack = newStream.getVideoTracks()[0];
            
            let localVideo = document.getElementById('localVideoFeed');

            if (isScreenShare) {
                localStaticContent.style.display = 'none';
                if (!localVideo) {
                    localVideo = document.createElement('video');
                    localVideo.id = 'localVideoFeed';
                    localVideo.autoplay = true;
                    localVideo.muted = true;
                    localParticipant.appendChild(localVideo);
                }
                localVideo.srcObject = newStream;
            } else {
                localStaticContent.style.display = 'flex';
                if (localVideo) localVideo.remove();
            }

            Object.values(peers).forEach(pc => {
                const videoSender = pc.getSenders().find(s => s.track && s.track.kind === 'video');
                if (newVideoTrack) {
                    if (videoSender) videoSender.replaceTrack(newVideoTrack);
                    else pc.addTrack(newVideoTrack, newStream);
                } else if (videoSender) pc.removeTrack(videoSender);

                const audioSender = pc.getSenders().find(s => s.track && s.track.kind === 'audio');
                if (newAudioTrack && audioSender) {
                    newAudioTrack.enabled = isMicOn;
                    audioSender.replaceTrack(newAudioTrack);
                }
            });
        }

        // --- RECORDING ---
        recordBtn.addEventListener("click", async () => {
            if (!localStream && !screenStream) return alert("Join meeting or start mic/screen share first.");
            
            if (isRecording) {
                recorder.stop();
                isRecording = false;
                recordBtn.classList.remove("active", "recording");
                recordBtn.innerHTML = '<i class="fas fa-circle"></i>';
                return;
            }
            
            const tracks = [];
            if (localStream) localStream.getTracks().forEach(t => tracks.push(t));
            if (screenStream) screenStream.getTracks().forEach(t => { if (!tracks.includes(t)) tracks.push(t); });

            const stream = new MediaStream(tracks);
            recorder = new MediaRecorder(stream);
            const chunks = [];
            recorder.ondataavailable = e => chunks.push(e.data);
            recorder.onstop = () => {
                const blob = new Blob(chunks, { type: 'video/webm' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `meeting-${roomCode}-${new Date().toISOString().slice(0,10)}.webm`;
                a.click();
            };
            recorder.start();
            isRecording = true;
            recordBtn.classList.add("active", "recording");
            recordBtn.innerHTML = '<i class="fas fa-stop"></i>';
        });

        // --- JOIN / CREATE ROOM (HOST DETECTION ADDED) ---
        joinBtn.addEventListener("click", async () => {
            let code = meetingCodeInput.value.trim().toUpperCase();

            if (!code) {
                const res = await fetch("create_room_meeting.php", {method: "POST", headers: {"Content-Type": "application/json"}, body: "{}"});
                const data = await res.json(); 
                code = data.room;
                isHost = true;   // ← YOU ARE THE HOST
            } else {
            // JOIN EXISTING → YOU ARE PARTICIPANT
                const res = await fetch("join_room_meeting.php", { 
                    method: "POST", 
                    headers: { "Content-Type": "application/json" }, 
                    body: JSON.stringify({ room: code }) 
                });
                if (!res.ok) return alert("Invalid code!");
                isHost = false;
            }

            roomCode = code; 
            roomCodeDisplay.textContent = roomCode; 
            meetingCodeInput.disabled = true; 
            joinBtn.disabled = true;
            document.querySelector(".join-box").style.opacity = "0.6";

            // ONLY HOST CAN END MEETING — FIXED FOREVER
            if (isHost) {
                endBtn.style.opacity = "1";
                endBtn.style.pointerEvents = "auto";
                endBtn.title = "End meeting for everyone";
                endBtn.classList.add("host-end");
            } else {
                endBtn.style.opacity = "0.4";
                endBtn.style.pointerEvents = "none";
                endBtn.title = "Only the host can end the meeting";
            }

            await initLocalStream();
            startPolling();
        });

       // END MEETING — ONLY HOST CAN CLICK
        endBtn.addEventListener("click", () => {
            if (!isHost) {
                if (confirm("Leave the meeting?")) {
                    location.href = "member_dashboard.php";
                }
                return;
            }

            if (confirm("END meeting for everyone? This will close the room.")) {
                fetch("end_meeting.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ room: roomCode })
                }).finally(() => {
                    location.href = "member_dashboard.php";
                });
            }
        });

        // --- MODAL, CHAT, REACTIONS, PARTICIPANTS (UNCHANGED) ---
        function openInviteModal() { if (!roomCode) return alert("Join a room first!"); modalCode.textContent = roomCode; inviteModal.style.display = "flex"; updateSelectedCounts(); }
        function closeInviteModal() { inviteModal.style.display = "none"; }
        inviteBtn.addEventListener("click", openInviteModal);
        document.querySelectorAll(".close-modal").forEach(el => el.addEventListener("click", closeInviteModal));

        document.querySelectorAll(".tab-btn").forEach(btn => {
            btn.addEventListener("click", () => {
                document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
                document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"));
                btn.classList.add("active");
                document.getElementById(btn.dataset.tab).classList.add("active");
            });
        });

        document.getElementById("copyCodeBtn").addEventListener("click", () => {
            navigator.clipboard.writeText(roomCode);
            showAlert("Code copied!");
        });

        function updateSelectedCounts() {
            document.getElementById("userCount").textContent = document.querySelectorAll("#users input:checked").length;
            document.getElementById("groupCount").textContent = document.querySelectorAll("#groups input:checked").length;
        }
        document.querySelectorAll("#users input, #groups input").forEach(ch => ch.addEventListener("change", updateSelectedCounts));

        document.getElementById("inviteUsersBtn").addEventListener("click", async () => {
            const selected = Array.from(document.querySelectorAll("#users input:checked")).map(ch => ch.value);
            if (!selected.length) return alert("Select users");
            await fetch("invite_meeting.php", {method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({room: roomCode, users: selected})});
            showAlert("Invited " + selected.length + " users");
            closeInviteModal();
        });

        document.getElementById("inviteGroupsBtn").addEventListener("click", async () => {
            const selected = Array.from(document.querySelectorAll("#groups input:checked")).map(ch => ch.value);
            if (!selected.length) return alert("Select groups");
            await fetch("invite_meeting.php", {method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({room: roomCode, groups: selected})});
            showAlert("Invited groups");
            closeInviteModal();
        });

        function filterList(type) {
            const term = event.target.value.toLowerCase(); 
            document.querySelectorAll(`.${type}-item`).forEach(item => {
                item.style.display = item.textContent.toLowerCase().includes(term) ? "flex" : "none";
            });
        }

         const emojiMap = {
            "thumbs up": "👍", 
            "heart": "❤️",
            "laughing face": "😂", 
            "party popper": "🎉", 
            "fire": "🔥", 
            "rocket": "🚀"
        };

        reactBtn.addEventListener("click", e => { e.stopPropagation(); emojiPicker.style.display = emojiPicker.style.display === "block" ? "none" : "block"; });
        document.addEventListener("click", e => { if (!reactBtn.contains(e.target) && !emojiPicker.contains(e.target)) emojiPicker.style.display = "none"; });

        document.querySelectorAll(".emoji").forEach(el => {
            el.addEventListener("click", () => {
                const key = el.dataset.emoji;
                const emoji = emojiMap[key];
                sendReactionToServer(emoji);
                showReaction(emoji);
                emojiPicker.style.display = "none";
            });
        });

        function showReaction(emoji) {
            const el = document.createElement("div");
            el.className = "reaction";
            el.textContent = emoji;
            el.style.left = Math.random() * 70 + 15 + "%";
            el.style.bottom = "120px";
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 2000);
        }

        async function sendReactionToServer(emoji) {
            if (!roomCode) return;
            await fetch("send_reaction.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ room: roomCode, emoji: emoji }) });
        }

        async function pollReactions() {
            if (!roomCode) return;
            const res = await fetch("get_reactions.php?room=" + roomCode + "&t=" + Date.now());
            const reactions = await res.json();
            reactions.forEach(r => { if (r.user_id != userId) showReaction(r.emoji); });
        }

        function toggleParticipants() { participantsPanel.style.display = participantsPanel.style.display === "flex" ? "none" : "flex"; }
        participantsBtn.addEventListener("click", toggleParticipants);

        document.addEventListener("click", e => {
            if (chatPanel.style.display === "flex" && !chatPanel.contains(e.target) && !chatBtn.contains(e.target)) {
                chatPanel.style.display = "none";
            }
        });

        function toggleChat() { chatPanel.style.display = chatPanel.style.display === "flex" ? "none" : "flex"; }
        chatBtn.addEventListener("click", toggleChat);
        document.getElementById("chatSendBtn").addEventListener("click", sendChat);
        chatInput.addEventListener("keypress", e => { if (e.key === "Enter") sendChat(); });
        
        async function sendChat() {
            const msg = chatInput.value.trim(); if (!msg || !roomCode) return;
            await fetch("send_chat.php", {method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify({room: roomCode, message: msg})});
            chatInput.value = "";
            pollChat();
        }

        async function pollChat() {
            if (!roomCode) return;
            const res = await fetch("get_chat.php?room=" + roomCode);
            const messages = await res.json();
            chatMessages.innerHTML = "";
            messages.forEach(m => {
                const div = document.createElement("div"); div.style.marginBottom = "12px";
                div.innerHTML = `<strong>${m.name}:</strong> ${m.message}<small style="color:var(--sub)">${new Date(m.sent_at).toLocaleTimeString()}</small>`;
                chatMessages.appendChild(div);
            });
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function showAlert(msg) {
            inviteAlert.innerText = msg;
            inviteAlert.style.display = "block";
            setTimeout(() => inviteAlert.style.display = "none", 2500);
        }
    });
</script>
</body>
</html>

