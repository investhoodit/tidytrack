<?php
// task_actions.php — AJAX handler for member task actions
// Place this file in the SAME folder as member_tasks.php

ob_start();
error_reporting(0);

session_start();

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$dpPath = '../dp.php';
if (!file_exists($dpPath)) {
    ob_end_clean();
    echo json_encode(['error' => 'Database config not found at: ' . $dpPath]);
    exit;
}
require_once $dpPath;

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['error' => 'Unauthorized - please log in']);
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['name'] ?? '';
$userRole = strtolower($_SESSION['role'] ?? 'member');
$action   = trim($_POST['action'] ?? $_GET['action'] ?? '');

ob_end_clean();

// ─────────────────────────────────────────────────────────────
// HELPER: email ONLY the leader who created this specific task
// Uses tasks.created_by → users.user_id
// ─────────────────────────────────────────────────────────────
function emailTaskLeader($conn, $taskId, $subject, $bodyTemplate) {
    $stmt = $conn->prepare("
        SELECT u.name, u.email
        FROM tasks t
        JOIN users u ON u.user_id = t.created_by
        WHERE t.task_id = ?
          AND LOWER(u.role) = 'leader'
          AND u.email IS NOT NULL AND u.email != ''
        LIMIT 1
    ");
    $stmt->bind_param("i", $taskId);
    $stmt->execute();
    $leader = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$leader) return; // task has no creator or no email on file

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: no-reply@investhoodprojects.com\r\n";

    $body = str_replace('{{LEADER_NAME}}', htmlspecialchars($leader['name']), $bodyTemplate);
    @mail($leader['email'], $subject, $body, $headers);
}

