<?php
session_start();
include '../dp.php';

// Protect the BA portal
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

// Get the selected project ID
$projectId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

//here are the links for each project
$projectWebsite = '';

if ((int) $projectId === 43) {
    $projectWebsite = 'https://investhoodprojects.com/tidytracker/tidytrack/';
}


if (!$projectId || $projectId < 1) {
    header('Location: dashboard.php');
    exit;
}

// Fetch the selected project and its team leader
$sql = "
    SELECT
        p.project_id,
        p.project_name,
        p.deliverable,
        p.start_date,
        p.end_date,
        p.created_by,
        u.name AS leader_name
    FROM projects_x p
    LEFT JOIN users u
        ON u.user_id = p.teamLeadder_id
    WHERE p.project_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $projectId);
$stmt->execute();

$result = $stmt->get_result();
$project = $result->fetch_assoc();

$stmt->close();

// Fetch unique team members assigned to tasks in this project
$teamSql = "
    SELECT
        TRIM(t.assignTo) AS member_name,
        COUNT(*) AS task_count,
        (
            SELECT u.profile_picture
            FROM users u
            WHERE TRIM(u.name) = TRIM(t.assignTo)
            LIMIT 1
        ) AS profile_picture
    FROM tasks t
    WHERE t.project_id = ?
      AND t.assignTo IS NOT NULL
      AND TRIM(t.assignTo) != ''
    GROUP BY TRIM(t.assignTo)
    ORDER BY member_name ASC
";

$teamStmt = $conn->prepare($teamSql);
$teamStmt->bind_param("i", $projectId);
$teamStmt->execute();

$teamResult = $teamStmt->get_result();

$teamMembers = [];

while ($member = $teamResult->fetch_assoc()) {
    $teamMembers[] = $member;
}

$teamStmt->close();

// Return to dashboard if the project does not exist
if (!$project) {
    header('Location: dashboard.php');
    exit;
}

