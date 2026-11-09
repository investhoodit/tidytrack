<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
$data = json_decode(file_get_contents('php://input'), true);
$groupId = $data['groupId'] ?? null;
$messageType = $data['messageType'] ?? 'text';
$messageText = $data['messageText'] ?? '';
$meetingLink = $data['meetingLink'] ?? '';

if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'User not logged in.']);
    exit();
}

if ($messageType === 'meeting' && !$meetingLink) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data. Meeting link is required.']);
    exit();
}

try {
    if ($messageType === 'meeting' && $meetingLink) {
        if ($groupId === null) {
            echo json_encode(['success' => false, 'error' => 'Group ID is required for meeting links.']);
            exit();
        }
        $stmt = $conn->prepare("INSERT INTO group_messages (group_id, user_id, message_type, meeting_link, sent_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiss", $groupId, $userId, $messageType, $meetingLink);
    } else {
        $stmt = $conn->prepare("INSERT INTO group_messages (group_id, user_id, message_text, message_type, sent_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("isss", $groupId, $userId, $messageText, $messageType);
    }
    if (!$stmt->execute()) {
        throw new Exception("Insert failed: " . $conn->error);
    }
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("Error in send_group_message.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>