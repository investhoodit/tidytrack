<?php
session_start();
// Assumes dp.php is two directories up
require_once __DIR__ . '/../../dp.php';
// Include tracking logic to reuse constants and get_total_minutes_today
require_once __DIR__ . '/../../track2.php'; 

// --- AUTH GUARD ---
$userId = $_SESSION['user_id'] ?? null;
$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!$userId || $role !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden access']);
    exit;
}

// --- DEPENDENCY CHECK ---
// Ensure the global $conn variable is available and the connection is active
global $conn;
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    error_log("Database connection failed in fetch_user_attendance.php");
    echo json_encode(['error' => 'Internal error: Database connection is unavailable.']);
    exit;
}
// Ensure the required tracking function exists (check if track2.php loaded correctly)
if (!function_exists('get_total_minutes_today')) {
    http_response_code(500);
    error_log("Missing function get_total_minutes_today. Check track2.php path and content.");
    echo json_encode(['error' => 'Internal error: Required tracking logic is missing.']);
    exit;
}

$targetUserId = (int)$_GET['user_id'] ?? 0;
if ($targetUserId === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

$response = [];

// --- 1. CURRENT DAY STATUS ---
try {
    $totalMinutes = get_total_minutes_today($targetUserId); // Reuses the capped function from track2.php
    $attendanceStatus = ($totalMinutes >= ATTENDANCE_MINUTES_REQUIRED) ? 'Present (Met Requirement)' : 'Absent (Did Not Meet Requirement)';
    
    $hours = floor($totalMinutes / 60);
    $minutes = $totalMinutes % 60;

    $response['daily_status'] = [
        'minutes' => $totalMinutes,
        'display' => sprintf("%d hours and %d minutes", $hours, $minutes),
        'status' => $attendanceStatus
    ];
} catch (Exception $e) {
    // If the function throws an exception, log it and return an error message
    $response['daily_status'] = ['error' => 'Failed to retrieve daily status: ' . $e->getMessage()];
    error_log("Error fetching daily status: " . $e->getMessage());
}


// --- 2. MONTHLY AND WEEKLY ATTENDANCE LOG ---
try {
    $firstDayOfMonth = date('Y-m-01');
    $lastDayOfMonth = date('Y-m-t');

    // Use $conn which is now ensured to be available
    $stmt = $conn->prepare("
        SELECT date, attendance, absence_reason 
        FROM attendance_log 
        WHERE user_id = ? 
        AND date BETWEEN ? AND ? 
        ORDER BY date ASC
    ");
    $stmt->bind_param("iss", $targetUserId, $firstDayOfMonth, $lastDayOfMonth);
    $stmt->execute();
    $monthlyLog = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $response['monthly_log'] = $monthlyLog;
    $response['month_name'] = date('F Y');

    // Calculate Weekly Log (only Monday to Friday)
    $today = date('Y-m-d');
    $startOfWeek = date('Y-m-d', strtotime('monday this week', strtotime($today)));
    $endOfWeek = date('Y-m-d', strtotime('friday this week', strtotime($today)));
    
    // Filter the monthly log to get only the current week (Mon-Fri)
    // We only care about entries that fall on a business day in the current week.
    $weeklyLog = array_filter($monthlyLog, function($item) use ($startOfWeek, $endOfWeek) {
        $dayOfWeek = date('N', strtotime($item['date'])); // 1 (Mon) to 7 (Sun)
        return $item['date'] >= $startOfWeek && $item['date'] <= $endOfWeek && $dayOfWeek < 6;
    });

    $response['weekly_log'] = array_values($weeklyLog);

} catch (Exception $e) {
    // If the log query fails, log it and return an error message
    $response['monthly_log'] = ['error' => 'Failed to retrieve monthly log: ' . $e->getMessage()];
    error_log("Error fetching monthly log: " . $e->getMessage());
}


header('Content-Type: application/json');
echo json_encode($response);
?>
