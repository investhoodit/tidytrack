<?php
define('RUNNING_AS_CRON', true);

include __DIR__ . '/dp.php'; 

$log = [];
$log[] = "═══════════════════════════════════════════";
$log[] = "AUTO-BAN RUN: " . date('Y-m-d H:i:s');
$log[] = "═══════════════════════════════════════════";

// ── 1. Ensure settings table exists ───────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    DATETIME DEFAULT NOW() ON UPDATE NOW(),
    updated_by    VARCHAR(100) DEFAULT NULL
)");

// ── 2. Check pause flag ────────────────────────────────────────
$res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'auto_ban_paused'");
$row = $res ? $res->fetch_assoc() : null;
$isPaused = $row && $row['setting_value'] === '1';

if ($isPaused) {
    $log[] = "⏸  AUTO-BAN IS PAUSED — skipping run.";
    echo implode("\n", $log) . "\n";
    exit;
}

// ── 3. Work out how many weekdays have elapsed this week ──────
$dayOfWeek = (int)date('N'); // 1=Mon … 7=Sun
if ($dayOfWeek >= 6) {
    $log[] = "📅 Today is a weekend — nothing to do.";
    echo implode("\n", $log) . "\n";
    exit;
}
$weekdaysElapsed = $dayOfWeek; // Mon=1 … Fri=5

$weekStart = date('Y-m-d', strtotime('monday this week'));
$today     = date('Y-m-d');

$log[] = "📅 Week start : {$weekStart}";
$log[] = "📅 Today      : {$today}";
$log[] = "📅 Weekdays elapsed this week: {$weekdaysElapsed}";

// ── 4. Find members to ban ────────────────────────────────────
// Missed reports = weekdays_elapsed - reports sent Mon→today
// Ban if missed >= 3
$stmt = $conn->prepare("
    SELECT u.user_id, u.name, u.email,
           (SELECT COUNT(*)
            FROM daily_tasks dt
            WHERE dt.user_id = u.user_id
              AND dt.sent_date >= ?
              AND dt.sent_date <= ?) AS reports_sent
    FROM users u
    WHERE u.role    = 'member'
      AND u.isallowed = 1
");
$stmt->bind_param("ss", $weekStart, $today);
$stmt->execute();
$members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$banned      = [];
$alreadyGood = [];

foreach ($members as $m) {
    $missed = $weekdaysElapsed - (int)$m['reports_sent'];
    if ($missed >= 3) {
        $banned[] = $m;
    } else {
        $alreadyGood[] = $m['name'] . " (sent:{$m['reports_sent']}, missed:{$missed})";
    }
}

$log[] = "👥 Total active members checked: " . count($members);
$log[] = "✅ Members within threshold: " . count($alreadyGood);
$log[] = "🚫 Members to ban now: " . count($banned);

// ── 5. Ban them ───────────────────────────────────────────────
if (!empty($banned)) {
    $banStmt = $conn->prepare("UPDATE users SET isallowed = 0 WHERE user_id = ?");
    foreach ($banned as $b) {
        $banStmt->bind_param("i", $b['user_id']);
        $banStmt->execute();
        $missed = $weekdaysElapsed - (int)$b['reports_sent'];
        $log[] = "  🚫 BANNED: {$b['name']} ({$b['email']}) — sent:{$b['reports_sent']}, missed:{$missed}";
    }
    $banStmt->close();
} else {
    $log[] = "  ✅ No one to ban this run.";
}

// ── 6. Log this run into DB (optional audit trail) ───────────
$conn->query("CREATE TABLE IF NOT EXISTS auto_ban_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    ran_at     DATETIME NOT NULL,
    week_start DATE NOT NULL,
    days_elapsed INT NOT NULL,
    total_checked INT NOT NULL,
    total_banned  INT NOT NULL,
    was_paused    TINYINT(1) DEFAULT 0,
    note       TEXT
)");
$checked = count($members);
$bannedN  = count($banned);
$note     = implode(" | ", array_map(fn($b) => $b['name'], $banned)) ?: 'none';
$conn->query("INSERT INTO auto_ban_log (ran_at, week_start, days_elapsed, total_checked, total_banned, was_paused, note)
    VALUES (NOW(), '{$weekStart}', {$weekdaysElapsed}, {$checked}, {$bannedN}, 0, " . $conn->real_escape_string($note) . ")");

$log[] = "";
$log[] = "✅ Run complete at " . date('H:i:s');
$log[] = "═══════════════════════════════════════════";

echo implode("\n", $log) . "\n";