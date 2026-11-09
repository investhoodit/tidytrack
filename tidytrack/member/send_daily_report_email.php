<?php
session_start();
include '../dp.php';
include '../track2.php'; // For attendance-related functions

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Member';
$userEmail = $_SESSION['email'] ?? null;
$userInitial = strtoupper($userName[0]);
$currentRole = strtolower($_SESSION['role'] ?? 'Member');

if (!$userId) {
    header("Location: ../login.php");
    exit;
}

// -------------------------------------------------------
// CREATE REMINDERS TABLE IF NOT EXISTS
// -------------------------------------------------------
$conn->query("
    CREATE TABLE IF NOT EXISTS `daily_report_reminders` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `reminder_date` date NOT NULL,
        `reminder_10min_sent` tinyint(1) DEFAULT 0,
        `reminder_5min_sent` tinyint(1) DEFAULT 0,
        `reminder_10min_sent_at` datetime DEFAULT NULL,
        `reminder_5min_sent_at` datetime DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `user_date` (`user_id`,`reminder_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// -------------------------------------------------------
// EMAIL REMINDER FUNCTION
// -------------------------------------------------------
function sendReminderEmail($to, $name, $type, $totalMinutes = 0) {
    $subject = "Daily Report Reminder - Investhood WorkHub";
    
    if ($type == 'upcoming') {
        $body = "Hello $name,\n\n";
        $body .= "🔔 REMINDER: Daily report submission window opens in 10 minutes!\n\n";
        $body .= "⏰ Time: 16:00 - 16:30 SAST\n";
        $body .= "📊 Requirement: At least 4 hours of logged work today\n\n";
        
        if ($totalMinutes > 0) {
            $hours = floor($totalMinutes / 60);
            $minutes = $totalMinutes % 60;
            $body .= "Current logged time: {$hours}h {$minutes}m\n";
            if ($totalMinutes < 240) {
                $short = 240 - $totalMinutes;
                $body .= "⚠️ You need {$short} more minutes to be eligible.\n\n";
            }
        }
        
        $body .= "Please ensure you're ready to submit your report when the window opens.\n\n";
        $body .= "Regards,\nInvesthood WorkHub Team";
    } else {
        $body = "Hello $name,\n\n";
        $body .= "⚠️ URGENT: Daily report submission window closes in 5 minutes!\n\n";
        $body .= "⏰ Time: 16:30 SAST\n\n";
        $body .= "If you haven't submitted your report yet, please do so IMMEDIATELY.\n";
        $body .= "Remember: You need at least 4 hours of logged work today to submit.\n\n";
        $body .= "Don't miss the deadline!\n\n";
        $body .= "Regards,\nInvesthood WorkHub Team";
    }
    
    $headers = "From: no-reply@investhoodprojects.com\r\n";
    $headers .= "Reply-To: no-reply@investhoodprojects.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    
    return @mail($to, $subject, $body, $headers);
}

// -------------------------------------------------------
// CHECK AND SEND REMINDERS
// -------------------------------------------------------
$today = date('Y-m-d');
$currentHour = (int)date('G');
$currentMinute = (int)date('i');
$isWeekend = date('N') >= 6;

if (!$isWeekend && $userId && $userEmail) {
    // Get today's total minutes for the reminder message
    $stmt = $conn->prepare("
        SELECT SUM(minutes) as total_minutes
        FROM user_sessions
        WHERE user_id = ? AND session_date = ?
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $totalMinutesToday = (int)($row['total_minutes'] ?? 0);
    $stmt->close();
    
    // Check if reminder has been sent for today
    $stmt = $conn->prepare("
        SELECT reminder_10min_sent, reminder_5min_sent 
        FROM daily_report_reminders 
        WHERE user_id = ? AND reminder_date = ?
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $reminderCheck = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // If no record exists, create one
    if (!$reminderCheck) {
        $stmt = $conn->prepare("
            INSERT INTO daily_report_reminders (user_id, reminder_date, reminder_10min_sent, reminder_5min_sent) 
            VALUES (?, ?, 0, 0)
        ");
        $stmt->bind_param("is", $userId, $today);
        $stmt->execute();
        $stmt->close();
        $reminderCheck = ['reminder_10min_sent' => 0, 'reminder_5min_sent' => 0];
    }
    
    // Send 10-minute before reminder (at 15:50)
    if ($currentHour == 15 && $currentMinute == 50 && $reminderCheck['reminder_10min_sent'] == 0) {
        $emailSent = sendReminderEmail($userEmail, $userName, 'upcoming', $totalMinutesToday);
        
        if ($emailSent) {
            $stmt = $conn->prepare("
                UPDATE daily_report_reminders 
                SET reminder_10min_sent = 1, reminder_10min_sent_at = NOW() 
                WHERE user_id = ? AND reminder_date = ?
            ");
            $stmt->bind_param("is", $userId, $today);
            $stmt->execute();
            $stmt->close();
            
            // Log the reminder
            error_log("Daily report reminder (10min) sent to $userEmail at " . date('Y-m-d H:i:s'));
        }
    }
    
    // Send 5-minute before window closes (at 15:55)
    if ($currentHour == 15 && $currentMinute == 55 && $reminderCheck['reminder_5min_sent'] == 0) {
        $emailSent = sendReminderEmail($userEmail, $userName, 'closing', $totalMinutesToday);
        
        if ($emailSent) {
            $stmt = $conn->prepare("
                UPDATE daily_report_reminders 
                SET reminder_5min_sent = 1, reminder_5min_sent_at = NOW() 
                WHERE user_id = ? AND reminder_date = ?
            ");
            $stmt->bind_param("is", $userId, $today);
            $stmt->execute();
            $stmt->close();
            
            // Log the reminder
            error_log("Daily report reminder (5min) sent to $userEmail at " . date('Y-m-d H:i:s'));
        }
    }
}

// -------------------------------------------------------
// ATTENDANCE & SESSION LOGIC
// -------------------------------------------------------

if (!$isWeekend) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count 
        FROM user_sessions 
        WHERE user_id = ? AND session_date = ? AND logout_time IS NULL
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $sessionCount = $result->fetch_assoc()['count'];
    $stmt->close();

    if ($sessionCount == 0) {
        log_session_start($userId);
    }

    update_current_session($userId);
}

// Log page view
include '../track.php';
log_activity($userId, "Viewed Daily Report Submission");

// -------------------------------------------------------
// CHECK SUBMISSION ELIGIBILITY
// -------------------------------------------------------

$canSubmitReport = false;
$totalMinutesToday = 0;

if (!$isWeekend) {
    $stmt = $conn->prepare("
        SELECT SUM(minutes) as total_minutes
        FROM user_sessions
        WHERE user_id = ? AND session_date = ?
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $totalMinutesToday = (int)($row['total_minutes'] ?? 0);
    $stmt->close();

    // Window: 16:00–16:30 SAST only
    $nowHour = (int)date('G');
    $nowMin = (int)date('i');
    $inWindow = ($nowHour === 16 && $nowMin < 30);
    
    // Must have 4hrs (240 min) logged today
    $has4Hours = $totalMinutesToday >= 240;
    
    $canSubmitReport = $inWindow && $has4Hours;
}

// -------------------------------------------------------
// HANDLE FORM SUBMISSION
// -------------------------------------------------------

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['task'])) {
    if (!$canSubmitReport) {
        if (date('G') != 16) {
            $error = "Daily reports can only be submitted between 16:00 and 16:30.";
        } elseif ($totalMinutesToday < 240) {
            $remaining = 240 - $totalMinutesToday;
            $hours = floor($remaining / 60);
            $minutes = $remaining % 60;
            $error = "You need to log at least 4 hours of work today to submit a report. You need {$hours}h {$minutes}m more.";
        } else {
            $error = "You are not eligible to submit a report at this time.";
        }
    } else {
        $task = trim($_POST['task']);
        if (strlen($task) < 10) {
            $error = "Task description must be at least 10 characters.";
        } else {
            // Create daily_tasks table if not exists
            $conn->query("
                CREATE TABLE IF NOT EXISTS `daily_tasks` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `task` text NOT NULL,
                    `sent_at` datetime NOT NULL,
                    `sent_date` date NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `user_date` (`user_id`,`sent_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            $stmt = $conn->prepare("
                INSERT INTO daily_tasks (user_id, task, sent_at, sent_date)
                VALUES (?, ?, NOW(), CURDATE())
                ON DUPLICATE KEY UPDATE 
                    task = VALUES(task), 
                    sent_at = NOW()
            ");
            $stmt->bind_param("is", $userId, $task);
            if ($stmt->execute()) {
                $success = true;
                
                // Log successful submission
                error_log("Daily report submitted by $userName (ID: $userId) on $today");
            } else {
                $error = "Error submitting report. Please try again.";
            }
            $stmt->close();
        }
    }
}

