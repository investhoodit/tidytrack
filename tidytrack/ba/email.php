<?php
session_start();
require_once '../dp.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (empty($_SESSION['ba_authenticated'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['ba_user_id'])) {
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| EMAIL CONFIGURATION
|--------------------------------------------------------------------------
| Fill these values in when you are ready to configure Gmail.
| Never publish your App Password or commit it to GitHub.
*/

$smtpHost = 'smtp.gmail.com';
$smtpPort = 587;

$smtpUsername = '';       // Your company Gmail address
$smtpPassword = '';       // Your Gmail App Password

$senderEmail = '';        // Same company Gmail address
$senderName = 'TidyTrack BA Team';

/*
|--------------------------------------------------------------------------
| LOAD PHPMailer
|--------------------------------------------------------------------------
*/

$autoload = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
}

/*
|--------------------------------------------------------------------------
| VERIFY BA TEAM MEMBERSHIP
|--------------------------------------------------------------------------
*/

$currentUserId = (int) $_SESSION['ba_user_id'];

$authStmt = $conn->prepare("
    SELECT user_id, name, email, department
    FROM users
    WHERE user_id = ?
      AND LOWER(TRIM(department)) = 'ba team'
    LIMIT 1
");

$authStmt->bind_param('i', $currentUserId);
$authStmt->execute();

$currentUser = $authStmt->get_result()->fetch_assoc();

$authStmt->close();

if (!$currentUser) {
    session_destroy();
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function emailEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function emailRedirect($message, $type = 'success')
{
    $_SESSION['email_flash'] = [
        'message' => $message,
        'type' => $type
    ];

    header('Location: email.php');
    exit;
}

function getEmailUsers($conn)
{
    $users = [];

    $result = $conn->query("
        SELECT user_id, name, email, department, role
        FROM users
        WHERE email IS NOT NULL
          AND TRIM(email) <> ''
        ORDER BY department ASC, name ASC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }

    return $users;
}

/*
|--------------------------------------------------------------------------
| LOAD USERS AND TEMPLATES
|--------------------------------------------------------------------------
*/

$users = getEmailUsers($conn);

$templates = [];

$templateResult = $conn->query("
    SELECT template_id, template_name, subject, message_body
    FROM ba_email_templates
    ORDER BY template_name ASC
");

if ($templateResult) {
    while ($row = $templateResult->fetch_assoc()) {
        $templates[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE FORM SUBMISSIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['email_csrf_token'] ?? '';

    if (
        !$sessionToken ||
        !hash_equals($sessionToken, $postedToken)
    ) {
        emailRedirect(
            'Your session token expired. Please try again.',
            'error'
        );
    }

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | SAVE EMAIL TEMPLATE
    |--------------------------------------------------------------------------
    */

    if ($action === 'save_template') {

        $templateName = trim($_POST['template_name'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $messageBody = trim($_POST['message_body'] ?? '');

        if (
            $templateName === '' ||
            $subject === '' ||
            $messageBody === ''
        ) {
            emailRedirect(
                'Enter a template name, subject and message.',
                'error'
            );
        }

        if (
            strlen($templateName) > 150 ||
            strlen($subject) > 255
        ) {
            emailRedirect(
                'The template name or subject is too long.',
                'error'
            );
        }

        $stmt = $conn->prepare("
            INSERT INTO ba_email_templates
                (template_name, subject, message_body, created_by)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'sssi',
            $templateName,
            $subject,
            $messageBody,
            $currentUserId
        );

        if ($stmt->execute()) {
            $stmt->close();

            emailRedirect('Email template saved successfully.');
        }

        $stmt->close();

        emailRedirect('Could not save the email template.', 'error');
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE EMAIL TEMPLATE
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete_template') {

        $templateId = (int) ($_POST['template_id'] ?? 0);

        $stmt = $conn->prepare("
            DELETE FROM ba_email_templates
            WHERE template_id = ?
        ");

        $stmt->bind_param('i', $templateId);
        $stmt->execute();

        $deleted = $stmt->affected_rows > 0;

        $stmt->close();

        emailRedirect(
            $deleted
                ? 'Template deleted.'
                : 'Template was not found.',
            $deleted ? 'success' : 'error'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SEND EMAIL
    |--------------------------------------------------------------------------
    */

    if ($action === 'send_email') {

        if (
            !class_exists(PHPMailer::class) ||
            !class_exists(Exception::class)
        ) {
            emailRedirect(
                'PHPMailer is not installed. Install it using Composer first.',
                'error'
            );
        }

        if (
            $smtpUsername === '' ||
            $smtpPassword === '' ||
            $senderEmail === ''
        ) {
            emailRedirect(
                'Gmail SMTP is not configured yet. Add your company Gmail address and App Password in email.php.',
                'error'
            );
        }

        $subject = trim($_POST['subject'] ?? '');
        $messageBody = trim($_POST['message_body'] ?? '');
        $recipientMode = $_POST['recipient_mode'] ?? 'selected';

        if ($subject === '' || $messageBody === '') {
            emailRedirect(
                'Enter an email subject and message.',
                'error'
            );
        }

        if (strlen($subject) > 255) {
            emailRedirect(
                'The email subject cannot exceed 255 characters.',
                'error'
            );
        }

        /*
         * Build recipient list from the database.
         * The browser does not supply recipient email addresses.
         */

        $availableUsers = [];

        foreach ($users as $user) {
            $id = (int) $user['user_id'];

            if (filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                $availableUsers[$id] = $user;
            }
        }

        $recipientIds = [];

        if ($recipientMode === 'all') {

            $recipientIds = array_keys($availableUsers);

        } elseif ($recipientMode === 'department') {

            $department = trim($_POST['department'] ?? '');

            if ($department === '') {
                emailRedirect(
                    'Select a department.',
                    'error'
                );
            }

            foreach ($availableUsers as $id => $user) {
                if (
                    strcasecmp(
                        trim($user['department'] ?? ''),
                        $department
                    ) === 0
                ) {
                    $recipientIds[] = $id;
                }
            }

        } else {

            $selectedIds = $_POST['recipients'] ?? [];

            if (!is_array($selectedIds)) {
                emailRedirect(
                    'Invalid recipient selection.',
                    'error'
                );
            }

            foreach ($selectedIds as $id) {
                $id = filter_var($id, FILTER_VALIDATE_INT);

                if ($id !== false && isset($availableUsers[$id])) {
                    $recipientIds[] = (int) $id;
                }
            }
        }

        $recipientIds = array_values(
            array_unique($recipientIds)
        );

        /*
         * Prevent accidentally sending an email to nobody.
         */

        if (empty($recipientIds)) {
            emailRedirect(
                'Select at least one valid recipient.',
                'error'
            );
        }

        /*
         * Protect against accidental very large sends.
         * Increase this limit deliberately if necessary.
         */

        if (count($recipientIds) > 200) {
            emailRedirect(
                'You can send to a maximum of 200 recipients at once.',
                'error'
            );
        }

        /*
         * Store recipient information for the email history.
         */

        $recipientDetails = [];

        foreach ($recipientIds as $id) {
            $user = $availableUsers[$id];

            $recipientDetails[] = [
                'user_id' => $id,
                'name' => $user['name'],
                'email' => $user['email']
            ];
        }

        $recipientJson = json_encode(
            $recipientDetails,
            JSON_UNESCAPED_UNICODE
        );

        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        /*
         * Send a separate email to each recipient.
         * Recipients will not see one another's email addresses.
         */

        foreach ($recipientDetails as $recipient) {

            $mail = new PHPMailer(true);

            try {

                $mail->isSMTP();

                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUsername;
                $mail->Password = $smtpPassword;

                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $smtpPort;

                $mail->CharSet = 'UTF-8';

                $mail->setFrom(
                    $senderEmail,
                    $senderName
                );

                $mail->addAddress(
                    $recipient['email'],
                    $recipient['name']
                );

                $mail->isHTML(true);

                $mail->Subject = $subject;

                /*
                 * Escape the submitted message before inserting it
                 * into HTML. Line breaks are converted to <br>.
                 */

                $safeMessage = nl2br(
                    htmlspecialchars(
                        $messageBody,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                );

                $mail->Body = '
                    <div style="
                        font-family:Arial,sans-serif;
                        color:#172b3a;
                        line-height:1.7;
                        font-size:15px;
                    ">
                        ' . $safeMessage . '
                        <br><br>
                        <p style="color:#64748b;font-size:13px;">
                            Sent by the TidyTrack BA Team.
                        </p>
                    </div>
                ';

                $mail->AltBody = $messageBody;

                $mail->send();

                $successCount++;

            } catch (Exception $e) {

                $failedCount++;

                /*
                 * Do not show SMTP credentials or detailed
                 * server errors to ordinary page visitors.
                 */

                error_log(
                    'BA email sending failed for recipient ID '
                    . $recipient['user_id']
                    . ': '
                    . $mail->ErrorInfo
                );

                $errors[] = $recipient['email'];
            }
        }

        /*
         * Record the sending attempt in the email history.
         */

        if ($successCount === count($recipientDetails)) {
            $status = 'Sent';
        } elseif ($successCount > 0) {
            $status = 'Partial';
        } else {
            $status = 'Failed';
        }

        $errorMessage = null;

        if ($failedCount > 0) {
            $errorMessage = 'Failed recipient addresses: '
                . implode(', ', $errors);
        }

        $recipientCount = $successCount;

        $stmt = $conn->prepare("
            INSERT INTO ba_email_logs
                (
                    sender_id,
                    subject,
                    recipients_json,
                    recipient_count,
                    message_body,
                    status,
                    error_message
                )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'ississs',
            $currentUserId,
            $subject,
            $recipientJson,
            $recipientCount,
            $messageBody,
            $status,
            $errorMessage
        );

        $stmt->execute();
        $stmt->close();

        if ($status === 'Sent') {
            emailRedirect(
                'Email sent successfully to '
                . $successCount
                . ' recipient(s).'
            );
        }

        if ($status === 'Partial') {
            emailRedirect(
                $successCount
                . ' email(s) sent. '
                . $failedCount
                . ' failed. Check Email History.',
                'error'
            );
        }

        emailRedirect(
            'All emails failed. Check the PHP error log and Gmail SMTP settings.',
            'error'
        );
    }
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['email_csrf_token'])) {
    $_SESSION['email_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['email_csrf_token'];

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['email_flash'] ?? null;
unset($_SESSION['email_flash']);

/*
|--------------------------------------------------------------------------
| LOAD EMAIL HISTORY
|--------------------------------------------------------------------------
*/

$emailHistory = [];

$historyResult = $conn->query("
    SELECT
        l.*,
        u.name AS sender_name
    FROM ba_email_logs l
    LEFT JOIN users u ON u.user_id = l.sender_id
    ORDER BY l.sent_at DESC
    LIMIT 100
");

if ($historyResult) {
    while ($row = $historyResult->fetch_assoc()) {
        $emailHistory[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| LOAD DEPARTMENTS
|--------------------------------------------------------------------------
*/

$departments = [];

foreach ($users as $user) {
    $department = trim($user['department'] ?? '');

    if ($department !== '') {
        $departments[$department] = $department;
    }
}

natcasesort($departments);

/*
|--------------------------------------------------------------------------
| EMAIL STATISTICS
|--------------------------------------------------------------------------
*/

$totalEmails = 0;
$successfulEmails = 0;
$failedEmails = 0;

$statsResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'Sent'), 0) AS successful,
        COALESCE(SUM(status = 'Failed'), 0) AS failed
    FROM ba_email_logs
");

if ($statsResult) {
    $stats = $statsResult->fetch_assoc();

    $totalEmails = (int) $stats['total'];
    $successfulEmails = (int) $stats['successful'];
    $failedEmails = (int) $stats['failed'];
}

$totalUsers = count($users);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Email Centre | TidyTrack BA Portal</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<style>
:root {
    --primary: #0d9488;
    --primary-dark: #0f766e;
    --background: #f8fafc;
    --text: #172b3a;
    --muted: #64748b;
    --border: #e2e8f0;
    --white: #ffffff;
    --success: #15803d;
    --danger: #dc2626;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: 'DM Sans', sans-serif;
    background: var(--background);
    color: var(--text);
}

.topbar {
    min-height: 76px;
    padding: 14px 6%;
    background: var(--white);
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 19px;
    font-weight: 700;
}

.brand img {
    width: 40px;
    height: 40px;
    object-fit: contain;
}

.brand small {
    display: block;
    font-size: 11px;
    font-weight: 500;
    color: var(--muted);
}

.back-button {
    text-decoration: none;
    color: var(--text);
    border: 1px solid var(--border);
    padding: 10px 15px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 600;
}

.page {
    max-width: 1250px;
    margin: auto;
    padding: 35px 6%;
}

.breadcrumb {
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 20px;
}

.breadcrumb a {
    color: var(--primary);
    text-decoration: none;
}

.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

h1 {
    font-size: 28px;
    margin: 0 0 8px;
}

h2 {
    font-size: 18px;
    margin: 0;
}

p {
    color: var(--muted);
}

.page-heading p {
    margin: 0;
    font-size: 14px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 25px;
}

.stat-card,
.card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 13px;
}

.stat-card {
    padding: 20px;
}

.stat-card span {
    color: var(--muted);
    font-size: 13px;
}

.stat-card strong {
    display: block;
    font-size: 27px;
    margin-top: 10px;
}

.card {
    padding: 24px;
    margin-bottom: 24px;
}

.card-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.card-heading p {
    font-size: 13px;
    margin: 6px 0 0;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.field {
    margin-bottom: 18px;
}

.field label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 8px;
}

input[type="text"],
input[type="search"],
select,
textarea {
    width: 100%;
    border: 1px solid var(--border);
    background: white;
    padding: 12px 13px;
    border-radius: 8px;
    font: inherit;
    font-size: 14px;
    color: var(--text);
}

textarea {
    min-height: 180px;
    resize: vertical;
    line-height: 1.6;
}

input:focus,
select:focus,
textarea:focus {
    outline: 2px solid #99f6e4;
    border-color: var(--primary);
}

.recipient-panel {
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
}

.recipient-toolbar {
    display: flex;
    gap: 12px;
    padding: 13px;
    background: #f8fafc;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
}

.recipient-toolbar input {
    flex: 1;
    min-width: 180px;
}

.recipient-list {
    max-height: 290px;
    overflow-y: auto;
}

.recipient {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 15px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
}

.recipient:last-child {
    border-bottom: 0;
}

.recipient:hover {
    background: #f8fafc;
}

.recipient input {
    width: 16px;
    height: 16px;
    accent-color: var(--primary);
}

.recipient-info {
    min-width: 0;
    flex: 1;
}

.recipient-info strong {
    display: block;
    font-size: 13px;
}

.recipient-info span {
    display: block;
    color: var(--muted);
    font-size: 12px;
    overflow-wrap: anywhere;
    margin-top: 3px;
}

.badge {
    display: inline-block;
    border-radius: 20px;
    padding: 5px 9px;
    font-size: 11px;
    font-weight: 700;
    background: #f1f5f9;
    color: #475569;
}

.badge.sent {
    color: #166534;
    background: #dcfce7;
}

.badge.partial {
    color: #92400e;
    background: #fef3c7;
}

.badge.failed {
    color: #991b1b;
    background: #fee2e2;
}

.button {
    border: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    border-radius: 8px;
    padding: 11px 16px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}

.button-primary {
    color: white;
    background: var(--primary);
}

.button-primary:hover {
    background: var(--primary-dark);
}

.button-secondary {
    color: var(--text);
    background: white;
    border: 1px solid var(--border);
}

.button-danger {
    background: #fee2e2;
    color: #991b1b;
}

.button-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 20px;
}

.flash {
    border-radius: 9px;
    padding: 14px 16px;
    margin-bottom: 20px;
    font-size: 13px;
}

.flash.success {
    background: #dcfce7;
    color: #166534;
}

.flash.error {
    background: #fee2e2;
    color: #991b1b;
}

.selection-count {
    font-size: 12px;
    color: var(--muted);
    margin-left: auto;
    align-self: center;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th, td {
    text-align: left;
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    vertical-align: top;
}

th {
    background: #f8fafc;
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

td strong {
    display: block;
    margin-bottom: 5px;
}

td small {
    color: var(--muted);
}

.template-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.template-item {
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 15px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.template-item strong {
    display: block;
    font-size: 13px;
    margin-bottom: 5px;
}

.template-item p {
    font-size: 12px;
    margin: 0;
    overflow-wrap: anywhere;
}

.empty {
    text-align: center;
    padding: 30px 15px;
    color: var(--muted);
    font-size: 13px;
}

footer {
    text-align: center;
    padding: 25px;
    color: var(--muted);
    font-size: 12px;
}

@media (max-width: 700px) {
    .page {
        padding: 25px 4%;
    }

    .topbar {
        padding: 14px 4%;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .form-grid,
    .template-list {
        grid-template-columns: 1fr;
    }

    .card {
        padding: 17px;
    }

    h1 {
        font-size: 24px;
    }
}
</style>
</head>

<body>

<header class="topbar">
    <div class="brand">
        <img
            src="assets/images/logo.png"
            alt="TidyTrack"
            onerror="this.style.display='none'"
        >
        <div>
            TidyTrack
            <small>Business Analyst Portal</small>
        </div>
    </div>

    <a href="dashboard.php" class="back-button">
        <i class="fa-solid fa-arrow-left"></i>
        Back to Dashboard
    </a>
</header>

<main class="page">

    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        &nbsp; / &nbsp; Email Centre
    </div>

    <div class="page-heading">
        <div>
            <h1>Email Centre</h1>
            <p>Send organised emails to individuals, teams or multiple users.</p>
        </div>

        <span class="badge">
            <i class="fa-solid fa-users"></i>
            <?= $totalUsers ?> registered users
        </span>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= emailEscape($flash['type']) ?>">
            <?= emailEscape($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat-card">
            <span>Total emails sent or attempted</span>
            <strong><?= $totalEmails ?></strong>
        </div>

        <div class="stat-card">
            <span>Fully sent campaigns</span>
            <strong><?= $successfulEmails ?></strong>
        </div>

        <div class="stat-card">
            <span>Failed campaigns</span>
            <strong><?= $failedEmails ?></strong>
        </div>
    </section>

    <!-- COMPOSE EMAIL -->

    <section class="card" id="compose">
        <div class="card-heading">
            <div>
                <h2>
                    <i class="fa-regular fa-envelope"></i>
                    Compose Email
                </h2>
                <p>Choose recipients and prepare your message.</p>
            </div>
        </div>

        <form method="POST" id="emailForm">
            <input type="hidden" name="csrf_token"
                   value="<?= emailEscape($csrfToken) ?>">

            <input type="hidden" name="action" value="send_email">

            <div class="field">
                <label for="recipient_mode">Recipient Type</label>

                <select name="recipient_mode" id="recipient_mode">
                    <option value="selected">Select one or more users</option>
                    <option value="department">Email a department</option>
                    <option value="all">All registered users</option>
                </select>
            </div>

            <div class="field" id="departmentField" hidden>
                <label for="department">Choose Department</label>

                <select name="department" id="department">
                    <option value="">Select department</option>

                    <?php foreach ($departments as $department): ?>
                        <option value="<?= emailEscape($department) ?>">
                            <?= emailEscape($department) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field" id="recipientField">
                <label>Recipients</label>

                <div class="recipient-panel">
                    <div class="recipient-toolbar">
                        <input
                            type="search"
                            id="recipientSearch"
                            placeholder="Search by name, email or department..."
                        >

                        <button
                            type="button"
                            class="button button-secondary"
                            id="selectVisible"
                        >
                            Select visible
                        </button>

                        <button
                            type="button"
                            class="button button-secondary"
                            id="clearSelection"
                        >
                            Clear
                        </button>
                    </div>

                    <div class="recipient-list" id="recipientList">

                        <?php if (empty($users)): ?>
                            <div class="empty">
                                No registered users were found.
                            </div>
                        <?php else: ?>

                            <?php foreach ($users as $user): ?>
                                <label
                                    class="recipient"
                                    data-search="<?= emailEscape(
                                        strtolower(
                                            $user['name'] . ' '
                                            . $user['email'] . ' '
                                            . ($user['department'] ?? '')
                                        )
                                    ) ?>"
                                >
                                    <input
                                        type="checkbox"
                                        name="recipients[]"
                                        value="<?= (int) $user['user_id'] ?>"
                                    >

                                    <div class="recipient-info">
                                        <strong>
                                            <?= emailEscape($user['name']) ?>
                                        </strong>

                                        <span>
                                            <?= emailEscape($user['email']) ?>
                                        </span>

                                        <span>
                                            <?= emailEscape(
                                                $user['department'] ?: 'No department'
                                            ) ?>
                                            ·
                                            <?= emailEscape($user['role']) ?>
                                        </span>
                                    </div>
                                </label>
                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>
                </div>

                <div class="button-row">
                    <span class="selection-count" id="selectionCount">
                        0 recipients selected
                    </span>
                </div>
            </div>

            <div class="field">
                <label for="templateSelect">Use Email Template (Optional)</label>

                <select id="templateSelect">
                    <option value="">Start with a blank email</option>

                    <?php foreach ($templates as $template): ?>
                        <option
                            value="<?= (int) $template['template_id'] ?>"
                            data-subject="<?= emailEscape($template['subject']) ?>"
                            data-message="<?= emailEscape($template['message_body']) ?>"
                        >
                            <?= emailEscape($template['template_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="subject">Email Subject</label>

                <input
                    type="text"
                    id="subject"
                    name="subject"
                    maxlength="255"
                    placeholder="Enter the email subject..."
                    required
                >
            </div>

            <div class="field">
                <label for="message_body">Message</label>

                <textarea
                    id="message_body"
                    name="message_body"
                    placeholder="Write your email message here..."
                    required
                ></textarea>

                <small style="color:#64748b;">
                    Plain text is supported. Line breaks will be preserved.
                </small>
            </div>

            <div class="button-row">
                <button type="submit" class="button button-primary">
                    <i class="fa-solid fa-paper-plane"></i>
                    Send Email
                </button>

                <button type="button"
                        class="button button-secondary"
                        id="saveTemplateButton">
                    <i class="fa-regular fa-bookmark"></i>
                    Save as Template
                </button>

                <button type="reset"
                        class="button button-secondary"
                        id="clearEmail">
                    Clear Form
                </button>
            </div>
        </form>

        <!-- Separate template-saving form -->
        <form method="POST" id="templateForm" style="display:none;">
            <input type="hidden" name="csrf_token"
                   value="<?= emailEscape($csrfToken) ?>">

            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="template_name" id="templateNameInput">
            <input type="hidden" name="subject" id="templateSubjectInput">
            <input type="hidden" name="message_body" id="templateMessageInput">
        </form>
    </section>

    <!-- EMAIL HISTORY -->

    <section class="card" id="history">
        <div class="card-heading">
            <div>
                <h2>
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    Email History
                </h2>
                <p>Review recent sending attempts and their status.</p>
            </div>

            <input
                type="search"
                id="historySearch"
                placeholder="Search history..."
                style="max-width:260px;"
            >
        </div>

        <div class="table-wrap">
            <table id="historyTable">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Recipients</th>
                        <th>Sent By</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($emailHistory)): ?>
                    <tr>
                        <td colspan="5" class="empty">
                            No email history yet.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($emailHistory as $item): ?>
                        <?php
                            $recipientList = json_decode(
                                $item['recipients_json'],
                                true
                            );

                            $recipientNames = [];

                            if (is_array($recipientList)) {
                                foreach ($recipientList as $recipient) {
                                    $recipientNames[] =
                                        $recipient['name']
                                        ?? $recipient['email']
                                        ?? 'Unknown recipient';
                                }
                            }

                            $statusClass = strtolower($item['status']);
                        ?>

                        <tr>
                            <td>
                                <strong>
                                    <?= emailEscape($item['subject']) ?>
                                </strong>

                                <small>
                                    <?= emailEscape(
                                        mb_substr(
                                            $item['message_body'],
                                            0,
                                            100
                                        )
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <?= (int) $item['recipient_count'] ?>
                                successful

                                <br>

                                <small>
                                    <?= emailEscape(
                                        implode(', ', $recipientNames)
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <?= emailEscape(
                                    $item['sender_name'] ?? 'Unknown'
                                ) ?>
                            </td>

                            <td>
                                <?= emailEscape($item['sent_at']) ?>
                            </td>

                            <td>
                                <span class="badge <?= emailEscape($statusClass) ?>">
                                    <?= emailEscape($item['status']) ?>
                                </span>

                                <?php if (!empty($item['error_message'])): ?>
                                    <br>
                                    <small>
                                        Some emails may have failed.
                                    </small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- EMAIL TEMPLATES -->

    <section class="card" id="templates">
        <div class="card-heading">
            <div>
                <h2>
                    <i class="fa-regular fa-folder-open"></i>
                    Email Templates
                </h2>
                <p>Reuse saved messages instead of rewriting them.</p>
            </div>
        </div>

        <?php if (empty($templates)): ?>
            <div class="empty">
                No templates saved yet. Write an email and select
                "Save as Template" to create your first one.
            </div>
        <?php else: ?>

            <div class="template-list">
                <?php foreach ($templates as $template): ?>
                    <div class="template-item">
                        <div>
                            <strong>
                                <?= emailEscape($template['template_name']) ?>
                            </strong>

                            <p>
                                <?= emailEscape($template['subject']) ?>
                            </p>
                        </div>

                        <form method="POST"
                              onsubmit="return confirm('Delete this template?');">

                            <input type="hidden" name="csrf_token"
                                   value="<?= emailEscape($csrfToken) ?>">

                            <input type="hidden"
                                   name="action"
                                   value="delete_template">

                            <input type="hidden"
                                   name="template_id"
                                   value="<?= (int) $template['template_id'] ?>">

                            <button type="submit"
                                    class="button button-danger"
                                    title="Delete template">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </section>

</main>

<footer>
    TidyTrack Business Analyst Portal &copy; <?= date('Y') ?>
</footer>

<script>
const recipientMode = document.getElementById('recipient_mode');
const recipientField = document.getElementById('recipientField');
const departmentField = document.getElementById('departmentField');
const recipientSearch = document.getElementById('recipientSearch');
const recipientRows = [...document.querySelectorAll('.recipient')];
const selectionCount = document.getElementById('selectionCount');

function updateRecipientMode() {
    const mode = recipientMode.value;

    recipientField.hidden = mode !== 'selected';
    departmentField.hidden = mode !== 'department';

    updateSelectionCount();
}

function updateSelectionCount() {
    const count = document.querySelectorAll(
        '.recipient input[type="checkbox"]:checked'
    ).length;

    selectionCount.textContent = count + ' recipient(s) selected';

    if (recipientMode.value === 'all') {
        selectionCount.textContent = 'All registered users will receive this email.';
    } else if (recipientMode.value === 'department') {
        selectionCount.textContent = 'All users in the selected department will receive this email.';
    }
}

recipientMode.addEventListener('change', updateRecipientMode);

recipientRows.forEach(row => {
    row.querySelector('input').addEventListener(
        'change',
        updateSelectionCount
    );
});

recipientSearch.addEventListener('input', function () {
    const search = this.value.toLowerCase().trim();

    recipientRows.forEach(row => {
        row.hidden = !row.dataset.search.includes(search);
    });
});

document.getElementById('selectVisible').addEventListener('click', () => {
    recipientRows.forEach(row => {
        if (!row.hidden) {
            row.querySelector('input').checked = true;
        }
    });

    updateSelectionCount();
});

document.getElementById('clearSelection').addEventListener('click', () => {
    document.querySelectorAll(
        '.recipient input[type="checkbox"]'
    ).forEach(checkbox => {
        checkbox.checked = false;
    });

    updateSelectionCount();
});

document.getElementById('templateSelect').addEventListener('change', function () {
    const option = this.options[this.selectedIndex];

    if (!option.value) {
        return;
    }

    document.getElementById('subject').value =
        option.dataset.subject || '';

    document.getElementById('message_body').value =
        option.dataset.message || '';
});

document.getElementById('saveTemplateButton').addEventListener('click', () => {
    const subject = document.getElementById('subject').value.trim();
    const message = document.getElementById('message_body').value.trim();

    if (!subject || !message) {
        alert('Enter an email subject and message before saving a template.');
        return;
    }

    const name = prompt('Enter a name for this email template:');

    if (!name || !name.trim()) {
        return;
    }

    document.getElementById('templateNameInput').value = name.trim();
    document.getElementById('templateSubjectInput').value = subject;
    document.getElementById('templateMessageInput').value = message;

    document.getElementById('templateForm').submit();
});

document.getElementById('emailForm').addEventListener('submit', function (event) {
    if (!this.reportValidity()) {
        event.preventDefault();
        return;
    }

    const mode = recipientMode.value;

    if (mode === 'selected') {
        const selected = document.querySelectorAll(
            '.recipient input[type="checkbox"]:checked'
        ).length;

        if (selected === 0) {
            event.preventDefault();
            alert('Please select at least one recipient.');
            return;
        }
    }

    if (mode === 'department' &&
        !document.getElementById('department').value) {
        event.preventDefault();
        alert('Please select a department.');
        return;
    }

    let message = 'Send this email?';

    if (mode === 'all') {
        message = 'Send this email to ALL registered users?';
    } else if (mode === 'department') {
        message = 'Send this email to everyone in the selected department?';
    } else {
        const count = document.querySelectorAll(
            '.recipient input[type="checkbox"]:checked'
        ).length;

        message = 'Send this email to ' + count + ' selected recipient(s)?';
    }

    if (!confirm(message)) {
        event.preventDefault();
    }
});

document.getElementById('historySearch').addEventListener('input', function () {
    const search = this.value.toLowerCase();

    document.querySelectorAll('#historyTable tbody tr').forEach(row => {
        row.hidden = !row.textContent.toLowerCase().includes(search);
    });
});

updateRecipientMode();
</script>

</body>
</html>