<?php
session_start();
include 'dp.php';

$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
if ($userId) {
    header("Location: signup.html");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Tidy Track</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="theme.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
        }
        .logo {
            position: absolute;
            top: 1.5rem;
            left: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .logo img { width: 35px; }
        .logo span {
            font-weight: bold;
            font-size: 1.1rem;
            color: #1d3557;
        }
        .form-container {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            text-align: center;
            animation: fadeSlideUp 0.5s ease forwards;
        }
        .form-container h2 {
            color: #1d3557;
            margin-bottom: 1rem;
            font-size: 1.8rem;
        }
        .form-container p {
            color: #444;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        .form-container input, .form-container select, .form-container textarea {
            width: 100%;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .form-container textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-container button {
            background: #1d3557;
            color: white;
            width: 100%;
            padding: 0.75rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }
        .form-container button:hover {
            background: #2a4b7c;
        }
        .form-container a {
            color: #e63946;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .form-container a:hover {
            text-decoration: underline;
        }
        #otherReason { display: none; }
        @keyframes fadeSlideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="logo">
        <img src="images/Tidy Track Logomark- Website Scale.png" alt="Logo">
        <span>Tidy Track</span>
    </div>
    <div class="form-container">
        <h2>Don’t Worry, We’ve Got You Covered!</h2>
        <p>Forgot your password? No problem! Enter your username or email, tell us why you need a reset, and set a new password. Your data is securely stored with us, so please store your new password safely.</p>
        <form action="api/submit_reset_request.php" method="POST" id="resetForm">
            <input type="text" name="identifier" placeholder="Username or Email" required>
            <select name="reason" id="reasonSelect" required>
                <option value="" disabled selected>Why did you forget your password?</option>
                <option value="forgot">I forgot my password</option>
                <option value="compromised">I suspect my account was compromised</option>
                <option value="new_device">I’m using a new device</option>
                <option value="other">Other</option>
            </select>
            <textarea name="other_reason" id="otherReason" placeholder="Please specify your reason"></textarea>
            <input type="password" name="new_password" placeholder="New Password" required>
            <button type="submit">Submit Request</button>
        </form>
        <p style="margin-top: 1rem;">
            <a href="signup.html">Back to Login</a>
        </p>
    </div>
    <script>
        document.getElementById('reasonSelect').addEventListener('change', function() {
            document.getElementById('otherReason').style.display = this.value === 'other' ? 'block' : 'none';
            document.getElementById('otherReason').required = this.value === 'other';
        });
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('submit_reset_request.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Your password reset request has been submitted. Please try logging in again after 1 hour. Your new password should work.');
                    window.location.href = 'signup.html';
                } else {
                    alert(data.error || 'An error occurred. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    </script>
</body>
</html>