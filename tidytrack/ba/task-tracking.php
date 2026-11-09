<?php
session_start();
include '../dp.php';

/*
|--------------------------------------------------------------------------
| BA PORTAL AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['ba_authenticated'])) {
    header('Location: index.php');
    exit;
}

if (
    empty($_SESSION['ba_user_id']) ||
    strcasecmp(trim($_SESSION['ba_department'] ?? ''), 'BA Team') !== 0
) {
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| DATE SETTINGS
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Johannesburg');

$today = new DateTimeImmutable('today');

$weekChoice = $_GET['week'] ?? 'this';

if (!in_array($weekChoice, ['this', 'last'], true)) {
    $weekChoice = 'this';
}

/*
|--------------------------------------------------------------------------
| CALCULATE MONDAY AND FRIDAY
|--------------------------------------------------------------------------
*/

$monday = $today->modify('monday this week');

if ($weekChoice === 'last') {
    $monday = $monday->modify('-7 days');
}

$friday = $monday->modify('+4 days');

$selectedDate = $_GET['date'] ?? $monday->format('Y-m-d');

$selectedDateObject = DateTimeImmutable::createFromFormat(
    '!Y-m-d',
    $selectedDate
);

if (
    !$selectedDateObject ||
    $selectedDateObject->format('Y-m-d') !== $selectedDate ||
    $selectedDateObject < $monday ||
    $selectedDateObject > $friday
) {
    $selectedDateObject = $monday;
}

$selectedDate = $selectedDateObject->format('Y-m-d');

$selectedDayNumber = (int) $selectedDateObject->format('N');

$dayNames = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday'
];

$selectedDayName = $dayNames[$selectedDayNumber];

$weekStart = $monday->format('Y-m-d');
$weekEnd = $friday->format('Y-m-d');

$isUpcomingDay = $selectedDateObject > $today;

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function normalizeMemberName($name): string
{
    return strtolower(trim((string)$name));
}

/*
|--------------------------------------------------------------------------
| LOAD PROJECTS
|--------------------------------------------------------------------------
*/

$projects = [];

$projectSql = "
    SELECT
        project_id,
        project_name,
        deliverable,
        start_date,
        end_date,
        created_by
    FROM projects_x
    ORDER BY project_name ASC
";

$projectResult = $conn->query($projectSql);

if ($projectResult) {
    while ($project = $projectResult->fetch_assoc()) {
        $projectId = (int)$project['project_id'];

        $projects[$projectId] = [
            'details' => $project,
            'members' => []
        ];
    }

    $projectResult->free();
}

/*
|--------------------------------------------------------------------------
| LOAD EXISTING TASKS
|--------------------------------------------------------------------------
|
| Tasks are grouped using the real project_id and assignTo fields.
| This page does not create or modify tasks.
|
*/

$taskSql = "
    SELECT
        task_id,
        taskTitle,
        description,
        assignTo,
        created_by,
        priority,
        dueDate,
        project_id,
        created_at,
        status
    FROM tasks
    WHERE project_id IS NOT NULL
      AND assignTo IS NOT NULL
      AND TRIM(assignTo) != ''
      AND LOWER(TRIM(COALESCE(status, ''))) NOT IN ('deleted', 'archived')
    ORDER BY project_id ASC, assignTo ASC, created_at DESC
";

$taskResult = $conn->query($taskSql);

if ($taskResult) {
    while ($task = $taskResult->fetch_assoc()) {
        $projectId = (int)$task['project_id'];

        if (!isset($projects[$projectId])) {
            continue;
        }

        $memberName = trim($task['assignTo']);
        $memberKey = normalizeMemberName($memberName);

        if ($memberKey === '') {
            continue;
        }

        if (!isset($projects[$projectId]['members'][$memberKey])) {
            $projects[$projectId]['members'][$memberKey] = [
                'name' => $memberName,
                'tasks' => [],
                'profile_picture' => ''
            ];
        }

        $projects[$projectId]['members'][$memberKey]['tasks'][] = $task;
    }

    $taskResult->free();
}

