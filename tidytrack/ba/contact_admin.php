<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Validate the submitted form.
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$developer = $_POST['developer'] ?? '';
$message = trim($_POST['message'] ?? '');

if (
    $name === '' ||
    strlen($name) > 100 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    strlen($email) > 254 ||
    $developer !== 'endy' ||
    $message === '' ||
    strlen($message) > 2000
) {
    http_response_code(400);
    exit('Please complete the form correctly and try again.');
}

// Available developer contacts.
// Only predefined recipients are allowed.
$developers = [
    'endy' => [
        'name' => 'Endy Fulufhelo',
        'email' => 'deandie17@gmail.com'
    ]
];

$recipient = $developers[$developer]['email'];

$subject = 'BA Portal - Access Assistance Request';

$body = "A user has requested assistance with the BA Portal.\n\n";
$body .= "Name: " . $name . "\n";
$body .= "Email: " . $email . "\n";
$body .= "Developer: " . $developers[$developer]['name'] . "\n\n";
$body .= "Message:\n" . $message . "\n";

$headers = [
    'From' => 'noreply@investhoodprojects.com',
    'Reply-To' => $email,
    'Content-Type' => 'text/plain; charset=UTF-8'
];

if (mail($recipient, $subject, $body, $headers)) {
    header('Location: contact_success.php');
    exit;
}

error_log('BA Portal: Failed to send access assistance email.');

http_response_code(500);
echo 'Your message could not be sent right now. Please try again later.';