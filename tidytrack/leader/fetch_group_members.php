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
        SELECT g.group_name, g.group_icon, COUNT(gm.user_id) AS member_count
        FROM groups g
        LEFT JOIN group_members gm ON g.group_id = gm.group_id
        WHERE g.group_id = ?
        GROUP BY g.group_id, g.group_name, g.group_icon
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();

    $stmt = $conn->prepare("
        SELECT u.user_id, u.name, u.status, (u.role = 'Leader' AND gm.user_id = ?) AS is_leader
        FROM group_members gm
        JOIN users u ON gm.user_id = u.user_id
        WHERE gm.group_id = ? AND gm.status = 'approved'
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("ii", $userId, $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    $members = $result->fetch_all(MYSQLI_ASSOC);

    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'group' => $group, 'members' => $members]);
} catch (Exception $e) {
    error_log("Error in fetch_group_members.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage]);
}
?>