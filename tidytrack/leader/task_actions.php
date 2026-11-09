<?php
// task_actions.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../dp.php';
require_once '../track.php';

header('Content-Type: application/json; charset=UTF-8');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================================================
   MAIL CONFIG
========================================================= */
$MAIL_FROM  = "noreply@investhoodprojects.com";
$MAIL_REPLY = "noreply@investhoodprojects.com";
$APP_URL    = "https://investhoodprojects.com/Investhood_TSM/tidytrack/";

/* =========================================================
   HELPERS
========================================================= */
function respond($ok, $message = '', $extra = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => $ok,
        'message' => $message
    ], $extra));
    exit;
}

function clean($v) {
    return trim((string)$v);
}

function tableExists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function appendComment($old, $line) {
    $old = trim((string)$old);
    return $old === '' ? $line : ($old . "\n" . $line);
}

function sendTaskMail($to, $subject, $body, $fromEmail, $replyToEmail) {
    $headers = [];
    $headers[] = "From: Investhood TMS <{$fromEmail}>";
    $headers[] = "Reply-To: {$replyToEmail}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "X-Mailer: PHP/" . phpversion();

    $headersStr = implode("\r\n", $headers);

    $ok = mail($to, $subject, $body, $headersStr, "-f {$fromEmail}");

    if (!$ok) {
        $err = error_get_last();
        error_log("MAIL FAILED => to={$to} | subject={$subject} | err=" . ($err['message'] ?? 'unknown'));
    }

    return $ok;
}

function getTask(mysqli $conn, int $taskId) {
    $sql = "
        SELECT t.*, u.email
        FROM tasks t
        LEFT JOIN users u ON u.name = t.assignTo
        WHERE t.task_id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $taskId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getUserIdByAssignTo(mysqli $conn, string $assignTo): int {
    $assignTo = trim($assignTo);
    if ($assignTo === '' || !tableExists($conn, 'users')) return 0;

    $possibleCols = ['name', 'full_name', 'username', 'email'];
    $existingCols = [];

    foreach ($possibleCols as $c) {
        if (columnExists($conn, 'users', $c)) {
            $existingCols[] = $c;
        }
    }

    if (empty($existingCols)) return 0;

    foreach ($existingCols as $col) {
        $sql = "SELECT user_id FROM users WHERE `$col` = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $assignTo);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row && !empty($row['user_id'])) {
            return (int)$row['user_id'];
        }
    }

    return 0;
}

