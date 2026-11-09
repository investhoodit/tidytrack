<?php
session_start();
include '../dp.php';

// Check if the user is logged in and the request is a POST request
if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized or invalid request']);
    exit();
}

$userId = $_SESSION['user_id'];

// Get the group ID from the AJAX request
$input = json_decode(file_get_contents('php://input'), true);
$groupId = isset($input['groupId']) ? (int)$input['groupId'] : null;

if (!$groupId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Group ID is required']);
    exit();
}

try {
    // Check if the group exists
    $stmt = $conn->prepare("SELECT group_name FROM groups WHERE group_id = ?");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Group not found']);
        $stmt->close();
        exit();
    }
    $group = $result->fetch_assoc();
    $groupName = $group['group_name'];
    $stmt->close();

    // Check if the user has already requested or joined the group
    $stmt = $conn->prepare("SELECT status FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $groupId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $memberStatus = $result->fetch_assoc()['status'];
        if ($memberStatus === 'pending') {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'You have already sent a request to join this group']);
            $stmt->close();
            exit();
        } elseif ($memberStatus === 'approved') {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'You are already a member of this group']);
            $stmt->close();
            exit();
        } elseif ($memberStatus === 'rejected') {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Your previous request was rejected. Please contact the group admin']);
            $stmt->close();
            exit();
        }
    }
    $stmt->close();

    // Insert a new pending request
    $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id, status, requested_at) VALUES (?, ?, 'pending', NOW())");
    $stmt->bind_param("ii", $groupId, $userId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => "Your request to join $groupName has been sent successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to send the join request']);
    }

    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>