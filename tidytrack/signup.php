<?php
session_start();
include 'dp.php';
include 'track2.php';

// --- PHPMailer Includes and Configuration ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


// (The core PHPMailer files are typically inside the 'src' folder)
require 'PHPMailer-master/PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/PHPMailer-master/src/SMTP.php';

$smtp_username = getenv('TIDYTRACK_SMTP_USERNAME') ?: '';
$smtp_password = getenv('TIDYTRACK_SMTP_PASSWORD') ?: '';

/**
 * Sends the confirmation email to the user.
 */
function sendConfirmationEmail($to, $name, $token, $username, $password) {
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; 
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587; 
        $mail->CharSet = 'UTF-8';

        // Recipients and Content
        $mail->setFrom('no-reply@investhoodit.com', 'InvesthoodIT');
        $mail->addAddress($to, $name);
        $mail->isHTML(true);
        $mail->Subject = 'Confirm Your InvesthoodIT Account';
        
        // Link to the confirm.php script (ensure this path is correct for your local setup)
        $confirmation_link = "https://investhoodprojects.com/tidytracker/tidytrack/confirm.php?token=" . $token;

        $mail->Body = "
            <html>
            <body style='font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; border-radius: 8px;'>
                <div style='max-width: 600px; margin: auto; background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.05);'>
                    <h2 style='color: #1d3557;'>Welcome to InvesthoodIT, " . htmlspecialchars($name) . "!</h2>
                    <p style='color: #333;'>Thank you for signing up. To complete your registration and activate your account, please click the button below:</p>
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='" . $confirmation_link . "' style='background-color: #457b9d; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px; display: inline-block;'>
                            Confirm Account Now
                        </a>
                    </p>
                    <p style='color: #888; font-size: 12px;'>If the button doesn't work, copy and paste this link into your browser:<br>
                    <a href='{$confirmation_link}' style='word-break: break-all;'>{$confirmation_link}</a></p>
                    <p style='color: #888; font-size: 14px; margin-top: 40px;'>If you did not sign up for this account, please ignore this email.</p>
                </div>
            </body>
            </html>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error ({$to}): " . $mail->ErrorInfo);
        return false;
    }
}


// --- Form Processing ---
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$role = isset($_POST['role']) ? trim($_POST['role']) : '';
// $admin_code = isset($_POST['admin_code']) ? trim($_POST['admin_code']) : '';
$access_code = isset($_POST['access_code']) ? trim($_POST['access_code']) : '';

// Validate required fields
$errors = [];
$fields = [];
if (empty($name)) {
    $errors[] = "Name is required.";
    $fields[] = "name";
}
if (empty($email)) {
    $errors[] = "Email is required.";
    $fields[] = "email";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Please enter a valid email address.";
    $fields[] = "email";
}
if (empty($password)) {
    $errors[] = "Password is required.";
    $fields[] = "password";
} elseif (strlen($password) < 6) {
    $errors[] = "Password must be at least 6 characters.";
    $fields[] = "password";
}

$role_lower = strtolower($role);
$valid_roles = ['member', 'leader', 'manager', 'admin'];
if (empty($role) || !in_array($role_lower, $valid_roles)) {
    $errors[] = "Please select a valid role.";
    $fields[] = "role";
}
// if ($role_lower === 'admin' && $admin_code !== '8f1c3b7e4a2f9d10c6a44e52d7abb8c1') {
//     $errors[] = "Invalid admin access code.";
//     $fields[] = "admin_code";
// }


// ✅ Role access code validation (ALL roles)
$ROLE_CODES = [
    'member'  => 'MEMBER',
    'leader'  => 'LEADER2026',
    'manager' => 'MANAGER2026',
    'admin'   => 'GET https://investhoodprojects.com/tidytracker/tidytrack/theme.css net::ERR_ABORTED 404 (Not Found)Understand this error
signup.html:545 Uncaught ReferenceError: adminCode is not defined
    at openForm (signup.html:545:7)
    at HTMLButtonElement.onclick (signup.html:454:59)
openForm @ signup.html:545
onclick @ signup.html:454Understand this error
signup.html:545 Uncaught ReferenceError: adminCode is not defined
    at openForm (signup.html:545:7)
    at HTMLButtonElement.onclick (signup.html:455:60)',
];

if (!isset($ROLE_CODES[$role_lower])) {
    $errors[] = "Please select a valid role.";
    $fields[] = "role";
} else {
    if (empty($access_code)) {
        $errors[] = "Access code is required.";
        $fields[] = "access_code";
    } elseif ($access_code !== $ROLE_CODES[$role_lower]) {
        $errors[] = "Invalid access code for selected role.";
        $fields[] = "access_code";
    }
}

// Check if email already exists
if (empty($errors)) {
    $stmt = $conn->prepare("SELECT user_id, email_confirmed FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        if ($user_data['email_confirmed'] == 0) {
            $errors[] = "This email is registered but not confirmed. Check your email for the confirmation link.";
        } else {
            $errors[] = "This email is already registered. Please log in or use a different email.";
        }
        $fields[] = "email";
    }
    $stmt->close();
}


// Process if no errors
if (empty($errors)) {
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $status = 'offline';
    $attendance = 'No';
    $google_user = 0;
    
    // --- NEW: Email confirmation fields ---
    $email_confirmed = 0; // Starts unconfirmed
    $confirmation_token = bin2hex(random_bytes(32)); // Generate a unique token
    
    // Updated INSERT statement to include the new columns
    $stmt = $conn->prepare("
        INSERT INTO users (name, email, password, role, status, attendance, google_user, email_confirmed, confirmation_token)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    // Bind parameters: 6 's' strings, 2 'i' integers, 1 's' string (9 characters for 9 variables)
    // FIX APPLIED: Changed "ssssssis" to "ssssssiis" to match 9 variables.
    $stmt->bind_param("ssssssiis", 
        $name, 
        $email, 
        $hashedPassword, 
        $role, 
        $status, 
        $attendance, 
        $google_user, 
        $email_confirmed, 
        $confirmation_token 
    );

    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;

        // --- NEW: Attempt to send email instead of logging in ---
        if (sendConfirmationEmail($email, $name, $confirmation_token, $smtp_username, $smtp_password)) {
            $stmt->close();
            $conn->close();
            // Redirect to the page informing the user to check their inbox
            header("Location: email-sent.php?email=" . urlencode($email));
            exit();
        } else {
            // Email failed to send: Rollback the database registration (DELETE the row)
            $conn->query("DELETE FROM users WHERE user_id = '$user_id'");
            $errors[] = "Registration succeeded, but failed to send confirmation email. Please contact support.";
            error_log("Failed to send email to $email. User record was deleted.");
        }

    } else {
        $errors[] = "Registration failed. Database execution error: " . $stmt->error;
        error_log("DB INSERT failed: " . $stmt->error);
    }
    $stmt->close();
}

// Redirect back with errors if any part of the process failed
$conn->close();
header("Location: signup.html?form=signup&errors=" . urlencode(implode('|', $errors)) . "&fields=" . urlencode(implode('|', $fields)));
exit();
