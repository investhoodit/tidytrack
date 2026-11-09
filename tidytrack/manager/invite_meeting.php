<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$senderId = $_SESSION['user_id'] ?? null;
if (!$senderId) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$room = $data['room'] ?? '';
$users = $data['users'] ?? [];
$groups = $data['groups'] ?? [];

if (!$room) {
    echo json_encode(['success' => false, 'error' => 'No room code']);
    exit;
}

// YOUR EXACT MEETING LINK
$meetingLink = "https://investhoodarcade.com/Investhood_TSM/investhood/manager/manager_meetings.php?code=" . urlencode($room);
$inviteMessage = "You've been invited to a meeting!\nJoin here: $meetingLink";

// === SEND TO USERS (Private DM) ===
foreach ($users as $receiverId) {
    $receiverId = (int)$receiverId;

    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, timestamp) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $senderId, $receiverId, $inviteMessage);
    $stmt->execute();
    $stmt->close();
}

// === SEND TO GROUPS (message_text + meeting_link) ===
foreach ($groups as $groupId) {
    $groupId = (int)$groupId;

    $stmt = $conn->prepare("
        INSERT INTO group_messages 
        (group_id, user_id, message_text, message_type, meeting_link, sent_at) 
        VALUES (?, ?, ?, 'meeting', ?, NOW())
    ");
    $stmt->bind_param("iiss", $groupId, $senderId, $inviteMessage, $meetingLink);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['success' => true, 'sent' => count($users) + count($groups)]);
?>