// -------------------------------------------------------
// CHECK IF ALREADY SUBMITTED TODAY
// -------------------------------------------------------

$reportSubmitted = false;
$existingTask = '';

// Check if daily_tasks table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'daily_tasks'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $stmt = $conn->prepare("SELECT task FROM daily_tasks WHERE user_id = ? AND sent_date = ?");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $reportSubmitted = true;
        $row = $result->fetch_assoc();
        $existingTask = $row['task'];
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Report - Investhood WorkHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }

        .user-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            border-radius: 15px;
            color: white;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .avatar {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
        }

        .user-info h3 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .user-info p {
            font-size: 14px;
            opacity: 0.9;
        }

        .stats {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-item {
            text-align: center;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }

        .progress-section {
            margin-bottom: 25px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
            color: #666;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: width 0.3s ease;
        }

        label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #333;
        }

        textarea {
            width: 100%;
            height: 200px;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            resize: vertical;
            transition: all 0.3s ease;
        }

        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        button {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease;
            margin-top: 15px;
        }

        button:hover:not(:disabled) {
            transform: translateY(-2px);
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
        }

        .success-message {
            text-align: center;
            padding: 40px 20px;
        }

        .success-message i {
            font-size: 80px;
            color: #4caf50;
            margin-bottom: 20px;
        }

        .success-message h2 {
            color: #333;
            margin-bottom: 10px;
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .info-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-badge i {
            font-size: 18px;
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: scale(0.95);
            }
        }

        .fadeOut {
            animation: fadeOut 0.5s forwards;
        }
    </style>
