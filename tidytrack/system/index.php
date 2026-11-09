<?php
session_start();
include '../dp.php';

// Handle login
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = "Email and password are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        $stmt = $conn->prepare("SELECT id, password FROM developers WHERE email = ? AND approved = 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['developer_id'] = $user['id'];
                $_SESSION['developer_email'] = $email;

                $update = $conn->prepare("UPDATE developers SET last_login = NOW() WHERE id = ?");
                $update->bind_param("i", $user['id']);
                $update->execute();
                $update->close();

                header('Location: dashboard.php');
                exit;
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "Invalid email or account not approved.";
        }
        $stmt->close();
    }
}

if (isset($_SESSION['developer_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Portal • TidyTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg: #0c0e13;
            --card: #161a23;
            --input: #101318;
            --accent: #4f7ef5;
            --violet: #7c5af5;
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
            overflow: hidden;
            position: relative;
        }

        /* Animated Background */
        .bg-animation {
            position: absolute;
            inset: 0;
            z-index: 1;
            background: 
                radial-gradient(circle at 20% 30%, rgba(79,126,245,0.18) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(124,90,245,0.18) 0%, transparent 50%);
            animation: gradientShift 25s ease infinite;
        }

        .bg-animation::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: 
                radial-gradient(circle, rgba(255,255,255,0.07) 1px, transparent 1px);
            background-size: 60px 60px;
            animation: gridMove 40s linear infinite;
        }

        @keyframes gradientShift {
            0%   { background-position: 0% 50%; }
            50%  { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes gridMove {
            0% { background-position: 0 0; }
            100% { background-position: 60px 60px; }
        }

        .login-box {
            background: var(--card);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            width: 100%;
            max-width: 420px;
            padding: 48px 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            position: relative;
            z-index: 2;
        }

        .logo {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, var(--accent), var(--violet));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            color: white;
            margin: 0 auto 24px;
            box-shadow: 0 0 35px rgba(79,126,245,0.5);
            animation: logoPulse 4s ease-in-out infinite;
        }

        @keyframes logoPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        h1 {
            text-align: center;
            font-size: 1.9rem;
            font-weight: 700;
            letter-spacing: -0.6px;
            margin-bottom: 6px;
        }

        .subtitle {
            text-align: center;
            color: var(--sub);
            font-size: 14.5px;
            margin-bottom: 36px;
            font-family: 'JetBrains Mono', monospace;
        }

        .input-group {
            position: relative;
            margin-bottom: 18px;
        }

        input {
            width: 100%;
            padding: 16px 20px 16px 52px;
            background: var(--input);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            color: var(--text);
            font-size: 15.5px;
            transition: all 0.3s;
        }

        input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(79,126,245,0.2);
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--sub);
            font-size: 1.1rem;
        }

        button {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--accent), var(--violet));
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }

        button:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(79,126,245,0.35);
        }

        .error {
            background: rgba(245,79,107,0.12);
            color: #f54f6b;
            padding: 14px 16px;
            border-radius: 12px;
            text-align: center;
            margin: 20px 0;
            font-size: 14.5px;
        }

        .footer {
            text-align: center;
            margin-top: 32px;
            color: var(--sub);
            font-size: 13px;
        }
    </style>
</head>
<body>

    <!-- Animated Background -->
    <div class="bg-animation"></div>

    <div class="login-box">
        <div class="logo">T</div>
        <h1>Developer Portal</h1>
        <p class="subtitle">TidyTrack System Access</p>

        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                <input type="email" name="email" placeholder="developer@investhood.com" required 
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="input-group">
                <span class="input-icon"><i class="fas fa-lock"></i></span>
                <input type="password" name="password" placeholder="Enter Password" required>
            </div>

            <button type="submit" name="login">
                <i class="fas fa-sign-in-alt"></i> SIGN IN
            </button>
        </form>

        <div class="footer">
            Only approved developers can access this portal.<br>
            <small>TidyTrack Dev Portal • investh2</small>
        </div>
    </div>

</body>
</html>