<?php
session_start();
include '../dp.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("
    SELECT m.sender_id, u.name AS sender_name, u.profile_picture AS sender_avatar, COUNT(*) AS count
    FROM messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE m.receiver_id = ? AND m.is_read = 0
    GROUP BY m.sender_id
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'sender_id' => $row['sender_id'],
        'sender_name' => $row['sender_name'],
        'sender_avatar' => $row['sender_avatar'] ?? '',
        'count' => (int)$row['count']
    ];
}
echo json_encode($messages);

$stmt->close();
$conn->close();
?>