function escapeHtml($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Calculate project status
$today = date('Y-m-d');

if ($today < $project['start_date']) {
    $projectStatus = 'Upcoming';
    $statusClass = 'upcoming';
} elseif ($today > $project['end_date']) {
    $projectStatus = 'Completed';
    $statusClass = 'completed';
} else {
    $projectStatus = 'Active';
    $statusClass = 'active';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= escapeHtml($project['project_name']) ?> | TidyTrack BA
    </title>

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
            min-height: 78px;
            background: var(--white);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 15px 6%;
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
            overflow: hidden;
        }

        .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brand small {
            display: block;
            color: var(--muted);
            font-size: 11px;
            font-weight: 500;
            margin-top: 3px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 11px 15px;
            border: 1px solid var(--border);
            border-radius: 11px;
            background: var(--white);
            font-size: 13px;
            font-weight: 700;
            transition: 0.2s ease;
        }

        .back-button:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .page {
            max-width: 1250px;
            margin: 0 auto;
            padding: 38px 6%;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 25px;
        }

        .breadcrumb a:hover {
            color: var(--primary);
        }

        .project-header {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 30px;
            margin-bottom: 25px;
        }

        .project-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 30px;
            background: var(--primary-light);
            border: 1px solid #ccfbf1;
            color: var(--primary-dark);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 17px;
        }

        h1 {
            font-size: clamp(25px, 4vw, 34px);
            font-weight: 800;
            letter-spacing: -1px;
            overflow-wrap: anywhere;
            margin-bottom: 12px;
        }

        .project-description {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .status.active {
            background: #ecfdf5;
            color: #047857;
        }

        .status.upcoming {
            background: #fff7ed;
            color: #c2410c;
        }

        .status.completed {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-top: 28px;
        }

        .detail-card {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 17px;
            min-width: 0;
        }

        .detail-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 9px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .workspace-section {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 26px;
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
            font-size: 13px;
            line-height: 1.7;
        }

        .placeholder {
            padding: 35px 20px;
            border: 1px dashed #cbd5e1;
            border-radius: 13px;
            text-align: center;
        }

        .placeholder-icon {
            width: 52px;
            height: 52px;
            display: grid;
            place-items: center;
            margin: 0 auto 15px;
            border-radius: 15px;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 21px;
        }

        .placeholder h3 {
            font-size: 15px;
            margin-bottom: 8px;
        }

        .placeholder p {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.8;
        }

        .footer {
            padding: 25px 15px;
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        @media (max-width: 650px) {
            .topbar {
                padding: 13px 5%;
                min-height: 70px;
            }

            .brand {
                font-size: 16px;
                gap: 9px;
            }

            .brand-mark {
                width: 38px;
                height: 38px;
            }

            .brand small {
                font-size: 10px;
            }

            .back-button {
                padding: 10px;
                font-size: 12px;
            }

            .page {
                padding: 25px 5%;
            }

            .project-header {
                padding: 22px;
            }

            .project-heading {
                flex-direction: column;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            .workspace-section {
                padding: 20px;
            }
        }
        
        .website-preview {
    margin-top: 25px;
    padding: 18px;
    border: 1px solid var(--border);
    border-radius: 13px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    background: #f8fafc;
}

.website-preview h3 {
    font-size: 14px;
    margin-bottom: 7px;
}

.website-preview h3 i {
    color: var(--primary);
    margin-right: 7px;
}

.website-preview p {
    font-size: 12px;
    color: var(--muted);
}

.website-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 11px 15px;
    background: var(--primary);
    color: white;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.website-unavailable {
    font-size: 12px;
    color: var(--muted);
}

@media (max-width: 500px) {
    .website-preview {
        align-items: stretch;
        flex-direction: column;
    }

    .website-button {
        width: 100%;
    }
}

.team-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.team-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px;
    background: #ffffff;
    border: 1px solid var(--border);
    border-radius: 13px;
    min-width: 0;
    transition: border-color 0.2s ease;
}

.team-card:hover {
    border-color: var(--primary);
}

.team-avatar {
    width: 46px;
    height: 46px;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    border-radius: 13px;
    background: var(--primary-light);
    color: var(--primary);
    font-size: 18px;
}

.team-member-info {
    min-width: 0;
}

.team-member-info h3 {
    font-size: 14px;
    margin-bottom: 6px;
    overflow-wrap: anywhere;
}

.team-member-info p {
    font-size: 12px;
    color: var(--muted);
}

@media (max-width: 900px) {
    .team-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 550px) {
    .team-grid {
        grid-template-columns: 1fr;
    }
}

.team-avatar {
    overflow: hidden;
}

.team-avatar img {
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
            <img
                src="assets/images/logo.png"
                alt="TidyTrack"
                onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=&quot;fa-solid fa-layer-group&quot;></i>';"
            >
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

    <nav class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <i class="fa-solid fa-chevron-right"></i>
        <span>Project Details</span>
    </nav>

    <section class="project-header">

        <div class="project-heading">

            <div>
                <span class="eyebrow">
                    <i class="fa-solid fa-folder-open"></i>
                    Project Workspace
                </span>

                <h1><?= escapeHtml($project['project_name']) ?></h1>

                <p class="project-description">
                    <?= nl2br(escapeHtml(
                        $project['deliverable'] ?: 'No project description has been added yet.'
                    )) ?>
                </p>
            </div>

            <span class="status <?= escapeHtml($statusClass) ?>">
                <i class="fa-solid fa-circle"></i>
                <?= escapeHtml($projectStatus) ?>
            </span>

              </div>

        <?php if (!empty($projectWebsite)): ?>
            <div class="website-preview">
                <div>
                    <h3>
                        <i class="fa-solid fa-globe"></i>
                        Project Website
                    </h3>

                    <p>
                        Open the live Tidy Track website in a new tab.
                    </p>
                </div>

                <a
                    href="<?= escapeHtml($projectWebsite) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="website-button"
                >
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    Visit Website
                </a>
            </div>
        <?php endif; ?>

        <div class="details-grid">

            <div class="detail-card">
                <div class="detail-label">
                    <i class="fa-regular fa-calendar"></i>
                    Start Date
                </div>

                <div class="detail-value">
                    <?= escapeHtml($project['start_date']) ?>
                </div>
            </div>

            <div class="detail-card">
                <div class="detail-label">
                    <i class="fa-regular fa-calendar-check"></i>
                    End Date
                </div>

                <div class="detail-value">
                    <?= escapeHtml($project['end_date']) ?>
                </div>
            </div>

            <div class="detail-card">
                <div class="detail-label">
                    <i class="fa-solid fa-user-tie"></i>
                    Team Leader
                </div>

                <div class="detail-value">
                    <?= escapeHtml($project['leader_name'] ?: 'Not assigned') ?>
                </div>
            </div>

        </div>

    </section>

    <section class="workspace-section">

        <div class="section-heading">
            <h2>Project workspace</h2>

            <p>
                Manage this project's team, tasks, requirements and testing
                from one place.
            </p>
        </div>

        <div class="team-grid">

    <?php if (!empty($teamMembers)): ?>

        <?php foreach ($teamMembers as $member): ?>
            <a
    href="member-tasks.php?project_id=<?= (int) $projectId ?>&member=<?= urlencode($member['member_name']) ?>"
    class="team-card"
>

               <div class="team-avatar">
    <?php if (!empty($member['profile_picture'])): ?>
        <img
            src="<?= escapeHtml($member['profile_picture']) ?>"
            alt="<?= escapeHtml($member['member_name']) ?>"
            onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
        >
    <?php endif; ?>

    <i
        class="fa-solid fa-user"
        style="<?= !empty($member['profile_picture']) ? 'display:none;' : '' ?>"
    ></i>
</div>

                <div class="team-member-info">
                    <h3>
                        <?= escapeHtml($member['member_name']) ?>
                    </h3>

                    <p>
                        <?= (int) $member['task_count'] ?>
                        <?= (int) $member['task_count'] === 1 ? 'task' : 'tasks' ?>
                        assigned
                    </p>
                </div>

            </a>
        <?php endforeach; ?>

    <?php else: ?>

        <div class="placeholder">
            <div class="placeholder-icon">
                <i class="fa-solid fa-users"></i>
            </div>

            <h3>No team members yet</h3>

            <p>
                No members have been assigned tasks for this project yet.
            </p>
        </div>

    <?php endif; ?>

</div>

    </section>

</main>

<footer class="footer">
    TidyTrack Business Analyst Portal
    &copy; <?= date('Y') ?>. All rights reserved.
</footer>

</body>
</html>