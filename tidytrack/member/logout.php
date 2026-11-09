<?php
session_start();
include '../dp.php';
include '../track2.php';

$user_id = $_SESSION['user_id'] ?? null;
$showTaskReminder = false;
$alreadySent = false;

if ($user_id) {
    $today = date('Y-m-d');

    // 1. Close current session in DB (but keep PHP session alive for now)
    $stmt = $conn->prepare("SELECT session_id, login_time FROM user_sessions WHERE user_id = ? AND session_date = ? AND logout_time IS NULL ORDER BY login_time DESC LIMIT 1");
    $stmt->bind_param("is", $user_id, $today);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $session = $result->fetch_assoc();
        $start_time = new DateTime($session['login_time'], new DateTimeZone('Africa/Johannesburg'));
        $now = new DateTime('now', new DateTimeZone('Africa/Johannesburg'));
        $minutes = min(round(($now->getTimestamp() - $start_time->getTimestamp()) / 60), 540);

        $update = $conn->prepare("UPDATE user_sessions SET logout_time = NOW(), minutes = ? WHERE session_id = ?");
        $update->bind_param("ii", $minutes, $session['session_id']);
        $update->execute();
        $update->close();
    }
    $stmt->close();

    // 2. Set user status to offline
    $status_stmt = $conn->prepare("UPDATE users SET status = 'offline' WHERE user_id = ?");
    $status_stmt->bind_param("i", $user_id);
    $status_stmt->execute();
    $status_stmt->close();

    // 3. Check if daily report already sent today
    $check = $conn->prepare("SELECT 1 FROM daily_tasks WHERE user_id = ? AND DATE(sent_at) = ?");
    $check->bind_param("is", $user_id, $today);
    $check->execute();
    $alreadySent = $check->get_result()->num_rows > 0;
    $check->close();

    $showTaskReminder = !$alreadySent;
}

// DO NOT CALL session_destroy() HERE — ONLY WHEN USER ACTUALLY LOGS OUT
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Logging Out - Investhood WorkHub</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
  <style>
    * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }
    body { display:flex; justify-content:center; align-items:center; min-height:100vh; background:linear-gradient(135deg,#1d3557,#457b9d); color:#fff; overflow:hidden; }
    .popup { background:#fff; color:#1d3557; border-radius:16px; padding:2.5rem; box-shadow:0 15px 40px rgba(0,0,0,0.3); text-align:center; max-width:420px; width:90%; animation:slideUp .6s ease-out; position:relative; overflow:hidden; }
    .popup::before { content:''; position:absolute; top:0; left:0; right:0; height:6px; background:linear-gradient(90deg,#4361ee,#3f37c9); }
    h2 { font-size:1.8rem; margin-bottom:.8rem; color:#1d3557; }
    p { color:#555; margin-bottom:1.5rem; line-height:1.5; }
    .highlight { color:#4361ee; font-weight:600; }
    .success-msg { color:#10b981; font-weight:600; font-size:1.1rem; }
    .popup-buttons { display:flex; justify-content:center; gap:1rem; margin-top:1.5rem; }
    button { padding:.8rem 1.5rem; border:none; border-radius:8px; cursor:pointer; font-weight:600; min-width:120px; transition:.3s; }
    .task-btn { background:#4361ee; color:#fff; }
    .task-btn:hover { background:#3f37c9; transform:translateY(-2px); box-shadow:0 6px 15px rgba(67,97,238,.3); }
    .logout-btn { background:#e63946; color:#fff; }
    .logout-btn:hover { background:#d62839; transform:translateY(-2px); box-shadow:0 6px 15px rgba(230,57,70,.3); }
    .continue-btn { background:#10b981; color:#fff; width:100%; }
    .continue-btn:hover { background:#0e9f6e; }
    .timer-text { font-size:.85rem; color:#777; margin-top:1.5rem; font-style:italic; }
    .success-check { font-size:3.5rem; color:#10b981; animation:bounce .6s; margin-bottom:1rem; }
    @keyframes slideUp { from{opacity:0;transform:translateY(40px)} to{opacity:1;transform:translateY(0)} }
    @keyframes fadeOut { to{opacity:0;transform:scale(.92)} }
    .fadeOut { animation:fadeOut .5s forwards; }
    @keyframes bounce { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-15px)} }
  </style>
</head>
<body>

  <div class="popup" id="logoutPopup">
    <h2>See You Soon!</h2>

    <?php if ($showTaskReminder): ?>
      <p>Don’t forget to <span class="highlight">send your daily report</span> before logging out!</p>
      <div class="popup-buttons">
        <button class="task-btn" id="sendTaskBtn">Send Report Now</button>
        <button class="logout-btn" id="logoutNowBtn">Logout Anyway</button>
      </div>
    <?php else: ?>
      <div class="success-check"><i class="fas fa-check-circle"></i></div>
      <p class="success-msg">Daily report already sent today!</p>
      <p>Logging you out safely...</p>
      <div class="popup-buttons">
        <button class="continue-btn" id="continueLogoutBtn">Continue Logout</button>
      </div>
    <?php endif; ?>

    <div class="timer-text">Redirecting in <span id="countdown">15</span> seconds...</div>
  </div>

  <script>
    confetti({ particleCount: 80, spread: 70, origin: { y: 0.6 } });

    let timeLeft = 15;
    const countdownEl = document.getElementById('countdown');
    const popup = document.getElementById('logoutPopup');

    const timer = setInterval(() => {
      timeLeft--;
      countdownEl.textContent = timeLeft;
      if (timeLeft <= 0) {
        clearInterval(timer);
        fullLogout();
      }
    }, 1000);

    function fullLogout() {
      // ONLY NOW destroy the session
      fetch('', { method: 'POST', body: 'destroy_session=1' })
        .finally(() => {
          popup.classList.add('fadeOut');
          confetti({ particleCount: 120, spread: 80, origin: { y: 0.6 } });
          setTimeout(() => window.location.href = "../logout.php", 600);
        });
    }

    // "Send Report" → keep session alive → go to report page
    document.getElementById('sendTaskBtn')?.addEventListener('click', () => {
      clearInterval(timer);
      popup.classList.add('fadeOut');
      setTimeout(() => {
        window.location.href = "send_daily_report.php";
      }, 500);
    });

    // "Logout Anyway" or "Continue" → destroy session
    document.getElementById('logoutNowBtn')?.addEventListener('click', () => {
      clearInterval(timer);
      fullLogout();
    });

    document.getElementById('continueLogoutBtn')?.addEventListener('click', () => {
      clearInterval(timer);
      fullLogout();
    });

    // Handle session destroy request from same file
    <?php
    if (isset($_POST['destroy_session'])) {
        session_destroy();
        exit;
    }
    ?>
  </script>
</body>
</html>