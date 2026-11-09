<?php
session_start();
include '../dp.php';

if (!isset($_SESSION['developer_id'])) {
    header('Location: index.php');
    exit;
}

$msg = ''; $msgType = 'info';

$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    DATETIME DEFAULT NOW() ON UPDATE NOW(),
    updated_by    VARCHAR(100) DEFAULT NULL
)");
$conn->query("CREATE TABLE IF NOT EXISTS auto_ban_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ran_at        DATETIME NOT NULL,
    week_start    DATE NOT NULL,
    days_elapsed  INT NOT NULL,
    total_checked INT NOT NULL,
    total_banned  INT NOT NULL,
    was_paused    TINYINT(1) DEFAULT 0,
    note          TEXT
)");

// ── Read pause state ───────────────────────────────────────────
$res      = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='auto_ban_paused'");
$row      = $res ? $res->fetch_assoc() : null;
$isPaused = $row && $row['setting_value'] === '1';

// ── Week start (always Monday, weekends never count) ──────────
$weekStartDate = date('Y-m-d', strtotime('monday this week'));
$todayDate     = date('Y-m-d');
$today_dow     = (int)date('N'); // 1=Mon … 7=Sun
$currentHour   = (int)date('H');

// ====================== AUTO BAN LOGIC ======================
if ($today_dow <= 5 && $currentHour >= 17 && !$isPaused) {
    $daysElapsed = min($today_dow, 5);

    $autoBanStmt = $conn->prepare("
        UPDATE users u
        SET u.isallowed = 0
        WHERE u.role = 'member'
          AND u.isallowed = 1
          AND (? - (
              SELECT COUNT(*)
              FROM daily_tasks dt
              WHERE dt.user_id = u.user_id
                AND dt.sent_date >= ?
                AND dt.sent_date <= ?
          )) >= 3
    ");
    $autoBanStmt->bind_param("iss", $daysElapsed, $weekStartDate, $todayDate);
    $autoBanStmt->execute();
    $affectedRows = $autoBanStmt->affected_rows;
    $autoBanStmt->close();

    if ($affectedRows > 0) {
        $note = "Page-load trigger: {$affectedRows} member(s) banned";
        $conn->query("INSERT INTO auto_ban_log (ran_at, week_start, days_elapsed, total_checked, total_banned, was_paused, note)
            VALUES (NOW(), '{$weekStartDate}', {$daysElapsed}, 0, {$affectedRows}, 0, '" . $conn->real_escape_string($note) . "')");
    }
}

// ====================== HANDLE APPROVE / REJECT ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $user_id = (int)$_POST['user_id'];
    $action  = $_POST['action'];

    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE users SET isallowed = 1, appeal = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) { $msg = "✅ User approved — they can now log in."; $msgType = 'success'; }
        $stmt->close();
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE users SET isallowed = 0, appeal = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) { $msg = "✅ Appeal rejected. User remains banned."; $msgType = 'success'; }
        $stmt->close();
    }
}

// ====================== FETCH BANNED / AT-RISK MEMBERS ======================
// BANNED  = isallowed=0  -> show always until approved via appeal
// AT RISK = isallowed=1 AND (days_elapsed - reports_this_week) >= 2
// Clean   = on track this week -> never shown here
$dayOfWeekNow   = (int)date('N');
$daysElapsedNow = min($dayOfWeekNow, 5); // Mon=1..Fri=5, cap at 5

