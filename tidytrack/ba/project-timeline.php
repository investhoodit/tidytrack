<?php
session_start();
include '../dp.php';

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

date_default_timezone_set('Africa/Johannesburg');

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$search = trim($_GET['search'] ?? '');
$filter = $_GET['status'] ?? 'all';

$allowedFilters = ['all', 'upcoming', 'active', 'overdue', 'completed'];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

/*
|--------------------------------------------------------------------------
| Fetch projects and calculate task progress
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.project_id,
        p.project_name,
        p.deliverable,
        p.start_date,
        p.end_date,
        p.created_by,
        p.teamLeadder_id,

        leader.name AS team_lead_name,
        creator.name AS creator_name,

        COALESCE(task_stats.total_tasks, 0) AS total_tasks,
        COALESCE(task_stats.completed_tasks, 0) AS completed_tasks

    FROM projects_x p

    LEFT JOIN users leader
        ON leader.user_id = p.teamLeadder_id

    LEFT JOIN users creator
        ON creator.user_id = p.created_by

    LEFT JOIN (
        SELECT
            project_id,
            COUNT(*) AS total_tasks,
            SUM(
                CASE
                    WHEN status IN ('Completed', 'Approved') THEN 1
                    ELSE 0
                END
            ) AS completed_tasks
        FROM tasks
        WHERE status NOT IN ('Archived', 'Deleted')
        GROUP BY project_id
    ) task_stats
        ON task_stats.project_id = p.project_id

    ORDER BY p.start_date ASC, p.project_name ASC
";

$result = $conn->query($sql);

if (!$result) {
    error_log('Project timeline query failed: ' . $conn->error);
    die('Unable to load the project timeline. Please check the database configuration.');
}

$projects = [];

while ($row = $result->fetch_assoc()) {

    $totalTasks = (int)$row['total_tasks'];
    $completedTasks = (int)$row['completed_tasks'];

    $row['progress'] = $totalTasks > 0
        ? (int)round(($completedTasks / $totalTasks) * 100)
        : 0;

    $startDate = $row['start_date'] ?? '';
    $endDate = $row['end_date'] ?? '';

    if (
        $totalTasks > 0 &&
        $completedTasks >= $totalTasks
    ) {
        $row['timeline_status'] = 'completed';

    } elseif (
        $startDate &&
        $startDate > $today
    ) {
        $row['timeline_status'] = 'upcoming';

    } elseif (
        $endDate &&
        $endDate < $today
    ) {
        $row['timeline_status'] = 'overdue';

    } else {
        $row['timeline_status'] = 'active';
    }

    $projects[] = $row;
}

/*
|--------------------------------------------------------------------------
| Search and filter
|--------------------------------------------------------------------------
*/

$filteredProjects = [];

