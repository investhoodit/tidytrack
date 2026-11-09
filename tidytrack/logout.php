<?php
session_start();

require_once 'dp.php';

$userId = $_SESSION['user_id'] ?? null;

// Only update the status if a user was actually logged in
if ($userId) {
    global $conn;
    // Update the user's status to 'Offline'
    $stmt = $conn->prepare("UPDATE users SET status = 'offline' WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    
    if (!$stmt->execute()) {
        // Log the error but continue with logout, as logging out is still the priority
        error_log("Failed to update user status to Offline for user_id: " . $userId . " Error: " . $stmt->error);
    }
    $stmt->close();
}

// ✅ Clear all session variables and destroy the session
session_unset();
session_destroy();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out - Investhood WorkHub</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background: linear-gradient(135deg, #1d3557, #457b9d);
            overflow: hidden;
        }

        .popup {
            background: #fff;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            text-align: center;
            max-width: 400px;
            width: 90%;
            animation: fadeIn 0.6s ease;
        }

        .popup h2 {
            color: #1d3557;
            margin-bottom: 1rem;
        }

        .popup p {
            color: #555;
            font-size: 0.95rem;
            margin-bottom: 2rem;
        }

        .popup-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .popup button {
            padding: 0.7rem 1.2rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.3s;
        }

        .feedback-btn {
            background: #e63946;
            color: #fff;
        }

        .feedback-btn:hover {
            background: #d62839;
        }

        .skip-btn {
            background: #1d3557;
            color: #fff;
        }

        .skip-btn:hover {
            background: #2a4b7c;
        }

        .timer-text {
            font-size: 0.85rem;
            color: #666;
            margin-top: 1rem;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fadeOut {
            animation: fadeOut 0.5s forwards;
        }

        @keyframes fadeOut {
            to { opacity: 0; transform: scale(0.9); }
        }
    </style>
</head>
<body>

    <div class="popup" id="logoutPopup">
        <h2>You’ve been logged out</h2>
        <p>Before you go, would you like to share your feedback with us?</p>
        <div class="popup-buttons">
            <button class="feedback-btn" id="feedbackBtn">Give Feedback</button>
            <button class="skip-btn" id="skipBtn">Skip</button>
        </div>
        <div class="timer-text" id="timerText">You’ll be redirected in 10 seconds...</div>
    </div>

    <script>
        const feedbackBtn = document.getElementById('feedbackBtn');
        const skipBtn = document.getElementById('skipBtn');
        const popup = document.getElementById('logoutPopup');
        const timerText = document.getElementById('timerText');

        let countdown = 10;
        const timer = setInterval(() => {
            countdown--;
            timerText.textContent = `You’ll be redirected in ${countdown} seconds...`;
            if (countdown <= 0) {
                clearInterval(timer);
                redirectToSignup();
            }
        }, 1000);

        function redirectToSignup() {
            popup.classList.add('fadeOut');
            setTimeout(() => {
                window.location.href = "signup.html";
            }, 400);
        }

        feedbackBtn.addEventListener('click', () => {
            clearInterval(timer);
            popup.classList.add('fadeOut');
            setTimeout(() => {
                window.location.href = "https://investhoodprojects.com/tidytracker/Form/";
            }, 400);
        });

        skipBtn.addEventListener('click', () => {
            clearInterval(timer);
            redirectToSignup();
        });
    </script>

</body>
</html>
