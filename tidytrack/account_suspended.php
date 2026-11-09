<?php
session_start();
include 'dp.php';

if (!isset($_SESSION['ban_message'])) {
    header("Location: signup.html");
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;
$message = $_SESSION['ban_message'];

// Handle Appeal Submission (Only if no pending appeal)
$success = '';
$hasPendingAppeal = false;

if ($user_id > 0) {
    // Check if user already has a pending appeal
    $check = $conn->prepare("SELECT appeal FROM users WHERE user_id = ? AND isallowed = 0");
    $check->bind_param("i", $user_id);
    $check->execute();
    $result = $check->get_result();
    $row = $result->fetch_assoc();
    $check->close();

    if (!empty($row['appeal'])) {
        $hasPendingAppeal = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appeal']) && !$hasPendingAppeal) {
    $appeal = trim($_POST['appeal']);

    if (!empty($appeal)) {
        $stmt = $conn->prepare("UPDATE users SET appeal = ? WHERE user_id = ?");
        $stmt->bind_param("si", $appeal, $user_id);
        if ($stmt->execute()) {
            $success = "✅ Your appeal has been submitted successfully.<br>The Developer Team will review it soon.";
            $hasPendingAppeal = true;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Suspended • TidyTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg: #0c0e13;
            --card: #161a23;
            --accent: #f54f6b;
            --text: #dde1eb;
            --sub: #52596e;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Syne', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .bg-animation {
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 50% 30%, rgba(245,79,107,0.15) 0%, transparent 60%);
        }
        .container {
            background: var(--card);
            border: 1px solid rgba(245,79,107,0.4);
            border-radius: 24px;
            width: 100%;
            max-width: 520px;
            padding: 50px 40px;
            text-align: center;
            box-shadow: 0 25px 60px rgba(0,0,0,0.6);
            position: relative;
            z-index: 2;
        }
        .icon { font-size: 5rem; color: var(--accent); margin-bottom: 20px; animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.08); } }
        h1 { color: var(--accent); margin-bottom: 12px; }
        .message { color: var(--sub); line-height: 1.65; margin-bottom: 30px; font-size: 15.5px; }
        textarea {
            width: 100%;
            height: 140px;
            background: #101318;
            border: 1px solid rgba(245,79,107,0.5);
            border-radius: 14px;
            color: var(--text);
            padding: 16px;
            font-size: 15px;
            resize: vertical;
            margin-bottom: 20px;
        }
        button {
            background: linear-gradient(135deg, var(--accent), #f5a623);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 14px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
        }
        button:hover { transform: translateY(-3px); }
        .success { color: #10c97a; margin: 15px 0; font-weight: 600; }
        .pending { color: #f5a623; font-style: italic; margin: 20px 0; }
        .back { margin-top: 24px; color: var(--sub); text-decoration: none; }
    </style>
</head>
<body>

    <div class="bg-animation"></div>

    <div class="container">
        <div class="icon">🚫</div>
        <h1>Account Temporarily Suspended</h1>
        <div class="message"><?= nl2br($message) ?></div>

        <?php if ($success): ?>
            <div class="success"><?= $success ?></div>
        <?php elseif ($hasPendingAppeal): ?>
            <div class="pending">
                <i class="fas fa-clock"></i> You have already submitted an appeal.<br>
                Please wait for the Developer Team to review it.
            </div>
        <?php else: ?>
            <form action="" method="POST">
                <textarea name="appeal" placeholder="Write your appeal here... Why should we restore your access?" required></textarea>
                <button type="submit">Submit Appeal</button>
            </form>
        <?php endif; ?>

        <a href="signup.html" class="back">← Back to Login</a>
    </div>

</body>
</html>