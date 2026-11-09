<?php
session_start();

include '../dp.php';

// Check the submitted login form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Validate the submitted information.
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    $_SESSION['ba_login_error'] = 'Please enter a valid email and password.';
    header('Location: index.php');
    exit;
}

// Find the account in the existing users table.
$sql = "SELECT
            user_id,
            email,
            password,
            name,
            role,
            department,
            google_user
        FROM users
        WHERE email = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('BA Portal: Failed to prepare login query.');
    $_SESSION['ba_login_error'] = 'Something went wrong. Please try again.';
    header('Location: index.php');
    exit;
}

$stmt->bind_param('s', $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

// Reject unknown accounts and accounts without a password.
if (!$user || empty($user['password'])) {
    $_SESSION['ba_login_error'] = 'Invalid email or password.';
    header('Location: index.php');
    exit;
}

// Verify the password.
if (!password_verify($password, $user['password'])) {
    $_SESSION['ba_login_error'] = 'Invalid email or password.';
    header('Location: index.php');
    exit;
}

// Developer exception: password is still required.
$developerEmail = 'deandie17@gmail.com';

if (strcasecmp($user['email'], $developerEmail) === 0) {

    session_regenerate_id(true);

    $_SESSION['ba_authenticated'] = true;
    $_SESSION['ba_user_id'] = (int) $user['user_id'];
    $_SESSION['ba_email'] = $user['email'];
    $_SESSION['ba_name'] = $user['name'] ?? '';
    $_SESSION['ba_department'] = $user['department'] ?? '';
    $_SESSION['ba_developer'] = true;

    header('Location: dashboard.php');
    exit;
}

// Other users must belong to the BA Team.
if (strcasecmp(trim($user['department'] ?? ''), 'BA Team') !== 0) {
    $_SESSION['ba_login_error'] = 'You are not authorized to access the BA portal.';
    header('Location: index.php');
    exit;
}

// Authorized BA users proceed to email verification.
session_regenerate_id(true);

$_SESSION['ba_pending_user_id'] = (int) $user['user_id'];
$_SESSION['ba_pending_email'] = $user['email'];
$_SESSION['ba_pending_name'] = $user['name'] ?? '';
$_SESSION['ba_pending_started'] = time();

header('Location: verify.php');
exit;
?>