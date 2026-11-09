<?php
session_start();
include '../dp.php';
if (!isset($_SESSION['developer_id'])) {
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
<title>Attendance Calendar · Dev Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --bg:#0c0e13; --surface:#101318; --card:#161a23; --card-h:#1c2132;
    --border:rgba(255,255,255,0.06); --border-h:rgba(255,255,255,0.13);
    --text:#dde1eb; --sub:#52596e;
    --blue:#4f7ef5; --violet:#7c5af5; --green:#10c97a;
    --amber:#f5a623; --rose:#f54f6b; --cyan:#0ac8e0;
    --r:14px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{
    font-family:'Syne',sans-serif;
    background:var(--bg); color:var(--text);
    min-height:100vh; overflow-x:hidden;
}
body::before{
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:radial-gradient(circle,rgba(79,126,245,.05) 1px,transparent 1px);
    background-size:28px 28px;
}
.page{position:relative;z-index:1;max-width:1400px;margin:0 auto;padding:28px 24px;}

/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:36px;flex-wrap:wrap;gap:12px;}
.tb-left{display:flex;align-items:center;gap:14px;}
.back-btn{
    width:38px;height:38px;border-radius:10px;border:1px solid var(--border);
    background:var(--card);color:var(--text);display:flex;align-items:center;
    justify-content:center;text-decoration:none;font-size:14px;
    transition:all .2s;flex-shrink:0;
}
.back-btn:hover{background:var(--card-h);border-color:var(--border-h);}
.page-title{font-size:1.4rem;font-weight:800;letter-spacing:-.3px;}
.page-sub{font-size:11px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-top:2px;}

/* MONTH NAV */
.month-nav{display:flex;align-items:center;gap:10px;}
.mnav-btn{
    width:36px;height:36px;border-radius:10px;border:1px solid var(--border);
    background:var(--card);color:var(--text);display:flex;align-items:center;
    justify-content:center;text-decoration:none;font-size:13px;
    transition:all .2s;
}
.mnav-btn:hover{background:var(--card-h);border-color:var(--border-h);}
.mnav-label{
    font-size:14px;font-weight:700;min-width:140px;text-align:center;
    font-family:'JetBrains Mono',monospace;
}
.today-btn{
    padding:8px 16px;border-radius:10px;border:1px solid var(--border);
    background:var(--card);color:var(--blue);font-size:12px;font-weight:700;
    text-decoration:none;font-family:'Syne',sans-serif;transition:all .2s;
}
.today-btn:hover{background:rgba(79,126,245,.1);border-color:var(--blue);}

/* SUMMARY STRIP */
.summary-strip{
    display:flex;gap:12px;flex-wrap:wrap;margin-bottom:28px;
}
.s-chip{
    display:flex;align-items:center;gap:8px;
    padding:10px 16px;border-radius:12px;border:1px solid var(--border);
    background:var(--card);font-size:13px;font-weight:700;
}
.s-chip-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.s-chip span{font-family:'JetBrains Mono',monospace;}

/* CALENDAR GRID */
.cal-wrap{
    background:var(--card);border:1px solid var(--border);
    border-radius:20px;overflow:hidden;margin-bottom:36px;
}
.cal-head{
    display:grid;grid-template-columns:repeat(7,1fr);
    background:var(--surface);border-bottom:1px solid var(--border);
}
.cal-dow{
    padding:12px 0;text-align:center;
    font-size:10.5px;font-weight:700;text-transform:uppercase;
    letter-spacing:.8px;color:var(--sub);
    font-family:'JetBrains Mono',monospace;
}
.cal-dow.weekend{color:rgba(245,79,107,.5);}

.cal-body{display:grid;grid-template-columns:repeat(7,1fr);}
.cal-day{
    min-height:96px;padding:10px;
    border-right:1px solid var(--border);border-bottom:1px solid var(--border);
    position:relative;cursor:pointer;
    transition:background .18s;
    display:flex;flex-direction:column;gap:6px;
}
.cal-day:nth-child(7n){border-right:none;}
.cal-day.empty{cursor:default;background:rgba(0,0,0,.15);}
.cal-day:not(.empty):hover{background:var(--card-h);}
.cal-day.is-today{background:rgba(79,126,245,.07);}
.cal-day.is-today .dn{color:var(--blue);}
.cal-day.weekend-day{background:rgba(0,0,0,.12);}

.dn{
    font-size:13px;font-weight:700;
    font-family:'JetBrains Mono',monospace;
    color:var(--sub);
}
.cal-day.is-today .dn{
    background:var(--blue);color:#fff;
    width:24px;height:24px;border-radius:7px;
    display:flex;align-items:center;justify-content:center;
    font-size:11px;
}

