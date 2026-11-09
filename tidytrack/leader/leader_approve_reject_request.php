<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? null;

if ($userRole !== 'leader' || !$userId || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied or invalid request']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$membershipId = isset($input['membershipId']) ? (int)$input['membershipId'] : null;
$action = $input['action'] ?? null;

if (!$membershipId || !in_array($action, ['approve', 'reject'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT g.creator_id, g.group_name, u.name AS user_name
        FROM group_members gm
        JOIN groups g ON gm.group_id = g.group_id
        JOIN users u ON gm.user_id = u.user_id
        WHERE gm.membership_id = ? AND gm.status = 'pending'
    ");
    $stmt->bind_param("i", $membershipId);
    $stmt->execute();
    $result = $stmt->get_result();
    $requestDetails = $result->fetch_assoc();

    if (!$requestDetails || $requestDetails['creator_id'] != $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You are not authorized to manage this request']);
        exit();
    }

    $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
    $stmt = $conn->prepare("UPDATE group_members SET status = ? WHERE membership_id = ?");
    $stmt->bind_param("si", $newStatus, $membershipId);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $message = $action === 'approve' 
            ? "Approved {$requestDetails['user_name']} to join {$requestDetails['group_name']}"
            : "Rejected {$requestDetails['user_name']}'s request to join {$requestDetails['group_name']}";
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update request status']);
    }

    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>