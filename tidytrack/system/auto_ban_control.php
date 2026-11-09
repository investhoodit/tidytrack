<?php
session_start();
include '../dp.php';

if (!isset($_SESSION['developer_id'])) {
    header('Location: index.php');
    exit;
}

// Ensure tables exist
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

$devName = $_SESSION['developer_name'] ?? 'Developer';
$msg = ''; $msgType = 'success';

// ── TOGGLE PAUSE ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_pause'])) {
    $newVal = $_POST['toggle_pause'] === '1' ? '1' : '0';
    $escaped = $conn->real_escape_string($devName);
    $conn->query("INSERT INTO system_settings (setting_key, setting_value, updated_by)
        VALUES ('auto_ban_paused', '{$newVal}', '{$escaped}')
        ON DUPLICATE KEY UPDATE setting_value='{$newVal}', updated_by='{$escaped}', updated_at=NOW()");
    $msg = $newVal === '1'
        ? "⏸ Auto-ban PAUSED. Cron will run but skip banning until you resume."
        : "▶️ Auto-ban RESUMED. Cron will ban normally at 18:00.";
}

// ── MANUAL BAN TRIGGER (for testing) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_run'])) {
    $output = shell_exec('/usr/bin/php ' . dirname(__DIR__) . '/auto_ban.php 2>&1');
    $msg = "🔧 Manual run triggered.";
    $msgType = 'info';
}

// ── FETCH CURRENT PAUSE STATE ─────────────────────────────────
$res = $conn->query("SELECT setting_value, updated_at, updated_by FROM system_settings WHERE setting_key='auto_ban_paused'");
$setting = $res ? $res->fetch_assoc() : null;
$isPaused = $setting && $setting['setting_value'] === '1';

// ── FETCH BAN LOG (last 20 runs) ──────────────────────────────
$logRows = $conn->query("SELECT * FROM auto_ban_log ORDER BY ran_at DESC LIMIT 20");
$banLogs = $logRows ? $logRows->fetch_all(MYSQLI_ASSOC) : [];

// ── FETCH THIS WEEK'S AT-RISK MEMBERS ────────────────────────
$weekStart    = date('Y-m-d', strtotime('monday this week'));
$today        = date('Y-m-d');
$dayOfWeek    = (int)date('N');
$daysElapsed  = min($dayOfWeek, 5);

$riskStmt = $conn->prepare("
    SELECT u.user_id, u.name, u.email, u.department, u.isallowed,
           (SELECT COUNT(*) FROM daily_tasks dt
            WHERE dt.user_id = u.user_id
              AND dt.sent_date >= ? AND dt.sent_date <= ?) AS reports_sent
    FROM users u
    WHERE u.role = 'member'
    ORDER BY reports_sent ASC
");
$riskStmt->bind_param("ss", $weekStart, $today);
$riskStmt->execute();
$allMembers = $riskStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$riskStmt->close();

// categorise
$banned = $atRisk = $safe = [];
foreach ($allMembers as $m) {
    $missed = $daysElapsed - (int)$m['reports_sent'];
    $m['missed'] = $missed;
    if ($m['isallowed'] == 0)  $banned[] = $m;
    elseif ($missed >= 2)      $atRisk[] = $m;
    else                       $safe[]   = $m;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Auto-Ban Control • Dev Portal</title>
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
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:48px;}
.tb-brand{display:flex;align-items:center;gap:14px;}
.tb-logo{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--blue),var(--violet));display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;color:#fff;}
.tb-name{font-size:1.1rem;font-weight:800;}
.tb-meta{font-size:10.5px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-top:2px;}
.tb-right{display:flex;align-items:center;gap:10px;}
.tb-clock{padding:7px 14px;border-radius:10px;background:var(--card);border:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--sub);}
.btn-back{padding:8px 18px;border-radius:10px;font-size:12px;font-weight:700;background:rgba(79,126,245,.1);color:var(--blue);border:1px solid rgba(79,126,245,.2);text-decoration:none;transition:all .2s;}
.btn-back:hover{background:rgba(79,126,245,.2);}

