<?php
session_start();
include '../../dp.php';  

$userId   = $_SESSION['user_id'] ?? 0;
$userName = $_SESSION['name']     ?? 'User';

if (!$userId) {
    echo json_encode(['response' => 'Session expired. Please refresh.']);
    exit;
}

// THIS LINE FIXED — works with JS POST
$message = trim($_POST['message'] ?? '');
$message = strtolower($message);

// === YOUR ORIGINAL CODE BELOW (100% untouched) ===
$response = "I'm here to help! Ask me about tasks, messages, meetings, attendance, timesheet, leave, or anything!";

if (strpos($message, 'task') !== false || strpos($message, 'what do i have') !== false || strpos($message, 'my tasks') !== false) {
    $stmt = $conn->prepare("SELECT taskTitle, status, dueDate FROM tasks WHERE assignTo = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->bind_param("s", $userName);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $response = "You have no tasks right now. Enjoy the peace";
    } else {
        $response = "<strong>Your recent tasks:</strong><br><br>";
        while ($task = $result->fetch_assoc()) {
            $status = $task['status'] === 'completed' ? 'Completed' : 'Pending';
            $due = $task['dueDate'] && $task['dueDate'] != '0000-00-00 00:00:00' ? date('M d, H:i', strtotime($task['dueDate'])) : 'No due date';
            $response .= "$status {$task['taskTitle']} (Due: $due)<br>";
        }
        $response .= "<br><a href='member_tasks.php' style='color:#4361ee;font-weight:600;'>→ View all tasks</a>";
    }
    $stmt->close();
}

elseif (strpos($message, 'meeting') !== false) {
    $response = "No upcoming meetings.<br><a href='member_meetings.php' style='color:#4361ee;font-weight:600;'>→ Go to Meetings</a>";
}

elseif (strpos($message, 'message') !== false || strpos($message, 'who messaged') !== false) {
    $stmt = $conn->prepare("SELECT sender_id, message_text, sent_at FROM messages WHERE receiver_id = ? ORDER BY sent_at DESC LIMIT 3");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $response = "No new messages.";
    } else {
        $response = "<strong>Recent messages:</strong><br><br>";
        while ($msg = $result->fetch_assoc()) {
            $senderStmt = $conn->prepare("SELECT name FROM users WHERE user_id = ?");
            $senderStmt->bind_param("i", $msg['sender_id']);
            $senderStmt->execute();
            $senderName = $senderStmt->get_result()->fetch_assoc()['name'] ?? 'Unknown';
            $senderStmt->close();
            $time = date('H:i', strtotime($msg['sent_at']));
            $response .= "<strong>$senderName:</strong> {$msg['message_text']} ($time)<br>";
        }
        $response .= "<br><a href='member_messages.php' style='color:#4361ee;font-weight:600;'>→ Open Messages</a>";
    }
    $stmt->close();
}

elseif (strpos($message, 'send to') !== false) {
    if (preg_match("/send to (.+?): (.+)/i", $message, $matches)) {
        $recipientName = trim($matches[1]);
        $msgText = trim($matches[2]);

        $stmt = $conn->prepare("SELECT user_id FROM users WHERE name = ?");
        $stmt->bind_param("s", $recipientName);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 0) {
            $response = "User '$recipientName' not found.";
        } else {
            $recipientId = $result->fetch_assoc()['user_id'];
            $stmt2 = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_text, sent_at) VALUES (?, ?, ?, NOW())");
            $stmt2->bind_param("iis", $userId, $recipientId, $msgText);
            $stmt2->execute();
            $response = "Message sent to $recipientName!";
        }
        $stmt->close();
    } else {
        $response = "Format: <strong>send to John: hello</strong>";
    }
}

else {
    $response = "I can help with:<br>• my tasks<br>• messages<br>• send to [name]: [message]<br>• meetings<br><br>What would you like to know?";
}

echo json_encode(['response' => $response]);
?>