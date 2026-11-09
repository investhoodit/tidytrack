<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;

if (!$userId || !$groupId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized or missing group ID']);
    exit();
}

// Verify user is an approved member
$stmt = $conn->prepare("
    SELECT membership_id FROM group_members 
    WHERE group_id = ? AND user_id = ? AND status = 'approved'
");
$stmt->bind_param("ii", $groupId, $userId);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not an approved member of this group']);
    exit();
}
$stmt->close();

// Fetch messages
$stmt = $conn->prepare("
    SELECT m.message_id, m.message_text, m.message_type, m.meeting_link, m.sent_at, 
           u.name AS sender_name, u.user_id
    FROM group_messages m
    JOIN users u ON m.user_id = u.user_id
    WHERE m.group_id = ?
    ORDER BY m.sent_at ASC
");
$stmt->bind_param("i", $groupId);
$stmt->execute();
$messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'messages' => $messages]);
?>