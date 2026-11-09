<?php
session_start();
include '../dp.php';
include '../track.php';
include '../track2.php';

$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$userName = isset($_SESSION['name']) ? $_SESSION['name'] : 'Member';
$userInitial = strtoupper($userName[0]);

if (!$userId) {
    header('Location: ../login.php');
    exit;
}

$stmt = $conn->prepare("SELECT profile_picture, status, role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$currentProfilePicture = $currentUser['profile_picture'] ?? null;
$currentStatus = $currentUser['status'] ?? 'offline';
$currentRole = $currentUser['role'] ?? 'Member';
$stmt->close();

log_activity($userId, "Viewed Help");

$status_info = [
    'online'        => ['name' => 'Online'],
    'out_of_office' => ['name' => 'Out of Office'],
    'sick'          => ['name' => 'Sick'],
    'focusing'      => ['name' => 'Focusing'],
    'in_meeting'    => ['name' => 'In Meeting'],
    'offline'       => ['name' => 'Offline'],
];

// ── TODAY'S TIME TRACKING ──────────────────────────────────────
$today = date('Y-m-d');
$isWeekend = date('N') >= 6;

$totalMinutesToday = 0;
if (!$isWeekend) {
    $stmt = $conn->prepare("SELECT SUM(minutes) as total FROM user_sessions WHERE user_id = ? AND session_date = ?");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $totalMinutesToday = (int)($result->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

$hoursToday = floor($totalMinutesToday / 60);
$minutesToday = $totalMinutesToday % 60;
$has4Hours = $totalMinutesToday >= 240;

// ── DAILY REPORT STATUS ────────────────────────────────────────
$reportSubmitted = false;
$existingReport = '';
$stmt = $conn->prepare("SELECT task FROM daily_tasks WHERE user_id = ? AND sent_date = ?");
$stmt->bind_param("is", $userId, $today);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $reportSubmitted = true;
    $existingReport = $result->fetch_assoc()['task'];
}
$stmt->close();

// ── TODAY'S PLAN (stored in DB or session) ────────────────────
$todayPlan = '';
$stmt = $conn->prepare("SELECT plan_text FROM member_daily_plans WHERE user_id = ? AND plan_date = ?");
// Gracefully handle if table doesn't exist yet
$tableCheck = $conn->query("SHOW TABLES LIKE 'member_daily_plans'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $stmt = $conn->prepare("SELECT plan_text FROM member_daily_plans WHERE user_id = ? AND plan_date = ?");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $todayPlan = $result->fetch_assoc()['plan_text'];
    }
    $stmt->close();
}


// ── HANDLE SAVING PLAN WITH FILE (multipart form, not JSON) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_plan_with_file') {
    header('Content-Type: application/json');
    $planText = trim($_POST['plan'] ?? '');
    if (strlen($planText) < 5) {
        echo json_encode(['success' => false, 'error' => 'Plan too short!']);
        exit;
    }

    $proofFile = null;
    if (!empty($_FILES['planFile']['tmp_name'])) {
        $allowed = ['pdf','doc','docx'];
        $ext = strtolower(pathinfo($_FILES['planFile']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Only PDF/DOC/DOCX allowed.']);
            exit;
        }
        $dir = "Uploads/plans/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $fname = "plan_{$userId}_" . time() . "." . $ext;
        if (move_uploaded_file($_FILES['planFile']['tmp_name'], $dir . $fname)) {
            $proofFile = $dir . $fname;
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS member_daily_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        plan_date DATE NOT NULL,
        plan_text TEXT NOT NULL,
        proof_file VARCHAR(255) NULL,
        created_at DATETIME DEFAULT NOW(),
        UNIQUE KEY unique_plan (user_id, plan_date)
    )");

    if ($proofFile) {
        $stmt = $conn->prepare("INSERT INTO member_daily_plans (user_id, plan_date, plan_text, proof_file) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE plan_text = VALUES(plan_text), proof_file = VALUES(proof_file), created_at = NOW()");
        $stmt->bind_param("isss", $userId, $today, $planText, $proofFile);
    } else {
        $stmt = $conn->prepare("INSERT INTO member_daily_plans (user_id, plan_date, plan_text) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE plan_text = VALUES(plan_text), created_at = NOW()");
        $stmt->bind_param("iss", $userId, $today, $planText);
    }
    $success = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $success, 'proof_file' => $proofFile]);
    exit;
}

