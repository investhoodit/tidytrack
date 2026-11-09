
<?php
session_start();
include '../dp.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['ba_authenticated'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (
    empty($_SESSION['ba_user_id']) ||
    strcasecmp(trim($_SESSION['ba_department'] ?? ''), 'BA Team') !== 0
) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$date = $_GET['date'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date']);
    exit;
}

$dateObject = DateTime::createFromFormat('!Y-m-d', $date);

if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date']);
    exit;
}

// Get all members
$allStmt = $conn->prepare("
    SELECT user_id, name
    FROM users
    WHERE LOWER(role) = 'member'
    ORDER BY name ASC
");

$allStmt->execute();
$allMembers = $allStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$allStmt->close();

// Get members who attended at least four hours
$attendStmt = $conn->prepare("
    SELECT user_id, SUM(minutes) AS total_mins
    FROM user_sessions
    WHERE session_date = ?
    GROUP BY user_id
    HAVING SUM(minutes) >= 240
");

$attendStmt->bind_param("s", $date);
$attendStmt->execute();

$attendResult = $attendStmt->get_result();
$attendedIds = [];

while ($row = $attendResult->fetch_assoc()) {
    $attendedIds[$row['user_id']] = round($row['total_mins'] / 60, 1);
}

$attendStmt->close();

// Get members who submitted a daily report
$repStmt = $conn->prepare("
    SELECT DISTINCT user_id
    FROM daily_tasks
    WHERE sent_date = ?
");

$repStmt->bind_param("s", $date);
$repStmt->execute();

$repResult = $repStmt->get_result();
$reportedIds = [];

while ($row = $repResult->fetch_assoc()) {
    $reportedIds[$row['user_id']] = true;
}

$repStmt->close();

// Prepare attendance lists
$attended = [];
$absent = [];

foreach ($allMembers as $member) {
    $uid = $member['user_id'];
    $reported = isset($reportedIds[$uid]);

    if (isset($attendedIds[$uid])) {
        $attended[] = [
            'name' => $member['name'],
            'hours' => $attendedIds[$uid],
            'reported' => $reported
        ];
    } else {
        $absent[] = [
            'name' => $member['name'],
            'reported' => $reported
        ];
    }
}

echo json_encode([
    'attended' => $attended,
    'absent' => $absent
]);
