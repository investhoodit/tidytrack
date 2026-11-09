<?php
session_start();
require '../dp.php';

/**
 * MANAGER ACCESS ONLY
 */
$userName      = $_SESSION['name'] ?? 'Manager';
$userID        = $_SESSION['user_id'] ?? null;
$currentRole   = $_SESSION['role'] ?? 'manager';
$userInitial   = strtoupper(substr($userName, 0, 1));
$currentStatus = $_SESSION['status'] ?? 'ready';

if (!$userID || $currentRole !== 'manager') {
    die("Unauthorized. Access restricted to managers.");
}

/**
 * STATUS LABELS
 */
$status_info = [
    'ready'         => ['name' => 'Ready'],
    'out_of_office' => ['name' => 'Out of Office'],
    'sick'          => ['name' => 'Sick'],
    'focusing'      => ['name' => 'Focusing'],
    'in_meeting'    => ['name' => 'In Meeting'],
    'offline'       => ['name' => 'Offline'],
];

$statusLabel = $status_info[$currentStatus]['name']
    ?? ucfirst(str_replace('_', ' ', $currentStatus));

/**
 * TARGET USER ID
 */
$targetUserId = isset($_GET['user_id'])
    ? (int) $_GET['user_id']
    : 0;

if ($targetUserId <= 0) {
    die("User ID missing.");
}

/**
 * MONTH / YEAR SELECTION
 */
$currentYear  = (int) date('Y');
$currentMonth = (int) date('n');

$selectedYear = isset($_GET['year'])
    ? (int) $_GET['year']
    : $currentYear;

$selectedMonth = isset($_GET['month'])
    ? (int) $_GET['month']
    : $currentMonth;

if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}

if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = $currentYear;
}

/**
 * FETCH USER DETAILS
 */
