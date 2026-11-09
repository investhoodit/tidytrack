<?php
session_start();
include '../dp.php';
include '../track2.php'; // For attendance-related functions


$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? 'Member';  // <-- This is the correctvariable
$userEmail = $_SESSION['email'] ?? null;
$userInitial = strtoupper($userName[0]);
$currentRole = strtolower($_SESSION['role'] ?? 'Member');


if (!$userId) {
    header("Location: ../login.php");
    exit;
}


// -------------------------------------------------------
// 1. ATTENDANCE & SESSION LOGIC
// -------------------------------------------------------


$today = date('Y-m-d');
$isWeekend = date('N') >= 6;


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
// NEW: Check login time for daily report restriction
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


    // Window: 16:00 :16:30 SAST only

$nowHour   = (int)date('G');   // 0-23
$nowMin    = (int)date('i');
$inWindow  = ($nowHour === 16) || ($nowHour === 17 && $nowMin === 0);

// Must have 4hrs (240 min) logged today
$has4Hours = $totalMinutesToday >= 240;
$canSubmitReport = $inWindow && $has4Hours;
}
// -------------------------------------------------------
// 2. HANDLE FORM SUBMISSION
// -------------------------------------------------------


$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['task'])) {
    if (!$canSubmitReport) {
        $error = "You must be logged in for at least 4 hour today to submit a daily report.";
    } else {
        $task = trim($_POST['task']);
        if (strlen($task) < 10) {
            $error = "Task must be at least 10 characters.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO daily_tasks (user_id, task, sent_at, sent_date)
                VALUES (?, ?, NOW(), CURDATE())
                ON DUPLICATE KEY UPDATE task = VALUES(task), sent_at = NOW()
            ");
            $stmt->bind_param("is", $userId, $task);
            if ($stmt->execute()) {
                $success = true;
            } else {
                $error = "Error submitting report. Try again.";
            }
            $stmt->close();
        }
    }
}

// -------------------------------------------------------
// 3. CHECK IF ALREADY SUBMITTED TODAY
// -------------------------------------------------------
$reportSubmitted = false;
$existingTask = '';
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
?>


