
<?php
session_start();
include '../dp.php';

if (empty($_SESSION['ba_authenticated'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['ba_user_id'])) {
    header('Location: index.php');
    exit;
}

date_default_timezone_set('Africa/Johannesburg');

$currentUserId = (int) $_SESSION['ba_user_id'];

if (empty($_SESSION['ba_chat_csrf'])) {
    $_SESSION['ba_chat_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['ba_chat_csrf'];

function e($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/*
|--------------------------------------------------------------------------
| Confirm the logged-in user is still a BA team member in the database
|--------------------------------------------------------------------------
*/

$authStmt = $conn->prepare("
    SELECT user_id, name
    FROM users
    WHERE user_id = ?
      AND LOWER(TRIM(department)) = 'ba team'
    LIMIT 1
");

if (!$authStmt) {
    http_response_code(500);
    exit('Unable to verify BA team access.');
}

$authStmt->bind_param('i', $currentUserId);
$authStmt->execute();

$currentUser = $authStmt->get_result()->fetch_assoc();
$authStmt->close();

if (!$currentUser) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$currentUserName = $currentUser['name'];

/*
|--------------------------------------------------------------------------
| AJAX API: send messages and load conversations
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrfToken, (string)$_POST['csrf_token'])
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'Your session token is invalid. Refresh the page and try again.'
        ], 403);
    }

    $action = (string)$_POST['action'];

    /*
    |--------------------------------------------------------------------------
    | Send a message
    |--------------------------------------------------------------------------
    */

    if ($action === 'send') {

        $message = trim((string)($_POST['message'] ?? ''));
        $receiverValue = $_POST['receiver_id'] ?? '';

        if ($message === '') {
            jsonResponse([
                'success' => false,
                'message' => 'Please type a message.'
            ], 422);
        }

        if (mb_strlen($message, 'UTF-8') > 5000) {
            jsonResponse([
                'success' => false,
                'message' => 'Your message cannot exceed 5,000 characters.'
            ], 422);
        }

        $receiverId = null;

        // Empty receiver means the BA group conversation.
        if ($receiverValue !== '') {

            if (
                !ctype_digit((string)$receiverValue) ||
                (int)$receiverValue <= 0
            ) {
                jsonResponse([
                    'success' => false,
                    'message' => 'Invalid recipient.'
                ], 422);
            }

            $receiverId = (int)$receiverValue;

            if ($receiverId === $currentUserId) {
                jsonResponse([
                    'success' => false,
                    'message' => 'You cannot send a private message to yourself.'
                ], 422);
            }

            // Only allow private messages to actual BA team members.
            $recipientStmt = $conn->prepare("
                SELECT user_id
                FROM users
                WHERE user_id = ?
                  AND LOWER(TRIM(department)) = 'ba team'
                LIMIT 1
            ");

            $recipientStmt->bind_param('i', $receiverId);
            $recipientStmt->execute();

            $recipient = $recipientStmt->get_result()->fetch_assoc();
            $recipientStmt->close();

            if (!$recipient) {
                jsonResponse([
                    'success' => false,
                    'message' => 'That recipient is not a BA team member.'
                ], 403);
            }
        }

        if ($receiverId === null) {

            $sendStmt = $conn->prepare("
                INSERT INTO ba_team_messages
                    (sender_id, receiver_id, message)
                VALUES (?, NULL, ?)
            ");

            $sendStmt->bind_param(
                'is',
                $currentUserId,
                $message
            );

        } else {

            $sendStmt = $conn->prepare("
                INSERT INTO ba_team_messages
                    (sender_id, receiver_id, message)
                VALUES (?, ?, ?)
            ");

            $sendStmt->bind_param(
                'iis',
                $currentUserId,
                $receiverId,
                $message
            );
        }

        if (!$sendStmt->execute()) {
            $sendStmt->close();

            jsonResponse([
                'success' => false,
                'message' => 'Unable to send your message. Please try again.'
            ], 500);
        }

        $newMessageId = $conn->insert_id;
        $sendStmt->close();

        jsonResponse([
            'success' => true,
            'message_id' => $newMessageId
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Load group or private conversation
    |--------------------------------------------------------------------------
    */

    if ($action === 'poll') {

        $receiverValue = $_POST['receiver_id'] ?? '';
        $afterId = max(0, (int)($_POST['after_id'] ?? 0));

        $receiverId = null;

        if ($receiverValue !== '') {

            if (
                !ctype_digit((string)$receiverValue) ||
                (int)$receiverValue <= 0
            ) {
                jsonResponse([
                    'success' => false,
                    'message' => 'Invalid conversation.'
                ], 422);
            }

            $receiverId = (int)$receiverValue;

            if ($receiverId === $currentUserId) {
                jsonResponse([
                    'success' => false,
                    'message' => 'Invalid conversation.'
                ], 422);
            }

            // Recheck access to the selected BA contact.
            $recipientStmt = $conn->prepare("
                SELECT user_id
                FROM users
                WHERE user_id = ?
                  AND LOWER(TRIM(department)) = 'ba team'
                LIMIT 1
            ");

            $recipientStmt->bind_param('i', $receiverId);
            $recipientStmt->execute();

            $recipient = $recipientStmt->get_result()->fetch_assoc();
            $recipientStmt->close();

            if (!$recipient) {
                jsonResponse([
                    'success' => false,
                    'message' => 'That conversation is not available.'
                ], 403);
            }
        }

        if ($receiverId === null) {

            if ($afterId > 0) {

                $messagesStmt = $conn->prepare("
                    SELECT
                        m.message_id,
                        m.sender_id,
                        m.receiver_id,
                        m.message,
                        m.sent_at,
                        u.name AS sender_name
                    FROM ba_team_messages m
                    INNER JOIN users u
                        ON u.user_id = m.sender_id
                    WHERE m.receiver_id IS NULL
                      AND m.message_id > ?
                      AND LOWER(TRIM(u.department)) = 'ba team'
                    ORDER BY m.message_id ASC
                    LIMIT 100
                ");

                $messagesStmt->bind_param('i', $afterId);

            } else {

                $messagesStmt = $conn->prepare("
                    SELECT
                        m.message_id,
                        m.sender_id,
                        m.receiver_id,
                        m.message,
                        m.sent_at,
                        u.name AS sender_name
                    FROM ba_team_messages m
                    INNER JOIN users u
                        ON u.user_id = m.sender_id
                    WHERE m.receiver_id IS NULL
                      AND LOWER(TRIM(u.department)) = 'ba team'
                    ORDER BY m.message_id DESC
                    LIMIT 100
                ");
            }

        } else {

            if ($afterId > 0) {

                $messagesStmt = $conn->prepare("
                    SELECT
                        m.message_id,
                        m.sender_id,
                        m.receiver_id,
                        m.message,
                        m.sent_at,
                        u.name AS sender_name
                    FROM ba_team_messages m
                    INNER JOIN users u
                        ON u.user_id = m.sender_id
                    WHERE (
                        (m.sender_id = ? AND m.receiver_id = ?)
                        OR
                        (m.sender_id = ? AND m.receiver_id = ?)
                    )
                    AND m.message_id > ?
                    AND LOWER(TRIM(u.department)) = 'ba team'
                    ORDER BY m.message_id ASC
                    LIMIT 100
                ");

                $messagesStmt->bind_param(
                    'iiiii',
                    $currentUserId,
                    $receiverId,
                    $receiverId,
                    $currentUserId,
                    $afterId
                );

            } else {

                $messagesStmt = $conn->prepare("
                    SELECT
                        m.message_id,
                        m.sender_id,
                        m.receiver_id,
                        m.message,
                        m.sent_at,
                        u.name AS sender_name
                    FROM ba_team_messages m
                    INNER JOIN users u
                        ON u.user_id = m.sender_id
                    WHERE (
                        (m.sender_id = ? AND m.receiver_id = ?)
                        OR
                        (m.sender_id = ? AND m.receiver_id = ?)
                    )
                    AND LOWER(TRIM(u.department)) = 'ba team'
                    ORDER BY m.message_id DESC
                    LIMIT 100
                ");

                $messagesStmt->bind_param(
                    'iiii',
                    $currentUserId,
                    $receiverId,
                    $receiverId,
                    $currentUserId
                );
            }
        }

        if (!$messagesStmt->execute()) {
            $messagesStmt->close();

            jsonResponse([
                'success' => false,
                'message' => 'Unable to load messages.'
            ], 500);
        }

        $messageResult = $messagesStmt->get_result();
        $messages = [];

        while ($row = $messageResult->fetch_assoc()) {
            $row['message_id'] = (int)$row['message_id'];
            $row['sender_id'] = (int)$row['sender_id'];

            if ($row['receiver_id'] !== null) {
                $row['receiver_id'] = (int)$row['receiver_id'];
            }

            $messages[] = $row;
        }

        $messagesStmt->close();

        // Initial history is loaded newest-first; display it oldest-first.
        if ($afterId === 0) {
            $messages = array_reverse($messages);
        }

        // Mark incoming private messages as read.
        if ($receiverId !== null && !empty($messages)) {

            $readStmt = $conn->prepare("
                UPDATE ba_team_messages
                SET read_at = COALESCE(read_at, NOW())
                WHERE sender_id = ?
                  AND receiver_id = ?
                  AND read_at IS NULL
            ");

            $readStmt->bind_param(
                'ii',
                $receiverId,
                $currentUserId
            );

            $readStmt->execute();
            $readStmt->close();
        }

        jsonResponse([
            'success' => true,
            'messages' => $messages
        ]);
    }

    jsonResponse([
        'success' => false,
        'message' => 'Unknown action.'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| Load BA contacts
|--------------------------------------------------------------------------
*/

$contactsStmt = $conn->prepare("
    SELECT
        u.user_id,
        u.name,
        u.profile_picture,

        (
            SELECT COUNT(*)
            FROM ba_team_messages m
            WHERE m.sender_id = u.user_id
              AND m.receiver_id = ?
              AND m.read_at IS NULL
        ) AS unread_count

    FROM users u

    WHERE LOWER(TRIM(u.department)) = 'ba team'
      AND u.user_id <> ?

    ORDER BY u.name ASC
");

$contactsStmt->bind_param(
    'ii',
    $currentUserId,
    $currentUserId
);

$contactsStmt->execute();
$contactsResult = $contactsStmt->get_result();

$contacts = [];

while ($row = $contactsResult->fetch_assoc()) {
    $row['user_id'] = (int)$row['user_id'];
    $row['unread_count'] = (int)$row['unread_count'];
    $contacts[] = $row;
}

$contactsStmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>BA Team Chat | TidyTrack</title>

    <link rel="icon" href="assets/images/logo.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --primary: #0d9488;
            --primary-dark: #0f766e;
            --primary-light: #ccfbf1;
            --background: #f8fafc;
            --white: #ffffff;
            --text: #172b3a;
            --muted: #64748b;
            --border: #e2e8f0;
            --green: #16a34a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
        }

        button,
        input,
        textarea {
            font-family: inherit;
        }

        button {
            cursor: pointer;
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
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
            font-weight: 800;
            text-decoration: none;
            color: var(--text);
        }

        .brand img {
            width: 42px;
            height: 42px;
            object-fit: contain;
        }

        .brand span {
            color: var(--primary);
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: var(--text);
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            background: white;
        }

        .back-btn:hover {
            color: var(--primary);
            border-color: var(--primary);
        }

        .page {
            max-width: 1350px;
            margin: auto;
            padding: 30px 5% 45px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 22px;
        }

        .breadcrumb a {
            text-decoration: none;
            color: inherit;
        }

        .breadcrumb a:hover {
            color: var(--primary);
        }

        .page-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            font-size: 28px;
            letter-spacing: -0.7px;
            margin: 0 0 8px;
        }

        .page-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .secure-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 13px;
            background: var(--primary-light);
            color: var(--primary-dark);
            border-radius: 9px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .chat-shell {
            height: min(690px, calc(100vh - 230px));
            min-height: 480px;
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr);
            background: white;
            border: 1px solid var(--border);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 25px rgba(15, 23, 42, 0.035);
        }

        .sidebar {
            min-width: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--border);
            background: #fff;
        }

        .sidebar-header {
            padding: 22px 19px 16px;
            border-bottom: 1px solid var(--border);
        }

        .sidebar-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 15px;
        }

        .sidebar-title h2 {
            font-size: 15px;
            margin: 0;
        }

        .member-count {
            font-size: 10px;
            padding: 5px 8px;
            border-radius: 20px;
            background: #f1f5f9;
            color: var(--muted);
            font-weight: 800;
        }

        .contact-search {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 11px;
            font-size: 12px;
            outline: none;
        }

        .contact-search:focus {
            border-color: var(--primary);
        }

        .contact-list {
            padding: 10px;
            overflow-y: auto;
            flex: 1;
        }

        .contact-btn {
            display: flex;
            align-items: center;
            gap: 11px;
            width: 100%;
            padding: 12px 10px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            text-align: left;
            color: var(--text);
            margin-bottom: 4px;
        }

        .contact-btn:hover {
            background: #f8fafc;
        }

        .contact-btn.active {
            background: #ecfdf5;
        }

        .avatar {
            width: 39px;
            height: 39px;
            flex: 0 0 39px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e2e8f0;
            color: #475569;
            font-size: 13px;
            font-weight: 800;
            overflow: hidden;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .group-avatar {
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .contact-content {
            flex: 1;
            min-width: 0;
        }

        .contact-name {
            display: block;
            font-size: 12px;
            font-weight: 800;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            margin-bottom: 5px;
        }

        .contact-subtitle {
            display: block;
            color: var(--muted);
            font-size: 10px;
        }

        .unread-badge {
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 20px;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
        }

        .sidebar-footer {
            padding: 14px 17px;
            border-top: 1px solid var(--border);
            color: var(--muted);
            font-size: 10px;
            line-height: 1.7;
        }

        .sidebar-footer i {
            color: var(--primary);
            margin-right: 5px;
        }

        .chat-main {
            min-width: 0;
            display: flex;
            flex-direction: column;
            background: white;
        }

        .chat-header {
            min-height: 78px;
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .chat-person {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .chat-person h2 {
            font-size: 14px;
            margin: 0 0 5px;
        }

        .chat-person p {
            font-size: 11px;
            margin: 0;
            color: var(--muted);
        }

        .online-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            color: var(--green);
            font-weight: 700;
        }

        .online-indicator span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
        }

        .chat-messages {
            flex: 1;
            padding: 23px;
            overflow-y: auto;
            background:
                radial-gradient(#e2e8f0 0.6px, transparent 0.6px);
            background-size: 17px 17px;
            background-color: #fbfdff;
        }

        .date-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 0 20px;
        }

        .date-divider span {
            background: white;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 6px 11px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
        }

        .message-row {
            display: flex;
            align-items: flex-end;
            gap: 9px;
            margin-bottom: 17px;
        }

        .message-row.mine {
            justify-content: flex-end;
        }

        .message-avatar {
            width: 29px;
            height: 29px;
            flex: 0 0 29px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e2e8f0;
            color: #475569;
            font-size: 9px;
            font-weight: 800;
        }

        .message-content {
            max-width: min(75%, 470px);
            min-width: 0;
        }

        .message-sender {
            font-size: 10px;
            font-weight: 800;
            margin: 0 0 5px 3px;
            color: var(--muted);
        }

        .message-bubble {
            padding: 11px 14px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px 12px 12px 3px;
            font-size: 12px;
            line-height: 1.65;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            box-shadow: 0 2px 4px rgba(15, 23, 42, 0.025);
        }

        .message-row.mine .message-bubble {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            border-radius: 12px 12px 3px 12px;
        }

        .message-row.mine .message-sender {
            text-align: right;
        }

        .message-time {
            margin-top: 5px;
            padding: 0 3px;
            color: var(--muted);
            font-size: 9px;
        }

        .message-row.mine .message-time {
            text-align: right;
        }

        .empty-chat {
            height: 100%;
            min-height: 230px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 25px;
        }

        .empty-chat-icon {
            width: 62px;
            height: 62px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 19px;
            font-size: 24px;
            margin-bottom: 17px;
        }

        .empty-chat h3 {
            font-size: 15px;
            margin: 0 0 8px;
        }

        .empty-chat p {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
            max-width: 280px;
            margin: 0;
        }

        .chat-composer {
            padding: 17px 20px;
            border-top: 1px solid var(--border);
            background: white;
        }

        .composer-form {
            display: flex;
            align-items: flex-end;
            gap: 12px;
        }

        .composer-form textarea {
            flex: 1;
            resize: none;
            min-height: 43px;
            max-height: 130px;
            padding: 13px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            font-size: 12px;
            line-height: 1.5;
        }

        .composer-form textarea:focus {
            border-color: var(--primary);
        }

        .send-btn {
            height: 43px;
            width: 46px;
            flex: 0 0 46px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 10px;
            background: var(--primary);
            color: white;
            font-size: 15px;
        }

        .send-btn:hover {
            background: var(--primary-dark);
        }

        .send-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .composer-hint {
            color: var(--muted);
            font-size: 10px;
            margin-top: 9px;
        }

        .chat-error {
            display: none;
            padding: 10px 13px;
            margin: 12px 20px 0;
            border-radius: 8px;
            background: #fef2f2;
            color: #b91c1c;
            font-size: 11px;
        }

        .footer {
            padding: 22px 15px;
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        .mobile-back {
            display: none;
        }

        @media (max-width: 850px) {
            .page {
                padding: 24px 3% 30px;
            }

            .chat-shell {
                grid-template-columns: 245px minmax(0, 1fr);
            }

            .chat-messages {
                padding: 16px;
            }
        }

        @media (max-width: 650px) {
            .topbar {
                padding: 12px 4%;
            }

            .brand {
                font-size: 17px;
            }

            .brand img {
                width: 35px;
                height: 35px;
            }

            .back-btn {
                padding: 9px 10px;
                font-size: 10px;
            }

            .page-heading {
                flex-direction: column;
            }

            .page-heading h1 {
                font-size: 24px;
            }

            .secure-label {
                font-size: 10px;
            }

            .chat-shell {
                height: 650px;
                min-height: 450px;
                display: block;
                position: relative;
            }

            .sidebar {
                height: 100%;
                border-right: 0;
            }

            .chat-main {
                display: none;
                position: absolute;
                inset: 0;
                z-index: 3;
                background: white;
            }

            .chat-shell.mobile-chat-open .sidebar {
                display: none;
            }

            .chat-shell.mobile-chat-open .chat-main {
                display: flex;
            }

            .mobile-back {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 33px;
                height: 33px;
                flex: 0 0 33px;
                border: 1px solid var(--border);
                border-radius: 8px;
                background: white;
                color: var(--text);
            }

            .chat-header {
                padding: 12px;
                min-height: 69px;
            }

            .chat-person {
                gap: 8px;
            }

            .chat-person .avatar {
                width: 34px;
                height: 34px;
                flex-basis: 34px;
            }

            .chat-messages {
                padding: 15px 10px;
            }

            .message-content {
                max-width: 83%;
            }

            .chat-composer {
                padding: 12px 10px;
            }

            .chat-error {
                margin: 10px;
            }
        }
    </style>
</head>

<body>

<header class="topbar">

    <a href="index.php" class="brand">
        <img src="assets/images/logo.png" alt="TidyTrack Logo">
        <div>Tidy<span>Track</span></div>
    </a>

    <a href="index.php" class="back-btn">
        <i class="fa-solid fa-arrow-left"></i>
        Back to Dashboard
    </a>

</header>

<main class="page">

    <div class="breadcrumb">
        <a href="index.php">Dashboard</a>
        <i class="fa-solid fa-chevron-right"></i>
        <span>Team Chat</span>
    </div>

    <section class="page-heading">

        <div>
            <h1>BA Team Chat</h1>

            <p>
                Communicate with your Business Analyst team, share updates
                and discuss work in one place.
            </p>
        </div>

        <div class="secure-label">
            <i class="fa-solid fa-lock"></i>
            BA TEAM ONLY
        </div>

    </section>

    <section class="chat-shell" id="chatShell">

        <!-- CONTACT SIDEBAR -->

        <aside class="sidebar">

            <div class="sidebar-header">

                <div class="sidebar-title">
                    <h2>Conversations</h2>

                    <span class="member-count">
                        <?= count($contacts) + 1 ?> chats
                    </span>
                </div>

                <input
                    type="search"
                    class="contact-search"
                    id="contactSearch"
                    placeholder="Search BA members..."
                    autocomplete="off"
                >

            </div>

            <div class="contact-list" id="contactList">

                <!-- BA group conversation -->

                <button
                    type="button"
                    class="contact-btn active"
                    data-chat-type="group"
                    data-user-id=""
                    data-name="BA Team Group"
                >

                    <div class="avatar group-avatar">
                        <i class="fa-solid fa-people-group"></i>
                    </div>

                    <div class="contact-content">
                        <span class="contact-name">BA Team Group</span>
                        <span class="contact-subtitle">Shared team conversation</span>
                    </div>

                </button>

                <?php foreach ($contacts as $contact): ?>

                    <?php
                    $initials = '';

                    $nameParts = preg_split(
                        '/\s+/',
                        trim($contact['name'])
                    );

                    foreach (array_slice($nameParts, 0, 2) as $part) {
                        $initials .= mb_substr($part, 0, 1, 'UTF-8');
                    }

                    $picture = trim((string)($contact['profile_picture'] ?? ''));
                    ?>

                    <button
                        type="button"
                        class="contact-btn"
                        data-chat-type="private"
                        data-user-id="<?= (int)$contact['user_id'] ?>"
                        data-name="<?= e($contact['name']) ?>"
                    >

                        <div class="avatar">

                            <?php if ($picture !== ''): ?>
                                <img
                                    src="<?= e($picture) ?>"
                                    alt=""
                                    loading="lazy"
                                    onerror="this.remove(); this.parentElement.textContent='<?= e($initials) ?>';"
                                >
                            <?php else: ?>
                                <?= e($initials) ?>
                            <?php endif; ?>

                        </div>

                        <div class="contact-content">
                            <span class="contact-name">
                                <?= e($contact['name']) ?>
                            </span>

                            <span class="contact-subtitle">
                                BA Team Member
                            </span>
                        </div>

                        <?php if ($contact['unread_count'] > 0): ?>
                            <span class="unread-badge">
                                <?= $contact['unread_count'] > 99 ? '99+' : $contact['unread_count'] ?>
                            </span>
                        <?php endif; ?>

                    </button>

                <?php endforeach; ?>

            </div>

            <div class="sidebar-footer">
                <i class="fa-solid fa-shield-halved"></i>
                Messages are restricted to authenticated BA team members.
            </div>

        </aside>

        <!-- CHAT WINDOW -->

        <section class="chat-main">

            <header class="chat-header">

                <div class="chat-person">

                    <button
                        type="button"
                        class="mobile-back"
                        id="mobileBack"
                        aria-label="Back to conversations"
                    >
                        <i class="fa-solid fa-arrow-left"></i>
                    </button>

                    <div class="avatar group-avatar" id="headerAvatar">
                        <i class="fa-solid fa-people-group"></i>
                    </div>

                    <div>
                        <h2 id="conversationName">BA Team Group</h2>
                        <p id="conversationDescription">Shared team conversation</p>
                    </div>

                </div>

                <div class="online-indicator">
                    <span></span>
                    Team chat
                </div>

            </header>

            <div class="chat-error" id="chatError"></div>

            <div
                class="chat-messages"
                id="chatMessages"
                aria-live="polite"
                aria-label="Conversation messages"
            >

                <div class="empty-chat">
                    <div class="empty-chat-icon">
                        <i class="fa-solid fa-comments"></i>
                    </div>

                    <h3>Welcome to BA Team Chat</h3>

                    <p>
                        Send a message to start a conversation with your
                        BA team members.
                    </p>
                </div>

            </div>

            <div class="chat-composer">

                <form class="composer-form" id="messageForm">

                    <textarea
                        id="messageInput"
                        placeholder="Write your message..."
                        maxlength="5000"
                        rows="1"
                        aria-label="Write your message"
                    ></textarea>

                    <button
                        type="submit"
                        class="send-btn"
                        id="sendButton"
                        aria-label="Send message"
                    >
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>

                </form>

                <div class="composer-hint">
                    Press Enter to send. Press Shift + Enter for a new line.
                </div>

            </div>

        </section>

    </section>

</main>

<footer class="footer">
    TidyTrack Business Analyst Portal &copy; <?= date('Y') ?>
</footer>

<script>
(() => {
    'use strict';

    const currentUserId = <?= json_encode($currentUserId) ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>;

    const chatShell = document.getElementById('chatShell');
    const contactList = document.getElementById('contactList');
    const contactSearch = document.getElementById('contactSearch');

    const chatMessages = document.getElementById('chatMessages');
    const conversationName = document.getElementById('conversationName');
    const conversationDescription = document.getElementById('conversationDescription');
    const headerAvatar = document.getElementById('headerAvatar');

    const messageForm = document.getElementById('messageForm');
    const messageInput = document.getElementById('messageInput');
    const sendButton = document.getElementById('sendButton');

    const chatError = document.getElementById('chatError');
    const mobileBack = document.getElementById('mobileBack');

    let selectedReceiverId = '';
    let selectedChatType = 'group';
    let selectedChatName = 'BA Team Group';

    let lastMessageId = 0;
    let requestInProgress = false;
    let pollTimer = null;
    let firstLoad = true;

    function showError(message) {
        chatError.textContent = message;
        chatError.style.display = 'block';
    }

    function clearError() {
        chatError.textContent = '';
        chatError.style.display = 'none';
    }

    async function apiRequest(data) {

        const formData = new FormData();

        formData.append('csrf_token', csrfToken);

        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, value ?? '');
        });

        const response = await fetch(window.location.href.split('#')[0], {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(
                result.message || 'Something went wrong. Please try again.'
            );
        }

        return result;
    }

    function createEmptyState(title, description) {

        const wrapper = document.createElement('div');
        wrapper.className = 'empty-chat';

        const icon = document.createElement('div');
        icon.className = 'empty-chat-icon';

        const iconElement = document.createElement('i');
        iconElement.className = 'fa-solid fa-comments';

        icon.appendChild(iconElement);

        const heading = document.createElement('h3');
        heading.textContent = title;

        const paragraph = document.createElement('p');
        paragraph.textContent = description;

        wrapper.append(icon, heading, paragraph);

        return wrapper;
    }

    function initials(name) {

        return String(name || '?')
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map(part => part.charAt(0))
            .join('')
            .toUpperCase();
    }

    function formatMessageTime(value) {

        const date = new Date(String(value).replace(' ', 'T'));

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function createMessage(message) {

        const mine = Number(message.sender_id) === Number(currentUserId);

        const row = document.createElement('div');
        row.className = 'message-row' + (mine ? ' mine' : '');

        if (!mine) {
            const avatar = document.createElement('div');
            avatar.className = 'message-avatar';
            avatar.textContent = initials(message.sender_name);
            row.appendChild(avatar);
        }

        const content = document.createElement('div');
        content.className = 'message-content';

        if (selectedChatType === 'group' && !mine) {
            const sender = document.createElement('div');
            sender.className = 'message-sender';
            sender.textContent = message.sender_name;
            content.appendChild(sender);
        }

        const bubble = document.createElement('div');
        bubble.className = 'message-bubble';

        // Use textContent so messages cannot inject HTML or scripts.
        bubble.textContent = message.message;

        const time = document.createElement('div');
        time.className = 'message-time';
        time.textContent = formatMessageTime(message.sent_at);

        content.append(bubble, time);
        row.appendChild(content);

        if (mine) {
            const avatar = document.createElement('div');
            avatar.className = 'message-avatar';
            avatar.style.background = '#ccfbf1';
            avatar.style.color = '#0f766e';
            avatar.textContent = initials('<?= e($currentUserName) ?>');
            row.appendChild(avatar);
        }

        return row;
    }

    function renderMessages(messages, replace = false) {

        if (replace) {
            chatMessages.replaceChildren();
            lastMessageId = 0;

            if (messages.length === 0) {
                chatMessages.appendChild(
                    createEmptyState(
                        'No messages yet',
                        'Send the first message to start this conversation.'
                    )
                );

                return;
            }
        } else if (messages.length > 0) {

            const emptyState = chatMessages.querySelector('.empty-chat');

            if (emptyState) {
                emptyState.remove();
            }
        }

        messages.forEach(message => {

            const messageId = Number(message.message_id);

            if (messageId > lastMessageId) {
                lastMessageId = messageId;
            }

            chatMessages.appendChild(createMessage(message));
        });

        if (messages.length > 0) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }

    function updateSelectedContact() {

        contactList.querySelectorAll('.contact-btn').forEach(button => {

            const type = button.dataset.chatType;
            const userId = button.dataset.userId || '';

            const active = (
                type === selectedChatType &&
                userId === String(selectedReceiverId)
            );

            button.classList.toggle('active', active);
        });
    }

    function updateHeader() {

        conversationName.textContent = selectedChatName;

        if (selectedChatType === 'group') {
            conversationDescription.textContent = 'Shared BA team conversation';

            headerAvatar.className = 'avatar group-avatar';
            headerAvatar.replaceChildren();

            const icon = document.createElement('i');
            icon.className = 'fa-solid fa-people-group';

            headerAvatar.appendChild(icon);

        } else {
            conversationDescription.textContent = 'Private conversation';

            headerAvatar.className = 'avatar';
            headerAvatar.textContent = initials(selectedChatName);
        }
    }

    async function loadMessages(replace = false) {

        if (requestInProgress) {
            return;
        }

        requestInProgress = true;

        try {

            const result = await apiRequest({
                action: 'poll',
                receiver_id: selectedReceiverId,
                after_id: replace ? 0 : lastMessageId
            });

            renderMessages(result.messages, replace);

            clearError();

            // Refresh unread badges after opening a conversation.
            if (selectedChatType === 'private') {
                refreshUnreadBadges();
            }

        } catch (error) {

            showError(error.message);

        } finally {

            requestInProgress = false;
        }
    }

    async function refreshUnreadBadges() {

        try {

            const result = await apiRequest({
                action: 'unread'
            });

            contactList.querySelectorAll('.contact-btn[data-chat-type="private"]')
                .forEach(button => {

                    const userId = button.dataset.userId;
                    const count = Number(result.unread[userId] || 0);

                    let badge = button.querySelector('.unread-badge');

                    if (count > 0) {

                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'unread-badge';
                            button.appendChild(badge);
                        }

                        badge.textContent = count > 99 ? '99+' : String(count);

                    } else if (badge) {

                        badge.remove();
                    }
                });

        } catch (error) {
            // Keep the conversation usable if unread counts cannot refresh.
        }
    }

    function startPolling() {

        if (pollTimer) {
            clearInterval(pollTimer);
        }

        pollTimer = setInterval(() => {
            loadMessages(false);
        }, 2500);
    }

    async function selectConversation(button) {

        selectedChatType = button.dataset.chatType;
        selectedReceiverId = button.dataset.userId || '';
        selectedChatName = button.dataset.name || 'BA Team Group';

        lastMessageId = 0;
        firstLoad = true;

        updateSelectedContact();
        updateHeader();

        clearError();

        chatShell.classList.add('mobile-chat-open');

        await loadMessages(true);

        firstLoad = false;

        messageInput.focus();
    }

    contactList.addEventListener('click', event => {

        const button = event.target.closest('.contact-btn');

        if (!button) {
            return;
        }

        selectConversation(button);
    });

    contactSearch.addEventListener('input', () => {

        const search = contactSearch.value.toLowerCase().trim();

        contactList.querySelectorAll('.contact-btn').forEach(button => {

            const name = (button.dataset.name || '').toLowerCase();

            button.style.display = name.includes(search) ? 'flex' : 'none';
        });
    });

    mobileBack.addEventListener('click', () => {
        chatShell.classList.remove('mobile-chat-open');
    });

    messageInput.addEventListener('input', () => {

        messageInput.style.height = 'auto';

        messageInput.style.height = Math.min(
            messageInput.scrollHeight,
            130
        ) + 'px';
    });

    messageInput.addEventListener('keydown', event => {

        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            messageForm.requestSubmit();
        }
    });

    messageForm.addEventListener('submit', async event => {

        event.preventDefault();

        const message = messageInput.value.trim();

        if (!message || sendButton.disabled) {
            return;
        }

        sendButton.disabled = true;
        clearError();

        try {

            await apiRequest({
                action: 'send',
                receiver_id: selectedReceiverId,
                message: message
            });

            messageInput.value = '';
            messageInput.style.height = 'auto';

            // Reload the active conversation to show the new message.
            lastMessageId = 0;
            await loadMessages(true);

            refreshUnreadBadges();

        } catch (error) {

            showError(error.message);

        } finally {

            sendButton.disabled = false;
            messageInput.focus();
        }
    });

    // Start with the BA group conversation.
    const groupButton = contactList.querySelector(
        '.contact-btn[data-chat-type="group"]'
    );

    if (groupButton) {
        updateSelectedContact();
        updateHeader();
        loadMessages(true);
    }

    refreshUnreadBadges();
    startPolling();

})();
</script>

</body>
</html>
