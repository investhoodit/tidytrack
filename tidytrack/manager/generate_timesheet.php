<?php
session_start();
require '../dp.php';

/**
 * REQUIRED INPUT
 */
$userId = $_POST['user_id'] ?? null;
$month  = $_POST['month'] ?? null;
$year   = $_POST['year'] ?? null;

if (!$userId || !$month || !$year) {
    die("Missing required fields.");
}

/**
 * CONFIG – MUST MATCH attendance logic
 */
$ATTENDANCE_MINUTES_REQUIRED = 240; // 4 hours
$MAX_DAILY_MINUTES = 540;           // 9 hours

/**
 * DATE RANGE
 */
$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate   = date('Y-m-t', strtotime($startDate));

/**
 * FETCH DAILY MINUTES FROM user_sessions
 */
$dailyMinutes = [];

$stmt = $conn->prepare("
    SELECT session_date, SUM(minutes) AS total_minutes
    FROM user_sessions
    WHERE user_id = ?
      AND session_date BETWEEN ? AND ?
    GROUP BY session_date
");
$stmt->bind_param("iss", $userId, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Safety cap at daily max
    $dailyMinutes[$row['session_date']] = min(
        (int)$row['total_minutes'],
        $MAX_DAILY_MINUTES
    );
}
$stmt->close();

/**
 * CALCULATE ATTENDANCE
 */
$totalDays = 0;
$presentDays = 0;
$absentDays = 0;
$totalMinutesWorked = 0;

for ($d = strtotime($startDate); $d <= strtotime($endDate); $d = strtotime('+1 day', $d)) {

    $day = date('Y-m-d', $d);
    $weekday = date('N', $d); // 6 = Sat, 7 = Sun

    // Skip weekends
    if ($weekday >= 6) {
        continue;
    }

    $totalDays++;

    $minutesWorked = $dailyMinutes[$day] ?? 0;
    $totalMinutesWorked += $minutesWorked;

    if ($minutesWorked >= $ATTENDANCE_MINUTES_REQUIRED) {
        $presentDays++;
    } else {
        $absentDays++;
    }
}

/**
 * TOTAL HOURS (MONTH)
 */
$totalHours = round($totalMinutesWorked / 60, 2);

/**
 * INSERT OR UPDATE TIMESHEET
 * Requires UNIQUE (user_id, month, year)
 */
$stmt = $conn->prepare("
    INSERT INTO timesheet (
        user_id,
        month,
        year,
        total_days,
        present_days,
        absent_days,
        total_hours,
        created_at,
        updated_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        total_days   = VALUES(total_days),
        present_days = VALUES(present_days),
        absent_days  = VALUES(absent_days),
        total_hours  = VALUES(total_hours),
        updated_at   = NOW()
");

$stmt->bind_param(
    "iiiiidd",
    $userId,
    $month,
    $year,
    $totalDays,
    $presentDays,
    $absentDays,
    $totalHours
);

$stmt->execute();
$stmt->close();

/**
 * REDIRECT
 */
header("Location: timesheet_list.php?user_id=" . $userId);
exit;