foreach ($projects as $project) {

    $searchText = strtolower(
        ($project['project_name'] ?? '') . ' ' .
        ($project['deliverable'] ?? '') . ' ' .
        ($project['team_lead_name'] ?? '')
    );

    if (
        $search !== '' &&
        strpos($searchText, strtolower($search)) === false
    ) {
        continue;
    }

    if (
        $filter !== 'all' &&
        $project['timeline_status'] !== $filter
    ) {
        continue;
    }

    $filteredProjects[] = $project;
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$totalProjects = count($projects);
$upcomingProjects = 0;
$activeProjects = 0;
$overdueProjects = 0;
$completedProjects = 0;

foreach ($projects as $project) {

    switch ($project['timeline_status']) {
        case 'upcoming':
            $upcomingProjects++;
            break;

        case 'active':
            $activeProjects++;
            break;

        case 'overdue':
            $overdueProjects++;
            break;

        case 'completed':
            $completedProjects++;
            break;
    }
}

/*
|--------------------------------------------------------------------------
| Calculate the overall timeline scale
|--------------------------------------------------------------------------
*/

$validDates = [];

foreach ($filteredProjects as $project) {

    if (
        !empty($project['start_date']) &&
        strtotime($project['start_date']) !== false
    ) {
        $validDates[] = strtotime($project['start_date']);
    }

    if (
        !empty($project['end_date']) &&
        strtotime($project['end_date']) !== false
    ) {
        $validDates[] = strtotime($project['end_date']);
    }
}

if (!empty($validDates)) {
    $timelineStart = min($validDates);
    $timelineEnd = max($validDates);
} else {
    $timelineStart = strtotime($today);
    $timelineEnd = strtotime($today);
}

$timelineDays = max(
    1,
    (int)ceil(($timelineEnd - $timelineStart) / 86400)
);

$timelineStartLabel = date('d M Y', $timelineStart);
$timelineEndLabel = date('d M Y', $timelineEnd);

function formatDate($date) {
    if (empty($date) || strtotime($date) === false) {
        return 'Not set';
    }

    return date('d M Y', strtotime($date));
}

function statusLabel($status) {
    $labels = [
        'upcoming' => 'Upcoming',
        'active' => 'In Progress',
        'overdue' => 'Overdue',
        'completed' => 'Completed'
    ];

    return $labels[$status] ?? 'Unknown';
}

function timelinePosition($date, $timelineStart, $timelineDays) {

    if (empty($date) || strtotime($date) === false) {
        return 0;
    }

    $position = (
        (strtotime($date) - $timelineStart) / 86400
    ) / $timelineDays * 100;

    return max(0, min(100, $position));
}

function timelineWidth($startDate, $endDate, $timelineStart, $timelineDays) {

    if (
        empty($startDate) ||
        empty($endDate) ||
        strtotime($startDate) === false ||
        strtotime($endDate) === false
    ) {
        return 0;
    }

    $start = strtotime($startDate);
    $end = strtotime($endDate);

    if ($end < $start) {
        return 0;
    }

    $width = (($end - $start) / 86400) / $timelineDays * 100;

    return max(1, min(100, $width));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Project Timeline | TidyTrack BA Portal</title>

    <link rel="icon" href="assets/images/logo.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

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
            --red: #dc2626;
            --orange: #ea580c;
            --blue: #2563eb;
            --green: #15803d;
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

        .topbar {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            min-height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 14px 6%;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
            font-weight: 800;
        }

        .brand img {
            width: 42px;
            height: 42px;
            object-fit: contain;
        }

        .brand span {
            color: var(--primary);
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 10px 16px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--white);
            color: var(--text);
            font-size: 13px;
            font-weight: 700;
            transition: 0.2s;
        }

        .back-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .page {
            max-width: 1250px;
            margin: auto;
            padding: 35px 6% 60px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 22px;
        }

        .breadcrumb a:hover {
            color: var(--primary);
        }

        .page-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 27px;
        }

        .page-heading h1 {
            font-size: 29px;
            margin: 0 0 8px;
            letter-spacing: -0.8px;
        }

        .page-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .today-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 10px 14px;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 27px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 19px;
            min-width: 0;
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 17px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .stat-icon {
            width: 35px;
            height: 35px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: var(--muted);
            flex-shrink: 0;
        }

        .stat-icon.teal {
            color: var(--primary);
            background: #ccfbf1;
        }

        .stat-icon.blue {
            color: var(--blue);
            background: #dbeafe;
        }

        .stat-icon.orange {
            color: var(--orange);
            background: #ffedd5;
        }

        .stat-icon.red {
            color: var(--red);
            background: #fee2e2;
        }

        .stat-icon.green {
            color: var(--green);
            background: #dcfce7;
        }

        .stat-value {
            font-size: 27px;
            font-weight: 800;
            line-height: 1;
        }

        .panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            margin-bottom: 24px;
            overflow: hidden;
        }

        .panel-header {
            padding: 21px 23px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .panel-header h2 {
            margin: 0 0 5px;
            font-size: 16px;
        }

        .panel-header p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }

        .panel-body {
            padding: 22px 23px;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 200px;
        }

        .search-box i {
            position: absolute;
            top: 50%;
            left: 13px;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 13px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 12px 12px 37px;
        }

        input,
        select {
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--white);
            color: var(--text);
            font-family: inherit;
            font-size: 13px;
            outline: none;
        }

        select {
            min-width: 165px;
            padding: 12px;
        }

        input:focus,
        select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.08);
        }

        .filter-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 17px;
            border: 0;
            border-radius: 9px;
            background: var(--primary);
            color: white;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
        }

        .filter-btn:hover {
            background: var(--primary-dark);
        }

        .reset-btn {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .timeline-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 23px;
        }

        .timeline-header h2 {
            margin: 0 0 5px;
            font-size: 17px;
        }

        .timeline-header p {
            margin: 0;
            font-size: 12px;
            color: var(--muted);
        }

        .legend {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 13px;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: var(--muted);
        }

        .legend-dot {
            width: 9px;
            height: 9px;
            border-radius: 3px;
        }

        .dot-upcoming {
            background: var(--blue);
        }

        .dot-active {
            background: var(--primary);
        }

        .dot-overdue {
            background: var(--red);
        }

        .dot-completed {
            background: var(--green);
        }

        .timeline-scroll {
            overflow-x: auto;
            padding-bottom: 5px;
        }

        .timeline-layout {
            min-width: 680px;
        }

        .scale-row {
            display: grid;
            grid-template-columns: 235px minmax(0, 1fr);
            border-bottom: 1px solid var(--border);
            padding-bottom: 13px;
            margin-bottom: 5px;
        }

        .scale-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
        }

        .scale-dates {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            color: var(--muted);
            font-size: 10px;
        }

        .timeline-row {
            display: grid;
            grid-template-columns: 235px minmax(0, 1fr);
            align-items: center;
            gap: 0;
            min-height: 105px;
            border-bottom: 1px solid #f1f5f9;
        }

        .timeline-row:last-child {
            border-bottom: none;
        }

        .project-info {
            padding: 17px 15px 17px 0;
            min-width: 0;
        }

        .project-name {
            font-size: 13px;
            font-weight: 800;
            line-height: 1.5;
            overflow-wrap: anywhere;
            margin-bottom: 5px;
        }

        .project-meta {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.8;
        }

        .project-meta i {
            width: 14px;
            margin-right: 3px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 800;
            margin-top: 7px;
        }

        .status-upcoming {
            color: #1d4ed8;
            background: #dbeafe;
        }

        .status-active {
            color: var(--primary-dark);
            background: #ccfbf1;
        }

        .status-overdue {
            color: #b91c1c;
            background: #fee2e2;
        }

        .status-completed {
            color: #166534;
            background: #dcfce7;
        }

        .timeline-track {
            position: relative;
            height: 49px;
            border-radius: 8px;
            background-color: #f8fafc;
            background-image: linear-gradient(
                to right,
                #e2e8f0 1px,
                transparent 1px
            );
            background-size: 25% 100%;
            border-left: 1px solid var(--border);
            border-right: 1px solid var(--border);
        }

        .timeline-bar {
            position: absolute;
            top: 9px;
            height: 31px;
            min-width: 5px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            overflow: hidden;
            padding: 0 9px;
            color: white;
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
            box-shadow: 0 2px 4px rgba(15, 23, 42, 0.08);
        }

        .bar-upcoming {
            background: var(--blue);
        }

        .bar-active {
            background: var(--primary);
        }

        .bar-overdue {
            background: var(--red);
        }

        .bar-completed {
            background: var(--green);
        }

        .no-dates {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
        }

        .today-line {
            position: absolute;
            z-index: 2;
            top: -8px;
            bottom: -8px;
            width: 2px;
            background: #f59e0b;
            pointer-events: none;
        }

        .today-line::before {
            content: "TODAY";
            position: absolute;
            top: -16px;
            left: 50%;
            transform: translateX(-50%);
            background: #fef3c7;
            color: #92400e;
            padding: 3px 5px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: 800;
        }

        .empty-state {
            text-align: center;
            padding: 55px 20px;
        }

        .empty-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 17px;
            border-radius: 17px;
            background: #ccfbf1;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
        }

        .empty-state h3 {
            margin: 0 0 8px;
            font-size: 16px;
        }

        .empty-state p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .project-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .project-card {
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 18px;
        }

        .project-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 16px;
        }

        .project-card h3 {
            margin: 0 0 6px;
            font-size: 14px;
        }

        .project-card p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .progress-number {
            font-size: 20px;
            font-weight: 800;
            white-space: nowrap;
        }

        .progress-track {
            height: 7px;
            border-radius: 20px;
            background: #e2e8f0;
            overflow: hidden;
            margin-bottom: 9px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 20px;
            background: var(--primary);
        }

        .progress-footer {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            color: var(--muted);
            font-size: 11px;
        }

        .footer {
            text-align: center;
            padding: 23px 15px;
            color: var(--muted);
            font-size: 11px;
            border-top: 1px solid var(--border);
            background: var(--white);
        }

        @media (max-width: 1000px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .topbar {
                padding: 13px 5%;
            }

            .brand {
                font-size: 17px;
            }

            .brand img {
                width: 35px;
                height: 35px;
            }

            .back-btn {
                padding: 9px 11px;
                font-size: 11px;
            }

            .page {
                padding: 27px 5% 40px;
            }

            .page-heading {
                flex-direction: column;
            }

            .page-heading h1 {
                font-size: 25px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-value {
                font-size: 24px;
            }

            .panel-header,
            .panel-body {
                padding: 17px;
            }

            .filters {
                align-items: stretch;
            }

            .search-box {
                flex-basis: 100%;
            }

            .filters select,
            .filter-btn {
                flex: 1;
            }

            .timeline-layout {
                min-width: 630px;
            }
        }
    </style>
</head>

<body>

<header class="topbar">

    <a href="index.php" class="brand">
        <img src="assets/images/logo.png" alt="TidyTrack Logo">
        <div>Tidy<span>Track</span></div>
    </a>

    <a href="project-details.php" class="back-btn">
        <i class="fa-solid fa-arrow-left"></i>
        Back to Projects
    </a>

</header>

<main class="page">

    <div class="breadcrumb">
        <a href="index.php">Dashboard</a>
        <i class="fa-solid fa-chevron-right"></i>
        <span>Project Timeline</span>
    </div>

    <section class="page-heading">

        <div>
            <h1>Project Timeline</h1>

            <p>
                Track project schedules, monitor progress and identify
                projects that need attention.
            </p>
        </div>

        <div class="today-badge">
            <i class="fa-regular fa-calendar"></i>
            <?= e(date('d F Y')) ?>
        </div>

    </section>

    <!-- Statistics -->

    <section class="stats-grid">

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Total Projects</span>
                <div class="stat-icon teal">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
            </div>
            <div class="stat-value"><?= $totalProjects ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Upcoming</span>
                <div class="stat-icon blue">
                    <i class="fa-regular fa-calendar"></i>
                </div>
            </div>
            <div class="stat-value"><?= $upcomingProjects ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">In Progress</span>
                <div class="stat-icon teal">
                    <i class="fa-solid fa-spinner"></i>
                </div>
            </div>
            <div class="stat-value"><?= $activeProjects ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Overdue</span>
                <div class="stat-icon red">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
            </div>
            <div class="stat-value"><?= $overdueProjects ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Completed</span>
                <div class="stat-icon green">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </div>
            <div class="stat-value"><?= $completedProjects ?></div>
        </div>

    </section>

    <!-- Search and Filters -->

    <section class="panel">

        <div class="panel-header">
            <div>
                <h2>Find a Project</h2>
                <p>Search projects or filter them by their timeline status.</p>
            </div>
        </div>

        <div class="panel-body">

            <form method="GET" class="filters">

                <div class="search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>

                    <input
                        type="search"
                        name="search"
                        placeholder="Search project, deliverable or team lead..."
                        value="<?= e($search) ?>"
                    >
                </div>

                <select name="status">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>
                        All Projects
                    </option>

                    <option value="upcoming" <?= $filter === 'upcoming' ? 'selected' : '' ?>>
                        Upcoming
                    </option>

                    <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>
                        In Progress
                    </option>

                    <option value="overdue" <?= $filter === 'overdue' ? 'selected' : '' ?>>
                        Overdue
                    </option>

                    <option value="completed" <?= $filter === 'completed' ? 'selected' : '' ?>>
                        Completed
                    </option>
                </select>

                <button type="submit" class="filter-btn">
                    <i class="fa-solid fa-filter"></i>
                    Apply
                </button>

                <?php if ($search !== '' || $filter !== 'all'): ?>
                    <a href="project-timeline.php" class="reset-btn">
                        Reset
                    </a>
                <?php endif; ?>

            </form>

        </div>

    </section>

    <!-- Visual Timeline -->

    <section class="panel">

        <div class="panel-header">
            <div>
                <h2>Project Schedule</h2>
                <p>
                    <?= count($filteredProjects) ?>
                    project(s) displayed on the timeline.
                </p>
            </div>
        </div>

        <div class="panel-body">

            <div class="timeline-header">

                <div>
                    <h2>Timeline Overview</h2>
                    <p>
                        <?= e($timelineStartLabel) ?>
                        &mdash;
                        <?= e($timelineEndLabel) ?>
                    </p>
                </div>

                <div class="legend">

                    <span class="legend-item">
                        <span class="legend-dot dot-upcoming"></span>
                        Upcoming
                    </span>

                    <span class="legend-item">
                        <span class="legend-dot dot-active"></span>
                        In Progress
                    </span>

                    <span class="legend-item">
                        <span class="legend-dot dot-overdue"></span>
                        Overdue
                    </span>

                    <span class="legend-item">
                        <span class="legend-dot dot-completed"></span>
                        Completed
                    </span>

                </div>

            </div>

            <?php if (empty($filteredProjects)): ?>

                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fa-solid fa-calendar-xmark"></i>
                    </div>

                    <h3>No projects found</h3>

                    <p>
                        There are no projects matching your search or selected filter.
                        Try changing your filters.
                    </p>
                </div>

            <?php else: ?>

                <div class="timeline-scroll">

                    <div class="timeline-layout">

                        <div class="scale-row">

                            <div class="scale-label">
                                PROJECT / TEAM LEAD
                            </div>

                            <div class="scale-dates">
                                <span><?= e($timelineStartLabel) ?></span>
                                <span>
                                    <?= e(date('d M Y', $timelineStart + (int)($timelineDays / 2) * 86400)) ?>
                                </span>
                                <span><?= e($timelineEndLabel) ?></span>
                            </div>

                        </div>

                        <?php
                        $todayPosition = timelinePosition(
                            $today,
                            $timelineStart,
                            $timelineDays
                        );

                        $showToday = (
                            strtotime($today) >= $timelineStart &&
                            strtotime($today) <= $timelineEnd
                        );
                        ?>

                        <?php foreach ($filteredProjects as $project): ?>

                            <?php
                            $start = $project['start_date'] ?? '';
                            $end = $project['end_date'] ?? '';
                            $status = $project['timeline_status'];

                            $left = timelinePosition(
                                $start,
                                $timelineStart,
                                $timelineDays
                            );

                            $width = timelineWidth(
                                $start,
                                $end,
                                $timelineStart,
                                $timelineDays
                            );
                            ?>

                            <div class="timeline-row">

                                <div class="project-info">

                                    <div class="project-name">
                                        <?= e($project['project_name']) ?>
                                    </div>

                                    <div class="project-meta">
                                        <div>
                                            <i class="fa-regular fa-calendar"></i>
                                            <?= e(formatDate($start)) ?>
                                            &mdash;
                                            <?= e(formatDate($end)) ?>
                                        </div>

                                        <div>
                                            <i class="fa-solid fa-user"></i>
                                            <?= e($project['team_lead_name'] ?: 'No team lead assigned') ?>
                                        </div>
                                    </div>

                                    <span class="status-badge status-<?= e($status) ?>">
                                        <?= e(statusLabel($status)) ?>
                                    </span>

                                </div>

                                <div class="timeline-track">

                                    <?php if ($showToday): ?>
                                        <div
                                            class="today-line"
                                            style="left: <?= round($todayPosition, 2) ?>%;"
                                            title="Today"
                                        ></div>
                                    <?php endif; ?>

                                    <?php if (
                                        !empty($start) &&
                                        !empty($end) &&
                                        strtotime($start) !== false &&
                                        strtotime($end) !== false &&
                                        strtotime($end) >= strtotime($start)
                                    ): ?>

                                        <div
                                            class="timeline-bar bar-<?= e($status) ?>"
                                            style="
                                                left: <?= round($left, 2) ?>%;
                                                width: <?= round($width, 2) ?>%;
                                            "
                                            title="<?= e($project['project_name']) ?>: <?= e(formatDate($start)) ?> to <?= e(formatDate($end)) ?>"
                                        >
                                            <?php if ($width >= 10): ?>
                                                <?= (int)$project['progress'] ?>% complete
                                            <?php endif; ?>
                                        </div>

                                    <?php else: ?>

                                        <div class="no-dates">
                                            Project dates not available
                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </section>

    <!-- Project Progress -->

    <section class="panel">

        <div class="panel-header">

            <div>
                <h2>Project Progress</h2>
                <p>
                    Completion is calculated from completed and approved tasks.
                </p>
            </div>

        </div>

        <div class="panel-body">

            <?php if (empty($filteredProjects)): ?>

                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fa-solid fa-chart-simple"></i>
                    </div>

                    <h3>No progress data available</h3>

                    <p>Projects matching your filters will appear here.</p>
                </div>

            <?php else: ?>

                <div class="project-list">

                    <?php foreach ($filteredProjects as $project): ?>

                        <div class="project-card">

                            <div class="project-card-top">

                                <div>
                                    <h3>
                                        <?= e($project['project_name']) ?>
                                    </h3>

                                    <p>
                                        <?= e($project['deliverable'] ?: 'No deliverable description available.') ?>
                                    </p>

                                    <p style="margin-top: 7px;">
                                        <i class="fa-regular fa-calendar"></i>
                                        <?= e(formatDate($project['start_date'])) ?>
                                        &mdash;
                                        <?= e(formatDate($project['end_date'])) ?>
                                    </p>
                                </div>

                                <div class="progress-number">
                                    <?= (int)$project['progress'] ?>%
                                </div>

                            </div>

                            <div class="progress-track">
                                <div
                                    class="progress-fill"
                                    style="width: <?= (int)$project['progress'] ?>%;"
                                ></div>
                            </div>

                            <div class="progress-footer">

                                <span>
                                    <?= (int)$project['completed_tasks'] ?>
                                    of
                                    <?= (int)$project['total_tasks'] ?>
                                    tasks completed
                                </span>

                                <span>
                                    <?= e(statusLabel($project['timeline_status'])) ?>
                                </span>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </section>

</main>

<footer class="footer">
    TidyTrack Business Analyst Portal &copy; <?= date('Y') ?>
</footer>

</body>
</html>