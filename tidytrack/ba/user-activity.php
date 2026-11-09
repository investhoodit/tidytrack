<?php
session_start();
include '../dp.php';
if (empty($_SESSION['ba_authenticated'])) {
    header('Location: index.php');
    exit;
}

if (
    empty($_SESSION['ba_user_id']) ||
    strcasecmp(trim($_SESSION['ba_department'] ?? ''), 'BA Team') !== 0
) {
    header('Location: index.php');
    exit;

}

// ── Month navigation ──
$today     = date('Y-m-d');
$thisYear  = (int)date('Y');
$thisMonth = (int)date('m');

$reqYear  = isset($_GET['year'])  ? (int)$_GET['year']  : $thisYear;
$reqMonth = isset($_GET['month']) ? (int)$_GET['month'] : $thisMonth;

// Clamp
if ($reqMonth < 1)  { $reqMonth = 12; $reqYear--; }
if ($reqMonth > 12) { $reqMonth = 1;  $reqYear++; }

$monthStart  = sprintf('%04d-%02d-01', $reqYear, $reqMonth);
$monthEnd    = date('Y-m-t', strtotime($monthStart));
$monthLabel  = date('F Y', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));
$firstDow    = (int)date('N', strtotime($monthStart)); // 1=Mon … 7=Sun, convert to 0=Sun
$firstDow    = $firstDow % 7; // Sunday=0

// Prev / Next
$prevMonth = $reqMonth - 1; $prevYear = $reqYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $reqMonth + 1; $nextYear = $reqYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// ── Total members (role = member only) ──
$stmtM = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE LOWER(role)='member'");
$stmtM->execute();
$totalMembers = (int)$stmtM->get_result()->fetch_assoc()['cnt'];
$stmtM->close();

