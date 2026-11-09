<?php
session_start();
include '../dp.php';

// Protect the BA portal
if (
    empty($_SESSION['ba_authenticated']) ||
    empty($_SESSION['ba_user_id']) ||
    strcasecmp(trim($_SESSION['ba_department'] ?? ''), 'BA Team') !== 0
) {
    header('Location: index.php');
    exit;
}

function escapeHtml($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Get the project and selected member
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT);
$memberName = trim($_GET['member'] ?? '');

if (!$projectId || $projectId < 1 || $memberName === '') {
    header('Location: dashboard.php');
    exit;
}

// Get the selected project
$stmt = $conn->prepare("
    SELECT project_id, project_name
    FROM projects_x
    WHERE project_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $projectId);
$stmt->execute();

$project = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$project) {
    header('Location: dashboard.php');
    exit;
}

// Get ONLY this member's tasks for THIS project
$stmt = $conn->prepare("
    SELECT
        task_id,
        taskTitle,
        description,
        priority,
        role,
        created_at,
        dueDate,
        status
    FROM tasks
    WHERE project_id = ?
      AND TRIM(assignTo) = ?
    ORDER BY dueDate ASC, task_id DESC
");

$stmt->bind_param("is", $projectId, $memberName);
$stmt->execute();

$result = $stmt->get_result();
$tasks = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Do not show a member page if they have no tasks in this project
if (empty($tasks)) {
    header('Location: project-details.php?id=' . $projectId);
    exit;
}

// Fetch the selected member's real profile picture
$profileStmt = $conn->prepare("
    SELECT profile_picture
    FROM users
    WHERE TRIM(name) = ?
    LIMIT 1
");

$profileStmt->bind_param("s", $memberName);
$profileStmt->execute();

$memberProfile = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();

$memberPicture = $memberProfile['profile_picture'] ?? '';


// Calculate task statistics
$totalTasks = count($tasks);
$completedTasks = 0;
$inProgressTasks = 0;
$inReviewTasks = 0;

foreach ($tasks as $task) {
    if (in_array($task['status'], ['Completed', 'Approved'], true)) {
        $completedTasks++;
    }

    if ($task['status'] === 'In progress') {
        $inProgressTasks++;
    }

    if ($task['status'] === 'In review') {
        $inReviewTasks++;
    }
}

// Format database dates
function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') {
        return 'Not set';
    }

    return date('d M Y', strtotime($date));
}