$bannedUsers = [];
$stmt = $conn->prepare("
    SELECT u.user_id, u.name, u.email, u.role, u.department, u.appeal, u.isallowed,
           (SELECT COUNT(*)
            FROM daily_tasks
            WHERE user_id = u.user_id
              AND sent_date = CURDATE()) AS submitted_today,
           (SELECT COUNT(*)
            FROM daily_tasks dt
            WHERE dt.user_id = u.user_id
              AND dt.sent_date >= ?
              AND dt.sent_date <= CURDATE()) AS reports_this_week
    FROM users u
    WHERE u.role = 'member'
      AND (
          u.isallowed = 0
          OR (
              ? - (
                  SELECT COUNT(*)
                  FROM daily_tasks dt
                  WHERE dt.user_id = u.user_id
                    AND dt.sent_date >= ?
                    AND dt.sent_date <= CURDATE()
              )
          ) >= 2
      )
    HAVING submitted_today = 0
    ORDER BY u.isallowed ASC, u.name ASC
");
$stmt->bind_param("sis", $weekStartDate, $daysElapsedNow, $weekStartDate);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $bannedUsers[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Daily Report Appeals • Dev Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --bg:#0c0e13;--surface:#101318;--card:#161a23;--card-h:#1c2132;
    --border:rgba(255,255,255,0.06);--border-h:rgba(255,255,255,0.13);
    --text:#dde1eb;--sub:#52596e;
    --blue:#4f7ef5;--violet:#7c5af5;--green:#10c97a;
    --amber:#f5a623;--rose:#f54f6b;--r:16px;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Syne',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}
.page{max-width:1300px;margin:0 auto;padding:36px 28px;}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:52px;}
.tb-brand{display:flex;align-items:center;gap:14px;}
.tb-logo{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--blue),var(--violet));display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;color:#fff;}
.tb-name{font-size:1.1rem;font-weight:800;letter-spacing:-.2px;}
.tb-meta{font-size:10.5px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-top:2px;}
.tb-right{display:flex;align-items:center;gap:10px;}
.tb-clock{padding:7px 14px;border-radius:10px;background:var(--card);border:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--sub);}
.btn-nav{padding:8px 18px;border-radius:10px;font-size:12px;font-weight:700;border:1px solid;text-decoration:none;transition:all .2s;}
.btn-back{color:var(--blue);background:rgba(79,126,245,.1);border-color:rgba(79,126,245,.2);}
.btn-back:hover{background:rgba(79,126,245,.2);}
.btn-control{color:var(--violet);background:rgba(124,90,245,.1);border-color:rgba(124,90,245,.2);}
.btn-control:hover{background:rgba(124,90,245,.2);}
.greet{margin-bottom:36px;}
.greet-hi{font-size:2.3rem;font-weight:800;letter-spacing:-.6px;line-height:1.1;}
.greet-hi em{font-style:normal;color:var(--blue);}
.greet-meta{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--sub);margin-top:8px;}
.pause-banner{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:16px 22px;border-radius:14px;margin-bottom:24px;}
.pause-label{font-size:14px;font-weight:700;}
.pause-sub{font-size:12px;color:var(--sub);margin-top:3px;font-family:'JetBrains Mono',monospace;}
.sh{display:flex;align-items:center;gap:10px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-bottom:18px;}
.sh::after{content:'';flex:1;height:1px;background:var(--border);}
.users-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:20px;}
.user-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:24px;transition:all .25s;}
.user-card.banned{border-color:rgba(245,79,107,.2);}
.user-card.banned:hover{transform:translateY(-5px);border-color:var(--rose);box-shadow:0 20px 40px rgba(245,79,107,.12);}
.user-card.atrisk{border-color:rgba(245,166,35,.2);}
.user-card.atrisk:hover{transform:translateY(-5px);border-color:var(--amber);box-shadow:0 20px 40px rgba(245,166,35,.1);}
.card-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;}
.card-name{font-size:16px;font-weight:700;}
.card-email{color:var(--sub);font-size:12.5px;margin-top:2px;}
.card-dept{font-size:12.5px;color:var(--sub);margin-top:6px;}
.badge{display:inline-block;padding:5px 13px;border-radius:30px;font-size:11.5px;font-weight:700;}
.badge-banned{background:rgba(245,79,107,.15);color:var(--rose);border:1px solid rgba(245,79,107,.3);}
.badge-risk{background:rgba(245,166,35,.15);color:var(--amber);border:1px solid rgba(245,166,35,.25);}
.appeal-box{background:rgba(245,79,107,.08);border:1px solid rgba(245,79,107,.25);border-radius:12px;padding:14px;margin:14px 0;font-size:13.5px;color:#fda4af;line-height:1.55;}
.appeal-box strong{color:var(--rose);}
.no-appeal{color:var(--sub);font-style:italic;font-size:13px;margin:14px 0;}
.week-info{font-size:11.5px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin:10px 0;padding:8px 12px;background:rgba(255,255,255,.03);border-radius:8px;}
.action-buttons{display:flex;gap:10px;margin-top:18px;}
.btn-action{flex:1;padding:13px;border-radius:12px;font-weight:700;cursor:pointer;transition:all .2s;border:none;font-size:13px;font-family:'Syne',sans-serif;}
.btn-approve{background:var(--green);color:#000;}
.btn-approve:hover{background:#0ea66a;transform:translateY(-2px);}
.btn-reject{background:var(--rose);color:#fff;}
.btn-reject:hover{background:#e03b57;transform:translateY(-2px);}
.btn-warning{background:var(--amber);color:#000;}
.btn-warning:hover{background:#e0961d;}
.msg-bar{margin-bottom:22px;padding:14px 18px;border-radius:12px;font-size:13.5px;}
.msg-success{background:rgba(16,201,122,.1);color:var(--green);border:1px solid rgba(16,201,122,.25);}
.msg-info{background:rgba(79,126,245,.1);color:var(--blue);border:1px solid rgba(79,126,245,.2);}
</style>
</head>
<body>
<div class="page">

    <div class="topbar">
        <div class="tb-brand">
            <div class="tb-logo">T</div>
            <div>
                <div class="tb-name">TidyTrack Dev Portal</div>
                <div class="tb-meta">// Daily Report Appeals & Risk</div>
            </div>
        </div>
        <div class="tb-right">
            <div class="tb-clock" id="devClock">--:--:--</div>
            <a href="auto_ban_control.php" class="btn-nav btn-control"><i class="fas fa-shield-halved"></i> Ban Control</a>
            <a href="index.php" class="btn-nav btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <div class="greet">
        <div class="greet-hi">Report Appeals <em>🚫</em></div>
        <div class="greet-meta">// Week of <?= date('M j', strtotime($weekStartDate)) ?> — banned users &amp; members with zero reports this week</div>
    </div>

    <?php if ($msg): ?>
    <div class="msg-bar <?= $msgType==='success'?'msg-success':'msg-info' ?>"><?= $msg ?></div>
    <?php endif; ?>

    <!-- AUTO-BAN STATUS BANNER -->
    <div class="pause-banner" style="background:<?= $isPaused?'rgba(245,166,35,.08)':'rgba(16,201,122,.06)' ?>;border:1px solid <?= $isPaused?'rgba(245,166,35,.3)':'rgba(16,201,122,.25)' ?>;">
        <div>
            <div class="pause-label" style="color:<?= $isPaused?'var(--amber)':'var(--green)' ?>;">
                <?= $isPaused ? '⏸ Auto-Ban is PAUSED' : '▶️ Auto-Ban is ACTIVE' ?>
            </div>
            <div class="pause-sub"><?= $isPaused
                ? '// Cron fires at 18:00 but will skip — no one gets banned until resumed'
                : '// Members missing 3+ reports this week get banned automatically at 18:00'
            ?></div>
        </div>
        <a href="auto_ban_control.php" class="btn-nav btn-control" style="white-space:nowrap;">
            <i class="fas fa-sliders"></i> &nbsp;<?= $isPaused ? 'Resume / Manage' : 'Pause / Manage' ?>
        </a>
    </div>

    <?php if (empty($bannedUsers)): ?>
        <div style="text-align:center;padding:100px 20px;color:var(--sub);">
            <i class="fas fa-check-circle" style="font-size:5rem;opacity:.12;display:block;margin-bottom:20px;"></i>
            <h3 style="font-size:1.4rem;margin-bottom:8px;">All clear!</h3>
            <p>No members banned or at risk this week.</p>
        </div>
    <?php else: ?>
        <div class="sh"><span>Attention Required (<?= count($bannedUsers) ?>)</span></div>
        <div class="users-grid">
        <?php foreach ($bannedUsers as $u):
            // AT RISK = still allowed but sent ZERO reports so far this week
            // BANNED  = isallowed = 0 (auto-ban already fired on them)
            // AT RISK = still allowed BUT missed >= 2 reports this week
            // BANNED  = isallowed = 0, stays banned until appeal approved
            $missed = $daysElapsedNow - (int)$u['reports_this_week'];
            $isAtRisk = ($u['isallowed'] == 1 && $missed >= 2);
            $daysElapsed = $daysElapsedNow;
        ?>
            <div class="user-card <?= $isAtRisk ? 'atrisk' : 'banned' ?>">
                <div class="card-head">
                    <div>
                        <div class="card-name"><?= htmlspecialchars($u['name']) ?></div>
                        <div class="card-email"><?= htmlspecialchars($u['email']) ?></div>
                        <div class="card-dept">
                            <?= htmlspecialchars($u['role']) ?>
                            <?php if ($u['department']): ?> · <?= htmlspecialchars($u['department']) ?><?php endif; ?>
                        </div>
                    </div>
                    <span class="badge <?= $isAtRisk ? 'badge-risk' : 'badge-banned' ?>">
                        <?= $isAtRisk ? 'AT RISK' : 'BANNED' ?>
                    </span>
                </div>

                <!-- Week report count -->
                <div class="week-info">
                    📅 This week: <strong style="color:<?= (int)$u['reports_this_week']===0?'var(--rose)':'var(--amber)' ?>;">
                        <?= (int)$u['reports_this_week'] ?> / <?= $daysElapsed ?> reports submitted
                    </strong>
                    &nbsp;·&nbsp; <?= $daysElapsed - (int)$u['reports_this_week'] ?> missed so far
                </div>

                <?php if (!empty($u['appeal'])): ?>
                    <div class="appeal-box">
                        <strong>Appeal:</strong><br>
                        <?= nl2br(htmlspecialchars($u['appeal'])) ?>
                    </div>
                <?php elseif (!$isAtRisk): ?>
                    <p class="no-appeal">No appeal submitted yet — user remains blocked until they appeal.</p>
                <?php else: ?>
                    <p class="no-appeal">Zero reports this week — at risk of ban at 18:00 tonight.</p>
                <?php endif; ?>

                <div class="action-buttons">
                    <?php if (!$isAtRisk): ?>
                    <form method="POST" style="flex:1;">
                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn-action btn-approve">✅ Approve</button>
                    </form>
                    <?php endif; ?>

                    <form method="POST" style="flex:1;"
                          <?= !$isAtRisk ? 'onsubmit="return confirm(\'Reject appeal? User stays banned.\')"' : '' ?>>
                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn-action <?= $isAtRisk ? 'btn-warning' : 'btn-reject' ?>">
                            <?= $isAtRisk ? '⚠️ Noted (At Risk)' : '❌ Reject Appeal' ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
<script>
function tick(){
    document.getElementById('devClock').textContent=new Date().toLocaleTimeString('en-ZA',{
        hour:'2-digit',minute:'2-digit',second:'2-digit',timeZone:'Africa/Johannesburg'
    });
}
setInterval(tick,1000); tick();
</script>
</body>
</html>