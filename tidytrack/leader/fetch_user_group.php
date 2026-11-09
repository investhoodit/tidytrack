<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'User not logged in.']);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT DISTINCT g.group_id, g.group_name, g.group_icon
        FROM groups g
        JOIN group_members gm ON g.group_id = gm.group_id
        JOIN users u ON gm.user_id = u.user_id
        WHERE gm.user_id = ? AND u.role = 'Leader' AND gm.status = 'approved'
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $groups = [];
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }

    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'groups' => $groups]);
} catch (Exception $e) {
    error_log("Error in fetch_user_groups.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>