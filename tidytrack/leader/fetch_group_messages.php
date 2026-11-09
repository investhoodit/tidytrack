<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
$groupId = $_GET['group_id'] ?? null;

if (!$userId || !$groupId) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT gm.message_id, gm.user_id, u.name AS sender_name, gm.message_text, gm.message_type, gm.meeting_link, gm.sent_at
        FROM group_messages gm
        JOIN users u ON gm.user_id = u.user_id
        WHERE gm.group_id = ?
        ORDER BY gm.sent_at ASC
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);

    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'messages' => $messages]);
} catch (Exception $e) {
    error_log("Error in fetch_group_messages.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage]);

}
?>