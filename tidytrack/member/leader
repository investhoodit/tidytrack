<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Ensure dp.php is loaded first to set the timezone correctly
include '../dp.php'; 
include '../track2.php';

$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$userName = isset($_SESSION['name']) ? $_SESSION['name'] : 'Member';
$userInitial = strtoupper($userName[0]);

if (!$userId) {
    // Redirect to login or show error if not logged in
    header("Location: login.php");
    exit();
}

// --- CORE SESSION MANAGEMENT ---

$today = date('Y-m-d');
$isWeekend = date('N') >= 6; // Check if it's Saturday or Sunday

if (!$isWeekend) {
    // 1. Check for an active session (logout_time IS NULL) only if it's a weekday
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND session_date = ? AND logout_time IS NULL");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();

    // If no active session, log a new start time
    if ($count == 0) {
        log_session_start($userId);
    }

    // 2. Update the current session segment's minutes (logout_time is set to NOW())
    // This is called on every page load/refresh
    update_current_session($userId);

    // 3. Get the final calculated total minutes for display
    $totalMinutes = get_total_minutes_today($userId);
} else {
    $totalMinutes = 0; // No tracking on weekends
}

// Debug: Log user_sessions data (for server-side debugging)
$stmt = $conn->prepare("SELECT session_id, minutes, login_time, logout_time FROM user_sessions WHERE user_id = ? AND session_date = ?");
$stmt->bind_param("is", $userId, $today);
$stmt->execute();
$result = $stmt->get_result();
$sessionData = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
error_log("User_sessions for user_id=$userId, date=$today: " . json_encode($sessionData));

// Fetch user profile
$stmt = $conn->prepare("SELECT profile_picture, status, role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$currentUser = $result->fetch_assoc();
$currentProfilePicture = $currentUser['profile_picture'] ?? null;
$currentStatus = $currentUser['status'] ?? 'offline';
$currentRole = $currentUser['role'] ?? 'Member';
$stmt->close();

// Handle manual attendance marking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance']) && !$isWeekend) {
    // Running both ensures the time is logged and the log table is updated immediately
    update_current_session($userId); 
    $totalMinutes = get_total_minutes_today($userId); // Re-fetch updated total
    update_attendance($userId, $totalMinutes);
    // Optional: Redirect to prevent form resubmission on refresh
    header("Location: member_attendance.php");
    exit();
}

// Handle absence reason submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_absence_reason'])) {
    $date = $_POST['absence_date'];
    $reason = trim($_POST['absence_reason']);
    if ($date === $today && !$isWeekend) {
        $stmt = $conn->prepare("UPDATE attendance_log SET absence_reason = ? WHERE user_id = ? AND date = ? AND attendance = 'No'");
        $stmt->bind_param("sis", $reason, $userId, $date);
        if (!$stmt->execute()) {
            error_log("Failed to update absence reason for user_id=$userId, date=$date: " . $stmt->error);
        }
        $stmt->close();
    }
}

