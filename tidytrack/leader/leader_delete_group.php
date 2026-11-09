<?php
header('Content-Type: application/json');
session_start();

// Check session and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'leader') {
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    http_response_code(403);
    exit();
}

// Get JSON data
$data = json_decode(file_get_contents("php://input"), true);
$groupId = $data['groupId'] ?? null;
$userId = $_SESSION['user_id'];

if (!$groupId || !is_numeric($groupId)) {
    echo json_encode(['success' => false, 'error' => 'Invalid group ID provided.']);
    http_response_code(400);
    exit();
}

require_once '../dp.php'; 

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    http_response_code(500);
    exit();
}

$conn->begin_transaction();

try {
    // 1. Verify that the current user is the CREATOR of the group.
    $stmt = $conn->prepare("SELECT group_name FROM groups WHERE group_id = ? AND creator_id = ?");
    $stmt->bind_param("ii", $groupId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $stmt->close();

    if (!$group) {
        throw new Exception("Group not found or you are not the creator.");
    }

    $groupName = htmlspecialchars($group['group_name']);

    // 2. Delete all members associated with the group (from group_members table)
    $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id = ?");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $stmt->close();

    // 3. Delete the group itself (from groups table)
    $stmt = $conn->prepare("DELETE FROM groups WHERE group_id = ? AND creator_id = ?");
    $stmt->bind_param("ii", $groupId, $userId);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        // This should not happen if step 1 passed, but check for safety
        throw new Exception("Group deletion failed (Group not found after verification).");
    }
    $stmt->close();

    $conn->commit();
    $conn->close();

    echo json_encode(['success' => true, 'message' => "Group '{$groupName}' successfully deleted."]);

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>