<?php
require_once '../dp.php';
header('Content-Type: application/json');

// Get the data from the JavaScript pulse
$data = json_decode(file_get_contents('php://input'), true);

if (!$conn) {
    echo json_encode(['status' => 'error', 'message' => 'DB Connection Failed']);
    exit;
}

// 1. Update the used minutes (Each pulse = 1 person-minute used)
$conn->query("UPDATE meeting_settings SET used_this_month = used_this_month + 1 WHERE id = 1");

// 2. Check the balance
$res = $conn->query("SELECT used_this_month, monthly_balance FROM meeting_settings WHERE id = 1");
$row = $res->fetch_assoc();

// If row doesn't exist, the table hasn't been set up correctly
if (!$row) {
    echo json_encode(['status' => 'error', 'message' => 'Table not initialized']);
    exit;
}

if ($row['used_this_month'] >= $row['monthly_balance']) {
    echo json_encode(['status' => 'exhausted']);
} else {
    echo json_encode(['status' => 'ok', 'remaining' => ($row['monthly_balance'] - $row['used_this_month'])]);
}