// Fetch monthly attendance
$firstDayOfMonth = date('Y-m-01');
$lastDayOfMonth = date('Y-m-t');
$stmt = $conn->prepare("
    SELECT date, attendance, absence_reason 
    FROM attendance_log 
    WHERE user_id = ? AND date BETWEEN ? AND ?
    ORDER BY date
");
$stmt->bind_param("iss", $userId, $firstDayOfMonth, $lastDayOfMonth);
$stmt->execute();
$result = $stmt->get_result();
$monthlyAttendance = [];
while ($row = $result->fetch_assoc()) {
    $monthlyAttendance[$row['date']] = [
        'attendance' => $row['attendance'],
        'absence_reason' => $row['absence_reason']
    ];
}
$stmt->close();

// Status display names and colors
$status_info = [
    'online' => ['name' => 'Online', 'color' => 'status-online'],
    'out_of_office' => ['name' => 'Out of Office', 'color' => 'status-out_of_office'],
    'sick' => ['name' => 'Sick', 'color' => 'status-sick'],
    'focusing' => ['name' => 'Focusing', 'color' => 'status-focusing'],
    'in_meeting' => ['name' => 'In Meeting', 'color' => 'status-in_meeting'],
    'offline' => ['name' => 'Offline', 'color' => 'status-offline']
];

// Calculation for display purposes
$hours = floor($totalMinutes / 60);
$minutes = $totalMinutes % 60;
$initialTimeDisplay = sprintf("%02d:%02d", $hours, $minutes);
$remainingMinutes = max(0, MAX_DAILY_MINUTES - $totalMinutes);
$remainingHours = floor($remainingMinutes / 60);
$remainingMinutes = $remainingMinutes % 60;
$initialRemainingDisplay = sprintf("%02d:%02d", $remainingHours, $remainingMinutes);
$initialAttendance = ($totalMinutes >= ATTENDANCE_MINUTES_REQUIRED) ? 'Yes' : 'No';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance - INVESTHOOD_TSM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
    <style>
       :root {
    --accent: #1d3557;
    --bg: #eef2f5;
    --sidebar-bg: #fff;
    --card-bg: #fff;
    --text-color: #333;
    --secondary-text: #666;
    --input-bg: #fff;
    --modal-bg: #fff;
    --bubble-me: #d9fdd3;
    --bubble-them: #ffffff;
}

/* Dark mode overrides */
body.dark-mode {
    --bg: #121212;
    --sidebar-bg: #1f1f1f;
    --card-bg: #1e1e1e;
    --text-color: #e0e0e0;       /* main readable text */
    --secondary-text: #cccccc;   /* less important text */
    --input-bg: #2b2b2b;
    --modal-bg: #1f1f1f;
    --bubble-me: #2c7a7b;
    --bubble-them: #3a3a3a;
}
        /* ... (rest of the CSS is unchanged) ... */
        * { box-sizing: border-box; }
        body { 
            font-family: 'Poppins', sans-serif; 
            margin: 0; 
           background: var(--bg);
    color: var(--text-color);
            display: flex; 
            min-height: 100vh; 
        }
        .sidebar { 
            width: 320px; 
            background: var(--sidebar-bg);
    color: var(--text-color); 
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
            bottom: 0;
            right: 0;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 2px solid white;
        }
        .status-ready { background-color: #00d4aa; box-shadow: 0 0 15px rgba(0, 212, 170, 0.4); }
        .status-out_of_office { background-color: #ffa726; box-shadow: 0 0 15px rgba(255, 167, 38, 0.4); }
        .status-sick { background-color: #e94560; box-shadow: 0 0 15px rgba(233, 69, 96, 0.4); }
        .status-focusing { background-color: #9c27b0; box-shadow: 0 0 15px rgba(156, 39, 176, 0.4); }
        .status-in_meeting { background-color: #2196f3; box-shadow: 0 0 15px rgba(33, 150, 243, 0.4); }
        .status-offline { background-color: #cccccc; box-shadow: 0 0 15px rgba(204, 204, 204, 0.4); }
        .profile-popup {
            display: none;
            position: absolute;
            top: 60px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            text-align: center;
            width: 200px;
        }
        .sidebar .top .me:hover .profile-popup {
            display: block;
        }
        .popup-avatar {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: linear-gradient(135deg, #e94560, #f39c12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.5rem;
            font-weight: 800;
            color: white;
            position: relative;
            overflow: hidden;
            border: 4px solid #e94560;
            box-shadow: 
                0 0 30px rgba(233, 69, 96, 0.3),
                inset 0 0 20px rgba(0, 0, 0, 0.2);
            margin: 0 auto;
        }
        .popup-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .popup-status {
            margin-top: 0.5rem;
            font-size: 1rem;
             font-weight: 700;           /* heavier font weight for visibility */
    color: #ffffff;             /* bright white for high contrast */

        }
        .popup-status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        .popup-role {
            font-size: 0.9rem;
            margin-top: 0.2rem;
             font-weight: 700;           /* heavier font weight for visibility */
    color: #ffffff;             /* bright white for high contrast */
        }
        .sidebar ul { 
            list-style: none; 
            margin-top: 20px; 
        }
        .sidebar ul li { 
            margin-bottom: 12px; 
        }
        .sidebar ul li a { 
            color: #666; 
            text-decoration: none; 
            font-size: 15px; 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            padding: 10px; 
            border-radius: 8px; 
        }
        .sidebar ul li a:hover,
        .sidebar ul li a.active { 
            background: #f6fbf6; 
            color: var(--accent); 
        }
        .dashboard-container { 
            flex: 1; 
            margin-left: 320px; 
            padding: 20px; 
            overflow: auto; 
        }
        .time-calendar { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            font-size: 14px; 
            color: #666; 
            margin-bottom: 20px; 
        }
        .time-calendar i { 
            color: var(--accent); 
        }
        .attendance-box {
           background: var(--card-bg);
    color: var(--text-color);
            padding: 16px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .attendance-box h2 {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 12px;
        }
        .attendance-box p {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }
        .attendance-box .status-label {
            font-size: 12px;
            background: #f1f1f1;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 700;           /* heavier font weight for visibility */
    color: #ffffff;             /* bright white for high contrast */
            display: inline-block;
            margin-top: 8px;
        }
        .attendance-box .status-label.attended {
            background: #00d4aa;
            color: #fff;
        }
        .mark-attendance {
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            margin-top: 12px;
             background: var(--input-bg);
     font-weight: 700;           /* heavier font weight for visibility */
    color: #ffffff;             /* bright white for high contrast */
    
        }
        .mark-attendance:hover {
            background: #2a4b7c;
        }
        .instructions {
            background: var(--card-bg);
    color: var(--text-color);
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .instructions ul {
            padding-left: 20px;
            font-size: 14px;
            color: #333;
        }
        .monthly-attendance {
            margin-top: 20px;
        }
        .monthly-attendance table {
            width: 100%;
            border-collapse: collapse;
           background: var(--card-bg);
    color: var(--text-color);
    
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .monthly-attendance th, .monthly-attendance td {
            padding: 12px;
            text-align: left;
             background: var(--accent);
      color: #ffffff;
    border-bottom: 1px solid #555; /* slightly brighter borders for contrast */
}
        }
        .monthly-attendance th {
    padding: 12px;
    text-align: left;
    background: var(--accent);
}

.monthly-attendance td {
    padding: 12px;
    text-align: left;
    background: var(--card-bg);
    color: var(--text-color);
    border-bottom: 1px solid #ddd;
}
        .monthly-attendance .attended {  color: #00ffaa;  /* brighter green */
    font-weight: 700; }
        .monthly-attendance .absent { color: #ff4d6d;  /* brighter red */
    font-weight: 700; }
        .monthly-attendance .off-day { color: #aaaaaa;  /* still muted for off-days */
    font-weight: 600; }
        .absence-form {
            margin-top: 8px;
            display: none;
        }
        .absence-form input[type="date"] {
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .absence-form textarea {
            width: 100%;
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
            resize: vertical;
        }
        .absence-form button {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
        }
        .absence-form button:hover {
            background: #2a4b7c;
        }
        @media (max-width: 900px) { 
            .sidebar { 
                width: 90px; 
            }
            .sidebar .top > div:not(.me) { 
                display: none; 
            }
            .sidebar ul li a span { 
                display: none; 
            }
            .dashboard-container { 
                margin-left: 90px; 
            }
        }
        /* ================= DARK MODE FULL VISIBILITY OVERRIDE ================= */
body.dark-mode, 
body.dark-mode p, 
body.dark-mode span, 
body.dark-mode li, 
body.dark-mode td, 
body.dark-mode th, 
body.dark-mode h1, 
body.dark-mode h2, 
body.dark-mode h3, 
body.dark-mode h4, 
body.dark-mode h5, 
body.dark-mode h6, 
body.dark-mode a, 
body.dark-mode button, 
body.dark-mode input, 
body.dark-mode textarea, 
body.dark-mode select, 
body.dark-mode label {
    color: #ffffff !important;       /* bright white for maximum contrast */
    font-weight: 700 !important;     /* make all text bold */
}

/* Tables in monthly attendance */
body.dark-mode .monthly-attendance table th,
body.dark-mode .monthly-attendance table td {
    color: #ffffff !important;       /* ensure table text is visible */
    font-weight: 700 !important;
    border-bottom: 1px solid #666 !important; /* slightly brighter borders for contrast */
}

/* Links */
body.dark-mode a {
    color: #ffffff !important;
    font-weight: 700 !important;
}

/* Buttons */
body.dark-mode button,
body.dark-mode .mark-attendance,
body.dark-mode .absence-form button {
    color: #ffffff !important;
    font-weight: 700 !important;
}

/* Lists */
body.dark-mode ul li {
    color: #ffffff !important;
    font-weight: 700 !important;
}

/* Sidebar text */
body.dark-mode .sidebar,
body.dark-mode .sidebar a,
body.dark-mode .sidebar span,
body.dark-mode .sidebar .top div,
body.dark-mode .sidebar ul li a span {
    color: #ffffff !important;
    font-weight: 700 !important;
}

/* Popup status & role */
body.dark-mode .popup-status,
body.dark-mode .popup-role,
body.dark-mode .popup-status-indicator {
    color: #ffffff !important;
    font-weight: 700 !important;
}

/* Instructions box */
body.dark-mode .instructions,
body.dark-mode .instructions ul,
body.dark-mode .instructions li {
    color: #ffffff !important;
    font-weight: 700 !important;
}

/* Attendance boxes */
body.dark-mode .attendance-box,
body.dark-mode .attendance-box h2,
body.dark-mode .attendance-box p,
body.dark-mode .attendance-box .status-label {
    color: #ffffff !important;
    font-weight: 700 !important;
}
/* ================= DARK MODE FULL VISIBILITY OVERRIDE ================= */
body.dark-mode, 
body.dark-mode p, 
body.dark-mode span, 
body.dark-mode li, 
body.dark-mode td, 
body.dark-mode th, 
body.dark-mode h1, 
body.dark-mode h2, 
body.dark-mode h3, 
body.dark-mode h4, 
body.dark-mode h5, 
body.dark-mode h6, 
body.dark-mode a, 
body.dark-mode button, 
body.dark-mode input, 
body.dark-mode textarea, 
body.dark-mode select, 
body.dark-mode label {
    color: #ffffff !important;       /* bright white for maximum contrast */
    font-weight: 700 !important;     /* make all text bold */
}

/* Keep "Yes" attendance green in dark mode */
body.dark-mode .monthly-attendance .attended {
    color: #00ffaa !important;       /* bright green for attendance */
    font-weight: 700 !important;
}

/* Absent in red, Off-Day muted */
body.dark-mode .monthly-attendance .absent {
    color: #ff4d6d !important;
    font-weight: 700 !important;
}
body.dark-mode .monthly-attendance .off-day {
    color: #aaaaaa !important;
    font-weight: 600 !important;
}

/* Tables in monthly attendance */
body.dark-mode .monthly-attendance table th,
body.dark-mode .monthly-attendance table td {
    border-bottom: 1px solid #666 !important; /* slightly brighter borders */
}

/* Sidebar, buttons, instructions, attendance boxes remain visible */
body.dark-mode .sidebar,
body.dark-mode .sidebar a,
body.dark-mode .sidebar span,
body.dark-mode .sidebar .top div,
body.dark-mode .sidebar ul li a span,
body.dark-mode .popup-status,
body.dark-mode .popup-role,
body.dark-mode .popup-status-indicator,
body.dark-mode .instructions,
body.dark-mode .instructions ul,
body.dark-mode .instructions li,
body.dark-mode .attendance-box,
body.dark-mode .attendance-box h2,
body.dark-mode .attendance-box p,
body.dark-mode .attendance-box .status-label,
body.dark-mode button,
body.dark-mode .mark-attendance,
body.dark-mode .absence-form button {
    color: #ffffff !important;
    font-weight: 700 !important;
}
    </style>
</head>
<body>
    <aside class="sidebar">
        <!-- Sidebar unchanged -->
        <div class="top">
            <div class="me">
                <?php if ($currentProfilePicture && file_exists($currentProfilePicture)): ?>
                    <img src="<?php echo htmlspecialchars($currentProfilePicture); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo htmlspecialchars($userInitial); ?>
                <?php endif; ?>
                <div class="status-indicator status-<?php echo htmlspecialchars($currentStatus); ?>"></div>
                <div class="profile-popup">
                    <div class="popup-avatar">
                        <?php if ($currentProfilePicture && file_exists($currentProfilePicture)): ?>
                            <img src="<?php echo htmlspecialchars($currentProfilePicture); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo htmlspecialchars($userInitial); ?>
                        <?php endif; ?>
                    </div>
                    <div class="popup-status">
                        <span class="popup-status-indicator status-<?php echo htmlspecialchars($currentStatus); ?>"></span>
                        <?php echo htmlspecialchars($status_info[$currentStatus]['name'] ?? 'Unknown'); ?>
                    </div>
                    <div class="popup-role"><?php echo htmlspecialchars($currentRole); ?></div>
                </div>
            </div>
            <div>
                <div style="font-weight:700"><?php echo htmlspecialchars($userName); ?></div>
                <div style="font-size:12px;color:#777"><?php echo htmlspecialchars($status_info[$currentStatus]['name'] ?? 'Unknown'); ?> | <?php echo htmlspecialchars($currentRole); ?></div>
            </div>
        </div>
        <ul>
            <li><a href="member_dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
            <li><a href="member_tasks.php"><i class="fas fa-tasks"></i> <span>My Tasks</span></a></li>
            <li><a href="member_projects.php"><i class="fas fa-briefcase"></i> <span>Projects</span></a></li>
            <li><a href="member_messages.php"><i class="fas fa-comments"></i> <span>Messages</span></a></li>
             <li class="nav-item">
  <a href="upload_timesheet_progress.php" class="nav-link">
    <i class="fas fa-file-signature"></i>
    <span>Timesheet Submissions</span>
  </a>
</li>
            <li><a href="member_Group.php"><i class="fas fa-users"></i> <span>Groups</span></a></li>
            <li><a href="member_Group_chat.php"><i class="fas fa-envelope"></i> <span>Group Messages</span></a></li>
            <li><a href="member_attendance.php" class="active"><i class="fas fa-check-circle"></i> <span>Attendance</span></a></li>
            <li><a href="leave_application.php"><i class="fa-solid fa-calendar-check"></i> <span>Leave Application</span></a></li>
            <li><a href="https://investhoodprojects.com/tidytracker/tidytrack/meeting/member_meetings.php"><i class="fas fa-video"></i> <span class="nav-text">Meetings</span></a></li>
            <li><a href="member_help.php"><i class="fas fa-life-ring"></i> <span>TSM ASSISTANCE</span></a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
              <li style="margin-top: auto;"><a href="profile.php"><i class="fas fa-user-circle"></i> <span>Profile Settings</span></a></li>
        </ul>
    </aside>

    <main class="dashboard-container">
        <div class="time-calendar">
            <i class="fas fa-clock"></i>
            <span id="localTime">--:--:--</span>
            <i class="fas fa-calendar-alt"></i>
            <span>Welcome, <?php echo htmlspecialchars($userName); ?></span>
        </div>

        <div class="attendance-box">
            <h2>Attendance Tracking</h2>
            <div class="instructions">
                <h3>Instructions</h3>
                <ul>
                    <li>Your time is automatically tracked when you load a page.</li>
                    <li>You must be active for at least <strong>4 hours (240 minutes)</strong> in a day to be marked as attended (Attendance: Yes).</li>
                    <li>Time below 4 hours does not count as attendance (Attendance: No).</li>
                    <li>Maximum usage per day is <strong>9 hours (540 minutes)</strong>. The system stops tracking after this limit.</li>
                    <li>If you log out and log back in on the same day, your time continues from the previous session segment.</li>
                    <li>Click "Mark Attendance" to manually confirm your presence and ensure the attendance status updates immediately.</li>
                    <li>Below, view your attendance for the current month. Absence reasons can only be added for today.</li>
                    <li>Weekends (Saturday and Sunday) are off days and do not require attendance or reasons.</li>
                </ul>
            </div>
            <?php if ($isWeekend) { ?>
                <p><strong>Today is an off day (weekend).</strong></p>
            <?php } else { ?>
                <p>Today's Time: <strong><span id="timeDisplay"><?php echo $initialTimeDisplay; ?></span></strong></p>
                <p>Remaining Time (up to 9 hours): <strong><span id="remainingDisplay"><?php echo $initialRemainingDisplay; ?></span></strong></p>
                <p>Attendance Status: <span id="attendanceStatus" class="status-label <?php echo $initialAttendance === 'Yes' ? 'attended' : ''; ?>"><?php echo $initialAttendance; ?></span></p>
                <?php if ($totalMinutes < MAX_DAILY_MINUTES): ?>
                    <form method="POST">
                        <button type="submit" name="mark_attendance" class="mark-attendance">Mark Attendance</button>
                    </form>
                <?php else: ?>
                    <p><em>Daily limit of 9 hours reached.</em></p>
                <?php endif; ?>
            <?php } ?>

            <div class="monthly-attendance">
                <h2>Monthly Attendance (<?php echo date('F Y'); ?>)</h2>
              
                <table>
                    <tr>
                        <th>Date</th>
                        <th>Attendance</th>
                        <th>Absence Reason</th>
                        <th>Action</th>
                    </tr>
                    <?php
                    $currentMonth = date('m');
                    $currentYear = date('Y');
                    $daysInMonth = date('t');
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $date = sprintf("%s-%s-%02d", $currentYear, $currentMonth, $day);
                        $isWeekend = (date('N', strtotime($date)) >= 6);
                        if ($isWeekend) {
                            echo "<tr>
                                    <td>" . htmlspecialchars($date) . "</td>
                                    <td class='off-day'>Off Day</td>
                                    <td>-</td>
                                    <td>-</td>
                                  </tr>";
                            continue;
                        }
                        $attendance = isset($monthlyAttendance[$date]) ? $monthlyAttendance[$date]['attendance'] : 'No';
                        $reason = isset($monthlyAttendance[$date]) ? $monthlyAttendance[$date]['absence_reason'] : '';
                        $isToday = ($date === $today);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($date); ?></td>
                            <td class="<?php echo $attendance === 'Yes' ? 'attended' : 'absent'; ?>">
                                <?php echo htmlspecialchars($attendance); ?>
                            </td>
                            <td><?php echo $reason ? htmlspecialchars($reason) : '-'; ?></td>
                            <td>
                                <?php 
                                // Show "Add Reason" only if absent, it's today, no reason is set, AND total tracked time is below required 4 hours
                                if ($attendance === 'No' && $isToday && !$reason && $totalMinutes < ATTENDANCE_MINUTES_REQUIRED): 
                                ?>
                                    <button class="show-absence-form" onclick="toggleAbsenceForm('<?php echo $date; ?>')">Add Reason</button>
                                    <form class="absence-form" id="absence-form-<?php echo $date; ?>" method="POST" style="display:none;">
                                        <input type="hidden" name="absence_date" value="<?php echo $date; ?>">
                                        <textarea name="absence_reason" placeholder="Enter reason for absence" required></textarea>
                                        <button type="submit" name="submit_absence_reason">Submit</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
    </main>

    <script src="theme_switcher.js"></script>
    <script>
        function updateTime() {
            // Updated to be consistent with the PHP server timezone for local display
            const now = new Date();
            const options = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
            document.getElementById('localTime').textContent = now.toLocaleTimeString('en-ZA', options);
        }
        setInterval(updateTime, 1000);
        updateTime();

        function toggleAbsenceForm(date) {
            const form = document.getElementById(`absence-form-${date}`);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }

        <?php if (!$isWeekend): ?>
        // This AJAX call is essential for real-time minute updates
        function updateAttendanceDisplay() {
            // Instead of calculating locally, we ask the server to update the session and return the new totals.
            fetch('get_attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                // We send the ID so the server can run update_current_session and get_total_minutes_today
                body: `user_id=<?php echo $userId; ?>&action=update_and_fetch`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('timeDisplay').textContent = data.timeDisplay;
                    document.getElementById('remainingDisplay').textContent = data.remainingDisplay;
                    const statusLabel = document.getElementById('attendanceStatus');
                    statusLabel.textContent = data.attendance;
                    statusLabel.className = `status-label ${data.attendance === 'Yes' ? 'attended' : ''}`;
                    
                    // Logic to hide the "Mark Attendance" button if daily limit is reached
                    if (data.totalMinutes >= 540) {
                        const form = document.querySelector('form[method="POST"]');
                        if (form) {
                            form.replaceWith('<p><em>Daily limit of 9 hours reached.</em></p>');
                        }
                    }
                } else {
                    console.error('Error fetching attendance:', data.error);
                }
            })
            .catch(error => console.error('AJAX error:', error));
        }
        
        // Update the display every minute (60 seconds)
        setInterval(updateAttendanceDisplay, 60000); 
        updateAttendanceDisplay();
        <?php endif; ?>
        
        document.getElementById('exportAttendanceBtn').addEventListener('click', function() {
    window.location.href = 'export_attendance.php';
});
    </script>
</body>
</html>