// ── HANDLE SAVING TODAY'S PLAN (AJAX) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    
    // Save plan action
    if (isset($input['action']) && $input['action'] === 'save_plan') {
        header('Content-Type: application/json');
        $planText = trim($input['plan'] ?? '');
        if (strlen($planText) < 5) {
            echo json_encode(['success' => false, 'error' => 'Plan too short!']);
            exit;
        }
        // Create table if not exists
        $conn->query("CREATE TABLE IF NOT EXISTS member_daily_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan_date DATE NOT NULL,
            plan_text TEXT NOT NULL,
            created_at DATETIME DEFAULT NOW(),
            UNIQUE KEY unique_plan (user_id, plan_date)
        )");
        $stmt = $conn->prepare("INSERT INTO member_daily_plans (user_id, plan_date, plan_text) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE plan_text = VALUES(plan_text), created_at = NOW()");
        $stmt->bind_param("iss", $userId, $today, $planText);
        $success = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $success]);
        exit;
    }

    // Submit daily report from assistant
    if (isset($input['action']) && $input['action'] === 'submit_report') {
        header('Content-Type: application/json');
        $nowHour = (int)date('G');
        $nowMin  = (int)date('i');
       $inWindow = ($nowHour >= 16 && $nowHour < 17);
        
        if (!$inWindow) {
            echo json_encode(['success' => false, 'error' => 'Report window is closed. Opens 16:00–17:00.']);
            exit;
        }
        if (!$has4Hours) {
            echo json_encode(['success' => false, 'error' => 'You need 4 hours logged today first.']);
            exit;
        }
        $reportText = trim($input['report'] ?? '');
        if (strlen($reportText) < 10) {
            echo json_encode(['success' => false, 'error' => 'Report must be at least 10 characters.']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO daily_tasks (user_id, task, sent_at, sent_date)
            VALUES (?, ?, NOW(), CURDATE())
            ON DUPLICATE KEY UPDATE task = VALUES(task), sent_at = NOW()");
        $stmt->bind_param("is", $userId, $reportText);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok]);
        exit;
    }
    
    // ── Smart AI chat responses (no external API) ──────────────
    header('Content-Type: application/json');
    $userMessage = trim($input['message'] ?? '');
    $msgLower = strtolower($userMessage);
    
    if ($userMessage === '') {
        echo json_encode(['reply' => 'Please type something first!', 'type' => 'text']);
        exit;
    }

    // Fetch member tasks
    $ctxStmt = $conn->prepare("
        SELECT t.taskTitle, t.status, t.priority, t.dueDate, t.role AS taskRole,
               p.project_name, u2.name AS leaderName
        FROM tasks t
        JOIN projects_x p ON t.project_id = p.project_id
        JOIN users u2 ON u2.user_id = p.teamLeadder_id
        WHERE t.assignTo = ?
        ORDER BY t.dueDate ASC LIMIT 30
    ");
    $ctxStmt->bind_param("s", $userName);
    $ctxStmt->execute();
    $memberTasks = $ctxStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ctxStmt->close();

    // Fetch today's plan from DB
    $planFromDB = '';
    $tableCheck2 = $conn->query("SHOW TABLES LIKE 'member_daily_plans'");
    if ($tableCheck2 && $tableCheck2->num_rows > 0) {
        $ps = $conn->prepare("SELECT plan_text FROM member_daily_plans WHERE user_id = ? AND plan_date = ?");
        $ps->bind_param("is", $userId, $today);
        $ps->execute();
        $pr = $ps->get_result();
        if ($pr->num_rows > 0) $planFromDB = $pr->fetch_assoc()['plan_text'];
        $ps->close();
    }

    // Categorise tasks
    $overdueTasks = $activeTasks = $completedTasks = $highPriTasks = [];
    $leaders = [];
    foreach ($memberTasks as $t) {
        $status = strtolower($t['status']);
        $due = $t['dueDate'] ?? '2099-12-31';
        if ($t['leaderName']) $leaders[$t['leaderName']] = true;
        if ($status === 'completed') $completedTasks[] = $t;
        elseif ($due < $today && $status !== 'completed') $overdueTasks[] = $t;
        else $activeTasks[] = $t;
        if (strtolower($t['priority'] ?? '') === 'high' && $status !== 'completed') $highPriTasks[] = $t;
    }
    $leaderList = implode(', ', array_keys($leaders)) ?: 'not assigned';

    function formatTaskList($tasks, $max = 6) {
        if (empty($tasks)) return null;
        $lines = [];
        foreach (array_slice($tasks, 0, $max) as $t) {
            $due = $t['dueDate'] ? " (due {$t['dueDate']})" : '';
            $lines[] = "• {$t['taskTitle']}{$due} [{$t['status']}]";
        }
        if (count($tasks) > $max) $lines[] = '...and ' . (count($tasks) - $max) . ' more.';
        return implode("\n", $lines);
    }

    // Time awareness
  // Time awareness
$nowHour = (int)date('G');
$nowMin  = (int)date('i');
$inReportWindow = ($nowHour >= 16 && $nowHour < 17);
// NEW
$isPlanWindow     = $nowHour < 16;
$planWindowActive = $isPlanWindow && !$isWeekend;
$showPlanNudge    = ($nowHour >= 13 && $nowHour < 16 && empty($todayPlan) && !$isWeekend);
    // ── Pattern matching for smart replies ──
    $isHoursQ   = preg_match('/\b(hour|hours|minute|minutes|time|logged|tracked|attendance|how long)\b/', $msgLower);
    $isTaskQ    = preg_match('/\b(task|tasks|todo|to do|work|assigned)\b/', $msgLower);
    $isOverdueQ = preg_match('/\b(overdue|late|missed|behind|past due)\b/', $msgLower);
    $isUrgentQ  = preg_match('/\b(urgent|high.?priority|important|critical)\b/', $msgLower);
    $isLeaderQ  = preg_match('/\b(leader|manager|boss|who.*(assign|create|made))\b/', $msgLower);
    $isCountQ   = preg_match('/\b(how many|count|number of|total)\b/', $msgLower);
    $isPlanQ    = preg_match('/\b(plan|today|going to|will do|working on|planning|set plan|my plan)\b/', $msgLower);
    $isReportQ  = preg_match('/\b(report|daily report|send report|submit)\b/', $msgLower);
    $isStatusQ  = preg_match('/\b(status|progress|summary|how am i doing|doing)\b/', $msgLower);
    $isGreetQ   = preg_match('/^(hi|hello|hey|morning|afternoon|sup|yo|howzit)\b/', $msgLower);

    $reply = null;
    $replyType = 'text';

    // Greeting
    if ($isGreetQ) {
        $timeGreet = $nowHour < 12 ? 'Good morning' : ($nowHour < 17 ? 'Good afternoon' : 'Good evening');
        $pct = $totalMinutesToday > 0 ? min(100, round(($totalMinutesToday / 240) * 100)) : 0;
        $reply = "{$timeGreet}, {$userName}! 👋\n\nHere's your quick status:\n⏱️ Time logged today: **{$hoursToday}h {$minutesToday}m** ({$pct}% of 4hr goal)\n📋 Active tasks: " . count($activeTasks) . "\n⚠️ Overdue: " . count($overdueTasks) . "\n" . ($reportSubmitted ? "✅ Daily report: Submitted!" : ($inReportWindow ? "🟡 Report window OPEN — go submit!" : "📝 Daily report: Not yet submitted"));
    }
    // Hours/attendance questions
    elseif ($isHoursQ) {
        $pct = $totalMinutesToday > 0 ? min(100, round(($totalMinutesToday / 240) * 100)) : 0;
        $remaining = max(0, 240 - $totalMinutesToday);
        $remH = floor($remaining / 60);
        $remM = $remaining % 60;
        if ($has4Hours) {
            $reply = "You're smashing it! ✅\n\n⏱️ **{$hoursToday}h {$minutesToday}m** logged today — that's 100%+ of your 4hr goal!\n\n" . ($inReportWindow ? "🎯 The report window is OPEN right now (16:00–17:00) — go submit your daily report!" : ($reportSubmitted ? "Your daily report is already submitted. Great work!" : "Report window opens at 16:00 today."));
        } else {
            $reply = "Here's your time tracker for today 📊\n\n⏱️ Logged: **{$hoursToday}h {$minutesToday}m**\n🎯 Progress: {$pct}%\n⏳ Still need: **{$remH}h {$remM}m** to hit the 4hr goal\n\nKeep your browser open and active — it tracks automatically! 💪";
        }
    }
    // Daily status summary
    elseif ($isStatusQ) {
        $pct = min(100, round(($totalMinutesToday / 240) * 100));
        $reply = "Here's your full status for today, {$userName} 📊\n\n";
        $reply .= "⏱️ Time: **{$hoursToday}h {$minutesToday}m** ({$pct}% of goal)\n";
        $reply .= "📋 Total tasks: " . count($memberTasks) . "\n";
        $reply .= "✅ Completed: " . count($completedTasks) . "\n";
        $reply .= "🔄 Active: " . count($activeTasks) . "\n";
        $reply .= "⚠️ Overdue: " . count($overdueTasks) . "\n";
        $reply .= $reportSubmitted ? "📝 Daily report: ✅ Submitted\n" : "📝 Daily report: ❌ Not yet\n";
        if ($planFromDB) $reply .= "\n📌 Today's plan: \"{$planFromDB}\"";
    }
    // Today's plan
    elseif ($isPlanQ && !$isTaskQ) {
     if ($planFromDB) {
    $reply = "Here's your plan for today 📌\n\n\"{$planFromDB}\"\n\nStill on track? You can update it anytime before 16:00!";
    $replyType = 'show_plan';
        } elseif (!$isPlanWindow) {
            $reply = "The daily planning feature is available from **1:00 AM to 11:59 AM** only 🌅\n\nCome back in the morning to set your plan for the day! Right now focus on executing. 💪";
        } else {
            $reply = "You haven't set a plan for today yet! 📌\n\nType your plan below and I'll save it for you. Example:\n\"Today I'll work on the login module, fix 2 bugs, and update the docs.\"";
            $replyType = 'prompt_plan';
        }
    }
    // Overdue
    elseif ($isOverdueQ) {
        if (empty($overdueTasks)) {
            $reply = "Great news, {$userName}! 🎉 Zero overdue tasks right now. Keep that streak going!";
        } else {
            $list = formatTaskList($overdueTasks);
            $reply = "You have **" . count($overdueTasks) . " overdue task" . (count($overdueTasks) > 1 ? 's' : '') . "** ⚠️:\n\n{$list}\n\nWant help deciding which to tackle first?";
        }
    }
    // Urgent/high priority
    elseif ($isUrgentQ) {
        if (empty($highPriTasks)) {
            $reply = "No high-priority tasks right now, {$userName}! 😌 Enjoy the calm while it lasts.";
        } else {
            $list = formatTaskList($highPriTasks);
            $reply = "🔥 Your high-priority tasks:\n\n{$list}";
        }
    }
    // Leader
    elseif ($isLeaderQ) {
        $reply = "Your leader(s): **{$leaderList}** 👤\n\nThey assigned your tasks. Reach out to them for task decisions!";
    }
    // Task count
    elseif ($isCountQ && $isTaskQ) {
        $reply = "Your task summary, {$userName}:\n\n📋 Total: " . count($memberTasks) . "\n✅ Completed: " . count($completedTasks) . "\n🔄 Active: " . count($activeTasks) . "\n⚠️ Overdue: " . count($overdueTasks);
    }
    // Daily report
    elseif ($isReportQ) {
        if ($reportSubmitted) {
            $reply = "✅ Daily report already submitted for today!\n\nYour submission: \"{$existingReport}\"\n\nRest easy — you're done for today! 🎉";
        } elseif (!$has4Hours) {
            $remaining = 240 - $totalMinutesToday;
            $remH = floor($remaining / 60);
            $remM = $remaining % 60;
            $reply = "🔒 Daily report locked!\n\nYou need **4 hours** logged first.\n\nCurrent: {$hoursToday}h {$minutesToday}m\nStill need: {$remH}h {$remM}m\n\nKeep going — you're getting there! 💪";
        } elseif (!$inReportWindow) {
            $reply = "📋 You've hit your 4hrs! ✅\n\nBut the report window only opens at **16:00–17:00**.\n\n" . ($nowHour < 16 ? "Come back at 4 PM to submit!" : "The window has closed for today — come back tomorrow.");
        } else {
            $reply = "🟢 The report window is OPEN right now!\n\nYou've logged {$hoursToday}h {$minutesToday}m — qualifying for submission!\n\nClick **\"Submit Daily Report\"** below or type what you accomplished today and I'll help you send it!";
            $replyType = 'prompt_report';
        }
    }
    // Active tasks list
    elseif ($isTaskQ) {
        if (empty($activeTasks)) {
            $reply = "No active tasks right now, {$userName}! 🎉 You're all caught up!";
        } else {
            $list = formatTaskList($activeTasks);
            $reply = "Your active tasks 📋:\n\n{$list}\n\nNeed help prioritising?";
        }
    }
    // Fallback smart responses
    else {
        $motivations = [
            "Hmm, I'm not sure about that one! 🤔 But I *do* know your task status — want a quick summary?",
            "That's outside my knowledge, {$userName}! I'm best at tasks, time tracking & reports. Try: 'How many hours have I logged?' 😊",
            "I'm your TidyTrack buddy — I know your tasks, hours & reports! Ask me something like 'What's overdue?' or 'Am I on track today?'",
            "Not sure how to help with that! But I noticed you have " . count($activeTasks) . " active tasks — want me to break them down? 📋",
        ];
        $reply = str_replace('{$userName}', $userName, $motivations[array_rand($motivations)]);
    }

    echo json_encode(['reply' => $reply, 'type' => $replyType]);
    exit;
}