$userStmt = $conn->prepare("
    SELECT
        user_id,
        name,
        email,
        role,
        COALESCE(department, 'Not assigned') AS department,
        COALESCE(phone, '—') AS phone,
        COALESCE(bio, '') AS bio
    FROM users
    WHERE user_id = ?
    LIMIT 1
");

$userStmt->bind_param("i", $targetUserId);
$userStmt->execute();

$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();

$userStmt->close();

if (!$user) {
    die("User not found.");
}

/**
 * FETCH DAILY ATTENDANCE NOTES
 *
 * Attendance status is no longer used to determine whether
 * the employee is present or absent.
 *
 * This table is only used for optional absence reasons or notes.
 */
$attendanceQuery = "
    SELECT
        date,
        attendance,
        absence_reason
    FROM attendance_log
    WHERE user_id = ?
      AND YEAR(date) = ?
      AND MONTH(date) = ?
    ORDER BY date ASC
";

$attStmt = $conn->prepare($attendanceQuery);
$attStmt->bind_param(
    "iii",
    $targetUserId,
    $selectedYear,
    $selectedMonth
);
$attStmt->execute();

$attResult = $attStmt->get_result();

$dailyRecords = [];

while ($row = $attResult->fetch_assoc()) {
    $dailyRecords[$row['date']] = $row;
}

$attStmt->close();

/**
 * FETCH DAILY LOGGED MINUTES
 *
 * All user session records for the same day are added together.
 */
$sessionsQuery = "
    SELECT
        session_date,
        SUM(minutes) AS total_minutes
    FROM user_sessions
    WHERE user_id = ?
      AND YEAR(session_date) = ?
      AND MONTH(session_date) = ?
    GROUP BY session_date
    ORDER BY session_date ASC
";

$sessStmt = $conn->prepare($sessionsQuery);
$sessStmt->bind_param(
    "iii",
    $targetUserId,
    $selectedYear,
    $selectedMonth
);
$sessStmt->execute();

$sessResult = $sessStmt->get_result();

$dailyMinutes = [];

while ($row = $sessResult->fetch_assoc()) {
    $dailyMinutes[$row['session_date']] =
        (int) $row['total_minutes'];
}

$sessStmt->close();

/**
 * BUILD ALL DAYS OF SELECTED MONTH
 */
$daysInMonth = cal_days_in_month(
    CAL_GREGORIAN,
    $selectedMonth,
    $selectedYear
);

$monthDates = [];

for ($day = 1; $day <= $daysInMonth; $day++) {
    $dateString = sprintf(
        '%04d-%02d-%02d',
        $selectedYear,
        $selectedMonth,
        $day
    );

    $monthDates[] = $dateString;
}

/**
 * CALCULATE ATTENDANCE USING LOGGED MINUTES
 *
 * 240 minutes or more = Present
 * Less than 240 minutes = Absent
 * Saturday and Sunday = Excluded
 */
$totalPresent = 0;
$totalAbsent  = 0;
$totalDays    = 0;

foreach ($monthDates as $date) {
    $dayNumber = (int) date('N', strtotime($date));
    $isWeekend = $dayNumber >= 6;

    if ($isWeekend) {
        continue;
    }

    $totalDays++;

    $minutesLogged = $dailyMinutes[$date] ?? 0;

    if ($minutesLogged >= 240) {
        $totalPresent++;
    } else {
        $totalAbsent++;
    }
}

/**
 * TOTAL LOGGED TIME
 */
$totalMinutesMonth = array_sum($dailyMinutes);
$totalHours = round($totalMinutesMonth / 60, 2);

/**
 * ATTENDANCE PERCENTAGE
 */
$attendanceRate = $totalDays > 0
    ? round(($totalPresent / $totalDays) * 100, 1)
    : 0;

/**
 * PRESENT DAYS AND FULL DAYS ARE THE SAME
 */
$fullDaysCount = $totalPresent;

/**
 * FORMAT MINUTES
 */
function formatMinutes($minutes)
{
    $minutes = (int) $minutes;

    if ($minutes <= 0) {
        return '—';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    if ($hours > 0 && $remainingMinutes > 0) {
        return "{$hours}h {$remainingMinutes}min";
    }

    if ($hours > 0) {
        return "{$hours}h";
    }

    return "{$remainingMinutes}min";
}

/**
 * GET SHORT DAY NAME
 */
function getDayName($dateStr)
{
    return date('D', strtotime($dateStr));
}

/**
 * ESCAPE HTML
 */
function escapeHtml($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>
        Detailed Timesheet –
        <?= escapeHtml($user['name']) ?>
    </title>

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap"
        rel="stylesheet"
    >

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f8;
            padding: 30px 20px;
            color: #1e293b;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .back-btn {
            background: #1e3a5f;
            color: white;
            padding: 10px 18px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-btn:hover {
            background: #0f2b44;
            transform: translateY(-1px);
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .action-btn {
            background: white;
            border: 1px solid #cbd5e1;
            padding: 9px 18px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 500;
            color: #1e3a5f;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
            font-size: 0.85rem;
            cursor: pointer;
        }

        .action-btn:hover {
            background: #f8fafc;
            border-color: #1e3a5f;
        }

        .profile-card {
            background: white;
            border-radius: 24px;
            padding: 20px 28px;
            margin-bottom: 28px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .employee-info h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: #0f172a;
        }

        .employee-details {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            margin-top: 8px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #475569;
        }

        .detail-item i {
            width: 20px;
            color: #3b7cbf;
        }

        .employee-bio {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #5b6e8c;
        }

        .status-badge {
            background: #e6f0fa;
            padding: 8px 20px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .month-selector {
            background: white;
            border-radius: 20px;
            padding: 15px 20px;
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
        }

        .filter-form {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-form select,
        .filter-form button {
            padding: 10px 16px;
            border-radius: 40px;
            border: 1px solid #cbd5e1;
            background: white;
            font-weight: 500;
            cursor: pointer;
        }

        .filter-form button {
            background: #1e3a5f;
            color: white;
            border: none;
        }

        .summary-stats {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .stat {
            text-align: center;
            min-width: 85px;
        }

        .stat .label {
            display: block;
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }

        .stat .value {
            display: block;
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
        }

        .timesheet-wrapper {
            background: white;
            border-radius: 24px;
            overflow-x: auto;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th {
            background: #f8fafc;
            padding: 16px 12px;
            text-align: left;
            font-weight: 600;
            color: #1e3a5f;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
        }

        tr:hover td {
            background-color: #fafcff;
        }

        .absent-row td {
            background-color: #fef9e6;
            color: #7c6b3b;
        }

        .weekend-row td {
            background-color: #f8f9fc;
            color: #94a3b8;
        }

        .status-present {
            color: #2b7e3a;
            background: #e3f5e9;
            display: inline-block;
            padding: 4px 10px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-absent {
            color: #b45353;
            background: #ffe6e6;
            display: inline-block;
            padding: 4px 10px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-weekend {
            color: #64748b;
            background: #e2e8f0;
            display: inline-block;
            padding: 4px 10px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .notes-cell {
            max-width: 250px;
            font-size: 0.75rem;
            color: #5b6e8c;
        }

        .minutes-cell {
            font-weight: 500;
            font-family: monospace;
            font-size: 0.8rem;
        }

        .full-day-cell {
            font-weight: 600;
            text-align: center;
        }

        .full-day-yes {
            color: #2b7e3a;
        }

        .full-day-no {
            color: #b45353;
        }

        .empty-state {
            text-align: center;
            padding: 50px;
            color: #5b6e8c;
        }

        .footer-note {
            margin-top: 20px;
            text-align: right;
            font-size: 0.72rem;
            line-height: 1.6;
            color: #7e8ba0;
        }

        @media (max-width: 768px) {
            body {
                padding: 18px 10px;
            }

            .profile-card,
            .month-selector {
                padding: 18px;
            }

            .summary-stats {
                width: 100%;
                justify-content: space-between;
            }

            .stat {
                flex: 1 1 90px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="top-bar">

        <a
            href="manager_index.php"
            class="back-btn"
        >
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>

        <div class="action-buttons">

            <a
                href="export_timesheet_pdf.php?user_id=<?= urlencode($targetUserId) ?>&year=<?= urlencode($selectedYear) ?>&month=<?= urlencode($selectedMonth) ?>"
                class="action-btn"
            >
                <i class="fas fa-file-pdf"></i>
                Export PDF
            </a>


        </div>

    </div>

    <div class="profile-card">

        <div class="employee-info">

            <h1>
                <?= escapeHtml($user['name']) ?>
            </h1>

            <div class="employee-details">

                <span class="detail-item">
                    <i class="fas fa-id-badge"></i>
                    ID: <?= escapeHtml($user['user_id']) ?>
                </span>

                <span class="detail-item">
                    <i class="fas fa-building"></i>
                    <?= escapeHtml($user['department']) ?>
                </span>

                <span class="detail-item">
                    <i class="fas fa-envelope"></i>
                    <?= escapeHtml($user['email']) ?>
                </span>

                <span class="detail-item">
                    <i class="fas fa-phone"></i>
                    <?= escapeHtml($user['phone']) ?>
                </span>

                <span class="detail-item">
                    <i class="fas fa-user-tag"></i>
                    Role:
                    <?= escapeHtml(ucfirst($user['role'])) ?>
                </span>

            </div>

            <?php if (!empty($user['bio'])): ?>

                <div class="employee-bio">
                    <i class="fas fa-info-circle"></i>
                    <?= escapeHtml($user['bio']) ?>
                </div>

            <?php endif; ?>

        </div>

        <div class="status-badge">

            <i
                class="fas fa-circle"
                style="
                    color:
                    <?= $currentStatus === 'ready'
                        ? '#2ecc71'
                        : '#f39c12' ?>;
                    font-size: 0.7rem;
                "
            ></i>

            Manager:
            <?= escapeHtml($statusLabel) ?>

        </div>

    </div>

    <div class="month-selector">

        <form
            method="GET"
            class="filter-form"
        >

            <input
                type="hidden"
                name="user_id"
                value="<?= escapeHtml($targetUserId) ?>"
            >

            <select name="month">

                <?php for ($month = 1; $month <= 12; $month++): ?>

                    <option
                        value="<?= $month ?>"
                        <?= $selectedMonth === $month
                            ? 'selected'
                            : '' ?>
                    >
                        <?= date(
                            'F',
                            mktime(0, 0, 0, $month, 1)
                        ) ?>
                    </option>

                <?php endfor; ?>

            </select>

            <select name="year">

                <?php
                for (
                    $year = $currentYear - 2;
                    $year <= $currentYear + 1;
                    $year++
                ):
                ?>

                    <option
                        value="<?= $year ?>"
                        <?= $selectedYear === $year
                            ? 'selected'
                            : '' ?>
                    >
                        <?= $year ?>
                    </option>

                <?php endfor; ?>

            </select>

            <button type="submit">
                <i class="fas fa-calendar-week"></i>
                Load Timesheet
            </button>

        </form>

        <div class="summary-stats">

            <div class="stat">
                <span class="label">Working Days</span>
                <span class="value"><?= $totalDays ?></span>
            </div>

            <div class="stat">
                <span class="label">Present (4h+)</span>
                <span class="value"><?= $totalPresent ?></span>
            </div>

            <div class="stat">
                <span class="label">Absent (&lt;4h)</span>
                <span class="value"><?= $totalAbsent ?></span>
            </div>

            <div class="stat">
                <span class="label">Total Logged</span>
                <span class="value">
                    <?= formatMinutes($totalMinutesMonth) ?>
                </span>
            </div>

            <div class="stat">
                <span class="label">Total Hours</span>
                <span class="value">
                    <?= number_format($totalHours, 1) ?>h
                </span>
            </div>

            <div class="stat">
                <span class="label">Attendance %</span>
                <span class="value">
                    <?= number_format($attendanceRate, 1) ?>%
                </span>
            </div>

        </div>

    </div>

    <div class="timesheet-wrapper">

        <table>

            <thead>

                <tr>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Attendance</th>
                    <th>Minutes Logged</th>
                    <th>Completed 4 Hours</th>
                    <th>Reason / Notes</th>
                </tr>

            </thead>

            <tbody>

            <?php if (empty($monthDates)): ?>

                <tr>
                    <td
                        colspan="6"
                        class="empty-state"
                    >
                        No data available for this period.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($monthDates as $date): ?>

                    <?php
                    $record = $dailyRecords[$date] ?? null;

                    $dayNumber = (int) date(
                        'N',
                        strtotime($date)
                    );

                    $isWeekend = $dayNumber >= 6;

                    $minutesLogged =
                        $dailyMinutes[$date] ?? 0;

                    $dayName = getDayName($date);

                    $minutesFormatted =
                        formatMinutes($minutesLogged);

                    $rowClass = '';
                    $attendanceDisplay = '';
                    $fullDayDisplay = '';
                    $reason = '';

                    if ($isWeekend) {
                        $rowClass = 'weekend-row';

                        $attendanceDisplay = '
                            <span class="status-weekend">
                                <i class="fas fa-calendar"></i>
                                Weekend
                            </span>
                        ';

                        $fullDayDisplay = '—';
                        $reason = 'Scheduled day off';
                    } elseif ($minutesLogged >= 240) {
                        $attendanceDisplay = '
                            <span class="status-present">
                                <i class="fas fa-check-circle"></i>
                                Present
                            </span>
                        ';

                        $fullDayDisplay = '
                            <span class="full-day-yes">
                                ✅ Yes
                            </span>
                        ';

                        $reason =
                            'Completed at least 4 logged hours';
                    } else {
                        $rowClass = 'absent-row';

                        $attendanceDisplay = '
                            <span class="status-absent">
                                <i class="fas fa-times-circle"></i>
                                Absent
                            </span>
                        ';

                        $fullDayDisplay = '
                            <span class="full-day-no">
                                ❌ No
                            </span>
                        ';

                        if ($minutesLogged > 0) {
                            $reason =
                                'Logged less than 4 hours';
                        } else {
                            $reason =
                                'No logged work time';
                        }

                        /*
                         * Append the manager-provided note,
                         * when one exists.
                         */
                        if (
                            $record &&
                            !empty($record['absence_reason'])
                        ) {
                            $reason .=
                                ' — ' .
                                $record['absence_reason'];
                        }
                    }
                    ?>

                    <tr class="<?= escapeHtml($rowClass) ?>">

                        <td>
                            <strong>
                                <?= date(
                                    'd M Y',
                                    strtotime($date)
                                ) ?>
                            </strong>
                        </td>

                        <td>
                            <?= escapeHtml($dayName) ?>
                        </td>

                        <td>
                            <?= $attendanceDisplay ?>
                        </td>

                        <td class="minutes-cell">
                            <?= escapeHtml($minutesFormatted) ?>
                        </td>

                        <td class="full-day-cell">
                            <?= $fullDayDisplay ?>
                        </td>

                        <td class="notes-cell">
                            <?= escapeHtml($reason) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

    <div class="footer-note">

        <i class="fas fa-info-circle"></i>

        Timesheet for

        <strong>
            <?= date(
                'F Y',
                strtotime(
                    sprintf(
                        '%04d-%02d-01',
                        $selectedYear,
                        $selectedMonth
                    )
                )
            ) ?>
        </strong>.

        Employees who log at least
        <strong>240 minutes (4 hours)</strong>
        are marked Present.

        Employees who log fewer than
        <strong>240 minutes</strong>
        are marked Absent.

        Saturdays and Sundays are excluded from the
        attendance calculation.

    </div>

</div>

<script>
document
    .getElementById('emailSheetBtn')
    ?.addEventListener('click', function () {
        const userEmail = <?= json_encode($user['email']) ?>;

        const monthYear = <?= json_encode(
            date(
                'F Y',
                strtotime(
                    sprintf(
                        '%04d-%02d-01',
                        $selectedYear,
                        $selectedMonth
                    )
                )
            )
        ) ?>;

        const confirmed = confirm(
            `Send timesheet for ${monthYear} to ${userEmail}?`
        );

        if (confirmed) {
            const url =
                'send_timesheet_email.php' +
                '?user_id=<?= urlencode($targetUserId) ?>' +
                '&year=<?= urlencode($selectedYear) ?>' +
                '&month=<?= urlencode($selectedMonth) ?>';

            window.location.href = url;
        }
    });
</script>

</body>
</html>