function sendInAppNotificationIfPossible(mysqli $conn, int $userId, string $title, string $message, int $taskId = 0): bool {
    if ($userId <= 0) return false;
    if (!tableExists($conn, 'notifications')) return false;

    $cols = [];
    $vals = [];
    $types = '';

    $map = [
        'user_id'    => $userId,
        'title'      => $title,
        'message'    => $message,
        'type'       => 'task_status',
        'task_id'    => $taskId,
        'related_id' => $taskId,
        'is_read'    => 0,
        'status'     => 'unread',
        'created_at' => date('Y-m-d H:i:s')
    ];

    foreach ($map as $col => $val) {
        if (columnExists($conn, 'notifications', $col)) {
            $cols[] = "`$col`";
            $vals[] = $val;
            $types .= is_int($val) ? 'i' : 's';
        }
    }

    if (empty($cols)) return false;

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO notifications (" . implode(',', $cols) . ") VALUES ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$vals);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/* =========================================================
   AUTH
========================================================= */
if (!isset($_SESSION['user_id'])) {
    respond(false, 'Not logged in.', [], 401);
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = clean($_SESSION['name'] ?? 'Leader');
$userRole = strtolower(clean($_SESSION['role'] ?? 'member'));

if (!in_array($userRole, ['leader', 'admin'])) {
    respond(false, 'Access denied.', [], 403);
}

$action = clean($_POST['action'] ?? '');
$taskId = (int)($_POST['task_id'] ?? 0);

if ($taskId <= 0) {
    respond(false, 'Invalid task ID.', [], 400);
}

if ($action === '') {
    respond(false, 'No action sent.', [], 400);
}

try {
    $task = getTask($conn, $taskId);

    if (!$task) {
        respond(false, 'Task not found.', [], 404);
    }

    $taskTitle   = clean($task['taskTitle'] ?? 'Task');
    $assignTo    = clean($task['assignTo'] ?? 'Member');
    $comment     = $task['comment'] ?? '';
    $memberEmail = clean($task['email'] ?? '');
    $now         = date('Y-m-d H:i:s');

    $assignedUserId = getUserIdByAssignTo($conn, $assignTo);

    /* ================================
       APPROVE DELETE
    ================================= */
    if ($action === 'approve_delete') {
        $note = "[Delete approved by {$userName} on {$now}]";

        $newComment = appendComment($comment, $note);

        $sql = "UPDATE tasks
                SET status = 'Deleted',
                    delete_requested = 0,
                    comment = ?,
                    delete_reason = NULL,
                    delete_requested_at = NULL
                WHERE task_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $newComment, $taskId);
        $stmt->execute();
        $stmt->close();

        if ($assignedUserId > 0) {
            sendInAppNotificationIfPossible(
                $conn,
                $assignedUserId,
                'Deletion request approved',
                "Your deletion request for '{$taskTitle}' was approved. Please check the updated task status.",
                $taskId
            );
        }

        if ($memberEmail !== '') {
            $subject = "Your Deletion Request Was Approved: " . $taskTitle;
            $body =
                "Hi {$assignTo},\n\n" .
                "Your request to delete the task has been approved by your Team Leader.\n\n" .
                "Task ID: {$taskId}\n" .
                "Task: {$taskTitle}\n\n" .
                "Please log in to the system to view the updated task status:\n" .
                "{$APP_URL}\n\n" .
                "Best regards,\n" .
                "Investhood Task Management System\n";

            sendTaskMail($memberEmail, $subject, $body, $MAIL_FROM, $MAIL_REPLY);
        } else {
            error_log("DELETE APPROVED MAIL FAILED => no email found for task_id={$taskId}, assignTo={$assignTo}");
        }

        log_activity($userId, "Approved delete request for task #{$taskId}");
        respond(true, 'Delete approved, saved, and member notified.');
    }

    /* ================================
       REJECT DELETE
    ================================= */
    if ($action === 'reject_delete') {
        $rejectReason = clean($_POST['reject_reason'] ?? '');
        $note = "[Delete rejected by {$userName} on {$now}" . ($rejectReason !== '' ? " | Reason: {$rejectReason}" : "") . "]";

        $newComment = appendComment($comment, $note);

        $sql = "UPDATE tasks
                SET status = 'In Progress',
                    delete_requested = 0,
                    comment = ?,
                    delete_requested_at = NULL
                WHERE task_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $newComment, $taskId);
        $stmt->execute();
        $stmt->close();

        if ($assignedUserId > 0) {
            sendInAppNotificationIfPossible(
                $conn,
                $assignedUserId,
                'Deletion request rejected',
                "Your deletion request for '{$taskTitle}' was rejected. Please check the updated task status.",
                $taskId
            );
        }

        if ($memberEmail !== '') {
            $subject = "Your Deletion Request Was Rejected: " . $taskTitle;
            $body =
                "Hi {$assignTo},\n\n" .
                "Your request to delete the task has been rejected by your Team Leader.\n\n" .
                "Task ID: {$taskId}\n" .
                "Task: {$taskTitle}\n";

            if ($rejectReason !== '') {
                $body .= "\nReason from Team Leader:\n{$rejectReason}\n";
            }

            $body .=
                "\nPlease log in to the system to view the updated task status:\n" .
                "{$APP_URL}\n\n" .
                "Best regards,\n" .
                "Investhood Task Management System\n";

            sendTaskMail($memberEmail, $subject, $body, $MAIL_FROM, $MAIL_REPLY);
        } else {
            error_log("DELETE REJECTED MAIL FAILED => no email found for task_id={$taskId}, assignTo={$assignTo}");
        }

        log_activity($userId, "Rejected delete request for task #{$taskId}");
        respond(true, 'Delete rejection saved and member notified.');
    }

    /* ================================
       APPROVE OVERDUE
    ================================= */
    if ($action === 'approve_overdue') {
        $note = "[Overdue reason accepted by {$userName} on {$now}]";

        $newComment = appendComment($comment, $note);

        $sql = "UPDATE tasks
                SET comment = ?
                WHERE task_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $newComment, $taskId);
        $stmt->execute();
        $stmt->close();

        if ($assignedUserId > 0) {
            sendInAppNotificationIfPossible(
                $conn,
                $assignedUserId,
                'Overdue explanation approved',
                "Your overdue explanation for '{$taskTitle}' was approved. Please check the updated task status.",
                $taskId
            );
        }

        if ($memberEmail !== '') {
            $subject = "Your Overdue Explanation Was Approved: " . $taskTitle;
            $body =
                "Hi {$assignTo},\n\n" .
                "Your overdue explanation has been approved by your Team Leader.\n\n" .
                "Task ID: {$taskId}\n" .
                "Task: {$taskTitle}\n\n" .
                "Please log in to the system to view the updated task status:\n" .
                "{$APP_URL}\n\n" .
                "Best regards,\n" .
                "Investhood Task Management System\n";

            sendTaskMail($memberEmail, $subject, $body, $MAIL_FROM, $MAIL_REPLY);
        } else {
            error_log("OVERDUE APPROVED MAIL FAILED => no email found for task_id={$taskId}, assignTo={$assignTo}");
        }

        log_activity($userId, "Accepted overdue explanation for task #{$taskId}");
        respond(true, 'Overdue explanation accepted, saved, and member notified.');
    }

    /* ================================
       REJECT OVERDUE
    ================================= */
    if ($action === 'reject_overdue') {
        $rejectReason = clean($_POST['reject_reason'] ?? '');
        $note = "[Overdue reason rejected by {$userName} on {$now}" . ($rejectReason !== '' ? " | Reason: {$rejectReason}" : "") . "]";

        $newComment = appendComment($comment, $note);

        $sql = "UPDATE tasks
                SET comment = ?
                WHERE task_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $newComment, $taskId);
        $stmt->execute();
        $stmt->close();

        if ($assignedUserId > 0) {
            sendInAppNotificationIfPossible(
                $conn,
                $assignedUserId,
                'Overdue explanation rejected',
                "Your overdue explanation for '{$taskTitle}' was rejected. Please check the updated task status.",
                $taskId
            );
        }

        if ($memberEmail !== '') {
            $subject = "Your Overdue Explanation Was Rejected: " . $taskTitle;
            $body =
                "Hi {$assignTo},\n\n" .
                "Your overdue explanation has been rejected by your Team Leader.\n\n" .
                "Task ID: {$taskId}\n" .
                "Task: {$taskTitle}\n";

            if ($rejectReason !== '') {
                $body .= "\nReason from Team Leader:\n{$rejectReason}\n";
            }

            $body .=
                "\nPlease log in to the system to view the updated task status:\n" .
                "{$APP_URL}\n\n" .
                "Best regards,\n" .
                "Investhood Task Management System\n";

            sendTaskMail($memberEmail, $subject, $body, $MAIL_FROM, $MAIL_REPLY);
        } else {
            error_log("OVERDUE REJECTED MAIL FAILED => no email found for task_id={$taskId}, assignTo={$assignTo}");
        }

        log_activity($userId, "Rejected overdue explanation for task #{$taskId}");
        respond(true, 'Overdue rejection saved and member notified.');
    }

    respond(false, 'Invalid action.', [], 400);

} catch (Throwable $e) {
    error_log("TASK ACTIONS ERROR => " . $e->getMessage());
    respond(false, 'Server error: ' . $e->getMessage(), [], 500);
}