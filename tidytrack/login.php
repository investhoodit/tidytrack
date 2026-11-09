<?php
session_start();
include 'dp.php';
include 'track2.php';

// Get user input
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Validation
$errors = [];
$fields = [];

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
}

if (empty($errors)) {
    $stmt = $conn->prepare("SELECT user_id, name, password, role, email_confirmed, isallowed, appeal FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();

        if (password_verify($password, $row['password'])) {

            // Check if email is confirmed
            if ($row['email_confirmed'] == 0) {
                $errors[] = "Account not confirmed. Please check your email for the activation link.";
                $fields[] = "email";
            }
            // Check if user is banned
            elseif ($row['isallowed'] == 0) {
                $_SESSION['ban_message'] = "Your account has been temporarily suspended due to missing daily reports (3+ times in a week).";
                if (!empty($row['appeal'])) {
                    $_SESSION['ban_message'] .= "<br><br><strong>Your Previous Appeal:</strong> " . htmlspecialchars($row['appeal']);
                }
                $_SESSION['user_id'] = $row['user_id'];   // For appeal submission
                header("Location: account_suspended.php");
                exit();
            }
            else {
                // Successful Login
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['name'] = $row['name'];
                $_SESSION['role'] = $row['role'];

                if (date('N') < 6) { // Not weekend
                    log_session_start($row['user_id']);
                }

                $stmt->close();
                $conn->close();
                header("Location: index.php");
                exit();
            }
        } else {
            $errors[] = "Incorrect password.";
            $fields[] = "password";
        }
    } else {
        $errors[] = "No account found with this email.";
        $fields[] = "email";
    }
    $stmt->close();
}

$conn->close();

// Redirect back with errors (for normal validation errors)
header("Location: signup.html?form=login&errors=" . urlencode(implode('|', $errors)) . "&fields=" . urlencode(implode('|', $fields)));
exit();
?>