/* Badges inside day */
.day-attend{
    display:flex;align-items:center;gap:4px;
    font-size:11px;font-weight:700;font-family:'JetBrains Mono',monospace;
    padding:3px 7px;border-radius:7px;width:fit-content;
}
.day-report{
    display:flex;align-items:center;gap:4px;
    font-size:10px;font-weight:600;font-family:'JetBrains Mono',monospace;
    color:var(--sub);padding:2px 0;
}
/* colour tiers for attendance fill */
.tier-full  { background:rgba(16,201,122,.15); color:var(--green); }
.tier-high  { background:rgba(79,126,245,.15); color:var(--blue);  }
.tier-mid   { background:rgba(245,166,35,.15); color:var(--amber); }
.tier-low   { background:rgba(245,79,107,.15); color:var(--rose);  }
.tier-zero  { background:rgba(255,255,255,.04); color:var(--sub);  }

/* ─── MODAL ─── */
.modal-bg{
    display:none;position:fixed;inset:0;z-index:999;
    background:rgba(0,0,0,.7);backdrop-filter:blur(4px);
    align-items:center;justify-content:center;padding:20px;
}
.modal-bg.open{display:flex;}
.modal{
    background:var(--card);border:1px solid var(--border-h);
    border-radius:20px;width:100%;max-width:640px;
    max-height:90vh;overflow-y:auto;
    animation:popUp .25s cubic-bezier(.34,1.56,.64,1) both;
}
.modal-head{
    padding:24px 28px 16px;border-bottom:1px solid var(--border);
    display:flex;justify-content:space-between;align-items:flex-start;
    position:sticky;top:0;background:var(--card);z-index:2;
}
.modal-title{font-size:1.1rem;font-weight:800;letter-spacing:-.2px;}
.modal-date{font-size:11px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-top:3px;}
.modal-close{
    width:32px;height:32px;border-radius:9px;border:1px solid var(--border);
    background:transparent;color:var(--sub);cursor:pointer;
    display:flex;align-items:center;justify-content:center;font-size:14px;
    transition:all .15s;flex-shrink:0;
}
.modal-close:hover{background:var(--rose);color:#fff;border-color:var(--rose);}
.modal-body{padding:20px 28px 28px;}

/* stat row inside modal */
.m-stat-row{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;}
.m-stat{
    flex:1;min-width:110px;background:var(--surface);border:1px solid var(--border);
    border-radius:12px;padding:14px;text-align:center;
}
.m-stat-num{font-size:1.8rem;font-weight:800;line-height:1;}
.m-stat-lbl{font-size:10px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-top:4px;text-transform:uppercase;letter-spacing:.5px;}

/* member list inside modal */
.m-section-label{
    font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;
    color:var(--sub);font-family:'JetBrains Mono',monospace;
    margin-bottom:10px;margin-top:18px;
}
.member-list{display:flex;flex-direction:column;gap:6px;}
.member-row{
    display:flex;align-items:center;gap:10px;
    padding:9px 12px;border-radius:10px;background:var(--surface);
    border:1px solid var(--border);font-size:13px;
}
.m-avatar{
    width:28px;height:28px;border-radius:8px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    font-size:11px;font-weight:800;color:#fff;
    background:linear-gradient(135deg,var(--blue),var(--violet));
}
.m-name{font-weight:600;flex:1;}
.m-tag{
    font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;
    font-family:'JetBrains Mono',monospace;
}
.tag-attend{background:rgba(16,201,122,.12);color:var(--green);}
.tag-report{background:rgba(79,126,245,.12); color:var(--blue);}
.tag-absent{background:rgba(245,79,107,.12); color:var(--rose);}
.tag-no-rep{background:rgba(245,166,35,.12); color:var(--amber);}

/* ─── STATS SECTION ─── */
.stats-section{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:40px;}
@media(max-width:700px){.stats-section{grid-template-columns:1fr;}}
.stat-table-card{
    background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;
}
.stc-head{
    padding:16px 20px;border-bottom:1px solid var(--border);
    font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;
}
.stc-head i{opacity:.6;}
.stc-body{padding:0;}
.stc-row{
    display:flex;align-items:center;gap:10px;
    padding:11px 20px;border-bottom:1px solid var(--border);
    font-size:13px;
}
.stc-row:last-child{border-bottom:none;}
.stc-rank{
    font-size:10px;font-weight:800;color:var(--sub);
    font-family:'JetBrains Mono',monospace;
    width:18px;flex-shrink:0;text-align:center;
}
.stc-rank.gold{color:#f5c842;}
.stc-rank.silver{color:#aab0be;}
.stc-rank.bronze{color:#c07a40;}
.stc-name{flex:1;font-weight:600;}
.stc-bar-wrap{width:80px;}
.stc-bar-bg{background:var(--surface);border-radius:20px;height:6px;overflow:hidden;}
.stc-bar{height:100%;border-radius:20px;background:linear-gradient(90deg,var(--blue),var(--violet));}
.stc-val{font-size:11px;font-family:'JetBrains Mono',monospace;color:var(--sub);min-width:28px;text-align:right;}

@keyframes popUp{from{opacity:0;transform:scale(.92);}to{opacity:1;transform:scale(1);}}

@media(max-width:600px){
    .cal-day{min-height:68px;padding:6px;}
    .day-attend{font-size:10px;}
    .day-report{display:none;}
    .modal{border-radius:16px;}
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
    fetch(`dev_attendance_day.php?date=${date}`)
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