</head>
<body>

<div class="container" id="formContainer">
    <?php if ($reportSubmitted && !$success): ?>
        <div class="success-message">
            <i class="fas fa-check-circle"></i>
            <h2>Report Already Submitted!</h2>
            <p>You've already submitted your daily report for today.</p>
            <div style="background: #f5f5f5; padding: 15px; border-radius: 8px; margin-top: 20px; text-align: left;">
                <strong>Your report:</strong>
                <p style="margin-top: 10px; color: #666;"><?php echo htmlspecialchars($existingTask); ?></p>
            </div>
            <a href="member_dashboard.php" class="back-link">← Back to Dashboard</a>
        </div>
        <script>
            setTimeout(() => {
                window.location = 'member_dashboard.php';
            }, 5000);
        </script>

    <?php elseif ($success): ?>
        <div class="success-message">
            <i class="fas fa-check-circle"></i>
            <h2>Daily Report Submitted!</h2>
            <p>Your report has been sent to your leader successfully.</p>
            <p style="margin-top: 10px; color: #666;">Redirecting to dashboard...</p>
        </div>
        <script>
            setTimeout(() => {
                document.getElementById('formContainer').classList.add('fadeOut');
                confetti({
                    particleCount: 150,
                    spread: 70,
                    origin: { y: 0.6 },
                    colors: ['#667eea', '#764ba2', '#4caf50']
                });
                setTimeout(() => {
                    window.location = 'member_dashboard.php';
                }, 2000);
            }, 1500);
        </script>

    <?php elseif (!$canSubmitReport && !$reportSubmitted): ?>
        <div class="success-message">
            <i class="fas fa-clock"></i>
            <h2>Not Yet Available</h2>
            <?php
            $nowHour = (int)date('G');
            $nowMin = (int)date('i');
            $inWindow = ($nowHour === 16 && $nowMin < 30);
            ?>
            
            <?php if (!$inWindow): ?>
                <p>Daily reports can only be submitted between <strong>16:00 and 16:30</strong>.</p>
                <?php if ($nowHour < 16): ?>
                    <p>The submission window opens in <strong><?php echo (16 - $nowHour); ?> hours</strong>.</p>
                <?php elseif ($nowHour >= 16 && $nowMin >= 30): ?>
                    <p>The submission window closed at 16:30 today.</p>
                <?php endif; ?>
            <?php else: ?>
                <p>You need to log at least <strong>4 hours</strong> of work today to submit a report.</p>
                <div style="margin-top: 20px;">
                    <div class="progress-label">
                        <span>Time Logged Today</span>
                        <span><?php echo floor($totalMinutesToday / 60); ?>h <?php echo $totalMinutesToday % 60; ?>m / 4h</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min(100, ($totalMinutesToday / 240) * 100); ?>%"></div>
                    </div>
                    <?php if ($totalMinutesToday < 240): ?>
                        <p style="margin-top: 15px; color: #ff9800;">
                            <i class="fas fa-exclamation-triangle"></i> 
                            You need <?php echo (240 - $totalMinutesToday); ?> more minutes to be eligible.
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <a href="member_dashboard.php" class="back-link">← Back to Dashboard</a>
        </div>
        <script>
            setTimeout(() => {
                window.location = 'member_dashboard.php';
            }, 8000);
        </script>

    <?php else: ?>
        <h1>
            <i class="fas fa-pen-alt"></i>
            Daily Report
        </h1>
        <div class="subtitle">Share what you accomplished today</div>

        <div class="user-card">
            <div class="avatar"><?php echo strtoupper(substr($userName, 0, 1)); ?></div>
            <div class="user-info">
                <h3><?php echo htmlspecialchars($userName); ?></h3>
                <p><?php echo htmlspecialchars($userEmail); ?></p>
            </div>
        </div>

        <div class="stats">
            <div class="stat-item">
                <div class="stat-label">Today's Date</div>
                <div class="stat-value"><?php echo date('d M Y'); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Time Logged</div>
                <div class="stat-value"><?php echo floor($totalMinutesToday / 60); ?>h <?php echo $totalMinutesToday % 60; ?>m</div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Required</div>
                <div class="stat-value">4h</div>
            </div>
        </div>

        <div class="progress-section">
            <div class="progress-label">
                <span>Progress towards 4 hours</span>
                <span><?php echo min(100, round(($totalMinutesToday / 240) * 100)); ?>%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo min(100, ($totalMinutesToday / 240) * 100); ?>%"></div>
            </div>
        </div>

        <?php if ($totalMinutesToday >= 240): ?>
            <div class="info-badge">
                <i class="fas fa-check-circle"></i>
                <span>✅ You've met the 4-hour requirement! You can submit your report between 16:00-16:30.</span>
            </div>
        <?php else: ?>
            <div class="info-badge">
                <i class="fas fa-hourglass-half"></i>
                <span>⚠️ You need <?php echo (240 - $totalMinutesToday); ?> more minutes to reach the 4-hour requirement.</span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <label for="task">What did you accomplish today?</label>
            <textarea 
                name="task" 
                id="task" 
                placeholder="Example: Completed the login module, fixed 3 bugs, attended team meeting, reviewed pull requests..." 
                required
                minlength="10"
            ><?php echo htmlspecialchars($_POST['task'] ?? ''); ?></textarea>
            
            <div style="font-size: 12px; color: #666; margin-top: -10px; margin-bottom: 10px;">
                <i class="fas fa-info-circle"></i> Minimum 10 characters
            </div>

            <button type="submit" <?php echo (!$canSubmitReport) ? 'disabled' : ''; ?>>
                <i class="fas fa-paper-plane"></i> Submit Daily Report
            </button>
        </form>

        <a href="member_dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    <?php endif; ?>
</div>

<script>
    // Auto-focus on textarea if form is active
    <?php if (!$reportSubmitted && $canSubmitReport && !$success): ?>
    document.getElementById('task').focus();
    <?php endif; ?>
    
    // Character counter for textarea
    const textarea = document.getElementById('task');
    if (textarea) {
        textarea.addEventListener('input', function() {
            const length = this.value.length;
            const minLength = 10;
            
            if (length < minLength) {
                this.style.borderColor = '#ff9800';
            } else {
                this.style.borderColor = '#4caf50';
            }
        });
    }
</script>

</body>
</html>