/*
|--------------------------------------------------------------------------
| LOAD MEMBER PROFILE PICTURES
|--------------------------------------------------------------------------
*/

$memberProfiles = [];

$userSql = "
    SELECT
        user_id,
        name,
        profile_picture
    FROM users
    WHERE LOWER(TRIM(COALESCE(role, ''))) = 'member'
";

$userResult = $conn->query($userSql);

if ($userResult) {
    while ($user = $userResult->fetch_assoc()) {
        $memberKey = normalizeMemberName($user['name']);

        if ($memberKey !== '') {
            $memberProfiles[$memberKey] = [
                'user_id' => (int)$user['user_id'],
                'name' => $user['name'],
                'profile_picture' => $user['profile_picture'] ?? ''
            ];
        }
    }

    $userResult->free();
}

/*
|--------------------------------------------------------------------------
| LOAD DAILY REPORTS FOR THE SELECTED WEEK
|--------------------------------------------------------------------------
|
| daily_tasks.task contains the actual report text.
| daily_tasks.sent_date identifies the date of the report.
|
*/

$reportsByMemberAndDate = [];

$reportSql = "
    SELECT
        dt.id,
        dt.user_id,
        dt.task AS report_text,
        dt.sent_at,
        dt.sent_date,
        u.name AS member_name
    FROM daily_tasks dt
    INNER JOIN users u ON u.user_id = dt.user_id
    WHERE dt.sent_date BETWEEN ? AND ?
      AND LOWER(TRIM(COALESCE(u.role, ''))) = 'member'
    ORDER BY dt.sent_at DESC
";

$reportStatement = $conn->prepare($reportSql);

if ($reportStatement) {
    $reportStatement->bind_param('ss', $weekStart, $weekEnd);
    $reportStatement->execute();

    $reportResult = $reportStatement->get_result();

    while ($report = $reportResult->fetch_assoc()) {
        $memberKey = normalizeMemberName($report['member_name']);
        $reportDate = $report['sent_date'];

        if (!isset($reportsByMemberAndDate[$memberKey])) {
            $reportsByMemberAndDate[$memberKey] = [];
        }

        if (!isset($reportsByMemberAndDate[$memberKey][$reportDate])) {
            $reportsByMemberAndDate[$memberKey][$reportDate] = [];
        }

        $reportsByMemberAndDate[$memberKey][$reportDate][] = $report;
    }

    $reportStatement->close();
}

/*
|--------------------------------------------------------------------------
| ADD PROFILE PICTURES TO PROJECT MEMBERS
|--------------------------------------------------------------------------
*/

foreach ($projects as &$projectData) {
    foreach ($projectData['members'] as $memberKey => &$memberData) {
        if (isset($memberProfiles[$memberKey])) {
            $memberData['profile_picture'] =
                $memberProfiles[$memberKey]['profile_picture'];
        }
    }

    unset($memberData);
}

unset($projectData);

/*
|--------------------------------------------------------------------------
| REPORT COUNTS FOR THE SELECTED DATE
|--------------------------------------------------------------------------
*/

$submittedMembers = [];

foreach ($reportsByMemberAndDate as $memberKey => $reportsByDate) {
    if (!empty($reportsByDate[$selectedDate])) {
        $submittedMembers[$memberKey] = true;
    }
}

$totalProjectCount = count($projects);
$totalTaskCount = 0;
$totalMemberCount = 0;

foreach ($projects as $projectData) {
    foreach ($projectData['members'] as $memberData) {
        $totalMemberCount++;
        $totalTaskCount += count($memberData['tasks']);
    }
}

$weekLabel = $weekChoice === 'this' ? 'This Week' : 'Last Week';

/*
|--------------------------------------------------------------------------
| DAILY REPORT DISPLAY HELPER
|--------------------------------------------------------------------------
*/

function getMemberReports(
    array $reportsByMemberAndDate,
    string $memberKey,
    string $date
): array {
    return $reportsByMemberAndDate[$memberKey][$date] ?? [];
}

