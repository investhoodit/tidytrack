<?php
require '../dp.php';

$month = date('n');   // Current month (1-12)
$year  = date('Y');

// Get all employees (exclude managers)
$users = $conn->query("SELECT user_id FROM users WHERE role != 'manager'");

while ($user = $users->fetch_assoc()) {

    $userId = $user['user_id'];

    // Check if a timesheet already exists
    $check = $conn->prepare("
        SELECT timesheet_id
        FROM timesheet
        WHERE user_id = ? AND month = ? AND year = ?
    ");
    $check->bind_param("iii", $userId, $month, $year);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows == 0) {

        // Calculate number of days in the month
        $totalDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);

        // Create empty timesheet
        $insert = $conn->prepare("
            INSERT INTO timesheet
            (
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
            VALUES (?, ?, ?, ?, 0, 0, 0, NOW(), NOW())
        ");

        $insert->bind_param(
            "iiii",
            $userId,
            $month,
            $year,
            $totalDays
        );

        $insert->execute();
    }
}

echo "Monthly timesheets generated successfully.";
?>