// ── Time window flags for JS ────────────────────────────────────
$nowHour = (int)date('G');
$inReportWindow   = ($nowHour >= 16 && $nowHour < 17);
$isPlanWindow     = $nowHour < 16;
$planWindowActive = $isPlanWindow && !$isWeekend;
$showPlanNudge    = ($nowHour >= 13 && $nowHour < 16 && empty($todayPlan) && !$isWeekend);
$showPlanPopup    = $isPlanWindow && !$isWeekend && empty($todayPlan);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TidyTrack Assistance</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
    <style>
        :root {
            --accent: #1d3557;
            --accent2: #457b9d;
            --bg: #eef2f5;
            --text: #333;
            --sidebar-bg: #fff;
            --sidebar-text: #666;
            --ai-bubble: #f0f4ff;
            --user-bubble: #d9fdd3;
            --border: #e2e8f0;
            --input-bg: #f8fafc;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        body.dark-mode {
            --bg: #121212;
            --text: #eee;
            --sidebar-bg: #1e1e1e;
            --sidebar-text: #ccc;
            --ai-bubble: #1e293b;
            --user-bubble: #1a3a2a;
            --border: #334155;
            --input-bg: #1e293b;
        }
        * { box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; margin: 0; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { width: 320px; background: var(--sidebar-bg); color: var(--sidebar-text); border-right: 1px solid #e0e0e0; display: flex; flex-direction: column; padding: 18px; position: fixed; height: 100vh; overflow-y: auto; }
        .sidebar .top { padding-bottom: 18px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 12px; }
        .sidebar .top .me { width: 44px; height: 44px; border-radius: 50%; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; position: relative; transition: transform 0.3s ease; cursor: default; }
        .sidebar .top .me:hover { transform: scale(1.1); }
        .sidebar .top .me img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .status-indicator { position: absolute; bottom: 0; right: 0; width: 10px; height: 10px; border-radius: 50%; border: 2px solid white; }
        .status-online { background: #00d4aa; } .status-out_of_office { background: #ffa726; }
        .status-sick { background: #e94560; } .status-focusing { background: #9c27b0; }
        .status-in_meeting { background: #2196f3; } .status-offline { background: #ccc; }
        .profile-popup { display: none; position: absolute; top: 60px; left: 50%; transform: translateX(-50%); background: white; padding: 1rem; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 1000; text-align: center; width: 200px; }
        .sidebar .top .me:hover .profile-popup { display: block; }
        .popup-avatar { width: 90px; height: 90px; border-radius: 50%; background: linear-gradient(135deg,#e94560,#f39c12); display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 800; color: white; overflow: hidden; border: 3px solid #e94560; margin: 0 auto; }
        .popup-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .popup-status { margin-top: .5rem; font-size: .85rem; color: #1d3557; font-weight: 500; }
        .popup-status-indicator { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: .4rem; }
        .popup-role { font-size: .78rem; color: #666; }
        .sidebar ul { list-style: none; margin-top: 20px; padding: 0; }
        .sidebar ul li { margin-bottom: 6px; }
        .sidebar ul li a { color: #666; text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 12px; padding: 10px; border-radius: 8px; }
        .sidebar ul li a:hover, .sidebar ul li a.active { background: #f0f4f8; color: var(--accent); }

        /* ── MAIN ── */
        .dashboard-container { flex: 1; margin-left: 320px; padding: 24px 28px; overflow: auto; display: flex; flex-direction: column; }
        .time-bar { display: flex; align-items: center; gap: 14px; font-size: 13px; color: #777; margin-bottom: 18px; }
        .time-bar i { color: var(--accent); }
        #localTime { font-weight: 700; color: var(--accent); }

        /* ── LIVE STATUS BAR ── */
        .live-status-bar {
            display: flex;
            align-items: center;
            gap: 16px;
            background: var(--sidebar-bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px 18px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .status-chip {
            display: flex;
            align-items: center;
            gap: 7px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 5px 14px;
            font-size: 12.5px;
            font-weight: 600;
        }
        .status-chip .dot { width: 8px; height: 8px; border-radius: 50%; }
        .dot-green { background: #10b981; }
        .dot-yellow { background: #f59e0b; }
        .dot-red { background: #ef4444; }
        .dot-blue { background: #3b82f6; }
        .hours-bar-wrap { flex: 1; min-width: 160px; }
        .hours-label { font-size: 11px; color: #999; font-weight: 600; margin-bottom: 4px; }
        .hours-bar-bg { background: var(--border); border-radius: 20px; height: 6px; overflow: hidden; }
        .hours-bar-fill { height: 100%; border-radius: 20px; transition: width .6s ease; background: linear-gradient(90deg, #10b981, #00d4aa); }
        .hours-bar-fill.warn { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .hours-bar-fill.danger { background: linear-gradient(90deg, #ef4444, #f87171); }

        /* ── PAGE HEADER ── */
        .page-head { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .page-head .ai-badge { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #1d3557, #457b9d); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; box-shadow: 0 4px 14px rgba(29,53,87,0.25); flex-shrink: 0; }
        .page-head h1 { margin: 0; font-size: 1.3rem; font-weight: 700; color: var(--accent); }
        .page-head p { margin: 2px 0 0; font-size: 12px; color: #aaa; }
        .beta-chip { display: inline-flex; align-items: center; gap: 5px; background: #fff3cd; color: #856404; font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 20px; border: 1px solid #ffc107; margin-left: 10px; vertical-align: middle; }

        /* ── CHAT ── */
        .chat-wrap { flex: 1; background: var(--bg); border-radius: 18px; box-shadow: 0 4px 24px rgba(0,0,0,0.07); display: flex; flex-direction: column; overflow: hidden; max-height: calc(100vh - 240px); min-height: 440px; }
        .chat-head { background: linear-gradient(135deg, #1d3557 0%, #457b9d 100%); padding: 16px 20px; display: flex; align-items: center; gap: 12px; }
        .chat-head .bot-avatar { width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; border: 1px solid rgba(255,255,255,0.2); }
        .chat-head .bot-info { flex: 1; }
        .chat-head .bot-name { font-weight: 700; color: white; font-size: 15px; }
        .chat-head .bot-sub { font-size: 11px; color: rgba(255,255,255,0.7); margin-top: 1px; }
        .online-dot { width: 8px; height: 8px; border-radius: 50%; background: #00e676; display: inline-block; margin-right: 5px; animation: pulse-dot 1.5s ease-in-out infinite; }
        @keyframes pulse-dot { 0%,100%{opacity:1;} 50%{opacity:.4;} }
        .clear-btn { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); color: white; padding: 6px 12px; border-radius: 8px; cursor: pointer; font-size: 11px; font-weight: 600; transition: background .2s; }
        .clear-btn:hover { background: rgba(255,255,255,0.25); }
        .chat-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 14px; background: var(--bg); }
        .chat-messages::-webkit-scrollbar { width: 5px; }
        .chat-messages::-webkit-scrollbar-thumb { background: #ddd; border-radius: 5px; }
        .msg-row { display: flex; gap: 10px; align-items: flex-end; }
        .msg-row.user { flex-direction: row-reverse; }
        .msg-avatar { width: 32px; height: 32px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 1rem; font-weight: 700; color: white; }
        .msg-avatar.bot { background: linear-gradient(135deg,#1d3557,#457b9d); }
        .msg-avatar.user { background: linear-gradient(135deg,#27ae60,#00d4aa); font-size: .85rem; }
        .bubble { max-width: 72%; padding: 11px 15px; border-radius: 16px; font-size: 13.5px; line-height: 1.6; position: relative; white-space: pre-wrap; }
        .bubble.bot { background: var(--ai-bubble); color: var(--text); border: 1px solid var(--border); border-bottom-left-radius: 4px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
        .bubble.user { background: var(--user-bubble); color: var(--text); border: 1px solid var(--border); border-bottom-right-radius: 4px; }
        .bubble .ts { font-size: 10px; color: #bbb; margin-top: 5px; display: block; }

        /* Report & Plan action buttons inside bubble */
        .bubble-action-btn {
            display: inline-flex; align-items: center; gap: 7px;
            background: linear-gradient(135deg, #1d3557, #457b9d);
            color: white; border: none; border-radius: 10px;
            padding: 8px 16px; font-size: 12px; font-weight: 700;
            cursor: pointer; margin-top: 10px; transition: opacity .2s, transform .15s;
            font-family: inherit;
        }
        .bubble-action-btn:hover { opacity: .88; transform: translateY(-1px); }
        .bubble-action-btn.success-btn { background: linear-gradient(135deg, #10b981, #059669); }
        .bubble-action-btn.danger-btn { background: linear-gradient(135deg, #ef4444, #dc2626); cursor: not-allowed; opacity: .6; }

        /* Typing indicator */
        .typing-row { display: flex; gap: 10px; align-items: flex-end; }
        .typing-bubble { background: var(--sidebar-bg); border: 1px solid var(--border); border-radius: 16px; border-bottom-left-radius: 4px; padding: 13px 18px; display: flex; gap: 5px; align-items: center; }
        .dot-anim { width: 7px; height: 7px; border-radius: 50%; background: #aaa; animation: bounce 1.2s infinite; }
        .dot-anim:nth-child(2) { animation-delay: .2s; }
        .dot-anim:nth-child(3) { animation-delay: .4s; }
        @keyframes bounce { 0%,60%,100%{transform:translateY(0);} 30%{transform:translateY(-6px);} }

        /* Quick chips */
        .chips-wrap { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 10px; }
        .chip { background: var(--bg); border: 1px solid var(--border); color: var(--accent); padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all .17s; font-family: inherit; }
        .chip:hover { background: var(--accent); color: white; border-color: var(--accent); }
        body.dark-mode .chip { background: #1e293b; border-color: #334155; color: #a5b4fc; }
        body.dark-mode .chip:hover { background: #457b9d; color: white; border-color: #457b9d; }

        /* Input */
        .chat-input-area { padding: 14px 16px; background: var(--sidebar-bg); border-top: 1px solid var(--border); display: flex; gap: 10px; align-items: center; }
        .chat-input-area input { flex: 1; padding: 11px 16px; border-radius: 12px; font-size: 13.5px; font-family: inherit; outline: none; background: var(--input-bg); color: var(--text); border: 1px solid var(--border); transition: border .2s; }
        .chat-input-area input:focus { border-color: var(--accent2); }
        .send-btn { width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0; background: linear-gradient(135deg,#1d3557,#457b9d); border: none; color: white; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1rem; transition: opacity .2s, transform .15s; box-shadow: 0 2px 8px rgba(29,53,87,0.25); }
        .send-btn:hover { opacity: .88; transform: scale(1.05); }
        .send-btn:disabled { opacity: .4; cursor: not-allowed; transform: none; }

        /* ══════════════════════════════════════════════
           PLAN POPUP — Smart morning task planner
        ══════════════════════════════════════════════ */
        .plan-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(6px);
            z-index: 9999;
            display: flex; align-items: center; justify-content: center;
            animation: fadeIn .4s ease;
        }
        @keyframes fadeIn { from{opacity:0;} to{opacity:1;} }
        .plan-popup {
            background: linear-gradient(145deg, #0f172a, #1e293b);
            border: 1px solid rgba(69,123,157,0.3);
            border-radius: 24px;
            padding: 40px 36px;
            max-width: 480px;
            width: 94%;
            text-align: center;
            box-shadow: 0 30px 80px rgba(0,0,0,0.5);
            animation: popIn .45s cubic-bezier(.34,1.56,.64,1);
            position: relative;
            overflow: hidden;
        }
        .plan-popup::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, #457b9d, #1d3557, #00d4aa);
            border-radius: 24px 24px 0 0;
        }
        @keyframes popIn { from{opacity:0;transform:scale(.8) translateY(30px);} to{opacity:1;transform:scale(1) translateY(0);} }
        .plan-popup-icon { font-size: 3.5rem; margin-bottom: 12px; animation: float 3s ease-in-out infinite; }
        @keyframes float { 0%,100%{transform:translateY(0);} 50%{transform:translateY(-8px);} }
        .plan-popup h2 { color: #e2e8f0; font-size: 1.5rem; font-weight: 700; margin-bottom: 8px; }
        .plan-popup .plan-sub { color: #94a3b8; font-size: 13.5px; margin-bottom: 24px; line-height: 1.6; }
        .plan-popup .time-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(69,123,157,0.2); color: #93c5fd;
            border: 1px solid rgba(69,123,157,0.4);
            border-radius: 20px; padding: 4px 14px; font-size: 11px; font-weight: 700;
            margin-bottom: 20px; letter-spacing: .5px;
        }
        .plan-textarea {
            width: 100%;
            background: rgba(15,23,42,0.8);
            border: 1.5px solid rgba(69,123,157,0.35);
            border-radius: 14px;
            padding: 14px 16px;
            color: #e2e8f0;
            font-size: 13.5px;
            font-family: 'Poppins', sans-serif;
            resize: none;
            height: 110px;
            margin-bottom: 16px;
            transition: border .2s, box-shadow .2s;
            outline: none;
        }
        .plan-textarea:focus { border-color: #457b9d; box-shadow: 0 0 0 3px rgba(69,123,157,0.15); }
        .plan-textarea::placeholder { color: #475569; }
        .plan-save-btn {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #1d3557, #457b9d);
            color: white; border: none; border-radius: 14px;
            font-size: 14px; font-weight: 700; cursor: pointer;
            font-family: inherit;
            transition: opacity .2s, transform .15s;
            box-shadow: 0 6px 20px rgba(29,53,87,0.35);
            margin-bottom: 10px;
        }
        .plan-save-btn:hover { opacity: .9; transform: translateY(-2px); }
        .plan-skip-btn {
            background: none; border: none; color: #64748b;
            font-size: 13px; cursor: pointer; padding: 8px;
            font-family: inherit; transition: color .2s;
        }
        .plan-skip-btn:hover { color: #94a3b8; }

        /* ── HOURS PULSE (if close to window) ── */
        .report-nudge {
            background: linear-gradient(135deg, rgba(16,185,129,0.1), rgba(0,212,170,0.05));
            border: 1px solid rgba(16,185,129,0.25);
            border-radius: 12px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            font-size: 12.5px;
            color: #10b981;
            font-weight: 600;
        }
        .report-nudge.warn { background: rgba(245,158,11,0.08); border-color: rgba(245,158,11,0.25); color: #f59e0b; }
        .report-nudge.active { background: rgba(16,185,129,0.12); border-color: rgba(16,185,129,0.35); color: #10b981; animation: nudgePulse 2s ease-in-out infinite; }
        @keyframes nudgePulse { 0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,0);} 50%{box-shadow:0 0 0 6px rgba(16,185,129,0.1);} }

       .menu-toggle {
    display: none;
    position: fixed;
    top: 12px;
    left: 12px;
    z-index: 2000;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 18px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

/* ===================== MOBILE ===================== */
@media (max-width: 900px) {
    .menu-toggle { display: block; }

    .sidebar {
        width: 260px !important;
        left: -100%;
        transition: left 0.25s ease;
        z-index: 1500;
        box-shadow: 0 0 24px rgba(0,0,0,0.2);
    }
    .sidebar.open { left: 0; }

    /* show labels again on slide-in menu */
    .sidebar .top > div:not(.me),
    .sidebar ul li a span {
        display: block !important;
    }

    .dashboard-container {
        margin-left: 0 !important;
        padding: 70px 12px 16px !important;
        width: 100%;
        min-width: 0;
    }

    .time-bar {
        font-size: 12px;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }

    .live-status-bar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
        padding: 12px !important;
    }

    .status-chip {
        width: 100%;
        justify-content: flex-start;
        font-size: 12px;
    }

    .hours-bar-wrap {
        width: 100%;
        min-width: 0;
    }

    .page-head {
        gap: 10px;
        margin-bottom: 14px;
    }
    .page-head .ai-badge {
        width: 40px;
        height: 40px;
        font-size: 1.2rem;
    }
    .page-head h1 {
        font-size: 1.1rem !important;
    }
    .page-head p {
        font-size: 11px !important;
    }
    .beta-chip {
        margin-left: 6px;
        font-size: 9px;
    }

    .chat-wrap {
        max-height: none !important;
        min-height: calc(100vh - 320px) !important;
        border-radius: 14px !important;
        flex: 1;
    }

    .chat-head {
        padding: 12px 14px !important;
    }
    .chat-head .bot-name {
        font-size: 14px;
    }
    .clear-btn {
        padding: 5px 10px;
        font-size: 10px;
    }

    .chat-messages {
        padding: 12px !important;
        gap: 10px !important;
    }

    .bubble {
        max-width: 88% !important;
        font-size: 13px !important;
        padding: 10px 12px !important;
    }

    .msg-avatar {
        width: 28px;
        height: 28px;
        font-size: 0.85rem;
    }

    .chips-wrap {
        gap: 6px;
    }
    .chip {
        font-size: 11px !important;
        padding: 6px 10px !important;
    }

    .chat-input-area {
        padding: 10px 12px !important;
        gap: 8px;
    }
    .chat-input-area input {
        font-size: 14px !important;
        padding: 10px 12px !important;
    }
    .send-btn {
        width: 44px;
        height: 44px;
    }

    .report-nudge {
        flex-direction: column;
        align-items: flex-start !important;
        font-size: 12px !important;
        padding: 12px !important;
    }

    /* Plan popup */
    .plan-popup {
        padding: 24px 18px !important;
        width: 96% !important;
        max-width: none;
        border-radius: 18px !important;
    }
    .plan-popup h2 {
        font-size: 1.2rem !important;
    }
    .plan-popup .plan-sub {
        font-size: 12.5px !important;
    }
    .plan-popup-icon {
        font-size: 2.5rem !important;
    }
    .plan-textarea {
        height: 100px !important;
        font-size: 14px !important;
    }
}

@media (max-width: 480px) {
    .dashboard-container {
        padding: 65px 8px 12px !important;
    }
    .page-head h1 {
        font-size: 1rem !important;
    }
    .bubble {
        max-width: 92% !important;
    }
    .live-status-bar {
        padding: 10px !important;
    }
}
    </style>
</head>
<body>
<button class="menu-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')">
    <i class="fas fa-bars"></i>
</button>
<!-- ══ SMART MORNING PLAN POPUP ══ -->
<?php if ($showPlanPopup): ?>
<div class="plan-overlay" id="planOverlay">
    <div class="plan-popup">
        <div class="plan-popup-icon">🌅</div>
        <div class="time-badge">⏰ MORNING PLANNER · 1AM–12PM ONLY</div>
        <h2>Good morning, <?php echo htmlspecialchars($userName); ?>! 👋</h2>
        <p class="plan-sub">
            Before you dive in — what's your plan for today?<br>
            Set your intention and I'll remind you at report time. 📌
        </p>
        <textarea class="plan-textarea" id="planInput" placeholder="e.g. Today I'll finish the login module, fix 2 bugs, attend the 10am sync, and update the docs..."></textarea>
        
        <input type="file" id="planFile" accept=".pdf,.doc,.docx" style="margin-bottom:16px;color:#e2e8f0;">
<div id="planFilePreview" style="margin-bottom:12px;"></div>

        <button class="plan-save-btn" onclick="savePlan()">
            <i class="fas fa-rocket"></i> &nbsp;Lock In My Plan!
        </button>
        <button class="plan-skip-btn" onclick="skipPlan()">Skip for now</button>
    </div>
</div>
<?php elseif ($planWindowActive && $todayPlan): ?>
<!-- Plan already set — show quietly in chat on load -->
<?php endif; ?>

<aside class="sidebar">
    <div class="top">
        <div class="me">
            <?php if ($currentProfilePicture && file_exists($currentProfilePicture)): ?>
                <img src="<?php echo htmlspecialchars($currentProfilePicture); ?>" alt="">
            <?php else: echo htmlspecialchars($userInitial); endif; ?>
            <div class="status-indicator status-<?php echo htmlspecialchars($currentStatus); ?>"></div>
            <div class="profile-popup">
                <div class="popup-avatar">
                    <?php if ($currentProfilePicture && file_exists($currentProfilePicture)): ?>
                        <img src="<?php echo htmlspecialchars($currentProfilePicture); ?>" alt="">
                    <?php else: echo htmlspecialchars($userInitial); endif; ?>
                </div>
                <div class="popup-status">
                    <span class="popup-status-indicator status-<?php echo htmlspecialchars($currentStatus); ?>"></span>
                    <?php echo htmlspecialchars($status_info[$currentStatus]['name'] ?? 'Unknown'); ?>
                </div>
                <div class="popup-role"><?php echo htmlspecialchars($currentRole); ?></div>
            </div>
        </div>
        <div>
            <div style="font-weight:700;font-size:15px;"><?php echo htmlspecialchars($userName); ?></div>
            <div style="font-size:11px;color:#777;"><?php echo htmlspecialchars($status_info[$currentStatus]['name'] ?? 'Unknown'); ?> | <?php echo htmlspecialchars($currentRole); ?></div>
        </div>
    </div>
    <ul>
        <li><a href="member_dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
        <li><a href="member_tasks.php"><i class="fas fa-tasks"></i> <span>My Tasks</span></a></li>
        <li><a href="member_projects.php"><i class="fas fa-briefcase"></i> <span>Projects</span></a></li>
        <li><a href="member_messages.php"><i class="fas fa-comments"></i> <span>Messages</span></a></li>
        <li><a href="upload_timesheet_progress.php"><i class="fas fa-file-signature"></i> <span>Timesheet Submissions</span></a></li>
        <li><a href="member_Group.php"><i class="fas fa-users"></i> <span>Groups</span></a></li>
        <li><a href="member_Group_chat.php"><i class="fas fa-envelope"></i> <span>Group Messages</span></a></li>
        <li><a href="member_attendance.php"><i class="fas fa-check-circle"></i> <span>Attendance</span></a></li>
        <li><a href="leave_application.php"><i class="fa-solid fa-calendar-check"></i> <span>Leave Application</span></a></li>
        <li><a href="https://investhoodprojects.com/tidytracker/tidytrack/meeting/member_meetings.php"><i class="fas fa-video"></i> <span>Meetings</span></a></li>
        <li><a href="member_help.php" class="active"><i class="fas fa-life-ring"></i> <span>TSM ASSISTANCE</span></a></li>
        <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
        <li style="margin-top:auto;"><a href="profile.php"><i class="fas fa-user-circle"></i> <span>Profile Settings</span></a></li>
    </ul>
</aside>

<main class="dashboard-container">
    <div class="time-bar">
        <i class="fas fa-clock"></i>
        <span id="localTime">--:--:--</span>
        <i class="fas fa-calendar-alt"></i>
        <span>Welcome, <?php echo htmlspecialchars($userName); ?></span>
    </div>

    <!-- ── LIVE STATUS BAR ── -->
    <div class="live-status-bar">
        <div class="status-chip">
            <?php
            $pct = $totalMinutesToday > 0 ? min(100, round(($totalMinutesToday / 240) * 100)) : 0;
            $dotClass = $has4Hours ? 'dot-green' : ($pct > 50 ? 'dot-yellow' : 'dot-red');
            ?>
            <div class="dot <?php echo $dotClass; ?>"></div>
            ⏱️ <?php echo $hoursToday; ?>h <?php echo $minutesToday; ?>m logged
        </div>
        <div class="hours-bar-wrap">
            <div class="hours-label">4hr attendance goal</div>
            <div class="hours-bar-bg">
                <div class="hours-bar-fill <?php echo $pct < 50 ? 'danger' : ($pct < 100 ? 'warn' : ''); ?>"
                     style="width:<?php echo $pct; ?>%"></div>
            </div>
        </div>
        <div class="status-chip">
            <?php if ($reportSubmitted): ?>
                <div class="dot dot-green"></div> 📝 Report Sent ✅
            <?php elseif ($inReportWindow && $has4Hours): ?>
                <div class="dot dot-green" style="animation:pulse-dot 1s infinite;"></div> 🟢 Report Window OPEN
            <?php elseif ($inReportWindow): ?>
                <div class="dot dot-yellow"></div> 🟡 Window open · need 4hrs
            <?php else: ?>
                <div class="dot dot-blue"></div> 📋 Report opens 16:00
            <?php endif; ?>
        </div>
        <?php if ($todayPlan): ?>
        <div class="status-chip" title="<?php echo htmlspecialchars($todayPlan); ?>">
            <div class="dot dot-green"></div> 📌 Plan set
        </div>
        <?php endif; ?>
    </div>
        <!-- Voice Greeting Toggle -->
    <div style="text-align:right; margin:8px 0 16px;">
        <button id="voiceToggle" onclick="toggleVoice()" 
                style="background:none;border:none;color:#4f7ef5;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:6px;">
            🔊 Voice Greeting <strong>ON</strong>
        </button>
    </div>

    <!-- ── REPORT WINDOW NUDGE ── -->
    <?php if ($inReportWindow && $has4Hours && !$reportSubmitted): ?>
    <div class="report-nudge active">
        <i class="fas fa-paper-plane"></i>
        <div>
            🎯 <strong>Report window is OPEN right now!</strong> You've hit 4hrs — go submit your daily report in the chat below or
            <a href="send_daily_report.php" style="color:#10b981;font-weight:700;">click here to submit now →</a>
        </div>
    </div>
    <?php elseif ($has4Hours && !$reportSubmitted && !$inReportWindow): ?>
    <div class="report-nudge warn">
        <i class="fas fa-clock"></i>
        <strong>4hr goal met!</strong> &nbsp;Daily report window opens at <strong>16:00</strong> — almost there!
    </div>
    <?php endif; ?>
<?php if ($showPlanNudge): ?>
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 18px;margin-bottom:14px;display:flex;align-items:flex-start;gap:12px;">
    <span style="font-size:1.4rem;flex-shrink:0;">📌</span>
    <div>
        <div style="font-weight:700;color:#1d4ed8;font-size:14px;margin-bottom:4px;">
            Planning window closes at 16:00 — set your plan now!
        </div>
        <div style="font-size:13px;color:#1e40af;line-height:1.6;">
            You haven't set a daily plan yet. The window closes in 
            <strong><?php echo 16 - $nowHour; ?> hour<?php echo (16 - $nowHour) > 1 ? 's' : ''; ?></strong> — 
            head to the assistant below and type what you're working on today.
        </div>
    </div>
</div>
<?php endif; ?>

    <div class="page-head">
        <div class="ai-badge">🤖</div>
        <div>
            <h1>TidyTrack Assistance <span class="beta-chip">⚡ BETA</span></h1>
            <p>Your personal AI task assistant — powered by your data</p>
        </div>
    </div>

    <div class="chat-wrap">
        <div class="chat-head">
            <div class="bot-avatar">🤖</div>
            <div class="bot-info">
                <div class="bot-name">TidyTrack Assistant</div>
                <div class="bot-sub"><span class="online-dot"></span>Online · Members only</div>
            </div>
            <button class="clear-btn" onclick="clearChat()"><i class="fas fa-broom"></i> Clear</button>
        </div>

        <div class="chat-messages" id="chatMessages"></div>

        <div class="chat-input-area">
            <input type="text" id="chatInput" placeholder="Ask me about your tasks, hours, or daily report..." autocomplete="off" />
            <button class="send-btn" id="sendBtn" onclick="handleSend()">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>
</main>

<script src="theme_switcher.js"></script>
<script>
// ── PHP context passed to JS ───────────────────────────────────
const userName       = <?php echo json_encode($userName); ?>;
const userInitial    = <?php echo json_encode($userInitial); ?>;
const hoursToday     = <?php echo $hoursToday; ?>;
const minutesToday   = <?php echo $minutesToday; ?>;
const totalMinutes   = <?php echo $totalMinutesToday; ?>;
const has4Hours      = <?php echo $has4Hours ? 'true' : 'false'; ?>;
const reportSubmitted= <?php echo $reportSubmitted ? 'true' : 'false'; ?>;
const todayPlan      = <?php echo json_encode($todayPlan); ?>;
const isPlanWindow   = <?php echo $planWindowActive ? 'true' : 'false'; ?>;
const showPlanPopup  = <?php echo $showPlanPopup ? 'true' : 'false'; ?>;
const existingReport = <?php echo json_encode($existingReport); ?>;

// Computed at JS time (more accurate than PHP server time for window checks)
function getInReportWindow() {
    const h = new Date().getHours();
    return h >= 16 && h < 17;
}
function getInPlanWindow() {
    return new Date().getHours() < 16; // midnight to 15:59
}

let chatHistory = [];
let pendingReportText = null; // stores plan text while user confirms report

// ── Time ─────────────────────────────────────────────────────
function updateTime() {
    const el = document.getElementById('localTime');
    if (el) el.textContent = new Date().toLocaleTimeString('en-ZA', {
        hour:'2-digit', minute:'2-digit', second:'2-digit', timeZone:'Africa/Johannesburg'
    });
}

// Auto-dismiss plan popup at 16:00 and open report nudge instead
function checkPlanPopupExpiry() {
    const h = new Date().getHours();
    const overlay = document.getElementById('planOverlay');
    if (overlay && h >= 16) {
        overlay.style.transition = 'opacity .5s';
        overlay.style.opacity = '0';
        setTimeout(() => overlay.remove(), 500);
        // If they had a plan saved, trigger auto-submit greeting
        if (todayPlan && has4Hours && !reportSubmitted) {
            greetUser(todayPlan);
        }
    }
}
setInterval(checkPlanPopupExpiry, 30000); // check every 30s
checkPlanPopupExpiry(); // check immediately on load too

setInterval(updateTime, 1000);
updateTime();

// ── Plan popup handlers ───────────────────────────────────────
async function savePlan() {
    const text = document.getElementById('planInput').value.trim();
    const fileInput = document.getElementById('planFile');
    if (text.length < 5) {
        document.getElementById('planInput').style.borderColor = '#ef4444';
        return;
    }

    const fd = new FormData();
    fd.append('action', 'save_plan_with_file');
    fd.append('plan', text);
    if (fileInput.files[0]) fd.append('planFile', fileInput.files[0]);

    try {
        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            window._planText = text;
            closePlanPopup();
            setTimeout(() => greetUser(window._planText), 400);
        } else {
            alert(data.error || 'Could not save plan.');
        }
    } catch(e) { console.error(e); }
}

// Simple preview on file select
document.getElementById('planFile')?.addEventListener('change', function() {
    const preview = document.getElementById('planFilePreview');
    if (!this.files[0]) { preview.innerHTML = ''; return; }
    const file = this.files[0];
    const ext = file.name.split('.').pop().toLowerCase();
    const icon = ext === 'pdf' ? '📄' : '📝';
    preview.innerHTML = `<div style="color:#93c5fd;font-size:12.5px;">${icon} ${file.name}</div>`;
});

function skipPlan() {
    closePlanPopup();
    setTimeout(() => greetUser(), 400); // greet normally, no plan set
}

function closePlanPopup() {
    const overlay = document.getElementById('planOverlay');
    if (!overlay) return;
    overlay.style.transition = 'opacity .3s';
    overlay.style.opacity = '0';
    setTimeout(() => overlay.remove(), 300);
}

// ── Chat utilities ────────────────────────────────────────────
function now() {
    return new Date().toLocaleTimeString('en-ZA', {hour:'2-digit', minute:'2-digit', timeZone:'Africa/Johannesburg'});
}
function scrollBottom() {
    const box = document.getElementById('chatMessages');
    box.scrollTop = box.scrollHeight;
}
function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escAttr(str) { return str.replace(/"/g,'&quot;'); }

function addUserMessage(text) {
    const box = document.getElementById('chatMessages');
    const row = document.createElement('div');
    row.className = 'msg-row user';
    row.innerHTML = `
        <div class="bubble user">${escHtml(text)}<span class="ts">${now()}</span></div>
        <div class="msg-avatar user">${userInitial}</div>`;
    box.appendChild(row);
    scrollBottom();
}

function addBotMessage(text, showChips = false, extraHtml = '') {
    const box = document.getElementById('chatMessages');
    const row = document.createElement('div');
    row.className = 'msg-row';

    // Bold **text** rendering
    const formatted = escHtml(text)
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br>');

    row.innerHTML = `
        <div class="msg-avatar bot">🤖</div>
        <div class="bubble bot">
            ${formatted}
            ${extraHtml}
            <span class="ts">${now()}</span>
            ${showChips ? buildChips() : ''}
        </div>`;
    box.appendChild(row);

    row.querySelectorAll('.chip').forEach(chip => {
        chip.addEventListener('click', () => {
            chip.closest('.chips-wrap')?.remove();
            handleSend(chip.dataset.msg);
        });
    });
    row.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', () => handleAction(btn.dataset.action, btn));
    });

    box.scrollTop = box.scrollHeight;
}

function buildChips() {
    const inWindow = getInReportWindow();
    const base = [
        { label: '⏱️ My hours today', msg: 'How many hours have I logged today?' },
        { label: '📋 My tasks', msg: 'What tasks do I have?' },
        { label: '⚠️ Overdue', msg: 'Do I have any overdue tasks?' },
        { label: '📊 My status', msg: 'Give me my full status' },
        { label: '👤 My leader', msg: 'Who is my leader?' },
    ];
    if (isPlanWindow && !todayPlan) base.push({ label: '📌 Set today\'s plan', msg: 'I want to set my plan for today' });
    if (inWindow && has4Hours && !reportSubmitted) base.push({ label: '📝 Submit report', msg: 'I want to submit my daily report' });
    return `<div class="chips-wrap">${base.map(c =>
        `<button class="chip" data-msg="${escAttr(c.msg)}">${c.label}</button>`
    ).join('')}</div>`;
}

function showTyping() {
    const box = document.getElementById('chatMessages');
    const row = document.createElement('div');
    row.className = 'typing-row';
    row.id = 'typingIndicator';
    row.innerHTML = `
        <div class="msg-avatar bot">🤖</div>
        <div class="typing-bubble">
            <div class="dot-anim"></div><div class="dot-anim"></div><div class="dot-anim"></div>
        </div>`;
    box.appendChild(row);
    scrollBottom();
}
function hideTyping() { document.getElementById('typingIndicator')?.remove(); }

// ── Handle in-bubble action buttons ──────────────────────────
async function handleAction(action, btn) {
    if (action === 'open_report_page') {
        window.location.href = 'send_daily_report.php';
    } else if (action === 'submit_plan_as_report') {
        if (!pendingReportText) return;
        btn.disabled = true;
        btn.textContent = 'Sending...';
        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify({ action: 'submit_report', report: pendingReportText })
            });
            const data = await res.json();
            if (data.success) {
                addBotMessage(`🎉 Daily report submitted!\n\n"${pendingReportText}"\n\nGreat work today, ${userName}! See you tomorrow! ✅`, false);
                pendingReportText = null;
            } else {
                addBotMessage(`❌ ${data.error || 'Could not submit. Try the full report page.'}`, false,
                    `<br><button class="bubble-action-btn" data-action="open_report_page">📝 Go to Report Page</button>`);
            }
        } catch(e) {
            addBotMessage('Oops, connection error. Try the report page directly.', false);
        }
    } else if (action === 'set_plan_from_chat') {
        const input = document.getElementById('chatInput');
        input.placeholder = 'Type your plan for today...';
        input.focus();
        // Flag that next message is a plan
        window._awaitingPlan = true;
    }
}

// ── Main send handler ─────────────────────────────────────────
async function handleSend(overrideMsg = null) {
    const input = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');
    const msg = overrideMsg ?? input.value.trim();
    if (!msg) return;
    if (!overrideMsg) input.value = '';
    sendBtn.disabled = true;
    addUserMessage(msg);
    chatHistory.push({ role: 'user', content: msg });

    // Special: if awaiting plan input
    if (window._awaitingPlan && !overrideMsg) {
        window._awaitingPlan = false;
        input.placeholder = 'Ask me about your tasks, hours, or daily report...';
        showTyping();
        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify({ action: 'save_plan', plan: msg })
            });
            const data = await res.json();
            hideTyping();
            if (data.success) {
                addBotMessage(`✅ Plan locked in!\n\n📌 "${msg}"\n\nAwesome commitment, ${userName}! I'll remind you at 16:00 when the report window opens. 💪`, true);
            } else {
                addBotMessage(data.error || 'Could not save plan. Try again!', true);
            }
        } catch(e) { hideTyping(); addBotMessage('Error saving plan. Try again.', false); }
        sendBtn.disabled = false;
        return;
    }

    showTyping();

    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ message: msg, history: chatHistory.slice(-8) })
        });
        const data = await res.json();
        hideTyping();

        const reply  = data.reply ?? "Hmm, no response. Try again?";
        const rtype  = data.type ?? 'text';

        chatHistory.push({ role: 'assistant', content: reply });

        let extraHtml = '';

        // Smart action buttons based on reply type
        if (rtype === 'prompt_report') {
            const inWindow = getInReportWindow();
            if (inWindow && has4Hours && !reportSubmitted) {
                // Use today's plan as prefill
                const prefill = todayPlan || '';
                pendingReportText = prefill;
                extraHtml = `
                    <br>
                    <button class="bubble-action-btn success-btn" data-action="open_report_page">
                        📝 Go to Report Page →
                    </button>`;
                if (prefill) {
                    extraHtml += `<br><small style="opacity:.7;font-size:11px;">Or use your plan as report below:</small>
                        <br><button class="bubble-action-btn" data-action="submit_plan_as_report" style="margin-top:5px;">
                        🚀 Submit "${prefill.substring(0,40)}${prefill.length>40?'...':''}" as report
                        </button>`;
                }
            }
        } else if (rtype === 'prompt_plan') {
            if (getInPlanWindow()) {
                extraHtml = `<br><button class="bubble-action-btn" data-action="set_plan_from_chat">📌 Type my plan now</button>`;
            }
        } else if (rtype === 'show_plan' && todayPlan) {
            pendingReportText = todayPlan;
            if (getInReportWindow() && has4Hours && !reportSubmitted) {
                extraHtml = `<br><button class="bubble-action-btn success-btn" data-action="submit_plan_as_report">
                    🚀 Submit this as today's report</button>`;
            }
        }

        addBotMessage(reply, true, extraHtml);

    } catch(err) {
        hideTyping();
        addBotMessage("Connection hiccup! 😅 Try again in a moment.", false);
    }

    sendBtn.disabled = false;
    input.focus();
}

// ── Enter key ─────────────────────────────────────────────────
document.getElementById('chatInput').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSend(); }
});

// ── Clear ─────────────────────────────────────────────────────
function clearChat() {
    chatHistory = [];
    document.getElementById('chatMessages').innerHTML = '';
    greetUser();
}

// ── Auto-submit daily report using today's plan ───────────────
// Fires on load if: window open + 4hrs met + plan exists + not yet submitted
async function tryAutoSubmitReport(activePlan = null) {
    activePlan = activePlan ?? todayPlan;
    if (!getInReportWindow())  return; // outside 16:00–17:00
    if (!has4Hours)            return; // attendance not met
    if (reportSubmitted)       return; // already done
    if (!activePlan)            return; // no plan set this morning

    // Show the AI "thinking" about it
    showTyping();
    await new Promise(r => setTimeout(r, 1800)); // natural pause
    hideTyping();

    addBotMessage(
        `🕓 It's report time, ${userName}!\n\n` +
        `You logged **${hoursToday}h ${minutesToday}m** today ✅ and your morning plan is ready.\n\n` +
        `📌 Plan: "${activePlan}"\n\n` +
        `Submitting your daily report automatically...`, false
    );

    // Short pause then submit
    await new Promise(r => setTimeout(r, 1200));
    showTyping();

    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'submit_report', report: activePlan })
        });
        const data = await res.json();
        hideTyping();

        if (data.success) {
            addBotMessage(
                `🎉 **Daily report submitted!**\n\n` +
                `"${activePlan}"\n\n` +
                `Your leader has been notified. Great work today, ${userName}! ` +
                `You crushed it — see you tomorrow! 💪✅`,
                false
            );
        } else {
            // Auto-submit failed (edge case) — tell the user clearly
            addBotMessage(
                `⚠️ Couldn't auto-submit your report.\n\n` +
                `Reason: ${data.error || 'Unknown error'}\n\n` +
                `Your plan is ready — please submit manually:`,
                false,
                `<br><button class="bubble-action-btn success-btn" data-action="open_report_page">
                    📝 Submit Report Now →
                 </button>`
            );
        }
    } catch(e) {
        hideTyping();
        addBotMessage(
            `⚠️ Connection issue — couldn't auto-submit.\n\nYour plan is saved, just submit manually:`,
            false,
            `<br><button class="bubble-action-btn success-btn" data-action="open_report_page">
                📝 Submit Report Now →
             </button>`
        );
    }
}

// ── Initial greeting ──────────────────────────────────────────
async function greetUser(overridePlan = null) {
    const activePlan = overridePlan ?? todayPlan; // use freshly saved plan if provided
    const h = new Date().getHours();
    const greet = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
    const pct = Math.min(100, Math.round((totalMinutes / 240) * 100));
    const inWindow = getInReportWindow();

    let msg = `${greet}, ${userName}! 👋 I'm your TidyTrack Assistant.\n\n`;
    msg += `⏱️ Today you've logged **${hoursToday}h ${minutesToday}m** (${pct}% of your 4hr goal)\n`;

    if (reportSubmitted) {
        msg += `\n✅ Daily report already submitted — you're all done for today! 🎉`;
    } else if (inWindow && has4Hours && activePlan) {
        msg += `\n🤖 **Auto-submitting your daily report now** — sit tight!`;
    } else if (inWindow && has4Hours && !activePlan) {
        msg += `\n🟡 Report window is open but **no morning plan was set**.\n\nType what you accomplished today and I'll submit it for you!`;
    } else if (has4Hours) {
        msg += `\n✅ 4hr goal met! Report window opens at **16:00** today.`;
    } else {
        const rem = 240 - totalMinutes;
        const rh = Math.floor(rem/60), rm = rem%60;
        msg += `\n⏳ Still need **${rh}h ${rm}m** more to hit the 4hr goal.`;
    }

    if (activePlan && !inWindow) {
        msg += `\n\n📌 Today's plan: "${activePlan}"`;
    } else if (!activePlan && getInPlanWindow()) {
        msg += `\n\n📌 No plan set yet — tell me what you're working on today and I'll save it!`;
    }

    if (!(inWindow && has4Hours && activePlan && !reportSubmitted)) {
        msg += `\n\nWhat can I help you with?`;
    }

    addBotMessage(msg, !(inWindow && has4Hours && activePlan && !reportSubmitted));

    // Trigger auto-submit after greeting is shown
    if (inWindow && has4Hours && activePlan && !reportSubmitted) {
        await new Promise(r => setTimeout(r, 600));
        await tryAutoSubmitReport(activePlan);
        // Show chips after auto-submit flow
        addBotMessage(`Anything else I can help you with?`, true);
    }
}

// ── VOICE GREETING ─────────────────────────────────────────────
let voiceEnabled = true;


function speakGreeting() {
    if (!voiceEnabled || !('speechSynthesis' in window)) return;

    // Wait for voices to load, then pick best available
    function speak() {
        const voices = speechSynthesis.getVoices();
        
        // Priority: Google UK English Female > Google US English > Microsoft Zira > any English female
        const preferred = [
            'Google UK English Female',
            'Google US English Female', 
            'Microsoft Zira Desktop',
            'Karen',
            'Samantha',
        ];
        let voice = null;
        for (const name of preferred) {
            voice = voices.find(v => v.name === name);
            if (voice) break;
        }
        // Fallback: any English female
        if (!voice) voice = voices.find(v => /en/i.test(v.lang) && /female/i.test(v.name));
        // Last resort: first English voice
        if (!voice) voice = voices.find(v => /en/i.test(v.lang));

        const hour = new Date().getHours();
        const pct  = Math.min(100, Math.round((totalMinutes / 240) * 100));

        // Build dynamic sentence fragments
        const timeGreet = hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening";

        const hoursLine = has4Hours
            ? `You've already hit your four-hour attendance goal today — well done.`
            : pct > 60
                ? `You're ${pct} percent of the way to your four-hour goal. Keep it up.`
                : `You've logged ${hoursToday} hours and ${minutesToday} minutes so far today.`;

        const reportLine = reportSubmitted
            ? `Your daily report has been submitted. You're all done for today.`
            : `Your daily report window opens at four PM.`;

        const planLine = todayPlan
            ? `Your plan for today is locked in. Let's make it happen.`
            : `You haven't set a plan yet — I'll remind you when the morning window is open.`;

        const closers = [
            `I'm here whenever you need me.`,
            `Ask me anything about your tasks or hours.`,
            `Let's have a productive day.`,
            `I've got your dashboard loaded and ready.`,
        ];
        const closer = closers[Math.floor(Math.random() * closers.length)];

        const script = `${timeGreet}, ${userName}. ${hoursLine} ${reportLine} ${planLine} ${closer}`;

        const u = new SpeechSynthesisUtterance(script);
        if (voice) u.voice = voice;
        u.rate   = 0.93;
        u.pitch  = 1.05;
        u.volume = 0.88;
        speechSynthesis.speak(u);
    }

    // Voices may not load instantly on first page load
    if (speechSynthesis.getVoices().length) {
        speak();
    } else {
        speechSynthesis.addEventListener('voiceschanged', speak, { once: true });
    }
}

function toggleVoice() {
    voiceEnabled = !voiceEnabled;
    const btn = document.getElementById('voiceToggle');
    btn.innerHTML = voiceEnabled 
        ? '🔊 Voice Greeting <strong>ON</strong>' 
        : '🔇 Voice Greeting <strong>OFF</strong>';

    if (!voiceEnabled && speechSynthesis.speaking) {
        speechSynthesis.cancel();
    }
}

// Auto-greet on page load
window.addEventListener('load', () => {
    setTimeout(() => {
        speakGreeting();
    }, 1400); // slight delay so page feels settled
});

// ── Boot ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (!showPlanPopup) {
        greetUser();
    } else {
        // Plan popup is showing — greetUser runs after user saves/skips
    }
});

document.querySelectorAll('.sidebar a').forEach(a => {
    a.addEventListener('click', () => {
        document.querySelector('.sidebar')?.classList.remove('open');
    });
});
</script>
</body>
</html>