function getReportStatus(
    array $reports,
    bool $isUpcomingDay
): string {
    if (!empty($reports)) {
        return 'Submitted';
    }

    return $isUpcomingDay ? 'Upcoming' : 'Pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Task Tracking | TidyTrack BA Portal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>
        :root {
            --primary: #0d9488;
            --primary-dark: #0f766e;
            --primary-light: #ccfbf1;
            --background: #f8fafc;
            --white: #ffffff;
            --text: #172b3a;
            --muted: #64748b;
            --border: #e2e8f0;
            --success: #15803d;
            --success-bg: #dcfce7;
            --warning: #b45309;
            --warning-bg: #fef3c7;
            --blue: #1d4ed8;
            --blue-bg: #dbeafe;
            --shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'DM Sans', sans-serif;
            background: var(--background);
            color: var(--text);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        select {
            font: inherit;
        }

        .topbar {
            min-height: 76px;
            padding: 14px 6%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            background: var(--white);
            border-bottom: 1px solid var(--border);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 19px;
            font-weight: 700;
        }

        .brand-mark {
            width: 43px;
            height: 43px;
            border-radius: 12px;
            background: #f0fdfa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brand small {
            display: block;
            margin-top: 2px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 500;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--white);
            font-size: 13px;
            font-weight: 600;
            transition: .2s;
        }

        .back-button:hover {
            border-color: var(--primary);
            color: var(--primary-dark);
        }

        .page {
            width: 100%;
            max-width: 1250px;
            margin: 0 auto;
            padding: 34px 6% 48px;
            min-height: calc(100vh - 150px);
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 9px;
            margin-bottom: 23px;
            color: var(--muted);
            font-size: 12px;
        }

        .breadcrumb a:hover {
            color: var(--primary);
        }

        .breadcrumb .current {
            color: var(--text);
            font-weight: 600;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 24px;
        }

        .page-heading h1 {
            margin: 0 0 8px;
            font-size: clamp(24px, 3vw, 30px);
            letter-spacing: -0.7px;
        }

        .page-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .heading-icon {
            width: 49px;
            height: 49px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            color: var(--primary-dark);
            background: var(--primary-light);
            font-size: 20px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }

        .summary-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 19px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: var(--shadow);
        }

        .summary-icon {
            width: 43px;
            height: 43px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 11px;
            background: #f0fdfa;
            color: var(--primary);
        }

        .summary-number {
            font-size: 23px;
            font-weight: 700;
            line-height: 1.2;
        }

        .summary-label {
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
        }

        .filter-card {
            padding: 20px;
            margin-bottom: 27px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: var(--shadow);
        }

        .filter-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 17px;
        }

        .filter-title {
            font-size: 15px;
            font-weight: 700;
        }

        .week-switch {
            display: flex;
            gap: 6px;
            padding: 4px;
            background: #f1f5f9;
            border-radius: 10px;
        }

        .week-switch a {
            padding: 9px 13px;
            border-radius: 7px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .week-switch a.active {
            color: var(--primary-dark);
            background: var(--white);
            box-shadow: 0 1px 4px rgba(15, 23, 42, .08);
        }

        .day-tabs {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 9px;
        }

        .day-tab {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            padding: 13px 8px;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--muted);
            background: var(--white);
            transition: .2s;
        }

        .day-tab:hover {
            border-color: var(--primary);
        }

        .day-tab.active {
            color: var(--primary-dark);
            border-color: var(--primary);
            background: #f0fdfa;
        }

        .day-tab .day-name {
            font-size: 12px;
            font-weight: 700;
        }

        .day-tab .day-date {
            font-size: 11px;
        }

        .selected-date {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 16px;
            padding-top: 15px;
            border-top: 1px solid var(--border);
            color: var(--muted);
            font-size: 13px;
        }

        .selected-date strong {
            color: var(--text);
        }

        .date-badge {
            padding: 5px 9px;
            color: var(--primary-dark);
            background: var(--primary-light);
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }

        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin: 30px 0 16px;
        }

        .section-heading h2 {
            margin: 0;
            font-size: 19px;
        }

        .section-heading p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .project-card {
            margin-bottom: 20px;
            overflow: hidden;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
        }

        .project-header {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            padding: 20px;
            border-bottom: 1px solid var(--border);
        }

        .project-icon {
            width: 43px;
            height: 43px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-dark);
            background: var(--primary-light);
            border-radius: 11px;
        }

        .project-info {
            min-width: 0;
            flex: 1;
        }

        .project-info h3 {
            margin: 0 0 6px;
            font-size: 16px;
            overflow-wrap: anywhere;
        }

        .project-info p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .project-task-count {
            flex-shrink: 0;
            padding: 6px 9px;
            color: var(--primary-dark);
            background: #f0fdfa;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
        }

        .member-list {
            padding: 5px 20px;
        }

        .member-card {
            padding: 20px 0;
            border-bottom: 1px solid var(--border);
        }

        .member-card:last-child {
            border-bottom: 0;
        }

        .member-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .avatar {
            width: 43px;
            height: 43px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border-radius: 50%;
            background: #e2e8f0;
            color: #475569;
            font-size: 15px;
            font-weight: 700;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .member-info {
            flex: 1;
            min-width: 0;
        }

        .member-info h4 {
            margin: 0 0 4px;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .member-info p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }

        .report-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            flex-shrink: 0;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-submitted {
            color: var(--success);
            background: var(--success-bg);
        }

        .status-pending {
            color: var(--warning);
            background: var(--warning-bg);
        }

        .status-upcoming {
            color: var(--blue);
            background: var(--blue-bg);
        }

        .member-subheading {
            margin: 0 0 10px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        .task-list {
            display: grid;
            gap: 9px;
            margin-bottom: 19px;
        }

        .task-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 12px;
            background: #f8fafc;
            border: 1px solid #edf2f7;
            border-radius: 9px;
        }

        .task-main {
            min-width: 0;
            flex: 1;
        }

        .task-title {
            margin-bottom: 5px;
            font-size: 13px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .task-description {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 8px;
            color: var(--muted);
            font-size: 11px;
        }

        .task-status {
            flex-shrink: 0;
            padding: 5px 8px;
            background: #e2e8f0;
            color: #475569;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
        }

        .daily-report {
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--white);
        }

        .daily-report.report-submitted {
            border-color: #bbf7d0;
            background: #fbfffc;
        }

        .report-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .report-heading strong {
            font-size: 13px;
        }

        .report-entry {
            padding: 12px 0;
            border-top: 1px solid var(--border);
        }

        .report-entry:first-of-type {
            border-top: 0;
        }

        .report-time {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
            margin-bottom: 8px;
            color: var(--muted);
            font-size: 11px;
        }

        .report-body {
            color: #334155;
            font-size: 13px;
            line-height: 1.8;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .empty-report {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .empty-state {
            padding: 35px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            margin-bottom: 12px;
            color: #94a3b8;
            font-size: 30px;
        }

        .empty-state strong {
            display: block;
            margin-bottom: 6px;
            color: var(--text);
            font-size: 14px;
        }

        .empty-state p {
            margin: 0;
            font-size: 12px;
            line-height: 1.7;
        }

        .footer {
            padding: 22px 6%;
            background: var(--white);
            border-top: 1px solid var(--border);
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        @media (max-width: 760px) {
            .topbar {
                padding: 13px 5%;
            }

            .page {
                padding: 26px 5% 38px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .summary-card {
                padding: 14px;
            }

            .filter-card {
                padding: 15px;
            }

            .day-tabs {
                gap: 5px;
            }

            .day-tab {
                padding: 12px 3px;
            }

            .day-tab .day-name {
                font-size: 10px;
            }

            .day-tab .day-date {
                font-size: 10px;
            }

            .project-header {
                padding: 15px;
            }

            .member-list {
                padding: 3px 15px;
            }

            .member-header {
                align-items: flex-start;
            }

            .report-status {
                font-size: 10px;
                padding: 6px 8px;
            }
        }

        @media (max-width: 430px) {
            .brand {
                font-size: 16px;
            }

            .brand-mark {
                width: 37px;
                height: 37px;
            }

            .brand small {
                font-size: 10px;
            }

            .back-button {
                padding: 9px;
                font-size: 11px;
            }

            .page-heading h1 {
                font-size: 24px;
            }

            .heading-icon {
                width: 41px;
                height: 41px;
            }

            .week-switch a {
                padding: 8px 10px;
                font-size: 11px;
            }

            .project-task-count {
                font-size: 10px;
            }

            .avatar {
                width: 37px;
                height: 37px;
            }

            .member-info h4 {
                font-size: 12px;
            }

            .task-item {
                flex-direction: column;
            }
        }
        
        .project-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.project-controls button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--white);
    color: var(--text);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: .2s;
}

.project-controls button:hover {
    color: var(--primary-dark);
    border-color: var(--primary);
    background: #f0fdfa;
}

.project-header {
    cursor: pointer;
    transition: background .2s;
}

.project-header:hover {
    background: #f8fafc;
}

.project-heading-main {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    flex: 1;
    min-width: 0;
}

.project-chevron {
    width: 32px;
    height: 32px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    background: #f1f5f9;
    border-radius: 8px;
    transition: transform .2s;
}

.project-card.is-collapsed .project-chevron {
    transform: rotate(-90deg);
}

.project-card.is-collapsed .project-content {
    display: none;
}

/* Different project accent colours */
.project-card:nth-of-type(6n + 1) {
    --project-accent: #0d9488;
    --project-tint: #f0fdfa;
}

.project-card:nth-of-type(6n + 2) {
    --project-accent: #2563eb;
    --project-tint: #eff6ff;
}

.project-card:nth-of-type(6n + 3) {
    --project-accent: #9333ea;
    --project-tint: #faf5ff;
}

.project-card:nth-of-type(6n + 4) {
    --project-accent: #ea580c;
    --project-tint: #fff7ed;
}

.project-card:nth-of-type(6n + 5) {
    --project-accent: #db2777;
    --project-tint: #fdf2f8;
}

.project-card:nth-of-type(6n) {
    --project-accent: #4f46e5;
    --project-tint: #eef2ff;
}

.project-card {
    border-top: 3px solid var(--project-accent, var(--primary));
}

.project-card .project-icon {
    color: var(--project-accent, var(--primary));
    background: var(--project-tint, var(--primary-light));
}

.project-card .project-task-count {
    color: var(--project-accent, var(--primary));
    background: var(--project-tint, var(--primary-light));
}

@media (max-width: 600px) {
    .section-heading {
        align-items: flex-start;
    }

    .project-controls {
        width: 100%;
    }

    .project-controls button {
        flex: 1;
    }

    .project-heading-main {
        gap: 9px;
    }

    .project-chevron {
        width: 28px;
        height: 28px;
    }
}
    </style>
</head>

<body>

<header class="topbar">
    <a href="dashboard.php" class="brand">
        <div class="brand-mark">
            <img src="assets/images/logo.png" alt="TidyTrack Logo">
        </div>

        <div>
            TidyTrack
            <small>Business Analyst Portal</small>
        </div>
    </a>

    <a href="dashboard.php" class="back-button">
        <i class="fa-solid fa-arrow-left"></i>
        <span>Back to Projects</span>
    </a>
</header>

<main class="page">

    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
        <span class="current">Task Tracking</span>
    </div>

    <div class="page-heading">
        <div>
            <h1>Task Tracking</h1>
            <p>
                Track project tasks, member activity and daily reports
                for the selected weekday.
            </p>
        </div>

        <div class="heading-icon">
            <i class="fa-solid fa-list-check"></i>
        </div>
    </div>

    <!-- SUMMARY -->

    <div class="summary-grid">

        <div class="summary-card">
            <div class="summary-icon">
                <i class="fa-solid fa-folder-open"></i>
            </div>

            <div>
                <div class="summary-number">
                    <?= $totalProjectCount ?>
                </div>
                <div class="summary-label">Projects</div>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon">
                <i class="fa-solid fa-list-check"></i>
            </div>

            <div>
                <div class="summary-number">
                    <?= $totalTaskCount ?>
                </div>
                <div class="summary-label">Existing Tasks</div>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon">
                <i class="fa-solid fa-file-circle-check"></i>
            </div>

            <div>
                <div class="summary-number">
                    <?= count($submittedMembers) ?>
                </div>
                <div class="summary-label">
                    Members with Reports on <?= e($selectedDayName) ?>
                </div>
            </div>
        </div>

    </div>

    <!-- WEEK AND DAY FILTERS -->

    <section class="filter-card">

        <div class="filter-top">
            <div class="filter-title">
                <i class="fa-regular fa-calendar" style="color:#0d9488;margin-right:7px;"></i>
                Select a week and day
            </div>

            <div class="week-switch">
                <a
                    href="?week=this&date=<?= e($today->modify('monday this week')->format('Y-m-d')) ?>"
                    class="<?= $weekChoice === 'this' ? 'active' : '' ?>"
                >
                    This Week
                </a>

                <a
                    href="?week=last&date=<?= e($today->modify('monday this week')->modify('-7 days')->format('Y-m-d')) ?>"
                    class="<?= $weekChoice === 'last' ? 'active' : '' ?>"
                >
                    Last Week
                </a>
            </div>
        </div>

        <div class="day-tabs">

            <?php for ($i = 0; $i < 5; $i++): ?>
                <?php
                    $dayDate = $monday->modify("+{$i} days");
                    $dayDateString = $dayDate->format('Y-m-d');
                    $dayNumber = (int)$dayDate->format('N');
                ?>

                <a
                    class="day-tab <?= $selectedDate === $dayDateString ? 'active' : '' ?>"
                    href="?week=<?= e($weekChoice) ?>&date=<?= e($dayDateString) ?>"
                >
                    <span class="day-name">
                        <?= e($dayNames[$dayNumber]) ?>
                    </span>

                    <span class="day-date">
                        <?= e($dayDate->format('d M')) ?>
                    </span>
                </a>

            <?php endfor; ?>

        </div>

        <div class="selected-date">
            <i class="fa-regular fa-calendar-days"></i>

            <span>
                Viewing:
                <strong>
                    <?= e($selectedDayName) ?>,
                    <?= e($selectedDateObject->format('d F Y')) ?>
                </strong>
            </span>

            <span class="date-badge">
                <?= e($weekLabel) ?>
            </span>
        </div>

    </section>

    <!-- PROJECTS AND MEMBER TASKS -->

   <div class="section-heading">
    <div>
        <h2>Projects and Member Reports</h2>
        <p>Expand a project to view its members, tasks and daily reports.</p>
    </div>

    <div class="project-controls">
        <button type="button" id="expandAll">
            <i class="fa-solid fa-angles-down"></i> Expand All
        </button>

        <button type="button" id="collapseAll">
            <i class="fa-solid fa-angles-up"></i> Collapse All
        </button>
    </div>
</div>

    <?php if (empty($projects)): ?>

        <div class="project-card">
            <div class="empty-state">
                <i class="fa-regular fa-folder-open"></i>
                <strong>No projects found</strong>
                <p>
                    No projects were found in the projects_x table.
                </p>
            </div>
        </div>

    <?php else: ?>

        <?php foreach ($projects as $projectId => $projectData): ?>

            <?php
                $project = $projectData['details'];
                $members = $projectData['members'];

                $projectTaskCount = 0;

                foreach ($members as $memberData) {
                    $projectTaskCount += count($memberData['tasks']);
                }
            ?>

            <section class="project-card is-collapsed">

              <div class="project-header"
     role="button"
     tabindex="0"
     aria-expanded="false">

    <div class="project-heading-main">

        <div class="project-icon">
            <i class="fa-solid fa-folder-open"></i>
        </div>

        <div class="project-info">
            <h3><?= e($project['project_name']) ?></h3>

            <?php if (!empty($project['deliverable'])): ?>
                <p><?= e($project['deliverable']) ?></p>
            <?php else: ?>
                <p>Project ID: <?= (int)$projectId ?></p>
            <?php endif; ?>

            <p style="margin-top:6px;">
                <?php if (!empty($project['start_date'])): ?>
                    <i class="fa-regular fa-calendar"></i>
                    <?= e($project['start_date']) ?>
                <?php endif; ?>

                <?php if (!empty($project['end_date'])): ?>
                    &nbsp;–&nbsp; <?= e($project['end_date']) ?>
                <?php endif; ?>
            </p>
        </div>

    </div>

    <span class="project-task-count">
        <?= $projectTaskCount ?>
        <?= $projectTaskCount === 1 ? 'Task' : 'Tasks' ?>
    </span>

    <span class="project-chevron">
        <i class="fa-solid fa-chevron-down"></i>
    </span>

</div>
<div class="project-content">
                <?php if (empty($members)): ?>

                    <div class="empty-state">
                        <i class="fa-solid fa-users"></i>
                        <strong>No assigned members</strong>
                        <p>
                            No existing tasks with assigned members were found
                            for this project.
                        </p>
                    </div>

                <?php else: ?>

                    <div class="member-list">

                        <?php foreach ($members as $memberKey => $memberData): ?>

                            <?php
                                $memberName = $memberData['name'];
                                $memberTasks = $memberData['tasks'];

                                $profilePicture = trim(
                                    $memberData['profile_picture'] ?? ''
                                );

                                $initial = strtoupper(
                                    substr($memberName, 0, 1)
                                );

                                $memberReports = getMemberReports(
                                    $reportsByMemberAndDate,
                                    $memberKey,
                                    $selectedDate
                                );

                                $reportStatus = getReportStatus(
                                    $memberReports,
                                    $isUpcomingDay
                                );

                                $statusClass = match ($reportStatus) {
                                    'Submitted' => 'status-submitted',
                                    'Upcoming' => 'status-upcoming',
                                    default => 'status-pending'
                                };
                            ?>

                            <article class="member-card">

                                <!-- MEMBER INFORMATION -->

                                <div class="member-header">

                                    <div class="avatar">
                                        <?php if ($profilePicture !== ''): ?>
                                            <img
                                                src="<?= e($profilePicture) ?>"
                                                alt="<?= e($memberName) ?>"
                                                onerror="this.style.display='none';this.parentElement.textContent='<?= e($initial) ?>';"
                                            >
                                        <?php else: ?>
                                            <?= e($initial) ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="member-info">
                                        <h4><?= e($memberName) ?></h4>

                                        <p>
                                            <?= count($memberTasks) ?>
                                            assigned
                                            <?= count($memberTasks) === 1 ? 'task' : 'tasks' ?>
                                        </p>
                                    </div>

                                    <span class="report-status <?= e($statusClass) ?>">
                                        <?php if ($reportStatus === 'Submitted'): ?>
                                            <i class="fa-solid fa-circle-check"></i>
                                        <?php elseif ($reportStatus === 'Upcoming'): ?>
                                            <i class="fa-regular fa-clock"></i>
                                        <?php else: ?>
                                            <i class="fa-solid fa-circle-exclamation"></i>
                                        <?php endif; ?>

                                        <?= e($reportStatus) ?>
                                    </span>

                                </div>

                                <!-- EXISTING TASKS -->

                                <div class="member-subheading">
                                    <i class="fa-solid fa-list-check"></i>
                                    Existing Tasks
                                </div>

                                <div class="task-list">

                                    <?php foreach ($memberTasks as $task): ?>

                                        <div class="task-item">

                                            <div class="task-main">

                                                <div class="task-title">
                                                    <?= e($task['taskTitle']) ?>
                                                </div>

                                                <?php if (!empty($task['description'])): ?>
                                                    <div class="task-description">
                                                        <?= e($task['description']) ?>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="task-meta">

                                                    <?php if (!empty($task['priority'])): ?>
                                                        <span>
                                                            <i class="fa-solid fa-flag"></i>
                                                            Priority:
                                                            <?= e($task['priority']) ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <?php if (!empty($task['dueDate'])): ?>
                                                        <span>
                                                            <i class="fa-regular fa-calendar"></i>
                                                            Due:
                                                            <?= e($task['dueDate']) ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <span>
                                                        Task #<?= (int)$task['task_id'] ?>
                                                    </span>

                                                </div>

                                            </div>

                                            <span class="task-status">
                                                <?= e($task['status'] ?: 'No status') ?>
                                            </span>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                                <!-- DAILY REPORT -->

                                <div class="daily-report <?= $reportStatus === 'Submitted' ? 'report-submitted' : '' ?>">

                                    <div class="report-heading">

                                        <strong>
                                            <i class="fa-regular fa-file-lines"
                                               style="color:#0d9488;margin-right:6px;"></i>

                                            Daily Report
                                        </strong>

                                        <span style="font-size:11px;color:#64748b;">
                                            <?= e($selectedDateObject->format('d M Y')) ?>
                                        </span>

                                    </div>

                                    <?php if (!empty($memberReports)): ?>

                                        <?php foreach ($memberReports as $report): ?>

                                            <div class="report-entry">

                                                <div class="report-time">
                                                    <span>
                                                        <i class="fa-solid fa-circle-check"
                                                           style="color:#15803d;"></i>

                                                        Submitted
                                                    </span>

                                                    <?php if (!empty($report['sent_at'])): ?>
                                                        <span>
                                                            <i class="fa-regular fa-clock"></i>

                                                            <?= e(
                                                                date(
                                                                    'H:i',
                                                                    strtotime($report['sent_at'])
                                                                )
                                                            ) ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <span>
                                                        Report #<?= (int)$report['id'] ?>
                                                    </span>
                                                </div>

                                                <div class="report-body"><?= e($report['report_text']) ?></div>

                                            </div>

                                        <?php endforeach; ?>

                                    <?php elseif ($reportStatus === 'Upcoming'): ?>

                                        <div class="empty-report">
                                            This date has not arrived yet.
                                            The member's report is not expected
                                            until this weekday.
                                        </div>

                                    <?php else: ?>

                                        <div class="empty-report">
                                            <i class="fa-regular fa-file-circle-xmark"></i>

                                            No daily report was found for
                                            <?= e($memberName) ?> on
                                            <?= e($selectedDateObject->format('d F Y')) ?>.
                                        </div>

                                    <?php endif; ?>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>
</div><!-- End project-content -->
            </section>

        <?php endforeach; ?>

    <?php endif; ?>

</main>

<footer class="footer">
    TidyTrack Business Analyst Portal &copy; <?= date('Y') ?>
</footer>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const projectCards = document.querySelectorAll('.project-card');

    function setProjectState(card, expanded) {
        card.classList.toggle('is-collapsed', !expanded);

        const header = card.querySelector('.project-header');

        if (header) {
            header.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    projectCards.forEach(function (card) {
        const header = card.querySelector('.project-header');

        if (!header) {
            return;
        }

        header.addEventListener('click', function () {
            const isExpanded = !card.classList.contains('is-collapsed');
            setProjectState(card, !isExpanded);
        });

        header.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();

                const isExpanded = !card.classList.contains('is-collapsed');
                setProjectState(card, !isExpanded);
            }
        });
    });

    document.getElementById('expandAll')?.addEventListener('click', function () {
        projectCards.forEach(function (card) {
            setProjectState(card, true);
        });
    });

    document.getElementById('collapseAll')?.addEventListener('click', function () {
        projectCards.forEach(function (card) {
            setProjectState(card, false);
        });
    });
});
</script>
</body>
</html>