// Get a safe CSS class for task statuses
function statusClass($status) {
    return match ($status) {
        'Completed', 'Approved' => 'completed',
        'In progress' => 'progress',
        'In review' => 'review',
        'Rejected' => 'rejected',
        'Archived' => 'archived',
        default => 'other'
    };
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= escapeHtml($memberName) ?> | Project Tasks</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    >

    <style>
        :root {
            --primary: #0d9488;
            --primary-dark: #0f766e;
            --primary-light: #f0fdfa;
            --background: #f8fafc;
            --white: #ffffff;
            --text: #172b3a;
            --muted: #64748b;
            --border: #e2e8f0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--background);
            color: var(--text);
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .topbar {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            min-height: 78px;
            padding: 15px 6%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 19px;
            font-weight: 800;
        }

        .brand-mark {
            width: 43px;
            height: 43px;
            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary);
            display: grid;
            place-items: center;
        }

        .brand small {
            display: block;
            color: var(--muted);
            font-size: 11px;
            font-weight: 500;
            margin-top: 3px;
        }

        .back-button {
            padding: 11px 15px;
            border: 1px solid var(--border);
            border-radius: 11px;
            font-size: 13px;
            font-weight: 700;
        }

        .back-button:hover {
            color: var(--primary);
            border-color: var(--primary);
        }

        .page {
            max-width: 1250px;
            margin: 0 auto;
            padding: 35px 6%;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 25px;
        }

        .breadcrumb a:hover {
            color: var(--primary);
        }

        .member-header {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 28px;
            margin-bottom: 24px;
        }

        .member-info {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .avatar {
            width: 65px;
            height: 65px;
            flex-shrink: 0;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 18px;
            display: grid;
            place-items: center;
            font-size: 25px;
        }

        .member-info h1 {
            font-size: clamp(22px, 4vw, 29px);
            margin-bottom: 7px;
            overflow-wrap: anywhere;
        }

        .member-info p {
            color: var(--muted);
            font-size: 13px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 20px;
        }

        .stat-card p {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 10px;
        }

        .stat-card h2 {
            font-size: 27px;
        }

        .tasks-section {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 25px;
        }

        .section-heading {
            margin-bottom: 20px;
        }

        .section-heading h2 {
            font-size: 19px;
            margin-bottom: 7px;
        }

        .section-heading p {
            color: var(--muted);
            font-size: 12px;
        }

        .tasks-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
        }

        .task-card {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            min-width: 0;
        }

        .task-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 13px;
        }

        .task-top h3 {
            font-size: 15px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .task-description {
            font-size: 13px;
            line-height: 1.7;
            color: var(--muted);
            margin-bottom: 18px;
            overflow-wrap: anywhere;
        }

        .task-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 13px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .meta-item span {
            display: block;
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .meta-item strong {
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 7px 10px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }

        .badge.completed {
            background: #ecfdf5;
            color: #047857;
        }

        .badge.progress {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .badge.review {
            background: #fff7ed;
            color: #c2410c;
        }

        .badge.rejected {
            background: #fef2f2;
            color: #b91c1c;
        }

        .badge.archived,
        .badge.other {
            background: #f1f5f9;
            color: #475569;
        }

        .footer {
            padding: 25px;
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        @media (max-width: 900px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .topbar {
                padding: 13px 5%;
            }

            .brand {
                font-size: 16px;
            }

            .back-button {
                font-size: 12px;
                padding: 10px;
            }

            .page {
                padding: 25px 5%;
            }

            .member-header,
            .tasks-section {
                padding: 20px;
            }

            .tasks-grid {
                grid-template-columns: 1fr;
            }

            .member-info {
                align-items: flex-start;
            }
        }
        
        .avatar {
    overflow: hidden;
}

.avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
    </style>
</head>

<body>

<header class="topbar">

    <a href="dashboard.php" class="brand">
        <div class="brand-mark">
            <i class="fa-solid fa-layer-group"></i>
        </div>

        <div>
            TidyTrack
            <small>Business Analyst Portal</small>
        </div>
    </a>

    <a
        href="project-details.php?id=<?= (int) $projectId ?>"
        class="back-button"
    >
        <i class="fa-solid fa-arrow-left"></i>
        Back to Project
    </a>

</header>

<main class="page">

    <nav class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>

        <i class="fa-solid fa-chevron-right"></i>

        <a href="project-details.php?id=<?= (int) $projectId ?>">
            <?= escapeHtml($project['project_name']) ?>
        </a>

        <i class="fa-solid fa-chevron-right"></i>

        <span><?= escapeHtml($memberName) ?></span>
    </nav>

    <section class="member-header">

        <div class="member-info">

<div class="avatar">

    <?php if (!empty($memberPicture)): ?>
        <img
            src="<?= escapeHtml($memberPicture) ?>"
            alt="<?= escapeHtml($memberName) ?>"
            onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
        >
    <?php endif; ?>

    <i
        class="fa-solid fa-user"
        style="<?= !empty($memberPicture) ? 'display:none;' : '' ?>"
    ></i>

</div>

            <div>
                <h1><?= escapeHtml($memberName) ?></h1>

                <p>
                    <i class="fa-solid fa-folder"></i>
                    <?= escapeHtml($project['project_name']) ?>
                    — Assigned Tasks
                </p>
            </div>

        </div>

    </section>

    <div class="stats-grid">

        <div class="stat-card">
            <p>Total Tasks</p>
            <h2><?= $totalTasks ?></h2>
        </div>

        <div class="stat-card">
            <p>In Progress</p>
            <h2><?= $inProgressTasks ?></h2>
        </div>

        <div class="stat-card">
            <p>In Review</p>
            <h2><?= $inReviewTasks ?></h2>
        </div>

        <div class="stat-card">
            <p>Completed / Approved</p>
            <h2><?= $completedTasks ?></h2>
        </div>

    </div>

    <section class="tasks-section">

        <div class="section-heading">
            <h2>Assigned Tasks</h2>

            <p>
                Tasks assigned to <?= escapeHtml($memberName) ?>
                in <?= escapeHtml($project['project_name']) ?>.
            </p>
        </div>

        <div class="tasks-grid">

            <?php foreach ($tasks as $task): ?>

                <div class="task-card">

                    <div class="task-top">

                        <h3>
                            <?= escapeHtml($task['taskTitle'] ?: 'Untitled Task') ?>
                        </h3>

                        <span class="badge <?= escapeHtml(statusClass($task['status'])) ?>">
                            <?= escapeHtml($task['status']) ?>
                        </span>

                    </div>

                    <p class="task-description">
                        <?= nl2br(escapeHtml(
                            $task['description'] ?: 'No description provided.'
                        )) ?>
                    </p>

                    <div class="task-meta">

                        <div class="meta-item">
                            <span>
                                <i class="fa-regular fa-calendar"></i>
                                Created Date
                            </span>

                            <strong>
                                <?= escapeHtml(formatDate($task['created_at'])) ?>
                            </strong>
                        </div>

                        <div class="meta-item">
                            <span>
                                <i class="fa-regular fa-calendar-check"></i>
                                Due Date
                            </span>

                            <strong>
                                <?= escapeHtml(formatDate($task['dueDate'])) ?>
                            </strong>
                        </div>

                        <div class="meta-item">
                            <span>Priority</span>

                            <strong>
                                <?= escapeHtml(ucfirst($task['priority'] ?: 'Not set')) ?>
                            </strong>
                        </div>

                        <div class="meta-item">
                            <span>Task ID</span>

                            <strong>
                                #<?= (int) $task['task_id'] ?>
                            </strong>
                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </section>

</main>

<footer class="footer">
    TidyTrack Business Analyst Portal
    &copy; <?= date('Y') ?>. All rights reserved.
</footer>

</body>
</html>