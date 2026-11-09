<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
$data = json_decode(file_get_contents('php://input'), true);
$groupId = $data['groupId'] ?? null;
$memberId = $data['memberId'] ?? null;

if (!$userId || !$groupId || !$memberId) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data.']);
    exit();
}

try {
    // Check if the user is a leader of this group
    $stmt = $conn->prepare("SELECT role FROM group_members gm JOIN users u ON gm.user_id = u.user_id WHERE gm.group_id = ? AND gm.user_id = ? AND gm.status = 'approved' AND u.role = 'Leader'");
    $stmt->bind_param("ii", $groupId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized action.']);
        exit();
    }
    $stmt->close();

    // Remove the member
    $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ? AND status = 'approved'");
    $stmt->bind_param("ii", $groupId, $memberId);
    if (!$stmt->execute()) {
        throw new Exception("Delete failed: " . $conn->error);
    }
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("Error in remove_group_member.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>