<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Send Daily Task - Investhood WorkHub</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
  <style>
    :root {

      --bg: #0f172a; --card: #1e293b; --accent: #4361ee; --accent-hover: #3f37c9;

      --text: #e2e8f0; --sub: #94a3b8; --border: #334155; --success: #10b981;

      --warning: #f59e0b; --danger: #f87171; --danger-soft: #fca5a5;

    }

    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

    body {

      background: linear-gradient(135deg, #1d3557, #457b9d);

      color: var(--text);

      min-height: 100vh;

      display: flex;

      align-items: center;

      justify-content: center;

      padding: 1rem;

      overflow: hidden;

    }



    .container {

      background: var(--card);

      border-radius: 20px;

      padding: 2.5rem;

      width: 100%;

      max-width: 520px;

      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);

      animation: slideUp 0.7s ease-out;

      position: relative;

      overflow: hidden;

    }



    .container::before {

      content: '';

      position: absolute;

      top: 0; left: 0; right: 0; height: 6px;

      background: linear-gradient(90deg, var(--accent), var(--accent-hover));

    }



    h1 {

      font-size: 1.9rem;

      font-weight: 700;

      text-align: center;

      margin-bottom: 0.5rem;

      color: #fff;

    }



    .subtitle {

      text-align: center;

      color: var(--sub);

      font-size: 0.95rem;

      margin-bottom: 2rem;

    }



    .user-info {

      display: flex;

      align-items: center;

      gap: 12px;

      background: rgba(67, 97, 238, 0.1);

      padding: 12px 16px;

      border-radius: 12px;

      margin-bottom: 1.5rem;

      font-size: 0.95rem;

    }



    .avatar {

      width: 44px; height: 44px; border-radius: 50%; background: var(--accent); 

      display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 1.1rem;

    }



    label {

      display: block;

      margin-bottom: 0.8rem;

      font-weight: 600;

      color: #fff;

    }



    textarea {

      width: 100%;

      height: 180px;

      padding: 16px;

      border: 2px solid var(--border);

      border-radius: 14px;

      background: #0f172a;

      color: var(--text);

      font-size: 1rem;

      resize: none;

      transition: all 0.3s ease;

      margin-bottom: 1rem;

    }



    textarea:focus {

      outline: none;

      border-color: var(--accent);

      box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.2);

      transform: scale(1.01);

    }



    .error {

      color: var(--danger-soft);

      font-size: 0.9rem;

      margin-bottom: 1rem;

      text-align: center;

      background: rgba(239, 68, 68, 0.1);

      padding: 10px;

      border-radius: 10px;

      display: none;

    }



    .btn {

      display: block;

      width: 100%;

      padding: 14px;

      background: var(--accent);

      color: #fff;

      border: none;

      border-radius: 12px;

      font-weight: 600;

      font-size: 1.05rem;

      cursor: pointer;

      transition: all 0.3s ease;

      margin-top: 1rem;

    }



    .btn:hover {

      background: var(--accent-hover);

      transform: translateY(-3px);

      box-shadow: 0 10px 20px rgba(67, 97, 238, 0.3);

    }



    .back-link {

      display: block;

      text-align: center;

      margin-top: 1.5rem;

      color: var(--sub);

      text-decoration: none;

      font-size: 0.9rem;

    }



    .back-link:hover {

      color: var(--accent);

    }



    @keyframes slideUp {

      from { opacity: 0; transform: translateY(50px); }

      to { opacity: 1; transform: translateY(0); }

    }



    @keyframes fadeOut {

      to { opacity: 0; transform: scale(0.95); }

    }



    .fadeOut {

      animation: fadeOut 0.5s forwards;

    }



    .success-check {

      display: none;

      text-align: center;

      margin: 2rem 0;

    }



    .success-check i {

      font-size: 4rem;

      color: var(--success);

      animation: bounce 0.6s ease;

    }



    @keyframes bounce {

      0%, 100% { transform: translateY(0); }

      50% { transform: translateY(-20px); }

    }



    /* NEW: Nicer Locked Alert Styles (only this part changed) */

    .locked-alert {

      text-align: center;

      padding: 3.5rem 2rem;

      background: linear-gradient(145deg, rgba(30,41,59,0.85), rgba(15,23,42,0.95));

      border-radius: 24px;

      border: 1px solid rgba(248,113,113,0.25);

      backdrop-filter: blur(12px);

      box-shadow: 0 20px 60px rgba(0,0,0,0.5);

      max-width: 480px;

      margin: 0 auto;

    }



    .locked-icon {

      font-size: 6rem;

      color: #f87171;

      margin-bottom: 1.5rem;

      opacity: 0.9;

      animation: pulse 2s infinite;

    }



    @keyframes pulse {

      0%, 100% { transform: scale(1); }

      50% { transform: scale(1.08); }

    }



    .locked-title {

      color: #fca5a5;

      font-size: 2.2rem;

      font-weight: 700;

      margin-bottom: 1rem;

    }



    .locked-text {

      font-size: 1.25rem;

      color: #fda4af;

      line-height: 1.6;

      margin-bottom: 1.5rem;

    }



    .locked-progress {

      background: rgba(248,113,113,0.15);

      border-radius: 16px;

      padding: 1.5rem;

      margin: 1.5rem 0;

    }



    .locked-time {

      font-size: 1.5rem;

      font-weight: 700;

      color: #fca5a5;

    }



    .locked-note {

      font-size: 1.1rem;

      color: #94a3b8;

      margin-top: 2rem;

    }

  </style>

</head>

