<?php
session_start();
include '../dp.php';
if (!isset($_SESSION['developer_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['error' => 'Invalid date']);
    exit;
}

// All members
$allStmt = $conn->prepare("SELECT user_id, name FROM users WHERE LOWER(role) = 'member' ORDER BY name ASC");
$allStmt->execute();
$allMembers = $allStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$allStmt->close();

// Who attended (≥ 240 min) on that day
$attendStmt = $conn->prepare("
    SELECT user_id, SUM(minutes) as total_mins
    FROM user_sessions
    WHERE session_date = ?
    GROUP BY user_id
    HAVING SUM(minutes) >= 240
");
$attendStmt->bind_param("s", $date);
$attendStmt->execute();
$attendResult = $attendStmt->get_result();
$attendedIds  = [];
while ($row = $attendResult->fetch_assoc()) {
    $attendedIds[$row['user_id']] = round($row['total_mins'] / 60, 1);
}
$attendStmt->close();

// Who submitted a daily report on that day
$repStmt = $conn->prepare("
    SELECT DISTINCT user_id FROM daily_tasks WHERE sent_date = ?
");
$repStmt->bind_param("s", $date);
$repStmt->execute();
$repResult = $repStmt->get_result();
$reportedIds = [];
while ($row = $repResult->fetch_assoc()) {
    $reportedIds[$row['user_id']] = true;
}
$repStmt->close();

$attended = [];
$absent   = [];

foreach ($allMembers as $m) {
    $uid = $m['user_id'];
    $reported = isset($reportedIds[$uid]);
    if (isset($attendedIds[$uid])) {
        $attended[] = [
            'name'     => $m['name'],
            'hours'    => $attendedIds[$uid],
            'reported' => $reported
        ];
    } else {
        $absent[] = [
            'name'     => $m['name'],
            'reported' => $reported
        ];
    }
}

echo json_encode(['attended' => $attended, 'absent' => $absent]);