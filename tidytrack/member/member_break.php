<?php
session_start();
include '../dp.php';
include '../track2.php';

$userId   = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name']    ?? 'Member';
$userRole = strtolower($_SESSION['role'] ?? '');
$userInitial = strtoupper($userName[0]);

// Members only
if (!$userId || $userRole !== 'member') {
    header('Location: member_dashboard.php');
    exit;
}

// Keep session alive (attendance keeps ticking)
$today = date('Y-m-d');
$isWeekend = date('N') >= 6;
if (!$isWeekend) {
    update_current_session($userId);
}

// ── Create breaks table if not exists ──
$conn->query("
    CREATE TABLE IF NOT EXISTS member_breaks (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        break_start DATETIME NOT NULL,
        break_end   DATETIME NOT NULL,
        INDEX(user_id)
    )
");

// ── Check cooldown / active break ──
$stmt = $conn->prepare("
    SELECT break_start, break_end,
           TIMESTAMPDIFF(SECOND, NOW(), break_end)    AS secs_to_end,
           TIMESTAMPDIFF(MINUTE,  break_end,   NOW()) AS mins_since_end
    FROM member_breaks
    WHERE user_id = ?
    ORDER BY break_start DESC
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$breakRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$breakActive   = false;
$cooldown      = false;
$secsRemaining = 0;    // seconds left on active break
$coolSecs      = 0;    // seconds left on cooldown

if ($breakRow) {
    $secsToEnd     = (int)$breakRow['secs_to_end'];
    $minsSinceEnd  = (int)$breakRow['mins_since_end'];

    if ($secsToEnd > 0) {
        // Still inside the 15-min break window
        $breakActive   = true;
        $secsRemaining = $secsToEnd;
    } elseif ($minsSinceEnd < 60) {
        // Break ended, but 60-min cooldown hasn't passed
        $cooldown = true;
        $coolSecs = max(0, (60 - $minsSinceEnd) * 60);
    }
}

// ── Start a NEW break (only if none active/cooldown) ──
if (!$breakActive && !$cooldown) {
    $stmt = $conn->prepare("
        INSERT INTO member_breaks (user_id, break_start, break_end)
        VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 15 MINUTE))
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
    $breakActive   = true;
    $secsRemaining = 900; // 15 min
}

// ── Fetch user profile picture ──
$stmt = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$picRow = $stmt->get_result()->fetch_assoc();
$profilePicture = $picRow['profile_picture'] ?? null;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Break Room · TidyTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --bg:#0f1a0f; --surface:#152015; --card:#1a2e1a;
    --card-h:#1f371f; --border:rgba(74,222,128,0.12);
    --border-h:rgba(74,222,128,0.28); --text:#e2f0e2;
    --sub:#6b8f6b; --green:#4ade80; --green2:#22c55e;
    --amber:#fbbf24; --rose:#f87171; --teal:#2dd4bf;
    --r:18px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{
    font-family:'Nunito',sans-serif;
    background:var(--bg); color:var(--text);
    min-height:100vh; overflow-x:hidden;
}

/* Ambient forest bg */
body::before{
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background:
        radial-gradient(ellipse 60% 50% at 20% 20%, rgba(34,197,94,.06) 0%, transparent 70%),
        radial-gradient(ellipse 50% 60% at 80% 80%, rgba(251,191,36,.04) 0%, transparent 70%),
        radial-gradient(ellipse 40% 40% at 50% 50%, rgba(45,212,191,.03) 0%, transparent 70%);
}
body::after{
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image: radial-gradient(circle, rgba(74,222,128,.04) 1px, transparent 1px);
    background-size: 24px 24px;
}

.page{position:relative;z-index:1;max-width:1200px;margin:0 auto;padding:24px 20px;}

/* ── TOPBAR ── */
.topbar{
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:32px; flex-wrap:wrap; gap:12px;
}
.tb-left{display:flex;align-items:center;gap:14px;}
.back-btn{
    display:flex;align-items:center;gap:8px;
    padding:9px 18px; border-radius:12px; border:1px solid var(--border);
    background:var(--card); color:var(--green); text-decoration:none;
    font-size:13px; font-weight:800; transition:all .2s;
    font-family:'Nunito',sans-serif;
}
.back-btn:hover{background:var(--card-h);border-color:var(--border-h);}
.tb-title{font-size:1.5rem;font-weight:900;letter-spacing:-.3px;}
.tb-title span{color:var(--green);}
.tb-title .sub{font-size:12px;color:var(--sub);font-family:'Space Mono',monospace;font-weight:400;margin-top:2px;}

/* User chip */
.user-chip{
    display:flex;align-items:center;gap:10px;
    background:var(--card);border:1px solid var(--border);
    border-radius:40px;padding:6px 14px 6px 6px;
}
.uc-avatar{
    width:32px;height:32px;border-radius:50%;
    background:linear-gradient(135deg,var(--green2),var(--teal));
    display:flex;align-items:center;justify-content:center;
    font-size:13px;font-weight:900;color:#fff;overflow:hidden;flex-shrink:0;
}
.uc-avatar img{width:100%;height:100%;object-fit:cover;}
.uc-name{font-size:13px;font-weight:800;color:var(--text);}

/* ── TIMER STRIP ── */
.timer-strip{
    background:var(--card);border:1px solid var(--border);
    border-radius:16px;padding:18px 24px;
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:28px; flex-wrap:wrap; gap:14px;
    animation: slideDown .5s ease both;
}
.timer-left{display:flex;align-items:center;gap:14px;}
.timer-icon{font-size:2rem;}
.timer-label{font-size:13px;color:var(--sub);font-family:'Space Mono',monospace;}
.timer-time{
    font-size:2rem;font-weight:900;
    font-family:'Space Mono',monospace;
    color:var(--green);letter-spacing:2px;
}
.timer-time.warn{color:var(--amber);}
.timer-time.danger{color:var(--rose);}

/* Progress bar */
.timer-bar-wrap{flex:1;min-width:120px;}
.timer-bar-bg{background:rgba(255,255,255,.06);border-radius:20px;height:8px;overflow:hidden;}
.timer-bar{
    height:100%;border-radius:20px;
    background:linear-gradient(90deg,var(--green2),var(--teal));
    transition:width .5s ease, background .5s;
}
.timer-bar.warn{background:linear-gradient(90deg,var(--amber),#f59e0b);}
.timer-bar.danger{background:linear-gradient(90deg,var(--rose),#ef4444);}
.timer-note{font-size:10px;color:var(--sub);font-family:'Space Mono',monospace;margin-top:4px;}

/* ── SECTION LABEL ── */
.sh{
    font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1.4px;
    color:var(--sub);font-family:'Space Mono',monospace;
    display:flex;align-items:center;gap:8px;margin-bottom:16px;
}
.sh::after{content:'';flex:1;height:1px;background:var(--border);}

/* ── ACTIVITY TABS ── */
.tabs{
    display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px;
}
.tab-btn{
    padding:9px 20px;border-radius:30px;border:1px solid var(--border);
    background:var(--card);color:var(--sub);font-size:13px;font-weight:800;
    cursor:pointer;transition:all .2s;font-family:'Nunito',sans-serif;
    display:flex;align-items:center;gap:7px;
}
.tab-btn:hover{border-color:var(--border-h);color:var(--text);}
.tab-btn.active{background:var(--green2);border-color:var(--green2);color:#fff;}

/* ── ACTIVITY PANELS ── */
.panel{display:none;}
.panel.active{display:block;animation:fadeUp .35s ease both;}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
@keyframes slideDown{from{opacity:0;transform:translateY(-10px);}to{opacity:1;transform:translateY(0);}}

/* ══════════════════════════════════
   MEMORY CARD GAME
══════════════════════════════════ */
.memory-wrap{max-width:600px;}
.mem-controls{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:16px;flex-wrap:wrap;gap:10px;
}
.mem-stat{
    background:var(--card);border:1px solid var(--border);
    border-radius:10px;padding:8px 16px;font-size:13px;font-weight:800;
    font-family:'Space Mono',monospace;
}
.mem-stat span{color:var(--green);}
.mem-btn{
    padding:9px 20px;border-radius:10px;border:none;
    background:var(--green2);color:#fff;font-weight:800;font-size:13px;
    cursor:pointer;transition:all .2s;font-family:'Nunito',sans-serif;
}
.mem-btn:hover{opacity:.88;transform:translateY(-1px);}
.mem-grid{
    display:grid;grid-template-columns:repeat(4,1fr);gap:10px;
}
.mem-card{
    aspect-ratio:1;border-radius:14px;cursor:pointer;
    perspective:600px;transition:transform .15s;
    position:relative;
}
.mem-card:hover{transform:scale(1.04);}
.mem-card-inner{
    width:100%;height:100%;position:relative;
    transform-style:preserve-3d;transition:transform .4s ease;border-radius:14px;
}
.mem-card.flipped .mem-card-inner{transform:rotateY(180deg);}
.mem-card.matched .mem-card-inner{transform:rotateY(180deg);}
.mem-card.matched{pointer-events:none;}
.mem-face,.mem-back{
    position:absolute;inset:0;border-radius:14px;
    display:flex;align-items:center;justify-content:center;
    backface-visibility:hidden;-webkit-backface-visibility:hidden;
}
.mem-back{
    background:linear-gradient(135deg,var(--card),var(--card-h));
    border:2px solid var(--border);font-size:1.4rem;
}
.mem-face{
    background:linear-gradient(135deg,#1a3a2a,#1e4a2e);
    border:2px solid rgba(74,222,128,.3);font-size:2rem;
    transform:rotateY(180deg);
}
.mem-card.matched .mem-face{
    background:linear-gradient(135deg,#052e16,#064e3b);
    border-color:var(--green2);
    box-shadow:0 0 14px rgba(34,197,94,.3);
}
.mem-win{
    display:none;text-align:center;padding:32px;
    background:var(--card);border:1px solid var(--border);border-radius:var(--r);
    margin-top:16px;
}
.mem-win.show{display:block;animation:fadeUp .4s ease both;}

/* ══════════════════════════════════
   QUICK GAME — Reaction / Reflex
══════════════════════════════════ */
.qg-wrap{max-width:560px;}
.qg-board{
    background:var(--card);border:1px solid var(--border);
    border-radius:var(--r);padding:32px;text-align:center;
    min-height:320px;display:flex;flex-direction:column;
    align-items:center;justify-content:center;gap:16px;
}
.qg-instruction{font-size:1rem;color:var(--sub);font-family:'Space Mono',monospace;}
.qg-target{
    width:120px;height:120px;border-radius:50%;
    cursor:pointer;display:none;
    align-items:center;justify-content:center;
    font-size:2.5rem;transition:transform .15s;
    box-shadow:0 0 30px rgba(74,222,128,.3);
}
.qg-target:hover{transform:scale(1.05);}
.qg-score{
    font-size:2.5rem;font-weight:900;color:var(--green);
    font-family:'Space Mono',monospace;
}
.qg-timer-bar{
    width:100%;background:rgba(255,255,255,.06);
    border-radius:20px;height:10px;overflow:hidden;margin-top:8px;
}
.qg-timer-fill{
    height:100%;border-radius:20px;
    background:linear-gradient(90deg,var(--green2),var(--teal));
    transition:width .1s linear;
}
.qg-result{font-size:1.1rem;font-weight:800;color:var(--amber);}
.qg-btn{
    padding:12px 32px;border-radius:12px;border:none;
    background:linear-gradient(135deg,var(--green2),var(--teal));
    color:#fff;font-size:15px;font-weight:800;cursor:pointer;
    font-family:'Nunito',sans-serif;transition:all .2s;
}
.qg-btn:hover{opacity:.88;transform:translateY(-2px);}

/* ══════════════════════════════════
   DAILY QUIZ
══════════════════════════════════ */
.quiz-wrap{max-width:600px;}
.quiz-q{
    background:var(--card);border:1px solid var(--border);
    border-radius:var(--r);padding:28px;margin-bottom:16px;
}
.quiz-q-num{font-size:11px;color:var(--sub);font-family:'Space Mono',monospace;margin-bottom:8px;}
.quiz-q-text{font-size:1.1rem;font-weight:800;line-height:1.5;}
.quiz-options{display:flex;flex-direction:column;gap:10px;margin-top:18px;}
.quiz-opt{
    padding:13px 18px;border-radius:12px;border:1px solid var(--border);
    background:var(--surface);cursor:pointer;font-weight:700;font-size:14px;
    transition:all .2s;text-align:left;font-family:'Nunito',sans-serif;
    color:var(--text);
}
.quiz-opt:hover:not(:disabled){background:var(--card-h);border-color:var(--border-h);}
.quiz-opt.correct{background:rgba(34,197,94,.15);border-color:var(--green2);color:var(--green);}
.quiz-opt.wrong  {background:rgba(248,113,113,.15);border-color:var(--rose);color:var(--rose);}
.quiz-opt:disabled{cursor:default;}
.quiz-nav{display:flex;justify-content:space-between;align-items:center;margin-top:12px;}
.quiz-score-badge{
    background:var(--card);border:1px solid var(--border);
    border-radius:30px;padding:7px 16px;
    font-size:12px;font-weight:800;font-family:'Space Mono',monospace;
}
.quiz-score-badge span{color:var(--green);}
.quiz-next{
    padding:10px 24px;border-radius:10px;border:none;
    background:var(--green2);color:#fff;font-weight:800;font-size:13px;
    cursor:pointer;font-family:'Nunito',sans-serif;transition:all .2s;
}
.quiz-next:hover{opacity:.88;}
.quiz-done{
    text-align:center;padding:40px;background:var(--card);
    border:1px solid var(--border);border-radius:var(--r);
    animation:fadeUp .4s ease both;
}
.quiz-done-score{font-size:3rem;font-weight:900;color:var(--green);}

/* ══════════════════════════════════
   CHESS (mini)
══════════════════════════════════ */
.chess-wrap{max-width:540px;}
.chess-info{
    display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;
    align-items:center;justify-content:space-between;
}
.chess-turn{
    background:var(--card);border:1px solid var(--border);
    border-radius:10px;padding:8px 16px;font-size:13px;font-weight:800;
    font-family:'Space Mono',monospace;
}
.chess-turn span{color:var(--amber);}
.chess-btn{
    padding:9px 20px;border-radius:10px;border:none;
    background:var(--amber);color:#1a1a0a;font-weight:800;font-size:13px;
    cursor:pointer;font-family:'Nunito',sans-serif;transition:all .2s;
}
.chess-btn:hover{opacity:.88;}
#chessBoard, #chessBoardComputer, #chessBoardOnline {
    display:grid;grid-template-columns:repeat(8,1fr);
    border:2px solid rgba(251,191,36,.3);border-radius:12px;overflow:hidden;
    box-shadow:0 8px 32px rgba(0,0,0,.4);
}
.chess-sq{
    aspect-ratio:1;display:flex;align-items:center;justify-content:center;
    font-size:clamp(1.1rem,3.5vw,1.8rem);cursor:pointer;
    transition:background .15s;user-select:none;position:relative;
}
.chess-sq.light{background:#d4a574;}
.chess-sq.dark {background:#8b5e3c;}
.chess-sq.selected{background:#4ade80 !important;box-shadow:inset 0 0 0 3px rgba(255,255,255,.4);}
.chess-sq.valid-move::after{
    content:'';position:absolute;
    width:30%;height:30%;border-radius:50%;
    background:rgba(74,222,128,.55);pointer-events:none;
}
.chess-sq.last-move{background:rgba(251,191,36,.35) !important;}
.chess-status{
    margin-top:12px;padding:10px 16px;border-radius:10px;
    background:var(--card);border:1px solid var(--border);
    font-size:13px;font-weight:800;font-family:'Space Mono',monospace;
    color:var(--sub);
}

/* ── COOLDOWN OVERLAY ── */
.cooldown-overlay{
    position:fixed;inset:0;z-index:9999;
    background:rgba(10,20,10,.96);
    display:flex;align-items:center;justify-content:center;
    animation:fadeUp .4s ease both;
}
.cooldown-card{
    background:var(--card);border:1px solid var(--border);
    border-radius:24px;padding:48px 40px;max-width:440px;width:90%;
    text-align:center;
}
.cooldown-emoji{font-size:4rem;margin-bottom:16px;}
.cooldown-title{font-size:1.6rem;font-weight:900;color:var(--rose);margin-bottom:12px;}
.cooldown-text{font-size:1rem;color:var(--sub);line-height:1.6;margin-bottom:24px;}
.cooldown-timer{
    font-size:2.4rem;font-weight:900;color:var(--amber);
    font-family:'Space Mono',monospace;margin-bottom:8px;
}
.cooldown-sub{font-size:11px;color:var(--sub);font-family:'Space Mono',monospace;}
</style>
</head>
<body>

<?php if ($cooldown): ?>
<!-- ── COOLDOWN OVERLAY ── -->
<div class="cooldown-overlay">
    <div class="cooldown-card">
        <div class="cooldown-emoji">😴</div>
        <div class="cooldown-title">Back to Work!</div>
        <div class="cooldown-text">
            You've already had your break. Break room is locked for 1 hour after each session.<br><br>
            Come back when the timer hits zero!
        </div>
        <div class="cooldown-timer" id="cooldownDisplay">--:--</div>
        <div class="cooldown-sub">then you can take another break</div>
        <br>
        <a href="member_dashboard.php" style="display:inline-block;margin-top:16px;padding:12px 28px;border-radius:12px;background:var(--green2);color:#fff;font-weight:800;text-decoration:none;font-size:14px;">
            ← Back to Dashboard
        </a>
    </div>
</div>
<script>
let coolSecs = <?= $coolSecs ?>;
function tickCool(){
    const m = String(Math.floor(coolSecs/60)).padStart(2,'0');
    const s = String(coolSecs%60).padStart(2,'0');
    document.getElementById('cooldownDisplay').textContent = m+':'+s;
    if(coolSecs > 0) coolSecs--;
    else window.location = 'member_dashboard.php';
}
setInterval(tickCool,1000); tickCool();
</script>
<?php endif; ?>

<div class="page">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="tb-left">
            <a href="member_dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <div>
                <div class="tb-title">☕ Break <span>Room</span></div>
                <div class="tb-title sub">// recharge · play · relax · 15 minutes</div>
            </div>
        </div>
        <div class="user-chip">
            <div class="uc-avatar">
                <?php if ($profilePicture && file_exists($profilePicture)): ?>
                    <img src="<?= htmlspecialchars($profilePicture) ?>" alt="">
                <?php else: ?>
                    <?= $userInitial ?>
                <?php endif; ?>
            </div>
            <div class="uc-name"><?= htmlspecialchars($userName) ?></div>
        </div>
    </div>

    <!-- TIMER STRIP -->
    <div class="timer-strip">
        <div class="timer-left">
            <div class="timer-icon">⏱️</div>
            <div>
                <div class="timer-label">Break ends in</div>
                <div class="timer-time" id="breakTimer">15:00</div>
            </div>
        </div>
        <div class="timer-bar-wrap">
            <div class="timer-bar-bg">
                <div class="timer-bar" id="timerBar" style="width:100%"></div>
            </div>
            <div class="timer-note" id="timerNote">You'll be redirected when time is up · session keeps running</div>
        </div>
    </div>

    <!-- ACTIVITY TABS -->
    <div class="sh"><span>Choose Your Activity</span></div>
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('memory',this)">🧠 Memory Cards</button>
        <button class="tab-btn" onclick="switchTab('quick',this)">⚡ Reflex Game</button>
        <button class="tab-btn" onclick="switchTab('quiz',this)">📝 Daily Quiz</button>
        <button class="tab-btn" onclick="switchTab('chess',this)">♟️ Chess</button>
    </div>

    <!-- ══ MEMORY CARDS ══ -->
    <div class="panel active" id="panel-memory">
        <div class="memory-wrap">
            <div class="mem-controls">
                <div class="mem-stat">Moves: <span id="memMoves">0</span></div>
                <div class="mem-stat">Pairs: <span id="memPairs">0</span>/8</div>
                <button class="mem-btn" onclick="initMemory()">🔀 Shuffle</button>
            </div>
            <div class="mem-grid" id="memGrid"></div>
            <div class="mem-win" id="memWin">
                <div style="font-size:3rem;margin-bottom:12px;">🎉</div>
                <div style="font-size:1.4rem;font-weight:900;color:var(--green)">You matched them all!</div>
                <div style="font-size:1rem;color:var(--sub);margin-top:8px;">in <span id="winMoves" style="color:var(--amber);font-weight:800;"></span> moves</div>
                <button class="mem-btn" style="margin-top:16px;" onclick="initMemory()">Play Again</button>
            </div>
        </div>
    </div>

    <!-- ══ REFLEX GAME ══ -->
    <div class="panel" id="panel-quick">
        <div class="qg-wrap">
            <div class="qg-board">
                <div class="qg-instruction" id="qgInstruction">Tap the target as fast as you can!</div>
                <div class="qg-score" id="qgScore" style="display:none">0</div>
                <div
                    id="qgTarget"
                    class="qg-target"
                    style="background:linear-gradient(135deg,var(--green2),var(--teal));"
                    onclick="hitTarget()"
                >🎯</div>
                <div class="qg-result" id="qgResult"></div>
                <div class="qg-timer-bar" id="qgTimerBar" style="display:none">
                    <div class="qg-timer-fill" id="qgTimerFill" style="width:100%"></div>
                </div>
                <button class="qg-btn" id="qgStartBtn" onclick="startQuick()">Start Game</button>
            </div>
        </div>
    </div>

    <!-- ══ DAILY QUIZ ══ -->
    <div class="panel" id="panel-quiz">
        <div class="quiz-wrap">
            <div id="quizContainer"></div>
        </div>
    </div>

  

<!-- ══ CHESS ══ -->
<div class="panel" id="panel-chess">
    <div class="chess-wrap">

        <!-- Mode selector -->
        <div id="chessModeSelect" style="margin-bottom:18px;">
            <div class="sh"><span>Choose Mode</span></div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <button class="qg-btn" onclick="startVsComputer()" style="background:linear-gradient(135deg,#a78bfa,#7c3aed);">
                    🤖 Play vs Computer
                </button>
                <button class="qg-btn" onclick="startLocalChess()" style="background:linear-gradient(135deg,var(--amber),#f59e0b);">
                    🖥️ Local (2 Players)
                </button>
                <button class="qg-btn" onclick="showOnlineChess()" style="background:linear-gradient(135deg,var(--blue,#4f7ef5),var(--violet,#7c5af5));">
                    🌐 Play vs Member Online
                </button>
            </div>
        </div>

        <!-- LOCAL 2-PLAYER GAME -->
        <div id="chessLocalWrap" style="display:none;">
            <div class="chess-info">
                <div class="chess-turn">Turn: <span id="chessTurnLabel">White ♙</span></div>
                <button class="chess-btn" onclick="initChess()">🔄 New Game</button>
                <button class="chess-btn" onclick="backToModeSelectFromLocal()" style="background:#555;">← Back</button>
            </div>
            <div id="chessBoard"></div>
            <div class="chess-status" id="chessStatus">Select a piece to move</div>
        </div>

        <!-- VS COMPUTER GAME -->
        <div id="chessComputerWrap" style="display:none;">
            <div class="chess-info">
                <div class="chess-turn">You play: <span id="computerMyColor">White ♙</span></div>
                <button class="chess-btn" onclick="newComputerGame()">🔄 New Game</button>
                <button class="chess-btn" onclick="backToModeSelect()" style="background:#555;">← Back</button>
            </div>
            <div id="chessBoardComputer"></div>
            <div class="chess-status" id="chessStatusComputer">Your turn — White ♙</div>
        </div>

        <!-- ONLINE GAME -->
        <div id="chessOnlineWrap" style="display:none;">
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
                <button class="chess-btn" onclick="backToModeSelectFromOnline()" style="background:#555;">← Back</button>
                <div class="chess-turn" id="onlineStatusLabel">Not connected</div>
            </div>

            <!-- Waiting / lobby -->
            <div id="onlineLobby">
                <div style="margin-bottom:14px;">
                    <div class="sh"><span>Your Room Code</span></div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                        <button class="qg-btn" onclick="createRoom()" style="background:var(--green,#10c97a);">➕ Create Room</button>
                    </div>
                    <div id="roomCodeDisplay" style="display:none;margin-bottom:14px;">
                        <div style="background:var(--surface,#101318);border:1px solid var(--border,rgba(255,255,255,.06));border-radius:12px;padding:14px;text-align:center;">
                            <div style="font-size:11px;color:var(--sub);font-family:'Space Mono',monospace;margin-bottom:6px;">Share this code with your opponent</div>
                            <div id="roomCodeText" style="font-size:2rem;font-weight:900;letter-spacing:4px;color:var(--green,#10c97a);font-family:'Space Mono',monospace;"></div>
                        </div>
                    </div>
                    <div class="sh"><span>Join a Room</span></div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <input id="joinCodeInput" type="text" maxlength="6" placeholder="Enter code" style="padding:10px 14px;border-radius:10px;border:1px solid var(--border,rgba(255,255,255,.1));background:var(--surface,#101318);color:var(--text,#dde1eb);font-size:1.1rem;font-family:'Space Mono',monospace;letter-spacing:3px;width:140px;text-transform:uppercase;outline:none;">
                        <button class="qg-btn" onclick="joinRoom()" style="background:var(--blue,#4f7ef5);">Join</button>
                    </div>
                    <div id="onlineError" style="color:var(--rose,#f54f6b);font-size:12px;margin-top:8px;font-family:'Space Mono',monospace;"></div>
                </div>
            </div>

            <!-- Online board -->
            <div id="onlineGameWrap" style="display:none;">
                <div class="chess-info">
                    <div class="chess-turn">You play: <span id="myColorLabel">-</span></div>
                    <div class="chess-turn">Turn: <span id="onlineTurnLabel">-</span></div>
                </div>
                <div id="chessBoardOnline"></div>
                <div class="chess-status" id="chessStatusOnline">Waiting for move...</div>
            </div>
        </div>

    </div>
</div>
 

<script>
// ────────────────────────────────────────────
// BREAK TIMER (server gives seconds remaining)
// ────────────────────────────────────────────
let breakSecs = <?= $secsRemaining ?>;
const TOTAL   = 900; // 15 min

function tickBreak(){
    const m = String(Math.floor(breakSecs/60)).padStart(2,'0');
    const s = String(breakSecs%60).padStart(2,'0');
    const el   = document.getElementById('breakTimer');
    const bar  = document.getElementById('timerBar');
    const note = document.getElementById('timerNote');

    el.textContent = m+':'+s;
    const pct = (breakSecs/TOTAL)*100;
    bar.style.width = pct+'%';

    // colour tiers
    el.className  = 'timer-time' + (breakSecs<=120?' danger': breakSecs<=300?' warn':'');
    bar.className = 'timer-bar'  + (breakSecs<=120?' danger': breakSecs<=300?' warn':'');

    if(breakSecs <= 30) note.textContent = '⚠️ Redirecting soon...';
    if(breakSecs <= 0) { window.location = 'member_dashboard.php'; return; }
    breakSecs--;
}
setInterval(tickBreak, 1000); tickBreak();

// ────────────────────────────────────────────
// TAB SWITCHING
// ────────────────────────────────────────────
function switchTab(id, btn){
    document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('panel-'+id).classList.add('active');
    btn.classList.add('active');
}

// ════════════════════════════════════════════
// MEMORY CARD GAME
// ════════════════════════════════════════════
const EMOJIS = ['🌿','🌸','🦋','🍃','☕','🎵','⭐','🌙'];
let memCards=[], memFlipped=[], memMoves=0, memMatched=0, memLock=false;

function initMemory(){
    memMoves=0; memMatched=0; memFlipped=[]; memLock=false;
    document.getElementById('memMoves').textContent='0';
    document.getElementById('memPairs').textContent='0';
    document.getElementById('memWin').classList.remove('show');
    const pairs = [...EMOJIS,...EMOJIS].sort(()=>Math.random()-.5);
    const grid  = document.getElementById('memGrid');
    grid.innerHTML='';
    pairs.forEach((em,i)=>{
        const card = document.createElement('div');
        card.className='mem-card';
        card.dataset.val=em;
        card.dataset.idx=i;
        card.innerHTML=`<div class="mem-card-inner"><div class="mem-back">🌿</div><div class="mem-face">${em}</div></div>`;
        card.addEventListener('click',()=>flipCard(card));
        grid.appendChild(card);
    });
}

function flipCard(card){
    if(memLock || card.classList.contains('flipped') || card.classList.contains('matched')) return;
    card.classList.add('flipped');
    memFlipped.push(card);
    if(memFlipped.length===2){
        memMoves++;
        document.getElementById('memMoves').textContent=memMoves;
        memLock=true;
        const [a,b]=memFlipped;
        if(a.dataset.val===b.dataset.val){
            a.classList.add('matched'); b.classList.add('matched');
            memMatched++;
            document.getElementById('memPairs').textContent=memMatched;
            memFlipped=[]; memLock=false;
            if(memMatched===8){
                document.getElementById('winMoves').textContent=memMoves;
                setTimeout(()=>document.getElementById('memWin').classList.add('show'),400);
            }
        } else {
            setTimeout(()=>{
                a.classList.remove('flipped'); b.classList.remove('flipped');
                memFlipped=[]; memLock=false;
            },900);
        }
    }
}
initMemory();

// ════════════════════════════════════════════
// REFLEX GAME
// ════════════════════════════════════════════
let qgRunning=false, qgScore=0, qgRemaining=30, qgInterval=null, qgTargetTimer=null, qgBarInterval=null;

function startQuick(){
    qgScore=0; qgRemaining=30; qgRunning=true;
    document.getElementById('qgStartBtn').style.display='none';
    document.getElementById('qgResult').textContent='';
    document.getElementById('qgScore').style.display='block';
    document.getElementById('qgTimerBar').style.display='block';
    document.getElementById('qgInstruction').textContent='Tap the target!';
    document.getElementById('qgScore').textContent='0';
    document.getElementById('qgTimerFill').style.width='100%';

    qgInterval = setInterval(()=>{
        qgRemaining--;
        document.getElementById('qgTimerFill').style.width=(qgRemaining/30*100)+'%';
        if(qgRemaining<=0) endQuick();
    },1000);

    showTarget();
}

function showTarget(){
    if(!qgRunning) return;
    const t = document.getElementById('qgTarget');
    const colors = [
        'linear-gradient(135deg,var(--green2),var(--teal))',
        'linear-gradient(135deg,var(--amber),#f59e0b)',
        'linear-gradient(135deg,#a78bfa,#7c3aed)',
        'linear-gradient(135deg,var(--rose),#dc2626)'
    ];
    t.style.background = colors[Math.floor(Math.random()*colors.length)];
    t.style.display='flex';
    qgTargetTimer = setTimeout(()=>{ t.style.display='none'; showTarget(); }, 1200);
}

function hitTarget(){
    if(!qgRunning) return;
    clearTimeout(qgTargetTimer);
    document.getElementById('qgTarget').style.display='none';
    qgScore++;
    document.getElementById('qgScore').textContent=qgScore;
    showTarget();
}

function endQuick(){
    qgRunning=false;
    clearInterval(qgInterval);
    clearTimeout(qgTargetTimer);
    document.getElementById('qgTarget').style.display='none';
    document.getElementById('qgScore').style.display='none';
    const ratings = qgScore>=25?'🔥 Incredible!':qgScore>=15?'⚡ Great reflexes!':qgScore>=8?'👍 Not bad!':'😅 Keep practising!';
    document.getElementById('qgResult').textContent=`${ratings} Score: ${qgScore}`;
    document.getElementById('qgInstruction').textContent='Game over!';
    document.getElementById('qgStartBtn').style.display='block';
    document.getElementById('qgStartBtn').textContent='Play Again';
    document.getElementById('qgTimerFill').style.width='0%';
}

// ════════════════════════════════════════════
// DAILY QUIZ
// ════════════════════════════════════════════
const QUIZ = [
    {q:"What does PHP stand for?", opts:["Personal Home Page","PHP: Hypertext Preprocessor","Practical Hypertext Processor","Programmed Hypertext Platform"], ans:1},
    {q:"What does CSS stand for?", opts:["Creative Style Sheets","Cascading Style Sheets","Computer Style System","Colorful Style Syntax"], ans:1},
    {q:"Which planet is closest to the Sun?", opts:["Venus","Earth","Mercury","Mars"], ans:2},
    {q:"What is 12 × 12?", opts:["132","144","124","148"], ans:1},
    {q:"Who painted the Mona Lisa?", opts:["Vincent van Gogh","Michelangelo","Leonardo da Vinci","Raphael"], ans:2},
    {q:"What does SQL stand for?", opts:["Simple Query Language","Structured Query Language","System Query Logic","Secure Query List"], ans:1},
    {q:"How many sides does a hexagon have?", opts:["5","7","6","8"], ans:2},
    {q:"What is the capital of South Africa (administrative)?", opts:["Cape Town","Johannesburg","Durban","Pretoria"], ans:3},
    {q:"What year was the World Wide Web invented?", opts:["1985","1989","1994","1991"], ans:1},
    {q:"Which is NOT a programming language?", opts:["Python","Kotlin","Photoshop","Rust"], ans:2},
];

let quizIdx=0, quizScore=0, quizAnswered=false;

function loadQuiz(){
    quizIdx=0; quizScore=0;
    const shuffled = [...QUIZ].sort(()=>Math.random()-.5).slice(0,6);
    window._quizQ = shuffled;
    showQuestion();
}

function showQuestion(){
    const container = document.getElementById('quizContainer');
    const q = window._quizQ[quizIdx];
    if(!q){ showQuizDone(); return; }
    quizAnswered=false;
    container.innerHTML=`
        <div class="quiz-q">
            <div class="quiz-q-num">Question ${quizIdx+1} of ${window._quizQ.length}</div>
            <div class="quiz-q-text">${q.q}</div>
            <div class="quiz-options">
                ${q.opts.map((o,i)=>`<button class="quiz-opt" onclick="answerQuiz(${i},${q.ans},this)">${String.fromCharCode(65+i)}. ${o}</button>`).join('')}
            </div>
        </div>
        <div class="quiz-nav">
            <div class="quiz-score-badge">Score: <span>${quizScore}</span>/${quizIdx}</div>
            <button class="quiz-next" id="quizNextBtn" onclick="nextQuestion()" style="display:none;">Next →</button>
        </div>
    `;
}

function answerQuiz(chosen, correct, btn){
    if(quizAnswered) return;
    quizAnswered=true;
    document.querySelectorAll('.quiz-opt').forEach(b=>b.disabled=true);
    if(chosen===correct){ btn.classList.add('correct'); quizScore++; }
    else{
        btn.classList.add('wrong');
        document.querySelectorAll('.quiz-opt')[correct].classList.add('correct');
    }
    document.getElementById('quizNextBtn').style.display='block';
    document.querySelector('.quiz-score-badge span').textContent=quizScore;
}

function nextQuestion(){ quizIdx++; showQuestion(); }

function showQuizDone(){
    const pct = Math.round((quizScore/window._quizQ.length)*100);
    const msg = pct>=80?'🎉 Excellent!':pct>=60?'👍 Good job!':pct>=40?'📚 Keep learning!':'😅 Try again!';
    document.getElementById('quizContainer').innerHTML=`
        <div class="quiz-done">
            <div class="quiz-done-score">${quizScore}/${window._quizQ.length}</div>
            <div style="font-size:1.5rem;font-weight:900;margin:8px 0;">${msg}</div>
            <div style="font-size:1rem;color:var(--sub);margin-bottom:24px;">You scored ${pct}%</div>
            <button class="quiz-next" onclick="loadQuiz()">🔄 Play Again</button>
        </div>
    `;
}
loadQuiz();

// ════════════════════════════════════════════
// CHESS (full playable vs another person)
// ════════════════════════════════════════════
// Standard initial board setup
// ════════════════════════════════════════
// LOCAL CHESS
// ════════════════════════════════════════
function startLocalChess(){
    document.getElementById('chessModeSelect').style.display='none';
    document.getElementById('chessLocalWrap').style.display='block';
    initChess();
}
 
const INIT = [
    ['r','n','b','q','k','b','n','r'],
    ['p','p','p','p','p','p','p','p'],
    ['','','','','','','',''],
    ['','','','','','','',''],
    ['','','','','','','',''],
    ['','','','','','','',''],
    ['P','P','P','P','P','P','P','P'],
    ['R','N','B','Q','K','B','N','R']
];
const PU = {'K':'♔','Q':'♕','R':'♖','B':'♗','N':'♘','P':'♙','k':'♚','q':'♛','r':'♜','b':'♝','n':'♞','p':'♟'};
let board=[], selected=null, turn='w', lastMove=null;
 
function initChess(){
    board=INIT.map(r=>[...r]); selected=null; turn='w'; lastMove=null;
    document.getElementById('chessTurnLabel').textContent='White ♙';
    document.getElementById('chessStatus').textContent='Select a piece to move';
    renderChess('chessBoard', board, selected, lastMove, chessClick);
}
 
function renderChess(boardId, brd, sel, lm, clickFn){
    const el=document.getElementById(boardId); el.innerHTML='';
    for(let r=0;r<8;r++){
        for(let c=0;c<8;c++){
            const sq=document.createElement('div');
            sq.className='chess-sq '+((r+c)%2===0?'light':'dark');
            sq.dataset.r=r; sq.dataset.c=c;
            if(sel&&sel[0]===r&&sel[1]===c) sq.classList.add('selected');
            if(lm&&((lm[0]===r&&lm[1]===c)||(lm[2]===r&&lm[3]===c))) sq.classList.add('last-move');
            if(sel){ const mv=getValidMoves(brd,sel[0],sel[1]); if(mv.some(m=>m[0]===r&&m[1]===c)) sq.classList.add('valid-move'); }
            const p=brd[r][c]; if(p) sq.textContent=PU[p]||p;
            sq.addEventListener('click',()=>clickFn(r,c));
            el.appendChild(sq);
        }
    }
}
 
function isW(p){return p&&p===p.toUpperCase();}
function isB(p){return p&&p===p.toLowerCase();}
function sameCol(a,b){return(isW(a)&&isW(b))||(isB(a)&&isB(b));}
function inB(r,c){return r>=0&&r<8&&c>=0&&c<8;}
 
function getValidMoves(brd,r,c){
    const piece=brd[r][c]; const moves=[]; if(!piece) return moves;
    const white=isW(piece);
    const enemy=sq=>sq&&(white?isB(sq):isW(sq));
    const empty=sq=>sq==='';
    const slide=(dr,dc)=>{let nr=r+dr,nc=c+dc; while(inB(nr,nc)){if(empty(brd[nr][nc])){moves.push([nr,nc]);}else{if(enemy(brd[nr][nc]))moves.push([nr,nc]);break;} nr+=dr;nc+=dc;}};
    const p=piece.toLowerCase();
    if(p==='p'){const dir=white?-1:1; if(inB(r+dir,c)&&empty(brd[r+dir][c])){moves.push([r+dir,c]); if(r===(white?6:1)&&empty(brd[r+2*dir][c]))moves.push([r+2*dir,c]);} [[r+dir,c-1],[r+dir,c+1]].forEach(([nr,nc])=>{if(inB(nr,nc)&&enemy(brd[nr][nc]))moves.push([nr,nc]);});}
    if(p==='r'||p==='q'){[[1,0],[-1,0],[0,1],[0,-1]].forEach(([dr,dc])=>slide(dr,dc));}
    if(p==='b'||p==='q'){[[1,1],[1,-1],[-1,1],[-1,-1]].forEach(([dr,dc])=>slide(dr,dc));}
    if(p==='n'){[[-2,-1],[-2,1],[-1,-2],[-1,2],[1,-2],[1,2],[2,-1],[2,1]].forEach(([dr,dc])=>{const nr=r+dr,nc=c+dc; if(inB(nr,nc)&&!sameCol(piece,brd[nr][nc]))moves.push([nr,nc]);});}
    if(p==='k'){[[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]].forEach(([dr,dc])=>{const nr=r+dr,nc=c+dc; if(inB(nr,nc)&&!sameCol(piece,brd[nr][nc]))moves.push([nr,nc]);});}
    return moves;
}
 
function chessClick(r,c){
    const piece=board[r][c];
    if(selected){
        const [sr,sc]=selected;
        const moves=getValidMoves(board,sr,sc);
        if(moves.some(m=>m[0]===r&&m[1]===c)){
            lastMove=[sr,sc,r,c];
            board[r][c]=board[sr][sc]; board[sr][sc]='';
            if(board[r][c]==='P'&&r===0) board[r][c]='Q';
            if(board[r][c]==='p'&&r===7) board[r][c]='q';
            selected=null; turn=turn==='w'?'b':'w';
            document.getElementById('chessTurnLabel').textContent=turn==='w'?'White ♙':'Black ♟';
            document.getElementById('chessStatus').textContent='Select a piece to move';
            // Check if kings still exist
            const allPieces=board.flat();
            if(!allPieces.includes('k')){document.getElementById('chessStatus').textContent='♟ White wins! 🎉';}
            if(!allPieces.includes('K')){document.getElementById('chessStatus').textContent='♚ Black wins! 🎉';}
        } else {
            selected=null;
            if((turn==='w'&&isW(piece))||(turn==='b'&&isB(piece))){ selected=[r,c]; document.getElementById('chessStatus').textContent=`Selected: ${PU[piece]} at ${String.fromCharCode(97+c)}${8-r}`; }
        }
    } else {
        if((turn==='w'&&isW(piece))||(turn==='b'&&isB(piece))){ selected=[r,c]; document.getElementById('chessStatus').textContent=`Selected: ${PU[piece]} at ${String.fromCharCode(97+c)}${8-r}`; }
    }
    renderChess('chessBoard', board, selected, lastMove, chessClick);
}
initChess();
 
 
// ════════════════════════════════════════
// ONLINE CHESS — uses chess_room.php AJAX
// ════════════════════════════════════════
let myRoom=null, myColor=null, pollTimer=null, onlineBoard=[], onlineSel=null, onlineLastMove=null, onlineMyTurn=false;
 
function showOnlineChess(){
    document.getElementById('chessModeSelect').style.display='none';
    document.getElementById('chessOnlineWrap').style.display='block';
    document.getElementById('onlineError').textContent='';
}
 
function stopPolling(){ clearInterval(pollTimer); }
 
function createRoom(){
    fetch('chess_room.php?action=create')
        .then(r=>r.json())
        .then(d=>{
            if(d.error){ showOnlineErr(d.error); return; }
            myRoom=d.room; myColor='w';
            document.getElementById('roomCodeText').textContent=d.room;
            document.getElementById('roomCodeDisplay').style.display='block';
            document.getElementById('onlineStatusLabel').textContent='Waiting for opponent...';
            startPolling();
        }).catch(()=>showOnlineErr('Server error'));
}
 
function joinRoom(){
    const code=document.getElementById('joinCodeInput').value.trim().toUpperCase();
    if(code.length!==6){ showOnlineErr('Code must be 6 characters'); return; }
    fetch(`chess_room.php?action=join&room=${code}`)
        .then(r=>r.json())
        .then(d=>{
            if(d.error){ showOnlineErr(d.error); return; }
            myRoom=code; myColor='b';
            document.getElementById('onlineStatusLabel').textContent='Joined! Starting game...';
            startGame();
            startPolling();
        }).catch(()=>showOnlineErr('Server error'));
}
 
function startPolling(){
    clearInterval(pollTimer);
    pollTimer=setInterval(pollRoom, 2000);
}
 
function pollRoom(){
    if(!myRoom) return;
    fetch(`chess_room.php?action=poll&room=${myRoom}`)
        .then(r=>r.json())
        .then(d=>{
            if(d.error) return;
            // If opponent joined and we're creator
            if(d.players>=2 && myColor==='w' && document.getElementById('onlineGameWrap').style.display==='none'){
                startGame();
            }
            if(d.board){
                onlineBoard=d.board;
                onlineLastMove=d.last_move||null;
                const isTurnW=d.turn==='w';
                onlineMyTurn=(myColor==='w'&&isTurnW)||(myColor==='b'&&!isTurnW);
                document.getElementById('onlineTurnLabel').textContent=isTurnW?'White ♙':'Black ♟';
                document.getElementById('chessStatusOnline').textContent=onlineMyTurn?'Your turn!':'Waiting for opponent...';
                renderOnlineBoard();
                if(d.result){
                    document.getElementById('chessStatusOnline').textContent=d.result;
                    stopPolling();
                }
            }
        }).catch(()=>{});
}
 
function startGame(){
    onlineBoard=INIT.map(r=>[...r]); onlineSel=null; onlineLastMove=null;
    document.getElementById('onlineLobby').style.display='none';
    document.getElementById('onlineGameWrap').style.display='block';
    document.getElementById('myColorLabel').textContent=myColor==='w'?'White ♙':'Black ♟';
    onlineMyTurn=(myColor==='w');
    document.getElementById('onlineTurnLabel').textContent='White ♙';
    document.getElementById('chessStatusOnline').textContent=onlineMyTurn?'Your turn!':'Waiting for opponent...';
    renderOnlineBoard();
}
 
function renderOnlineBoard(){
    // flip board for black
    const flip=(myColor==='b');
    const el=document.getElementById('chessBoardOnline'); el.innerHTML='';
    for(let ri=0;ri<8;ri++){
        for(let ci=0;ci<8;ci++){
            const r=flip?(7-ri):ri, c=flip?(7-ci):ci;
            const sq=document.createElement('div');
            sq.className='chess-sq '+((r+c)%2===0?'light':'dark');
            if(onlineSel&&onlineSel[0]===r&&onlineSel[1]===c) sq.classList.add('selected');
            if(onlineLastMove&&((onlineLastMove[0]===r&&onlineLastMove[1]===c)||(onlineLastMove[2]===r&&onlineLastMove[3]===c))) sq.classList.add('last-move');
            if(onlineSel&&onlineMyTurn){ const mv=getValidMoves(onlineBoard,onlineSel[0],onlineSel[1]); if(mv.some(m=>m[0]===r&&m[1]===c)) sq.classList.add('valid-move'); }
            const p=onlineBoard[r][c]; if(p) sq.textContent=PU[p]||p;
            sq.addEventListener('click',()=>onlineChessClick(r,c));
            el.appendChild(sq);
        }
    }
}
 
function onlineChessClick(r,c){
    if(!onlineMyTurn) return;
    const piece=onlineBoard[r][c];
    const myPiece=p=>(myColor==='w'?isW(p):isB(p));
    if(onlineSel){
        const [sr,sc]=onlineSel;
        const moves=getValidMoves(onlineBoard,sr,sc);
        if(moves.some(m=>m[0]===r&&m[1]===c)){
            // Make the move locally then send
            onlineBoard[r][c]=onlineBoard[sr][sc]; onlineBoard[sr][sc]='';
            if(onlineBoard[r][c]==='P'&&r===0) onlineBoard[r][c]='Q';
            if(onlineBoard[r][c]==='p'&&r===7) onlineBoard[r][c]='q';
            onlineLastMove=[sr,sc,r,c]; onlineSel=null; onlineMyTurn=false;
            document.getElementById('chessStatusOnline').textContent='Waiting for opponent...';
            renderOnlineBoard();
            // Send move to server
            fetch('chess_room.php?action=move',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({room:myRoom,board:onlineBoard,last_move:onlineLastMove,turn:myColor==='w'?'b':'w'})
            }).catch(()=>{});
        } else {
            onlineSel=null;
            if(myPiece(piece)){ onlineSel=[r,c]; }
            renderOnlineBoard();
        }
    } else {
        if(myPiece(piece)){ onlineSel=[r,c]; renderOnlineBoard(); }
    }
}
 
function showOnlineErr(msg){ document.getElementById('onlineError').textContent='⚠ '+msg; }


// ════════════════════════════════════════
// VS COMPUTER — Simple AI (you play White, Computer = Black)
// ════════════════════════════════════════
let computerBoard = [];
let computerSelected = null;
let computerLastMove = null;
let isComputerThinking = false;

function startVsComputer() {
    document.getElementById('chessModeSelect').style.display = 'none';
    document.getElementById('chessComputerWrap').style.display = 'block';
    newComputerGame();
}

function backToModeSelect() {
    document.getElementById('chessComputerWrap').style.display = 'none';
    document.getElementById('chessModeSelect').style.display = 'block';
    if (typeof pollTimer !== 'undefined') clearInterval(pollTimer);
}

function newComputerGame() {
    computerBoard = INIT.map(r => [...r]);  // use your existing INIT
    computerSelected = null;
    computerLastMove = null;
    document.getElementById('computerMyColor').textContent = 'White ♙';
    document.getElementById('chessStatusComputer').textContent = 'Your turn — White ♙';
    renderComputerBoard();
}

function renderComputerBoard() {
    const el = document.getElementById('chessBoardComputer');
    el.innerHTML = '';
    for (let r = 0; r < 8; r++) {
        for (let c = 0; c < 8; c++) {
            const sq = document.createElement('div');
            sq.className = 'chess-sq ' + ((r + c) % 2 === 0 ? 'light' : 'dark');
            if (computerSelected && computerSelected[0] === r && computerSelected[1] === c) sq.classList.add('selected');
            if (computerLastMove && ((computerLastMove[0] === r && computerLastMove[1] === c) || (computerLastMove[2] === r && computerLastMove[3] === c))) sq.classList.add('last-move');

            const p = computerBoard[r][c];
            if (p) sq.textContent = PU[p] || p;

            sq.addEventListener('click', () => computerClick(r, c));
            el.appendChild(sq);
        }
    }
}

function computerClick(r, c) {
    if (isComputerThinking) return;

    const piece = computerBoard[r][c];

    if (computerSelected) {
        const [sr, sc] = computerSelected;
        const moves = getValidMoves(computerBoard, sr, sc);   // reuse your existing function

        if (moves.some(m => m[0] === r && m[1] === c)) {
            // Player move
            computerLastMove = [sr, sc, r, c];
            computerBoard[r][c] = computerBoard[sr][sc];
            computerBoard[sr][sc] = '';
            // Promotion
            if (computerBoard[r][c] === 'P' && r === 0) computerBoard[r][c] = 'Q';

            computerSelected = null;
            renderComputerBoard();

            // Check win
            if (!computerBoard.flat().includes('k')) {
                document.getElementById('chessStatusComputer').textContent = '🎉 You win!';
                return;
            }

            document.getElementById('chessStatusComputer').textContent = 'Computer thinking...';
            isComputerThinking = true;

            // Computer plays after a short delay (so it feels responsive)
            setTimeout(computerMakeMove, 600);
        } else {
            computerSelected = null;
            if (piece && piece === piece.toUpperCase()) { // white piece
                computerSelected = [r, c];
            }
            renderComputerBoard();
        }
    } else {
        // Select piece (only white)
        if (piece && piece === piece.toUpperCase()) {
            computerSelected = [r, c];
            renderComputerBoard();
        }
    }
}

function computerMakeMove() {
    const bestMove = getBestComputerMove(computerBoard);
    if (!bestMove) {
        document.getElementById('chessStatusComputer').textContent = 'Computer has no moves!';
        isComputerThinking = false;
        return;
    }

    const [sr, sc, tr, tc] = bestMove;
    computerLastMove = [sr, sc, tr, tc];
    computerBoard[tr][tc] = computerBoard[sr][sc];
    computerBoard[sr][sc] = '';

    // Simple promotion for black
    if (computerBoard[tr][tc] === 'p' && tr === 7) computerBoard[tr][tc] = 'q';

    renderComputerBoard();

    if (!computerBoard.flat().includes('K')) {
        document.getElementById('chessStatusComputer').textContent = '💀 Computer wins!';
    } else {
        document.getElementById('chessStatusComputer').textContent = 'Your turn — White ♙';
    }

    isComputerThinking = false;
}

// Very simple AI: picks a random valid move for black (easy difficulty)
function getBestComputerMove(brd) {
    let allMoves = [];
    for (let r = 0; r < 8; r++) {
        for (let c = 0; c < 8; c++) {
            const p = brd[r][c];
            if (p && p === p.toLowerCase()) { // black piece
                const moves = getValidMoves(brd, r, c);
                moves.forEach(m => {
                    allMoves.push([r, c, m[0], m[1]]);
                });
            }
        }
    }
    if (allMoves.length === 0) return null;
    // Random move = easy AI
    return allMoves[Math.floor(Math.random() * allMoves.length)];
}
</script>
</body>
</html>