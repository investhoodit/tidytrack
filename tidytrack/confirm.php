<?php
session_start();
include 'dp.php';

$message = "Invalid or expired confirmation link.";
$success = false;

if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];

    // Find the user with the matching token AND ensure the email is not already confirmed
    $stmt = $conn->prepare("SELECT user_id, name, role FROM users WHERE confirmation_token = ? AND email_confirmed = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $user_id = $user['user_id'];
        $user_name = $user['name'];
        $user_role = $user['role'];

        // 1. Update the user record: set email_confirmed = 1 and clear the token
        $update_stmt = $conn->prepare("UPDATE users SET email_confirmed = 1, confirmation_token = NULL WHERE user_id = ?");
        $update_stmt->bind_param("i", $user_id);
        
        if ($update_stmt->execute()) {
            $message = "Success! Your account has been activated.";
            $success = true;

            // 2. Automatically log the user in after successful confirmation
            $_SESSION['user_id'] = $user_id;
            $_SESSION['name'] = $user_name;
            $_SESSION['role'] = $user_role; 
            $_SESSION['logged_in'] = true; 
            
            // Redirect to dashboard after a short delay
            header("Refresh: 3; url=index.php"); 
        } else {
            $message = "Account activation failed due to a database error.";
            error_log("DB UPDATE failed in confirm.php: " . $conn->error);
        }
        $update_stmt->close();
        
    } else {
        // If no rows found, check if the token belongs to an ALREADY confirmed user
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE confirmation_token = ?");
        $check_stmt->bind_param("s", $token);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            $message = "This account is already confirmed. You can now log in.";
        } else {
            $message = "Invalid or expired confirmation link.";
        }
        $check_stmt->close();
    }
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Account Confirmation</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Poppins',sans-serif;
            background:#eef2f5;
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:1.5rem;
        }
        .card {
            background:#fff;
            border-radius:18px;
            padding:2.5rem 2rem;
            max-width:420px;
            width:100%;
            text-align:center;
            box-shadow:0 10px 30px rgba(0,0,0,0.08);
        }
        .icon {
            width:64px; height:64px;
            border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:1.6rem;
            margin:0 auto 1.2rem;
            color:#fff;
            background:<?php echo $success ? '#27ae60' : '#e63946'; ?>;
        }
        h1 { color:#1d3557; font-size:1.5rem; margin-bottom:.8rem; }
        p { color:#555; font-size:.9rem; line-height:1.6; margin-bottom:.8rem; }
        .redirect-note { color:#457b9d; font-size:.8rem; }
        a.btn {
            display:inline-block;
            margin-top:1.2rem;
            background:#1d3557;
            color:#fff;
            text-decoration:none;
            padding:.8rem 1.6rem;
            border-radius:8px;
            font-weight:600;
            font-size:.9rem;
        }
        a.btn:hover { background:#2a4b7c; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <i class="fas <?php echo $success ? 'fa-check' : 'fa-times'; ?>"></i>
        </div>
        <h1>Account Activation Status</h1>
        <p><?php echo htmlspecialchars($message); ?></p>
        <?php if ($success): ?>
            <p class="redirect-note">You are being redirected to the dashboard in 3 seconds...</p>
        <?php endif; ?>
        <a class="btn" href="index.php">Go to Dashboard/Login</a>
    </div>
</body>
</html>