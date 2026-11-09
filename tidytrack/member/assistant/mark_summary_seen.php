<?php
// assistant/mark_summary_seen.php
session_start();
header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
$today = date('Y-m-d');

if ($userId) {
    $dailySummaryKey = 'daily_summary_shown_' . $userId;
    // Set the session flag to today's date
    $_SESSION[$dailySummaryKey] = $today;
    echo json_encode(['status' => 'success', 'date' => $today]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'User not logged in']);
}
?>