/* HERO TOGGLE */
.toggle-hero{
    background:var(--card);border:1px solid var(--border);border-radius:20px;
    padding:32px;margin-bottom:28px;
    display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;
}
.toggle-hero.paused{border-color:rgba(245,166,35,.3);background:rgba(245,166,35,.04);}
.toggle-hero.active{border-color:rgba(16,201,122,.3);background:rgba(16,201,122,.04);}
.th-left{}
.th-status{font-size:1.6rem;font-weight:800;margin-bottom:6px;}
.th-sub{color:var(--sub);font-size:13.5px;font-family:'JetBrains Mono',monospace;}
.th-updated{font-size:11.5px;color:var(--sub);margin-top:8px;}
.toggle-btn{
    padding:14px 32px;border-radius:14px;font-size:14px;font-weight:800;
    border:none;cursor:pointer;transition:all .25s;font-family:'Syne',sans-serif;
    letter-spacing:-.2px;
}
.toggle-btn.pause{background:var(--amber);color:#000;}
.toggle-btn.pause:hover{background:#e0961d;transform:translateY(-2px);}
.toggle-btn.resume{background:var(--green);color:#000;}
.toggle-btn.resume:hover{background:#0ea66a;transform:translateY(-2px);}

/* CRON BOX */
.cron-box{
    background:var(--card);border:1px solid var(--border);border-radius:16px;
    padding:24px;margin-bottom:28px;
}
.cron-box h3{font-size:13px;font-weight:700;color:var(--sub);text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;}
.cron-cmd{
    background:#0c0e13;border:1px solid var(--border-h);border-radius:10px;
    padding:14px 18px;font-family:'JetBrains Mono',monospace;font-size:13px;
    color:var(--green);word-break:break-all;position:relative;
}
.copy-btn{
    position:absolute;top:10px;right:10px;background:rgba(255,255,255,.07);
    border:1px solid var(--border-h);color:var(--sub);padding:5px 12px;
    border-radius:8px;font-size:11px;cursor:pointer;font-family:inherit;transition:all .2s;
}
.copy-btn:hover{background:rgba(255,255,255,.14);color:var(--text);}
.copy-btn.copied{color:var(--green);border-color:var(--green);}
.cron-notes{margin-top:12px;font-size:12px;color:var(--sub);line-height:1.7;font-family:'JetBrains Mono',monospace;}
.cron-notes span{color:var(--blue);}

/* STATS ROW */
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:28px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;}
.stat-card .sc-label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-bottom:8px;}
.stat-card .sc-val{font-size:2rem;font-weight:800;}
.stat-card .sc-sub{font-size:11.5px;color:var(--sub);margin-top:4px;}
.val-green{color:var(--green);} .val-amber{color:var(--amber);} .val-rose{color:var(--rose);} .val-blue{color:var(--blue);}

/* SECTION HEADER */
.sh{display:flex;align-items:center;gap:10px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-bottom:16px;}
.sh::after{content:'';flex:1;height:1px;background:var(--border);}

/* MEMBER TABLE */
.member-table{width:100%;border-collapse:collapse;margin-bottom:28px;}
.member-table th{text-align:left;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);font-family:'JetBrains Mono',monospace;padding:10px 14px;border-bottom:1px solid var(--border);}
.member-table td{padding:13px 14px;border-bottom:1px solid var(--border);font-size:13.5px;}
.member-table tr:hover td{background:var(--card-h);}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-banned{background:rgba(245,79,107,.15);color:var(--rose);border:1px solid rgba(245,79,107,.3);}
.badge-risk{background:rgba(245,166,35,.15);color:var(--amber);border:1px solid rgba(245,166,35,.3);}
.badge-safe{background:rgba(16,201,122,.12);color:var(--green);border:1px solid rgba(16,201,122,.25);}
.progress-bar{width:100%;background:rgba(255,255,255,.06);border-radius:20px;height:5px;overflow:hidden;margin-top:4px;}
.progress-fill{height:100%;border-radius:20px;}
.fill-green{background:var(--green);} .fill-amber{background:var(--amber);} .fill-rose{background:var(--rose);}

/* LOG TABLE */
.log-table{width:100%;border-collapse:collapse;}
.log-table th{text-align:left;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--sub);font-family:'JetBrains Mono',monospace;padding:10px 14px;border-bottom:1px solid var(--border);}
.log-table td{padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;font-family:'JetBrains Mono',monospace;color:var(--sub);}
.log-table tr:hover td{background:var(--card-h);}

