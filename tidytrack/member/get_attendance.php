<?php
include '../dp.php';
header('Content-Type: application/json');

$user_id = $_POST['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

$today = date('Y-m-d');
$is_weekend = date('N', strtotime($today)) >= 6;
if ($is_weekend) {
    echo json_encode([
        'success' => true,
        'timeDisplay' => '00:00',
        'remainingDisplay' => '09:00',
        'attendance' => 'Off Day',
        'totalMinutes' => 0
    ]);
    exit;
}

$stmt = $conn->prepare("SELECT SUM(minutes) as total_minutes FROM user_sessions WHERE user_id = ? AND session_date = ?");
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$total_minutes = $row['total_minutes'] ?? 0;
$stmt->close();

// Cap at 540 minutes
$total_minutes = min($total_minutes, 540);
$hours = floor($total_minutes / 60);
$minutes = $total_minutes % 60;
$time_display = sprintf("%02d:%02d", $hours, $minutes);

$remaining_minutes = max(0, 540 - $total_minutes);
$remaining_hours = floor($remaining_minutes / 60);
$remaining_minutes = $remaining_minutes % 60;
$remaining_display = sprintf("%02d:%02d", $remaining_hours, $remaining_minutes);

$attendance = ($total_minutes >= 240) ? 'Yes' : 'No';

echo json_encode([
    'success' => true,
    'timeDisplay' => $time_display,
    'remainingDisplay' => $remaining_display,
    'attendance' => $attendance,
    'totalMinutes' => $total_minutes
]);
?>