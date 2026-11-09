<?php
session_start();
require_once '../dp.php';

header('Content-Type: application/json');

// Log session start
error_log("leader_create_group.php - Session started: session_id=" . session_id());

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    error_log("leader_create_group.php - Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? null;

// Log session data
error_log("leader_create_group.php - Session data: userId=$userId, userRole=$userRole");

if ($userRole !== 'leader' || !$userId || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    error_log("leader_create_group.php - Access denied: userId=$userId, role=$userRole, method={$_SERVER['REQUEST_METHOD']}");
    echo json_encode(['success' => false, 'error' => 'Access denied or invalid request']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
error_log("leader_create_group.php - Input received: " . json_encode($input));

if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    http_response_code(400);
    error_log("leader_create_group.php - Invalid JSON: " . json_last_error_msg());
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit();
}

$groupName = trim($input['groupName'] ?? '');
$description = trim($input['description'] ?? '');
$groupType = $input['groupType'] ?? 'private';
$groupIcon = $input['groupIcon'] ?? 'fas fa-users';

if (empty($groupName) || !in_array($groupType, ['public', 'private'])) {
    http_response_code(400);
    error_log("leader_create_group.php - Invalid input: groupName=$groupName, groupType=$groupType");
    echo json_encode(['success' => false, 'error' => 'Invalid group name or type']);
    exit();
}

$validIcons = [
    'fas fa-users', 'fas fa-bullhorn', 'fas fa-user-tie', 'fas fa-tasks',
    'fas fa-coffee', 'fas fa-bug', 'fas fa-headset', 'fas fa-cogs',
    'fas fa-chart-line', 'fas fa-folder'
];
$groupIcon = in_array($groupIcon, $validIcons) ? $groupIcon : 'fas fa-users';

try {
    // Check group name uniqueness
    $stmt = $conn->prepare("SELECT group_id FROM groups WHERE group_name = ?");
    if (!$stmt) {
        error_log("leader_create_group.php - Prepare failed (group name check): " . $conn->error);
        throw new Exception("Database prepare error");
    }
    $stmt->bind_param("s", $groupName);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $stmt->close();
        http_response_code(409);
        error_log("leader_create_group.php - Group name exists: groupName=$groupName");
        echo json_encode(['success' => false, 'error' => 'Group name already exists']);
        exit();
    }
    $stmt->close();

    // Insert group
    $stmt = $conn->prepare("
        INSERT INTO groups (group_name, description, group_type, group_icon, creator_id, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        error_log("leader_create_group.php - Prepare failed (group insert): " . $conn->error);
        throw new Exception("Database prepare error");
    }
    $stmt->bind_param("ssssi", $groupName, $description, $groupType, $groupIcon, $userId);
    $stmt->execute();
    $groupId = $stmt->insert_id;
    $stmt->close();

    if (!$groupId) {
        error_log("leader_create_group.php - Group insert failed: groupName=$groupName");
        throw new Exception("Failed to create group");
    }

    // Auto-add leader to group_members
    $stmt = $conn->prepare("
        INSERT INTO group_members (group_id, user_id, status, requested_at)
        VALUES (?, ?, 'approved', NOW())
    ");
    if (!$stmt) {
        error_log("leader_create_group.php - Prepare failed (member insert): " . $conn->error);
        throw new Exception("Database prepare error");
    }
    $stmt->bind_param("ii", $groupId, $userId);
    $stmt->execute();
    $memberInserted = $stmt->affected_rows > 0;
    $stmt->close();

    if ($memberInserted) {
        error_log("leader_create_group.php - Success: groupId=$groupId, groupName=$groupName, creatorId=$userId");
        echo json_encode(['success' => true, 'message' => "Group '$groupName' created successfully"]);
    } else {
        error_log("leader_create_group.php - Failed to auto-add leader: groupId=$groupId, userId=$userId");
        echo json_encode(['success' => false, 'error' => 'Group created, but failed to add leader as member']);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("leader_create_group.php - Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to create group: ' . $e->getMessage()]);
}
$conn->close();
?>