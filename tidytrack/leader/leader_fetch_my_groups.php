<?php
header('Content-Type: application/json');
session_start();

// Check for required session data and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'leader') {
    echo json_encode(['success' => false, 'error' => 'Access denied. Leader session required.']);
    http_response_code(403);
    exit();
}

require_once '../dp.php'; 

$userId = $_SESSION['user_id'];

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    http_response_code(500);
    exit();
}

try {
    // SQL Query: 
    // 1. Uses g.creator_id to filter for groups created by the current leader.
    // 2. Joins with group_members and counts ALL members (approved, pending, etc.).
    $sql = "
        SELECT 
            g.group_id, 
            g.group_name, 
            g.group_type, 
            g.group_icon,
            COUNT(m.user_id) AS member_count
        FROM 
            groups g
        LEFT JOIN 
            group_members m ON g.group_id = m.group_id
        WHERE 
            g.creator_id = ?
        GROUP BY 
            g.group_id
        ORDER BY
            g.group_name ASC
    ";

    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("SQL Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $groups = [];
    while ($row = $result->fetch_assoc()) {
        $row['member_count'] = (int)$row['member_count']; 
        $groups[] = $row;
    }

    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'groups' => $groups]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>