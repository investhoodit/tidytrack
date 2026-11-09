<?php
session_start();
include 'dp.php';

// Check if the required temporary session data is present. 
// If not, redirect to the main page (index.php) or google.php to restart the flow.
if (!isset($_SESSION['temp_email']) || !isset($_SESSION['temp_name']) || 
    !isset($_SESSION['google_user'])) {
    header("Location: google.php"); 
    exit();
}

$email = $_SESSION['temp_email'];
$name = $_SESSION['temp_name'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['role'])) {
    // Convert role to lowercase for consistent database storage/checking
    $role = strtolower($_POST['role'] ?? '');
    // $admin_code = $_POST['admin_code'] ?? '';
$access_code = trim($_POST['access_code'] ?? '');
    // Validate role against lowercase array, matching your ENUM
    $valid_roles = ['member', 'manager', 'leader', 'admin'];
    if (!in_array($role, $valid_roles)) {
        $error = "Please select a valid role.";
    // } elseif ($role === 'admin' && $admin_code !== '8f1c3b7e4a2f9d10c6a44e52d7abb8c1') { 
    //     $error = "Invalid admin access code.";
    // } else {
    
    } else {
    // ✅ Role access codes (ALL roles) - change these to your real codes
    $ROLE_CODES = [
        'member'  => 'MEMBER',
        'leader'  => 'LEADER2026',
        'manager' => 'MANAGER2026',
        'admin'   => '8f1c3b7e4a2f9d10c6a44e52d7abb8c1',
    ];

    if (empty($access_code)) {
        $error = "Access code is required.";
    } elseif (!isset($ROLE_CODES[$role]) || !hash_equals($ROLE_CODES[$role], $access_code)) {
        $error = "Invalid access code for selected role.";
    } else {
    
        // Register user into the 'users' table
        // google_user = 1 (True), password will be NULL, which is allowed by your schema
        $stmt = $conn->prepare("INSERT INTO users (name, email, role, google_user) VALUES (?, ?, ?, 1)");
        $stmt->bind_param("sss", $name, $email, $role);
        
        if ($stmt->execute()) {
            $user_id = $conn->insert_id;
            
            // --- Set final session variables for login ---
            $_SESSION['user_id'] = $user_id;
            $_SESSION['name'] = $name;
            $_SESSION['role'] = $role;
            $_SESSION['logged_in'] = true; 
            
            // Clear temporary session data
            unset($_SESSION['temp_email']);
            unset($_SESSION['temp_name']);
            unset($_SESSION['google_user']);

            $stmt->close();
            $conn->close();
            
            // *** CHANGE FOR TESTING: Redirect to google.php to see the Status change ***
            header("Location: index.php"); 
            exit();
        } else {
            // Database INSERT failed
            $error = "Registration failed (DB error): " . $conn->error;
            error_log("Database INSERT failed for email {$email}: " . $conn->error);
            $stmt->close();
        }
    }
}
} // ✅ closes access code validation success block
// Close connection if POST request fails or is GET request
if (isset($conn)) $conn->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Google Sign Up | Tidy Tracker</title>
    <style>
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

        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #4285F4, #34a853, #fbbc05, #ea4335);
        }

        h1 {
            color: #1a237e;
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0 0 10px 0;
            letter-spacing: -0.5px;
        }

        h2 {
            color: #4285F4;
            font-size: 1.4rem;
            margin: 20px 0 15px 0;
            font-weight: 600;
        }

        hr {
            border: none;
            border-top: 2px solid #e0e0e0;
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

        /* Status message styling (used for success/info by default) */
        .status-message {
            font-weight: 500;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 1rem;
            text-align: left;
            
            /* Default to success style if no error class is applied */
            color: #2e7d32;
            background: #e8f5e8;
            border-left: 4px solid #4caf50;
        }
        
        /* Specific style for PHP error message */
        .error-message {
            color: #b71c1c; /* Dark Red */
            background: #ffebee; /* Light Red */
            border-left: 4px solid #f44336; /* Red */
        }
        
        /* --- Form Specific Styles --- */

        .form-container {
            padding: 20px 0;
        }

        .form-container select,
        .form-container input[type="password"] {
            width: 100%;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1rem;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
            box-sizing: border-box;
            transition: border-color 0.3s;
            -webkit-appearance: none; /* Hide default dropdown arrow on WebKit browsers */
            -moz-appearance: none;    /* Hide default dropdown arrow on Firefox */
            appearance: none;         /* Hide default dropdown arrow */
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%234285F4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-chevron-down"><polyline points="6 9 12 15 18 9"></polyline></svg>') no-repeat right 15px center;
            background-size: 16px;
        }

        .form-container select:focus,
        .form-container input[type="password"]:focus {
            border-color: #4285F4;
            outline: none;
        }

        /* Styling for the submit button */
        .form-container button[type="submit"] {
            font-family: inherit;
            font-size: 1.1rem;
            padding: 15px;
            border-radius: 8px;
            border: none;
            background: #457b9d; /* A professional blue */
            color: #fff;
            cursor: pointer;
            margin-top: 0;
            transition: all 0.3s ease;
            font-weight: 600;
            width: 100%;
        }

        .form-container button[type="submit"]:hover {
            background: #3c6a85;
            transform: translateY(-1px);
        }
        
        /* --- General Link/Info Box Styles (copied from google.php) --- */

        a {
            color: #4285F4;
            text-decoration: none;
            font-weight: 500;
        }

        a:hover {
            text-decoration: underline;
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
        
        @media (max-width: 600px) {
            .container {
                margin: 10px;
                padding: 30px 25px;
            }

            h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Welcome, <?php echo htmlspecialchars($name); ?>!</h1>
        
        <p>You are signing up with the email: <strong><?php echo htmlspecialchars($email); ?></strong></p>
        
        <hr>

        <h2>Complete Sign Up</h2>
        <p>Please select your role to finalize your InvesthoodIT account creation.</p>
        
        <?php if ($error): ?>
            <!-- Apply error-message class for red/error styling -->
            <p class="status-message error-message"><?php echo htmlspecialchars($error); ?></p> 
        <?php endif; ?>

        <div class="form-container">
            <form method="POST">
                <select name="role" id="roleInput" required>
                    <option value="" disabled selected>Select Role</option>
                    <option value="member">👥 Member</option>
                    <option value="leader">📈 Leader</option>
                    <option value="manager">💼 Manager</option>
                    <option value="admin">🛡️ Admin</option>
                </select>
                <!--<input type="password" name="admin_code" id="adminCode" placeholder="Admin access code" style="display:none;">-->
                
                <input type="password" name="access_code" id="accessCode" placeholder="Access code" style="display:none;">
                
                <button type="submit">Continue</button>
            </form>
        </div>
        
        <!-- Troubleshooting Section - Adapted for role selection context -->
        <div class="info-box">
            <h3>&#9888; Role Selection Notes</h3>
            <p>If you are unsure of your role, please select **Member**. Roles can often be updated later by a Manager or Admin.</p>
            <ul>
                <li>**Admin:** Requires a specific access code for verification.</li>
                <li>**Manager/Leader:** Roles designed for team organization and elevated permissions.</li>
                <li>**Member:** Standard user access.</li>
            </ul>
        </div>
    </div>
<script>
    // ... (Your JavaScript for dynamic admin code field) ...
    const roleInput = document.getElementById('roleInput');
    // const adminCode = document.getElementById('adminCode');
    const accessCode = document.getElementById('accessCode');

    // roleInput.addEventListener('change', () => {
    //     // Check for lowercase 'admin' value
    //     if (roleInput.value === 'admin') { 
    //         adminCode.style.display = 'block';
    //         adminCode.required = true;
    //     } else {
    //         adminCode.style.display = 'none';
    //         adminCode.required = false;
    //         adminCode.value = '';
    //     }
    // });
    
    roleInput.addEventListener('change', () => {
    // Show for ANY selected role
    if (roleInput.value) {
        accessCode.style.display = 'block';
        accessCode.required = true;
    } else {
        accessCode.style.display = 'none';
        accessCode.required = false;
        accessCode.value = '';
    }
});
</script>
</body>
</html>