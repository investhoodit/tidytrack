<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? null;

if ($userRole !== 'leader' || !$userId) {
    error_log("Access denied: Role = $userRole, userId = $userId");
    echo json_encode(['success' => false, 'error' => 'Access denied. You must be a leader.']);
    exit();
}

try {
    // Verify database connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    // Query with exact schema for all tables
    $stmt = $conn->prepare("
        SELECT 
            gm.membership_id,
            gm.requested_at,
            u.name AS user_name,
            g.group_id,
            g.group_name,
            g.group_icon
        FROM 
            group_members gm
        JOIN 
            groups g ON gm.group_id = g.group_id
        JOIN 
            users u ON gm.user_id = u.user_id  -- Updated to user_id
        WHERE 
            g.creator_id = ? AND gm.status = 'pending'
        ORDER BY 
            gm.requested_at ASC
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }

    error_log("Query executed for userId $userId, found " . count($requests) . " requests");
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'requests' => $requests]);
} catch (Exception $e) {
    error_log("Error in leader_fetch_pending_requests.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>