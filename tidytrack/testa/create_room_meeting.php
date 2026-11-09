<?php
session_start();

// Set JSON header first
header('Content-Type: application/json');

// Simple error reporting
error_reporting(0); // Turn off error display to avoid breaking JSON

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

// Generate a simple room code
$room_code = 'ROOM' . rand(1000, 9999);

// Try to include database connection
$db_file = '../dp.php';
if (file_exists($db_file)) {
    include $db_file;
    
    // Check if tables exist, if not create them
    $conn->query("CREATE TABLE IF NOT EXISTS meeting_rooms (
        room_code VARCHAR(20) PRIMARY KEY,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $conn->query("CREATE TABLE IF NOT EXISTS meeting_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_code VARCHAR(20),
        user_id INT,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create room
    $stmt = $conn->prepare("INSERT INTO meeting_rooms (room_code, created_by) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("si", $room_code, $_SESSION['user_id']);
        $stmt->execute();
        $stmt->close();
    }
    
    // Add creator as participant
    $stmt = $conn->prepare("INSERT INTO meeting_participants (room_code, user_id) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("si", $room_code, $_SESSION['user_id']);
        $stmt->execute();
        $stmt->close();
    }
} else {
    // If no database, still return a room code (for testing)
    $room_code = 'TEST' . rand(1000, 9999);
}

// Always return JSON
echo json_encode([
    'room' => $room_code,
    'success' => true,
    'user_id' => $_SESSION['user_id']
]);
?>