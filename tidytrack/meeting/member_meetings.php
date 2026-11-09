<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../dp.php';

if (!isset($_SESSION['name']) || !isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userName = $_SESSION['name'];
$userRole = $_SESSION['role'] ?? 'member';
$userId   = $_SESSION['user_id'];

$role = strtolower($userRole);
$dashboardLink = '../logout.php';
if ($role === 'member')  $dashboardLink = '../member/member_dashboard.php';
elseif ($role === 'leader')  $dashboardLink = '../leader/leader_index.php';
elseif ($role === 'manager') $dashboardLink = '../manager/manager_index.php';
elseif ($role === 'admin')   $dashboardLink = '../admin/admin_index.php';

// Sanitise room code
$roomCode = isset($_GET['code']) 
    ? strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['code'])) 
    : '';

$inMeeting = ($roomCode !== '');

// Log join
if ($inMeeting) {
    $stmt = $conn->prepare("INSERT INTO meeting_logs (user_id, user_name, room_code, role, joined_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("isss", $userId, $userName, $roomCode, $userRole);
    $stmt->execute();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>TidyTrack Meet</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
 
<script src="https://meet.jit.si/external_api.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:      #0b0e14;
            --surface: #161b22;
            --border:  #2a3241;
            --purple:  #8b5cf6;
            --purple2: #7c3aed;
            --muted:   #8b949e;
            --text:    #e2e8f0;
            --red:     #ef4444;
            --amber:   #f59e0b;
        }

        body, html {
            width: 100%; height: 100%;
            overflow: hidden;
            background: var(--bg);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--text);
        }

        /* ── LOBBY ── */
        #lobby {
            display: <?= $inMeeting ? 'none' : 'flex' ?>;
            height: 100vh;
            align-items: center;
            justify-content: center;
            background: radial-gradient(ellipse 80% 60% at 50% 0%, rgba(139,92,246,.12) 0%, transparent 70%);
        }

        .lobby-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 48px 32px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(0,0,0,.6);
        }

        .lobby-logo {
            font-size: 2.2rem;
            font-weight: 700;
            background: linear-gradient(90deg, #a78bfa, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 6px;
        }
        .lobby-sub { font-size: 13px; color: var(--muted); margin-bottom: 32px; }

        .nav-icons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 28px;
        }
        .nav-icons a {
            width: 40px; height: 40px; border-radius: 10px;
            background: rgba(255,255,255,.06); border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            color: var(--muted); font-size: 16px; text-decoration: none;
            transition: all .2s;
        }
        .nav-icons a:hover { background: rgba(139,92,246,.2); color: var(--purple); border-color: var(--purple); }

        .lobby-greeting { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
        .lobby-greeting strong { color: var(--text); }

        .code-input {
            width: 100%;
            padding: 16px;
            border-radius: 14px;
            border: 2px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-size: 22px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 3px;
            text-transform: uppercase;
            outline: none;
            transition: border .2s;
            margin-bottom: 16px;
        }
        .code-input:focus { border-color: var(--purple); }
        .code-input::placeholder { font-size: 14px; letter-spacing: 1px; font-weight: 400; color: #444; }

        .btn-join {
            width: 100%;
            padding: 16px;
            border-radius: 14px;
            border: none;
            background: linear-gradient(135deg, var(--purple), var(--purple2));
            color: white;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
            box-shadow: 0 6px 24px rgba(139,92,246,.35);
        }
        .btn-join:hover { transform: translateY(-2px); box-shadow: 0 10px 32px rgba(139,92,246,.5); }
        .btn-join:active { transform: translateY(0); }

        /* ── HEADER BAR (in-meeting) ── */
        .header-bar {
            display: <?= $inMeeting ? 'flex' : 'none' ?>;
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 56px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            align-items: center;
            padding: 0 14px;
            gap: 10px;
            z-index: 100;
        }

        .hb-nav { display: flex; gap: 10px; }
        .hb-nav a {
            width: 34px; height: 34px; border-radius: 9px;
            background: rgba(255,255,255,.06); border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            color: var(--muted); font-size: 14px; text-decoration: none;
            transition: all .2s;
        }
        .hb-nav a:hover { color: var(--purple); border-color: var(--purple); }

        .hb-center {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            line-height: 1.2;
        }
        .hb-room { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
        #timerDisplay { font-size: 15px; font-weight: 700; color: var(--purple); font-variant-numeric: tabular-nums; }

        #closeChatBtn {
            display: none;
            padding: 7px 14px;
            border-radius: 8px;
            border: none;
            background: rgba(239,68,68,.15);
            color: var(--red);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
        }
        #closeChatBtn:hover { background: rgba(239,68,68,.3); }

        .btn-exit {
            padding: 8px 18px;
            border-radius: 9px;
            border: none;
            background: var(--red);
            color: white;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
            margin-left: auto;
        }
        .btn-exit:hover { background: #dc2626; transform: translateY(-1px); }

        /* ── MEETING CONTAINER ── */
        #meetContainer {
            display: <?= $inMeeting ? 'block' : 'none' ?>;
            position: fixed;
            top: 56px; left: 0; right: 0; bottom: 0;
        }

        /* ── WARNING TOAST ── */
        #warningToast {
            display: none;
            position: fixed;
            top: 68px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--amber);
            color: #000;
            padding: 10px 24px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            z-index: 999;
            box-shadow: 0 4px 20px rgba(245,158,11,.4);
            animation: slideDown .3s ease;
        }

        @keyframes slideDown {
            from { opacity:0; transform: translateX(-50%) translateY(-10px); }
            to   { opacity:1; transform: translateX(-50%) translateY(0); }
        }

        /* ── INSTRUCTION MODAL ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11,14,20,.92);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(6px);
        }
        .modal-overlay.open { display: flex; }

        .modal-box {
            background: linear-gradient(160deg, #161b22 0%, #0f141a 100%);
            border: 1px solid var(--border);
            border-radius: 22px;
            width: 90%;
            max-width: 480px;
            padding: 44px 32px;
            text-align: center;
            box-shadow: 0 28px 70px rgba(0,0,0,.7);
        }

        .modal-icon { font-size: 3rem; color: var(--purple); margin-bottom: 18px; display: block; }
        .modal-title {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 20px;
            background: linear-gradient(90deg, #a78bfa, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .modal-body { font-size: 1rem; color: #cbd5e1; line-height: 1.65; margin-bottom: 28px; }
        .modal-body strong { color: #d8b4fe; }

        .modal-examples {
            background: rgba(139,92,246,.08);
            border: 1px solid rgba(139,92,246,.2);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 32px;
            font-size: 1rem;
            line-height: 1.8;
            color: var(--text);
        }
        .modal-examples strong { display: block; color: #c084fc; margin-bottom: 8px; font-size: 1.05rem; }

        .modal-cta {
            width: 100%;
            padding: 16px;
            border-radius: 13px;
            border: none;
            background: linear-gradient(135deg, var(--purple), var(--purple2));
            color: white;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 28px rgba(139,92,246,.4);
            transition: all .2s;
        }
        .modal-cta:hover { transform: translateY(-2px); box-shadow: 0 12px 36px rgba(139,92,246,.55); }
        .modal-footer-note { margin-top: 16px; font-size: 12px; color: #475569; }

        @media (max-width: 480px) {
            .lobby-box { padding: 36px 20px; }
            .modal-box { padding: 32px 20px; }
            .header-bar { padding: 0 10px; }
        }
    </style>
</head>
<body>

<!-- ── INSTRUCTION MODAL (shows once) ── -->
<div id="instructionModal" class="modal-overlay">
    <div class="modal-box">
        <i class="fas fa-info-circle modal-icon"></i>
        <h2 class="modal-title">Use Your Team Code</h2>
        <div class="modal-body">
            <p>To join the right room and track attendance correctly, use your <strong>project-specific team code</strong>.</p>
        </div>
        <div class="modal-examples">
            <strong>Examples:</strong>
            TIDYTRACKER &nbsp;·&nbsp; RECRUITMENT<br>
            SIGNA &nbsp;·&nbsp; PROJECTX
        </div>
        <button class="modal-cta" onclick="closeModal()">Got it — Let's go</button>
        <div class="modal-footer-note">Won't show again after this.</div>
    </div>
</div>

<!-- ── WARNING TOAST ── -->
<div id="warningToast">⚠️ 5 minutes remaining!</div>

<!-- ── LOBBY ── -->
<div id="lobby">
    <div class="lobby-box">
        <div class="lobby-logo">TidyTrack Meet</div>
        <div class="lobby-sub">Powered by Jitsi</div>
        <div class="nav-icons">
            <a href="../index.php" title="Home"><i class="fas fa-house"></i></a>
            <a href="<?= $dashboardLink ?>" title="Dashboard"><i class="fas fa-gauge-high"></i></a>
        </div>
        <div class="lobby-greeting">Hi, <strong><?= htmlspecialchars($userName) ?></strong> 👋</div>
        <input
            type="text"
            id="codeInput"
            class="code-input"
            placeholder="Enter team code"
            autocomplete="off"
            maxlength="20"
            onkeydown="if(event.key==='Enter') goToMeeting()"
        >
        <button class="btn-join" onclick="goToMeeting()">
            <i class="fas fa-video" style="margin-right:8px;"></i> Join Meeting
        </button>
    </div>
</div>

<!-- ── HEADER BAR ── -->
<div class="header-bar">
    <div class="hb-nav">
        <a href="member_meetings.php" title="Lobby"><i class="fas fa-house"></i></a>
        <a href="<?= $dashboardLink ?>" title="Dashboard"><i class="fas fa-gauge-high"></i></a>
    </div>
    <div class="hb-center">
        <span class="hb-room">ROOM: <?= $roomCode ?></span>
        <div id="timerDisplay">40:00</div>
    </div>
    <button id="closeChatBtn">Close Chat</button>
    <button class="btn-exit" onclick="exitMeeting()">
        <i class="fas fa-phone-slash"></i> Exit
    </button>
</div>

<!-- ── JITSI CONTAINER ── -->
<div id="meetContainer"></div>

<script>
// Modal & Lobby functions
function closeModal() {
    document.getElementById('instructionModal').classList.remove('open');
    localStorage.setItem('ttMeetNoticeSeen', '1');
}

window.addEventListener('load', () => {
    if (!<?= json_encode($inMeeting) ?> && !localStorage.getItem('ttMeetNoticeSeen')) {
        document.getElementById('instructionModal').classList.add('open');
    }
});

function goToMeeting() {
    const code = document.getElementById('codeInput').value.trim().toUpperCase().replace(/[^A-Z0-9]/g,'');
    if (code.length < 3) {
        alert("Please enter a valid team code");
        return;
    }
    window.location.href = 'member_meetings.php?code=' + code;
}

function exitMeeting() {
    if (typeof api !== 'undefined') api.executeCommand('hangup');
    window.location.href = 'member_meetings.php';
}

<?php if ($inMeeting): ?>

const domain = 'meet.jit.si';

const options = {
    roomName: 'TidyTrackV4_<?= $roomCode ?>',
    width: '100%',
    height: '100%',
    parentNode: document.getElementById('meetContainer'),
    userInfo: {
        displayName: '<?= addslashes($userName) ?>'
    },
    configOverwrite: {
        prejoinPageEnabled: false,
        startWithAudioMuted: false,
        startWithVideoMuted: false,
        requireDisplayName: false,
        enableLobby: false,
        lobby: { enabled: false },
        p2p: { enabled: true },
        disableModeratorIndicator: false,
        startSilent: false
    },
    interfaceConfigOverwrite: {
        TOOLBAR_BUTTONS: [
            'microphone', 'camera', 'desktop', 'hangup', 'chat',
            'tileview', 'participants-pane', 'mute-everyone', 'kick', 'settings'
        ],
        MOBILE_APP_PROMO: false,
        TOOLBAR_ALWAYS_VISIBLE: true,
        SHOW_JITSI_WATERMARK: false,
    }
};

const api = new JitsiMeetExternalAPI(domain, options);

// Chat button
let chatOpen = false;
api.addListener('chatUpdated', e => {
    chatOpen = e.isOpen;
    document.getElementById('closeChatBtn').style.display = e.isOpen ? 'block' : 'none';
});

document.getElementById('closeChatBtn').addEventListener('click', () => {
    api.executeCommand('toggleChat');
});

// Timer + Exit
let timeLeft = 40 * 60;
const timerTick = setInterval(() => {
    timeLeft--;
    const m = Math.floor(timeLeft / 60);
    const s = timeLeft % 60;
    document.getElementById('timerDisplay').textContent = `${m}:${s < 10 ? '0' : ''}${s}`;

    if (timeLeft === 300) {
        document.getElementById('warningToast').style.display = 'block';
        setTimeout(() => document.getElementById('warningToast').style.display = 'none', 8000);
    }
    if (timeLeft <= 0) {
        clearInterval(timerTick);
        api.executeCommand('hangup');
        window.location.href = 'member_meetings.php';
    }
}, 1000);

api.addEventListener('videoConferenceLeft', () => {
    clearInterval(timerTick);
    window.location.href = 'member_meetings.php';
});



<?php endif; ?>
</script>
</body>
</html>

