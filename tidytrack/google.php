<?php
session_start();

$client_id = getenv('TIDYTRACK_GOOGLE_CLIENT_ID') ?: '';
$redirect_uri = getenv('TIDYTRACK_GOOGLE_REDIRECT_URI') ?: '';

if ($client_id === '' || $redirect_uri === '') {
    error_log('TidyTrack Google sign-in configuration is incomplete.');
    http_response_code(500);
    exit('Google sign-in configuration is incomplete.');
}
// *** IMPORTANT: The redirect URI must point to your main project's callback file.

// Scopes (what data we ask Google for)
$scopes = [
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile'
];

// 1. Build the Authorization URL
$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'scope' => implode(' ', $scopes),
    'access_type' => 'online', 
    'prompt' => 'select_account' 
]);

// Determine current user status for display
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $status_message = "Logged in as: " . htmlspecialchars($_SESSION['name']) . 
                     " (Role: " . htmlspecialchars($_SESSION['role']) . ")";
} else {
    $status_message = "Not currently logged in.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Sign-In | Tidy Track</title>
    <style>
        /* ========================================================= */
        /* BASE STYLES & LAYOUT */
        /* ========================================================= */
        body {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            padding: 50px 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        /* Google Color Bar at Top */
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #4285F4, #34a853, #fbbc05, #ea4335);
        }

        /* ========================================================= */
        /* TYPOGRAPHY & HEADER */
        /* ========================================================= */

        /* Main Heading (Used for Tidy Track Title) */
        h1 {
            color: #1a237e;
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0 0 10px 0;
            letter-spacing: -0.5px;
        }

        /* Sub-Heading (Used for Sign in with Google) */
        h2 {
            color: #4285F4;
            font-size: 1.4rem;
            margin: 20px 0 15px 0;
            font-weight: 600;
        }

        hr {
            border: none;
            border-top: 1px solid #e0e0e0; /* Slightly thinner border */
            margin: 25px 0;
            width: 80%;
            margin-left: auto;
            margin-right: auto;
        }

        p {
            color: #333;
            font-size: 1.1rem;
            margin-bottom: 15px;
            line-height: 1.6;
        }

        /* ========================================================= */
        /* LOGO STYLES (NEW) */
        /* ========================================================= */
        .logo-header {
            display: flex;
            align-items: center;
            justify-content: center; /* Center the logo block */
            gap: 10px;
            margin-bottom: 30px; /* Space above the main content */
        }
        .logo-header img {
            width: 45px; /* Slightly larger logo */
            height: 45px;
        }
        .logo-header span {
            font-weight: 800; /* Extra bold for visibility */
            font-size: 1.6rem;
            color: #1a237e; /* Matches the H1 color */
        }
        
        /* Ensure H1 styling is applied correctly */
        .logo-header + h2 {
            margin-top: 0; /* Remove extra space if logo is present */
        }

        /* ========================================================= */
        /* STATUS & INFO BOXES */
        /* ========================================================= */
        .status-message {
            font-weight: 500;
            color: #2e7d32;
            background: #e8f5e8;
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid #4caf50;
            margin-bottom: 20px;
            font-size: 1rem;
            text-align: left;
        }

        .info-box {
            background: #fff3e0;
            color: #e65100;
            border-left: 4px solid #ff9800;
            padding: 20px;
            margin: 25px 0;
            border-radius: 12px;
            font-size: 0.95rem;
            text-align: left;
            line-height: 1.6;
        }

        .info-box h3 {
            color: #e65100;
            font-weight: 600;
            font-size: 1.1rem;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-box ul {
            margin: 10px 0;
            padding-left: 20px;
            list-style-type: disc;
        }

        .info-box li {
            margin-bottom: 8px;
            color: #333;
        }

        /* ========================================================= */
        /* BUTTONS & LINKS */
        /* ========================================================= */
        .google-btn {
            background: linear-gradient(135deg, #4285F4 0%, #34a853 100%);
            color: white;
            padding: 16px 32px;
            font-size: 1.1rem;
            font-weight: 600;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(66, 133, 244, 0.3);
            margin: 20px 0;
            min-width: 200px;
        }

        .google-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(66, 133, 244, 0.4);
            background: linear-gradient(135deg, #357ae8 0%, #2e7d32 100%);
        }

        .google-btn::before {
            content: 'G';
            background: white;
            color: #4285F4;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }

        .google-link {
            text-decoration: none;
            display: block;
        }

        a {
            color: #4285F4;
            text-decoration: none;
            font-weight: 500;
        }

        a:hover {
            text-decoration: underline;
        }

        button {
            font-family: inherit;
            font-size: 1rem;
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            background: #dc3545;
            color: #fff;
            cursor: pointer;
            margin-top: 15px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        button:hover {
            background: #c82333;
            transform: translateY(-1px);
        }
        
        .email-link {
            color: #1976d2;
            text-decoration: none;
            font-weight: 500;
        }

        .email-link:hover {
            text-decoration: underline;
        }


        /* ========================================================= */
        /* MEDIA QUERIES */
        /* ========================================================= */
        @media (max-width: 600px) {
            .container {
                margin: 10px;
                padding: 30px 25px;
            }

            h1 {
                font-size: 2rem;
            }

            .google-btn {
                width: 100%;
                padding: 14px 20px;
            }
            
            .logo-header span {
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-header">
            <img src="images/Tidy Track Logomark- Website Scale.png" alt="Tidy Track Logo">
            <span>Tidy Track</span>
        </div>
        
        <div class="status-message">Status: <?php echo $status_message; ?></div>
        
        <hr>

        <?php if (!isset($_SESSION['logged_in'])): ?>
            <h2>Sign in with Google</h2>
            <p>Use your existing Google account for a fast and secure login experience.</p>
            
            <a href="<?php echo $auth_url; ?>" class="google-link">
                <button type="button" class="google-btn">
                    Sign In with Google
                </button>
            </a>
            
            <p><a href="login.php">Use email and password instead</a></p>

        <?php else: ?>
            <p>You are already logged in. <a href="index.php">Go to Dashboard</a></p>
            <a href="logout.php"><button>Logout</button></a>
        <?php endif; ?>

        <hr>
        
        <div class="info-box">
            <h3>&#9888; Troubleshooting & Help</h3>
            <p>If you encounter issues signing in or confirming your account, please try the following steps:</p>
            <ul>
                <li>**Clear Browser Cache and Cookies:** This often resolves unexpected login loops or session errors. Try signing in again after clearing.</li>
                <li>**Use an Incognito/Private Window:** Test the sign-in flow in a private browser window to ensure no extensions or stored data are interfering.</li>
                <li>**Ensure Email Confirmation:** If you just signed up, verify you have clicked the confirmation link sent to your inbox (check spam folder!).</li>
            </ul>
            <p>If problems persist, please contact our support team:</p>
            <p>Email: <a href="mailto:investhoodit@gmail.com" class="email-link">investhoodit@gmail.com</a></p>
        </div>
    </div>
</body>
</html>