// ── Attendance per day in month (4h = 240 min = present) ──
$attendStmt = $conn->prepare("
    SELECT session_date,
           COUNT(DISTINCT user_id) AS present_count
    FROM user_sessions
    WHERE session_date BETWEEN ? AND ?
    GROUP BY session_date
    HAVING SUM(minutes) >= 240 OR 1  -- we'll filter per-user in the JS modal
");
// Actually we need per-user per-day properly:
$attendStmt = $conn->prepare("
    SELECT us.session_date,
           COUNT(DISTINCT us.user_id) AS present_count
    FROM user_sessions us
    JOIN users u ON u.user_id = us.user_id AND LOWER(u.role)='member'
    WHERE us.session_date BETWEEN ? AND ?
    GROUP BY us.session_date, us.user_id
    HAVING SUM(us.minutes) >= 240
");
// Re-do as subquery for correct count:
$attendStmt->close();

$attendStmt = $conn->prepare("
    SELECT session_date, COUNT(*) as present_count
    FROM (
        SELECT us.session_date, us.user_id
        FROM user_sessions us
        JOIN users u ON u.user_id = us.user_id AND LOWER(u.role)='member'
        WHERE us.session_date BETWEEN ? AND ?
        GROUP BY us.session_date, us.user_id
        HAVING SUM(us.minutes) >= 240
    ) sub
    GROUP BY session_date
");
$attendStmt->bind_param("ss", $monthStart, $monthEnd);
$attendStmt->execute();
$attendResult = $attendStmt->get_result();
$attendByDay = [];
while ($row = $attendResult->fetch_assoc()) {
    $attendByDay[$row['session_date']] = (int)$row['present_count'];
}
$attendStmt->close();

// ── Daily reports per day in month ──
$reportStmt = $conn->prepare("
    SELECT sent_date, COUNT(DISTINCT user_id) as report_count
    FROM daily_tasks
    WHERE sent_date BETWEEN ? AND ?
    GROUP BY sent_date
");
$reportStmt->bind_param("ss", $monthStart, $monthEnd);
$reportStmt->execute();
$reportResult = $reportStmt->get_result();
$reportsByDay = [];
while ($row = $reportResult->fetch_assoc()) {
    $reportsByDay[$row['sent_date']] = (int)$row['report_count'];
}
$reportStmt->close();

// ── Consistency stats: members who attended >= 80% of working days this month ──
// Count working days elapsed (Mon–Fri, up to today if current month)
function countWorkingDays($year, $month, $limitToToday = false) {
    $today = date('Y-m-d');
    $days  = 0;
    $daysInM = (int)date('t', mktime(0,0,0,$month,1,$year));
    for ($d = 1; $d <= $daysInM; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        if ($limitToToday && $dateStr > $today) break;
        $dow = (int)date('N', strtotime($dateStr));
        if ($dow <= 5) $days++;
    }
    return $days;
}
$isCurrentMonth = ($reqYear === $thisYear && $reqMonth === $thisMonth);
$workingDays = countWorkingDays($reqYear, $reqMonth, $isCurrentMonth);

// Top attendees
$topAttendStmt = $conn->prepare("
    SELECT u.user_id, u.name,
           COUNT(DISTINCT sub.session_date) AS days_attended
    FROM users u
    LEFT JOIN (
        SELECT user_id, session_date
        FROM user_sessions
        WHERE session_date BETWEEN ? AND ?
        GROUP BY user_id, session_date
        HAVING SUM(minutes) >= 240
    ) sub ON sub.user_id = u.user_id
    WHERE LOWER(u.role) = 'member'
    GROUP BY u.user_id, u.name
    ORDER BY days_attended DESC
    LIMIT 10
");
$topAttendStmt->bind_param("ss", $monthStart, $monthEnd);
$topAttendStmt->execute();
$topAttendeesRaw = $topAttendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$topAttendStmt->close();

// Top report senders
$topReportStmt = $conn->prepare("
    SELECT u.user_id, u.name,
           COUNT(dt.sent_date) AS reports_sent
    FROM users u
    LEFT JOIN daily_tasks dt ON dt.user_id = u.user_id
        AND dt.sent_date BETWEEN ? AND ?
    WHERE LOWER(u.role) = 'member'
    GROUP BY u.user_id, u.name
    ORDER BY reports_sent DESC
    LIMIT 10
");
$topReportStmt->bind_param("ss", $monthStart, $monthEnd);
$topReportStmt->execute();
$topReportersRaw = $topReportStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$topReportStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Calendar · BA Portal</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
    --bg: #f5f8fa;
    --surface: #ffffff;
    --card: #ffffff;
    --card-h: #f0fdfa;
    --border: #e2e8f0;
    --border-h: #b7e4dc;
    --text: #1e293b;
    --sub: #64748b;
    --primary: #0d9488;
    --primary-dark: #0f766e;
    --blue: #0d9488;
    --violet: #14b8a6;
    --green: #16a34a;
    --amber: #d97706;
    --rose: #e11d48;
    --cyan: #0891b2;
    --r: 14px;
}

*, *::before, *::after {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'DM Sans', Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
}

body::before {
    content: none;
}

.page {
    position: relative;
    z-index: 1;
    max-width: 1440px;
    margin: 0 auto;
    padding: 30px;
}

/* TOP BAR */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    flex-wrap: wrap;
    gap: 18px;
}

.tb-left {
    display: flex;
    align-items: center;
    gap: 15px;
}

.back-btn {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text);
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 15px;
    transition: .2s;
    flex-shrink: 0;
}

.back-btn:hover {
    background: #ccfbf1;
    color: var(--primary-dark);
    border-color: #99f6e4;
}

.page-title {
    font-size: 25px;
    font-weight: 800;
    letter-spacing: -.6px;
    color: #0f172a;
}

.page-sub {
    font-size: 12px;
    color: var(--sub);
    margin-top: 5px;
}

/* MONTH NAVIGATION */
.month-nav {
    display: flex;
    align-items: center;
    gap: 9px;
    flex-wrap: wrap;
}

.mnav-btn {
    width: 39px;
    height: 39px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text);
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 13px;
    transition: .2s;
}

.mnav-btn:hover {
    background: #ccfbf1;
    border-color: #99f6e4;
    color: var(--primary-dark);
}

.mnav-label {
    font-size: 14px;
    font-weight: 800;
    min-width: 140px;
    text-align: center;
    color: var(--text);
}

.today-btn {
    padding: 10px 16px;
    border-radius: 10px;
    border: 1px solid var(--primary);
    background: var(--primary);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    transition: .2s;
}

.today-btn:hover {
    background: var(--primary-dark);
}

/* SUMMARY CARDS */
.summary-strip {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 25px;
}

.s-chip {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px;
    border-radius: 15px;
    border: 1px solid var(--border);
    background: var(--surface);
    font-size: 13px;
    font-weight: 600;
    color: var(--sub);
    box-shadow: 0 2px 5px rgba(15, 23, 42, .025);
}

.s-chip-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.s-chip span {
    font-weight: 800;
    color: var(--text);
    margin-left: auto;
    white-space: nowrap;
}

/* CALENDAR */
.cal-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 30px;
    box-shadow: 0 3px 10px rgba(15, 23, 42, .025);
}

.cal-head {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    background: #f8fafc;
    border-bottom: 1px solid var(--border);
}

.cal-dow {
    padding: 15px 4px;
    text-align: center;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #475569;
}

.cal-dow.weekend {
    color: #94a3b8;
}

.cal-body {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
}

.cal-day {
    min-width: 0;
    min-height: 112px;
    padding: 12px;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 9px;
    transition: background .2s;
}

.cal-day:nth-child(7n) {
    border-right: none;
}

.cal-day.empty {
    cursor: default;
    background: #f8fafc;
}

.cal-day:not(.empty):hover {
    background: #f0fdfa;
}

.cal-day.is-today {
    background: #f0fdfa;
}

.cal-day.weekend-day {
    background: #fafbfc;
}

.cal-day.weekend-day:hover {
    background: #f1f5f9;
}

.dn {
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    width: 27px;
    height: 27px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.cal-day.is-today .dn {
    background: var(--primary);
    color: #fff;
    border-radius: 9px;
}

/* ATTENDANCE BADGES */
.day-attend {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 800;
    padding: 6px 8px;
    border-radius: 7px;
    width: fit-content;
    max-width: 100%;
}

.day-report {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    font-weight: 600;
    color: var(--sub);
    padding: 2px 0;
}

.tier-full {
    background: #dcfce7;
    color: #15803d;
}

.tier-high {
    background: #ccfbf1;
    color: #0f766e;
}

.tier-mid {
    background: #fef3c7;
    color: #92400e;
}

.tier-low {
    background: #ffe4e6;
    color: #be123c;
}

.tier-zero {
    background: #f1f5f9;
    color: #64748b;
}

/* DAY DETAIL MODAL */
.modal-bg {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999;
    background: rgba(15, 23, 42, .5);
    backdrop-filter: blur(3px);
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-bg.open {
    display: flex;
}

.modal {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 18px;
    width: 100%;
    max-width: 680px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(15, 23, 42, .18);
    animation: popUp .2s ease both;
}

.modal-head {
    padding: 23px 26px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 2;
}

.modal-title {
    font-size: 19px;
    font-weight: 800;
    color: #0f172a;
}

.modal-date {
    font-size: 12px;
    color: var(--sub);
    margin-top: 5px;
}

.modal-close {
    width: 35px;
    height: 35px;
    border-radius: 9px;
    border: 1px solid var(--border);
    background: #fff;
    color: var(--sub);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: .2s;
}

.modal-close:hover {
    background: #ffe4e6;
    color: #be123c;
    border-color: #fecdd3;
}

.modal-body {
    padding: 22px 26px 26px;
}

/* MODAL STATS */
.m-stat-row {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.m-stat {
    min-width: 0;
    background: #f8fafc;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 15px 8px;
    text-align: center;
}

.m-stat-num {
    font-size: 26px;
    font-weight: 800;
    line-height: 1.2;
}

.m-stat-lbl {
    font-size: 10px;
    color: var(--sub);
    margin-top: 6px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
}

/* MEMBER LIST */
.m-section-label {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: #475569;
    margin-bottom: 10px;
    margin-top: 20px;
}

.member-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.member-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 12px;
    border-radius: 10px;
    background: #fff;
    border: 1px solid var(--border);
    font-size: 13px;
    min-width: 0;
}

.m-avatar {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    color: #fff;
    background: linear-gradient(135deg, #0d9488, #14b8a6);
}

.m-name {
    font-weight: 700;
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}

.m-tag {
    font-size: 10px;
    font-weight: 800;
    padding: 5px 8px;
    border-radius: 20px;
    white-space: nowrap;
}

.tag-attend {
    background: #dcfce7;
    color: #15803d;
}

.tag-report {
    background: #ccfbf1;
    color: #0f766e;
}

.tag-absent {
    background: #ffe4e6;
    color: #be123c;
}

.tag-no-rep {
    background: #fef3c7;
    color: #92400e;
}

/* LEADERBOARDS */
.stats-section {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 35px;
}

.stat-table-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 3px 10px rgba(15, 23, 42, .025);
}

.stc-head {
    padding: 19px 20px;
    border-bottom: 1px solid var(--border);
    background: #fff;
    font-size: 14px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 9px;
    color: #1e293b;
}

.stc-body {
    padding: 0;
}

.stc-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 20px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
}

.stc-row:last-child {
    border-bottom: none;
}

.stc-rank {
    font-size: 11px;
    font-weight: 800;
    color: var(--sub);
    width: 23px;
    flex-shrink: 0;
    text-align: center;
}

.stc-rank.gold {
    color: #ca8a04;
}

.stc-rank.silver {
    color: #64748b;
}

.stc-rank.bronze {
    color: #b45309;
}

.stc-name {
    flex: 1;
    min-width: 0;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.stc-bar-wrap {
    width: 80px;
    flex-shrink: 0;
}

.stc-bar-bg {
    background: #e2e8f0;
    border-radius: 20px;
    height: 6px;
    overflow: hidden;
}

.stc-bar {
    height: 100%;
    border-radius: 20px;
    background: linear-gradient(90deg, #0d9488, #2dd4bf);
}

.stc-val {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    min-width: 32px;
    text-align: right;
}

@keyframes popUp {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* TABLET */
@media (max-width: 900px) {
    .page {
        padding: 24px 18px;
    }

    .summary-strip {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .cal-day {
        min-height: 90px;
        padding: 8px;
    }

    .day-attend {
        font-size: 10px;
        padding: 5px;
    }
}

/* MOBILE */
@media (max-width: 600px) {
    .page {
        padding: 18px 12px;
    }

    .topbar {
        align-items: flex-start;
        margin-bottom: 22px;
    }

    .tb-left {
        gap: 10px;
    }

    .page-title {
        font-size: 20px;
    }

    .page-sub {
        font-size: 11px;
        line-height: 1.6;
    }

    .month-nav {
        width: 100%;
        justify-content: space-between;
        gap: 6px;
    }

    .mnav-label {
        min-width: 0;
        font-size: 13px;
    }

    .mnav-btn {
        width: 34px;
        height: 34px;
    }

    .today-btn {
        padding: 9px 11px;
    }

    .summary-strip {
        gap: 10px;
        margin-bottom: 18px;
    }

    .s-chip {
        padding: 13px 10px;
        gap: 8px;
        font-size: 11px;
        flex-wrap: wrap;
    }

    .s-chip span {
        width: 100%;
        margin-left: 18px;
        font-size: 13px;
    }

    .cal-wrap {
        border-radius: 12px;
        margin-bottom: 22px;
    }

    .cal-dow {
        padding: 11px 0;
        font-size: 9px;
    }

    .cal-day {
        min-height: 66px;
        padding: 5px 3px;
        gap: 5px;
    }

    .dn {
        font-size: 11px;
        width: 22px;
        height: 22px;
    }

    .cal-day.is-today .dn {
        width: 22px;
        height: 22px;
    }

    .day-attend {
        font-size: 9px;
        padding: 4px 3px;
        gap: 3px;
    }

    .day-attend i {
        font-size: 8px !important;
    }

    .day-report {
        display: none;
    }

    .stats-section {
        grid-template-columns: 1fr;
        gap: 14px;
    }

    .stc-head {
        padding: 15px;
        font-size: 12px;
    }

    .stc-row {
        padding: 12px;
        gap: 8px;
    }

    .stc-bar-wrap {
        width: 48px;
    }

    .modal-bg {
        padding: 10px;
    }

    .modal {
        max-height: 94vh;
        border-radius: 14px;
    }

    .modal-head {
        padding: 18px 16px 14px;
    }

    .modal-title {
        font-size: 16px;
    }

    .modal-body {
        padding: 16px;
    }

    .m-stat-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .m-stat {
        padding: 12px 6px;
    }

    .m-stat-num {
        font-size: 23px;
    }

    .member-row {
        gap: 7px;
        padding: 9px 7px;
        flex-wrap: wrap;
    }

    .m-name {
        font-size: 12px;
    }

    .m-tag {
        font-size: 9px;
        padding: 4px 6px;
    }
}
</style>

</head>
<body>
<div class="page">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="tb-left">
            <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
            <div>
                <div class="page-title">Attendance Overview</div>
                <div class="page-sub">member attendance · daily reports · <?= $totalMembers ?> total members</div>
            </div>
        </div>
        <div class="month-nav">
            <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="mnav-btn"><i class="fas fa-chevron-left"></i></a>
            <div class="mnav-label"><?= $monthLabel ?></div>
            <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="mnav-btn"><i class="fas fa-chevron-right"></i></a>
            <?php if (!($reqYear === $thisYear && $reqMonth === $thisMonth)): ?>
                <a href="dev_attendance.php" class="today-btn">Today</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- SUMMARY STRIP -->
    <?php
    $todayKey   = date('Y-m-d');
    $todayCount = $attendByDay[$todayKey] ?? 0;
    $todayRep   = $reportsByDay[$todayKey] ?? 0;
    $totalAttendDays = count($attendByDay);
    ?>
    <div class="summary-strip">
        <div class="s-chip">
            <div class="s-chip-dot" style="background:var(--green)"></div>
            Today present: <span><?= $todayCount ?> / <?= $totalMembers ?></span>
        </div>
        <div class="s-chip">
            <div class="s-chip-dot" style="background:var(--blue)"></div>
            Today reports: <span><?= $todayRep ?> / <?= $totalMembers ?></span>
        </div>
        <div class="s-chip">
            <div class="s-chip-dot" style="background:var(--sub)"></div>
            Working days: <span><?= $workingDays ?> <?= $isCurrentMonth ? '(to date)' : '' ?></span>
        </div>
        <div class="s-chip">
            <div class="s-chip-dot" style="background:var(--amber)"></div>
            Days with data: <span><?= $totalAttendDays ?></span>
        </div>
    </div>

    <!-- CALENDAR -->
    <div class="cal-wrap">
        <div class="cal-head">
            <?php
            $dows = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            foreach ($dows as $i => $d) {
                $wkClass = ($i === 0 || $i === 6) ? ' weekend' : '';
                echo "<div class='cal-dow{$wkClass}'>{$d}</div>";
            }
            ?>
        </div>
        <div class="cal-body">
            <?php
            // Empty cells before month start
            for ($e = 0; $e < $firstDow; $e++) {
                echo "<div class='cal-day empty'></div>";
            }

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dateStr  = sprintf('%04d-%02d-%02d', $reqYear, $reqMonth, $d);
                $dow      = (int)date('N', strtotime($dateStr)); // 1=Mon 7=Sun
                $isWknd   = ($dow === 6 || $dow === 7);
                $isToday  = ($dateStr === $today);
                $present  = $attendByDay[$dateStr] ?? 0;
                $reports  = $reportsByDay[$dateStr] ?? 0;
                $pct      = $totalMembers > 0 ? ($present / $totalMembers) : 0;

                // Colour tier
                if ($isWknd)       $tier = 'tier-zero';
                elseif ($pct >= .9) $tier = 'tier-full';
                elseif ($pct >= .6) $tier = 'tier-high';
                elseif ($pct >= .3) $tier = 'tier-mid';
                elseif ($present > 0) $tier = 'tier-low';
                else               $tier = 'tier-zero';

                $classes = 'cal-day';
                if ($isToday) $classes .= ' is-today';
                if ($isWknd)  $classes .= ' weekend-day';

                echo "<div class='{$classes}' 
                    data-date='{$dateStr}'
                    data-present='{$present}'
                    data-reports='{$reports}'
                    onclick='openModal(this)'>";

                // Day number
                if ($isToday) echo "<div class='dn'>{$d}</div>";
                else echo "<div class='dn'>{$d}</div>";

                if (!$isWknd) {
                    echo "<div class='day-attend {$tier}'>
                            <i class='fas fa-users' style='font-size:9px;'></i> {$present}/{$totalMembers}
                          </div>";
                    echo "<div class='day-report'>
                            <i class='fas fa-file-alt' style='font-size:9px;color:var(--blue);'></i> {$reports} reports
                          </div>";
                }

                echo "</div>";
            }

            // Fill remaining cells to complete last row
            $cellsUsed = $firstDow + $daysInMonth;
            $remainder = $cellsUsed % 7;
            if ($remainder > 0) {
                for ($e = $remainder; $e < 7; $e++) {
                    echo "<div class='cal-day empty'></div>";
                }
            }
            ?>
        </div>
    </div>

    <!-- CONSISTENCY STATS -->
    <div class="stats-section">
        <!-- Top Attendees -->
        <div class="stat-table-card">
            <div class="stc-head"><i class="fas fa-calendar-check" style="color:var(--green)"></i> Top Attendees · <?= $monthLabel ?></div>
            <div class="stc-body">
                <?php foreach ($topAttendeesRaw as $i => $row):
                    $rankClass = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
                    $barW = $workingDays > 0 ? round(($row['days_attended'] / $workingDays) * 100) : 0;
                ?>
                <div class="stc-row">
                    <div class="stc-rank <?= $rankClass ?>"><?= $i+1 ?></div>
                    <div class="stc-name"><?= htmlspecialchars($row['name']) ?></div>
                    <div class="stc-bar-wrap">
                        <div class="stc-bar-bg"><div class="stc-bar" style="width:<?= $barW ?>%"></div></div>
                    </div>
                    <div class="stc-val"><?= $row['days_attended'] ?>d</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Top Report Senders -->
        <div class="stat-table-card">
            <div class="stc-head"><i class="fas fa-file-alt" style="color:var(--blue)"></i> Top Report Senders · <?= $monthLabel ?></div>
            <div class="stc-body">
                <?php foreach ($topReportersRaw as $i => $row):
                    $rankClass = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
                    $barW = $workingDays > 0 ? round(($row['reports_sent'] / $workingDays) * 100) : 0;
                ?>
                <div class="stc-row">
                    <div class="stc-rank <?= $rankClass ?>"><?= $i+1 ?></div>
                    <div class="stc-name"><?= htmlspecialchars($row['name']) ?></div>
                    <div class="stc-bar-wrap">
                        <div class="stc-bar-bg"><div class="stc-bar" style="width:<?= $barW ?>%;background:linear-gradient(90deg,var(--blue),var(--cyan));"></div></div>
                    </div>
                    <div class="stc-val"><?= $row['reports_sent'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>

<!-- ─── DAY DETAIL MODAL ─── -->
<div class="modal-bg" id="modalBg" onclick="closeModal(event)">
    <div class="modal" id="modal">
        <div class="modal-head">
            <div>
                <div class="modal-title" id="modalTitle">Day Detail</div>
                <div class="modal-date" id="modalDate"></div>
            </div>
            <button class="modal-close" onclick="document.getElementById('modalBg').classList.remove('open')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="modalBody">
            <div style="text-align:center;padding:40px;color:var(--sub);">Loading...</div>
        </div>
    </div>
</div>

<script>
const TOTAL_MEMBERS = <?= $totalMembers ?>;

function openModal(el) {
    if (el.classList.contains('empty') || el.classList.contains('weekend-day')) return;
    const date     = el.dataset.date;
    const present  = parseInt(el.dataset.present);
    const reports  = parseInt(el.dataset.reports);
    const absent   = TOTAL_MEMBERS - present;
    const noReport = TOTAL_MEMBERS - reports;

    // Header
    const d = new Date(date + 'T00:00:00');
    document.getElementById('modalTitle').textContent = d.toLocaleDateString('en-ZA',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
    document.getElementById('modalDate').textContent  = date;

    // Build stat row
    const pct = TOTAL_MEMBERS > 0 ? Math.round((present/TOTAL_MEMBERS)*100) : 0;
    let html = `
        <div class="m-stat-row">
            <div class="m-stat">
                <div class="m-stat-num" style="color:var(--green)">${present}</div>
                <div class="m-stat-lbl">Present (4h+)</div>
            </div>
            <div class="m-stat">
                <div class="m-stat-num" style="color:var(--rose)">${absent}</div>
                <div class="m-stat-lbl">Absent</div>
            </div>
            <div class="m-stat">
                <div class="m-stat-num" style="color:var(--blue)">${reports}</div>
                <div class="m-stat-lbl">Reports sent</div>
            </div>
            <div class="m-stat">
                <div class="m-stat-num" style="color:var(--amber)">${noReport}</div>
                <div class="m-stat-lbl">No report</div>
            </div>
        </div>
        <div style="text-align:center;font-size:11px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-bottom:4px;">
            ${pct}% attendance rate
        </div>
    `;

    // Loading members
    html += `<div id="memberListArea" style="margin-top:4px;">
        <div style="text-align:center;padding:30px;color:var(--sub);font-size:13px;">
            <i class="fas fa-spinner fa-spin"></i> Loading members...
        </div>
    </div>`;

    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('modalBg').classList.add('open');

    // Fetch member detail
    fetch(`user-activity-day.php?date=${encodeURIComponent(date)}`)
        .then(r => r.json())
        .then(data => {
            let mHtml = '';

            if (data.attended && data.attended.length > 0) {
                mHtml += `<div class="m-section-label">✅ Attended (${data.attended.length})</div><div class="member-list">`;
                data.attended.forEach(m => {
                    const repTag = m.reported
                        ? `<span class="m-tag tag-report">Report ✓</span>`
                        : `<span class="m-tag tag-no-rep">No report</span>`;
                    mHtml += `<div class="member-row">
                        <div class="m-avatar">${m.name.charAt(0).toUpperCase()}</div>
                        <div class="m-name">${escHtml(m.name)}</div>
                        <span class="m-tag tag-attend">${m.hours}h</span>
                        ${repTag}
                    </div>`;
                });
                mHtml += '</div>';
            }

            if (data.absent && data.absent.length > 0) {
                mHtml += `<div class="m-section-label">❌ Absent (${data.absent.length})</div><div class="member-list">`;
                data.absent.forEach(m => {
                    const repTag = m.reported
                        ? `<span class="m-tag tag-report">Report ✓</span>`
                        : `<span class="m-tag tag-no-rep">No report</span>`;
                    mHtml += `<div class="member-row">
                        <div class="m-avatar" style="background:linear-gradient(135deg,var(--rose),#c0392b)">${m.name.charAt(0).toUpperCase()}</div>
                        <div class="m-name">${escHtml(m.name)}</div>
                        <span class="m-tag tag-absent">Absent</span>
                        ${repTag}
                    </div>`;
                });
                mHtml += '</div>';
            }

            if (!mHtml) mHtml = '<div style="color:var(--sub);font-size:13px;padding:16px 0;">No member data for this day.</div>';
            document.getElementById('memberListArea').innerHTML = mHtml;
        })
        .catch(() => {
            document.getElementById('memberListArea').innerHTML = '<div style="color:var(--rose);font-size:13px;padding:16px 0;">Failed to load member data.</div>';
        });
}

function closeModal(e) {
    if (e.target === document.getElementById('modalBg')) {
        document.getElementById('modalBg').classList.remove('open');
    }
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>