<body>



 <div class="container" id="formContainer">

    <?php if ($reportSubmitted): ?>

      <div class="success-check">

        <i class="fas fa-check-circle"></i>

        <h2 style="margin:1rem 0;color:var(--success);">Daily Task Sent!</h2>

        <p>Your report for today has been submitted successfully.</p>

        <p style="margin-top:1rem;">Redirecting back to dashboard...</p>

      </div>

      <script>

        setTimeout(() => {

          document.getElementById('formContainer').classList.add('fadeOut');

          confetti({

            particleCount: 120,

            spread: 80,

            origin: { y: 0.6 },

            colors: ['#4361ee', '#10b981', '#f59e0b', '#ec4899']

          });

          setTimeout(() => window.location = 'member_dashboard.php', 1500);

        }, 2800);

      </script>



    <?php elseif (!$canSubmitReport): ?>

  <div style="text-align:center; padding:4rem 2rem; background:linear-gradient(145deg, rgba(30,41,59,0.85), rgba(15,23,42,0.95)); border-radius:24px; border:1px solid rgba(248,113,113,0.25); backdrop-filter:blur(12px); box-shadow:0 20px 60px rgba(0,0,0,0.5);">

    <i class="fas fa-hourglass-half" style="font-size:6rem; color:#f87171; margin-bottom:1.5rem; animation:pulse 2s infinite;"></i>



    <?php

    $nowHour = (int)date('G');

    $nowMin  = (int)date('i');

    $inWindow = ($nowHour === 16 && $nowMin < 30);

    ?>



    <?php if (!$inWindow): ?>

      <h2 style="color:#fca5a5; margin-bottom:1rem; font-size:2.2rem;">Window Closed</h2>

      <p style="font-size:1.3rem; color:#fda4af; line-height:1.6;">

        Daily reports can only be submitted between <strong>16:00 : 17:00</strong>.<br>

        Come back at 4 PM!

      </p>

    <?php else: ?>

      <h2 style="color:#fca5a5; margin-bottom:1rem; font-size:2.2rem;">4hr Attendance Not Met</h2>

      <p style="font-size:1.3rem; color:#fda4af; line-height:1.6;">

        You have only logged <strong><?= floor($totalMinutesToday/60) ?>h <?= $totalMinutesToday % 60 ?>m</strong> today.<br>

        Please try to do better to avoid consequences.

      </p>

      <div style="background:rgba(248,113,113,0.15); border-radius:16px; padding:1.5rem; margin:1.5rem auto; max-width:380px;">

        <div style="font-size:1.5rem; font-weight:700; color:#fca5a5;">

          <?= 240 - $totalMinutesToday ?> minutes short

        </div>

      </div>

    <?php endif; ?>



    <p style="font-size:1.1rem; color:#94a3b8; margin-top:2rem;">Redirecting you back...</p>

  </div>

  <script>setTimeout(() => { window.location = 'member_dashboard.php'; }, 6000);</script>



    <?php elseif ($success): ?>

      <div class="success-check">

        <i class="fas fa-check-circle"></i>

        <h2 style="margin:1rem 0;color:var(--success);">Task Sent!</h2>

        <p>Redirecting to logout...</p>

      </div>

      <script>

        setTimeout(() => {

          document.getElementById('formContainer').classList.add('fadeOut');

          confetti({

            particleCount: 100,

            spread: 70,

            origin: { y: 0.6 },

            colors: ['#4361ee', '#10b981', '#f59e0b']

          });

          setTimeout(() => window.location = 'logout.php', 800);

        }, 1200);

      </script>



    <?php else: ?>

      <h1>Daily Task Report</h1>

      <p class="subtitle">Let your leader know what you accomplished today</p>



      <div class="user-info">

        <div class="avatar"><?php echo strtoupper(substr($userName, 0, 1)); ?></div> <!-- Fixed: $userName -->

        <div>

          <strong><?php echo htmlspecialchars($userName); ?></strong><br> <!-- Fixed: $userName -->

          <small>Submitting for <?php echo date('l, F j, Y'); ?></small>

        </div>

      </div>



      <?php if (!empty($error)): ?>

        <div class="error" style="display:block;"><?php echo $error; ?></div>

      <?php endif; ?>



      <form method="post">

        <label for="task">What did you accomplish today?</label>

        <textarea name="task" id="task" placeholder="e.g., Completed login system, fixed 3 bugs, attended team sync..." required><?php echo htmlspecialchars($_POST['task'] ?? ''); ?></textarea>



        <button type="submit" class="btn">

          <i class="fas fa-paper-plane"></i> Send Task to Leader

        </button>

      </form>



      <a href="member_dashboard.php" class="back-link">

        <i class="fas fa-arrow-left"></i> Back to Dashboard

      </a>

    <?php endif; ?>

  </div>



</body>

</html>                      