// ─────────────────────────────────────────────────────────────
// HELPER: email the member (by their name → look up email)
// ─────────────────────────────────────────────────────────────
function emailUser($conn, $memberName, $subject, $body) {
    $stmt = $conn->prepare("SELECT email FROM users WHERE name = ? AND email IS NOT NULL AND email != ''");
    $stmt->bind_param("s", $memberName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return;
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: no-reply@investhoodprojects.com\r\n";
    @mail($row['email'], $subject, $body, $headers);
}

// ─────────────────────────────────────────────────────────────
// HELPER: build a styled HTML email
// ─────────────────────────────────────────────────────────────
function emailTemplate($headerColor, $headerText, $bodyHtml) {
    return "
    <html><body style='font-family:Poppins,sans-serif;background:#f4f6f9;padding:30px;'>
      <div style='max-width:540px;margin:auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.08);'>
        <div style='background:{$headerColor};padding:22px 28px;'>
          <h2 style='color:white;margin:0;font-size:1.15rem;'>{$headerText}</h2>
        </div>
        <div style='padding:24px 28px;'>{$bodyHtml}</div>
        <div style='background:#f9f9f9;padding:12px 28px;font-size:11px;color:#aaa;text-align:center;'>INVESTHOOD TSM · Automated Notification</div>
      </div>
    </body></html>";
}

// ─────────────────────────────────────────────────────────────
// HELPER: verify task belongs to logged-in member
// ─────────────────────────────────────────────────────────────
function getOwnTask($conn, $taskId, $userName) {
    $stmt = $conn->prepare("
        SELECT task_id, taskTitle, status, assignTo, overdue_reason, delete_requested
        FROM tasks
        WHERE task_id = ? AND assignTo = ?
    ");
    $stmt->bind_param("is", $taskId, $userName);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $task;
}

// ─────────────────────────────────────────────────────────────
// 1. SUBMIT OVERDUE REASON
// ─────────────────────────────────────────────────────────────
if ($action === 'submit_overdue_reason') {
    $taskId = (int)($_POST['task_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if (!$taskId || $reason === '') {
        echo json_encode(['error' => 'Missing task ID or reason']); exit;
    }

    $task = getOwnTask($conn, $taskId, $userName);
    if (!$task) {
        echo json_encode(['error' => 'Task not found or not assigned to you']); exit;
    }
    if (!empty($task['overdue_reason'])) {
        echo json_encode(['error' => 'You already submitted a reason for this task']); exit;
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        UPDATE tasks
        SET overdue_reason = ?, overdue_reason_at = ?, status = 'In review'
        WHERE task_id = ? AND assignTo = ?
    ");
    $stmt->bind_param("ssis", $reason, $now, $taskId, $userName);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        echo json_encode(['error' => 'Update failed — task may have already changed']); exit;
    }

    $title = htmlspecialchars($task['taskTitle']);
    $safe  = htmlspecialchars($userName);
    $safeR = htmlspecialchars($reason);

    // Email ONLY the leader who created this task
    $body = emailTemplate('#e94560', '⚠️ Overdue Explanation Received',
        "<p>Hi <strong>{{LEADER_NAME}}</strong>,</p>
         <p>One of your team members submitted an explanation for their overdue task.</p>
         <div style='background:#fff5f5;border-left:4px solid #e94560;padding:14px;border-radius:8px;margin:16px 0;'>
           <p style='margin:0;font-weight:700;font-size:1rem;'>$title</p>
           <p style='margin:6px 0 0;font-size:13px;'>By: <strong>$safe</strong></p>
         </div>
         <div style='background:#f9f9f9;padding:12px;border-radius:8px;font-size:14px;color:#444;'>
           <strong>Their reason:</strong><br>$safeR
         </div>
         <p style='margin-top:16px;font-size:13px;color:#777;'>Task is now <strong>In Review</strong>.</p>
         <a href='https://investhoodprojects.com/tidytracker/tidytrack/' style='display:inline-block;margin-top:8px;background:#1d3557;color:white;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;'>Go to Task Manager</a>"
    );
    emailTaskLeader($conn, $taskId, "⚠️ Overdue Explanation: $title", $body);

    echo json_encode(['success' => true, 'new_status' => 'In review']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. REQUEST DELETE
// ─────────────────────────────────────────────────────────────
if ($action === 'request_delete') {
    $taskId = (int)($_POST['task_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if (!$taskId || $reason === '') {
        echo json_encode(['error' => 'Missing task ID or reason']); exit;
    }

    $stmt = $conn->prepare("SELECT task_id, taskTitle, status, assignTo FROM tasks WHERE task_id = ? AND assignTo = ?");
    $stmt->bind_param("is", $taskId, $userName);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$task) {
        echo json_encode(['error' => 'Task not found or not assigned to you']); exit;
    }

    $status = strtolower(trim($task['status'] ?? ''));
    if ($status === 'deleted' || $status === 'delete requested') {
        echo json_encode(['error' => 'Task is already in the deletion process']); exit;
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        UPDATE tasks
        SET delete_requested = 1, delete_reason = ?, delete_requested_at = ?, status = 'Delete Requested'
        WHERE task_id = ? AND assignTo = ?
    ");
    $stmt->bind_param("ssis", $reason, $now, $taskId, $userName);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        echo json_encode(['error' => 'Update failed']); exit;
    }

    $title = htmlspecialchars($task['taskTitle']);
    $safe  = htmlspecialchars($userName);
    $safeR = htmlspecialchars($reason);

    // Email ONLY the leader who created this task
    $body = emailTemplate('#c53030', '🗑️ Task Deletion Requested',
        "<p>Hi <strong>{{LEADER_NAME}}</strong>,</p>
         <p>One of your team members has requested that a task be deleted.</p>
         <div style='background:#fff5f5;border-left:4px solid #c53030;padding:14px;border-radius:8px;margin:16px 0;'>
           <p style='margin:0;font-weight:700;font-size:1rem;'>$title</p>
           <p style='margin:6px 0 0;font-size:13px;'>Requested by: <strong>$safe</strong></p>
         </div>
         <div style='background:#f9f9f9;padding:12px;border-radius:8px;font-size:14px;color:#444;'>
           <strong>Reason:</strong><br>$safeR
         </div>
         <p style='margin-top:16px;font-size:13px;color:#777;'>Please log in to approve or reject this request.</p>
         <a href='https://investhoodprojects.com/tidytracker/tidytrack/' style='display:inline-block;margin-top:8px;background:#c53030;color:white;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;'>Review Request</a>"
    );
    emailTaskLeader($conn, $taskId, "🗑️ Delete Request: $title", $body);

    echo json_encode(['success' => true]);
    exit;
}

// Unknown action fallback
echo json_encode(['error' => "Unknown action: '$action'"]);