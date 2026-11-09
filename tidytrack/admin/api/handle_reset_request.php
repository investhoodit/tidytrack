<?php
session_start();
require_once __DIR__ . '/../../dp.php';
require_once __DIR__ . '/../../track.php';

header('Content-Type: application/json');

// Auth guard
$userId = $_SESSION['user_id'] ?? null;
$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!$userId || $role !== 'admin') {
    error_log("Unauthorized access attempt to handle_reset_request.php: user_id=$userId, role=$role");
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON parsing error in handle_reset_request.php: " . json_last_error_msg());
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$requestId = (int)($input['request_id'] ?? 0);
$action = $input['action'] ?? '';

if (!$requestId || !in_array($action, ['approved', 'rejected'])) {
    error_log("Invalid request parameters: request_id=$requestId, action=$action");
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

// Start transaction
$conn->begin_transaction();

try {
    // Fetch reset request
    $stmt = $conn->prepare("SELECT user_id, new_password_hash FROM password_reset_requests WHERE request_id = ? AND status = 'pending'");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param('i', $requestId);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        throw new Exception("Request not found or already processed");
    }

    // Update request status
    $stmt = $conn->prepare("UPDATE password_reset_requests SET status = ? WHERE request_id = ?");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param('si', $action, $requestId);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    $stmt->close();

    // If approved, update user password
    if ($action === 'approved') {
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param('si', $request['new_password_hash'], $request['user_id']);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $stmt->close();
    }

    // Commit transaction
    $conn->commit();

    // Log activity
    log_activity($userId, "PasswordReset.$action", "Password reset request $requestId $action for user ID: {$request['user_id']}");

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    error_log("Error in handle_reset_request.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();