/* MSG BANNER */
.msg-banner{margin-bottom:24px;padding:14px 18px;border-radius:12px;font-size:13.5px;}
.msg-success{background:rgba(16,201,122,.12);color:var(--green);border:1px solid rgba(16,201,122,.25);}
.msg-info{background:rgba(79,126,245,.1);color:var(--blue);border:1px solid rgba(79,126,245,.25);}

.manual-btn{padding:10px 22px;border-radius:10px;font-size:12px;font-weight:700;background:rgba(124,90,245,.12);color:var(--violet);border:1px solid rgba(124,90,245,.25);cursor:pointer;font-family:inherit;transition:all .2s;}
.manual-btn:hover{background:rgba(124,90,245,.22);}
</style>
</head>
<body>
<div class="page">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="tb-brand">
            <div class="tb-logo">T</div>
            <div>
                <div class="tb-name">TidyTrack Dev Portal</div>
                <div class="tb-meta">// Auto-Ban Control Centre</div>
            </div>
        </div>
        <div class="tb-right">
            <div class="tb-clock" id="devClock">--:--:--</div>
            <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="msg-banner <?= $msgType==='info' ? 'msg-info' : 'msg-success' ?>"><?= $msg ?></div>
    <?php endif; ?>

    <!-- MAIN TOGGLE HERO -->
    <div class="toggle-hero <?= $isPaused ? 'paused' : 'active' ?>">
        <div class="th-left">
            <div class="th-status"><?= $isPaused ? '⏸ Auto-Ban is PAUSED' : '▶️ Auto-Ban is ACTIVE' ?></div>
            <div class="th-sub">// Cron fires at 18:00 every weekday — ban if missed reports &ge; 3 this week</div>
            <?php if ($setting): ?>
            <div class="th-updated">Last changed: <?= $setting['updated_at'] ?> by <?= htmlspecialchars($setting['updated_by'] ?? '—') ?></div>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <form method="POST">
                <input type="hidden" name="toggle_pause" value="<?= $isPaused ? '0' : '1' ?>">
                <button type="submit" class="toggle-btn <?= $isPaused ? 'resume' : 'pause' ?>">
                    <?= $isPaused ? '▶️ Resume Auto-Ban' : '⏸ Pause Auto-Ban' ?>
                </button>
            </form>
            <form method="POST" onsubmit="return confirm('Manually trigger the ban script now?')">
                <input type="hidden" name="manual_run" value="1">
                <button type="submit" class="manual-btn">🔧 Manual Run Now</button>
            </form>
        </div>
    </div>

    <!-- STATS ROW -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="sc-label">Week days elapsed</div>
            <div class="sc-val val-blue"><?= $daysElapsed ?>/5</div>
            <div class="sc-sub">Week of <?= date('M j', strtotime($weekStart)) ?></div>
        </div>
        <div class="stat-card">
            <div class="sc-label">Members checked</div>
            <div class="sc-val"><?= count($allMembers) ?></div>
            <div class="sc-sub">active members</div>
        </div>
        <div class="stat-card">
            <div class="sc-label">Currently banned</div>
            <div class="sc-val val-rose"><?= count($banned) ?></div>
            <div class="sc-sub">isallowed = 0</div>
        </div>
        <div class="stat-card">
            <div class="sc-label">At risk (2 missed)</div>
            <div class="sc-val val-amber"><?= count($atRisk) ?></div>
            <div class="sc-sub">1 more = ban tonight</div>
        </div>
        <div class="stat-card">
            <div class="sc-label">All good</div>
            <div class="sc-val val-green"><?= count($safe) ?></div>
            <div class="sc-sub">on track this week</div>
        </div>
    </div>

    <!-- MEMBER STATUS TABLE -->
    <div class="sh"><span>Member Compliance — Week of <?= date('M j', strtotime($weekStart)) ?></span></div>
    <table class="member-table">
        <thead>
            <tr>
                <th>Member</th>
                <th>Department</th>
                <th>Reports sent</th>
                <th>Missed</th>
                <th>Progress</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $sortedAll = array_merge($banned, $atRisk, $safe);
        foreach ($sortedAll as $m):
            $missed  = (int)$m['missed'];
            $sent    = (int)$m['reports_sent'];
            $pct     = $daysElapsed > 0 ? round(($sent / $daysElapsed) * 100) : 0;
            $fillCls = $pct >= 80 ? 'fill-green' : ($pct >= 50 ? 'fill-amber' : 'fill-rose');
            $isBanned = $m['isallowed'] == 0;
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($m['name']) ?></strong><br>
                <span style="color:var(--sub);font-size:11.5px;"><?= htmlspecialchars($m['email']) ?></span>
            </td>
            <td style="color:var(--sub);"><?= htmlspecialchars($m['department'] ?? '—') ?></td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:14px;color:var(--green);"><?= $sent ?>/<?= $daysElapsed ?></td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:14px;color:<?= $missed>=3?'var(--rose)':($missed>=2?'var(--amber)':'var(--sub)') ?>;"><?= $missed ?></td>
            <td style="min-width:100px;">
                <?= $pct ?>%
                <div class="progress-bar"><div class="progress-fill <?= $fillCls ?>" style="width:<?= $pct ?>%"></div></div>
            </td>
            <td>
                <?php if ($isBanned): ?>
                    <span class="badge badge-banned">BANNED</span>
                <?php elseif ($missed >= 2): ?>
                    <span class="badge badge-risk">AT RISK</span>
                <?php else: ?>
                    <span class="badge badge-safe">✅ OK</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- BAN LOG HISTORY -->
    <div class="sh"><span>Auto-Ban Run History (last 20)</span></div>
    <?php if (empty($banLogs)): ?>
    <p style="color:var(--sub);font-size:13px;margin-bottom:28px;">No runs logged yet — cron hasn't fired or table was just created.</p>
    <?php else: ?>
    <table class="log-table" style="margin-bottom:32px;">
        <thead>
            <tr>
                <th>Ran at</th>
                <th>Week start</th>
                <th>Days elapsed</th>
                <th>Checked</th>
                <th>Banned</th>
                <th>Paused?</th>
                <th>Banned members</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($banLogs as $l): ?>
        <tr>
            <td><?= $l['ran_at'] ?></td>
            <td><?= $l['week_start'] ?></td>
            <td><?= $l['days_elapsed'] ?></td>
            <td><?= $l['total_checked'] ?></td>
            <td style="color:<?= $l['total_banned']>0?'var(--rose)':'var(--green)' ?>;"><?= $l['total_banned'] ?></td>
            <td style="color:<?= $l['was_paused']?'var(--amber)':'var(--sub)' ?>;"><?= $l['was_paused'] ? '⏸ yes' : 'no' ?></td>
            <td style="font-size:12px;color:var(--sub);max-width:260px;word-break:break-word;"><?= htmlspecialchars($l['note'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

</div>
<script>
function tick(){
    document.getElementById('devClock').textContent=new Date().toLocaleTimeString('en-ZA',{hour:'2-digit',minute:'2-digit',second:'2-digit',timeZone:'Africa/Johannesburg'});
}
setInterval(tick,1000); tick();

function copyCron() {
    const text = document.getElementById('cronCmd').textContent.trim().replace('Copy','').trim();
    navigator.clipboard.writeText(text).then(()=>{
        const btn = document.querySelector('.copy-btn');
        btn.textContent='Copied!'; btn.classList.add('copied');
        setTimeout(()=>{ btn.textContent='Copy'; btn.classList.remove('copied'); },2000);
    });
}
</script>
</body>
</html>