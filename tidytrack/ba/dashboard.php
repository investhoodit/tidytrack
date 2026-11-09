<?php
session_start();

include '../dp.php';

if (empty($_SESSION['ba_authenticated'])) {
    header('Location: index.php');
    exit;
}

if (
    empty($_SESSION['ba_user_id']) ||
    strcasecmp(
        trim($_SESSION['ba_department'] ?? ''),
        'BA Team'
    ) !== 0
) {
    header('Location: index.php');
    exit;
}

$baName = $_SESSION['ba_name'] ?? 'BA Team Member';
$firstName = explode(' ', trim($baName))[0];


// Get project statistics
$today = date('Y-m-d');

$totalProjects = 0;
$activeProjects = 0;
$completedProjects = 0;
$upcomingProjects = 0;

$statsQuery = "
    SELECT
        COUNT(*) AS total_projects,

        COALESCE(SUM(
            CASE
                WHEN start_date <= '$today'
                 AND end_date >= '$today'
                THEN 1 ELSE 0
            END
        ), 0) AS active_projects,

        COALESCE(SUM(
            CASE
                WHEN end_date < '$today'
                THEN 1 ELSE 0
            END
        ), 0) AS completed_projects,

        COALESCE(SUM(
            CASE
                WHEN start_date > '$today'
                THEN 1 ELSE 0
            END
        ), 0) AS upcoming_projects

    FROM projects_x
";

$statsResult = $conn->query($statsQuery);

if ($statsResult) {
    $stats = $statsResult->fetch_assoc();

    $totalProjects = (int) $stats['total_projects'];
    $activeProjects = (int) $stats['active_projects'];
    $completedProjects = (int) $stats['completed_projects'];
    $upcomingProjects = (int) $stats['upcoming_projects'];
}


// Get projects and their team leaders
$projects = [];

$projectsQuery = "
    SELECT
        p.project_id,
        p.project_name,
        p.start_date,
        p.end_date,
        p.deliverable,
        u.name AS leader_name,
        u.profile_picture AS leader_picture
    FROM projects_x p
    LEFT JOIN users u ON u.user_id = p.teamLeadder_id
    ORDER BY p.project_id DESC
";
$projectsResult = $conn->query($projectsQuery);

if ($projectsResult) {
    while ($row = $projectsResult->fetch_assoc()) {
        $projects[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>BA Dashboard | TidyTrack</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

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

        /* HEADER */

        .topbar {
            height: 78px;
            background: var(--white);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 6%;
            position: sticky;
            top: 0;
            z-index: 100;
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
            font-size: 21px;
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

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .icon-button {
            width: 42px;
            height: 42px;
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: 12px;
            display: grid;
            place-items: center;
            color: var(--muted);
            font-size: 17px;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .icon-button:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #99f6e4;
        }

        .logout-button {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 11px 16px;
            border: 1px solid var(--border);
            border-radius: 11px;
            background: var(--white);
            color: var(--text);
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            transition: 0.2s ease;
        }

        .logout-button:hover {
            border-color: #fecaca;
            background: #fef2f2;
            color: #dc2626;
        }

        /* MAIN CONTENT */

        .page {
            max-width: 1450px;
            margin: 0 auto;
            padding: 42px 6%;
        }

        .welcome {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 32px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-dark);
            background: var(--primary-light);
            border: 1px solid #ccfbf1;
            border-radius: 30px;
            padding: 7px 12px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .welcome h1 {
            font-size: clamp(27px, 3vw, 36px);
            letter-spacing: -1.2px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .welcome h1 span {
            color: var(--primary);
        }

        .welcome p {
            font-size: 14px;
            line-height: 1.7;
            color: var(--muted);
            max-width: 580px;
        }

        .today-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 17px 20px;
            min-width: 190px;
        }

        .today-card .label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .today-card .date {
            font-size: 14px;
            font-weight: 800;
        }

        /* PLACEHOLDER FOR NEXT STEP */

        .next-step {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 35px;
            min-height: 200px;
            display: flex;
            align-items: center;
            gap: 22px;
        }

        .next-step-icon {
            width: 58px;
            height: 58px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            border-radius: 16px;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 24px;
        }

        .next-step h2 {
            font-size: 19px;
            margin-bottom: 9px;
        }

        .next-step p {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.8;
        }

        /* FOOTER */

        .footer {
            text-align: center;
            padding: 25px 15px;
            color: var(--muted);
            font-size: 11px;
        }

        /* MOBILE */

        @media (max-width: 650px) {
            .topbar {
                height: 70px;
                padding: 0 5%;
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

            .header-actions {
                gap: 7px;
            }

            .icon-button {
                width: 38px;
                height: 38px;
            }

            .logout-button {
                width: 38px;
                height: 38px;
                justify-content: center;
                padding: 0;
            }

            .logout-button span {
                display: none;
            }

            .page {
                padding: 30px 5%;
            }

            .welcome {
                align-items: flex-start;
                flex-direction: column;
            }

            .today-card {
                width: 100%;
            }

            .next-step {
                padding: 24px;
                align-items: flex-start;
                gap: 15px;
            }

            .next-step-icon {
                width: 45px;
                height: 45px;
                font-size: 19px;
            }
        }
        
        
        /* PROJECT OVERVIEW */

.overview-section,
.projects-section {
    margin-top: 34px;
}

.section-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 19px;
}

.section-heading h2 {
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 6px;
}

.section-heading p {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
}

.stat-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 22px;
    min-width: 0;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.05);
}

.stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 22px;
}

.stat-icon {
    width: 43px;
    height: 43px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    font-size: 17px;
}

.stat-icon.teal {
    background: #f0fdfa;
    color: #0d9488;
}

.stat-icon.green {
    background: #ecfdf5;
    color: #059669;
}

.stat-icon.blue {
    background: #eff6ff;
    color: #2563eb;
}

.stat-icon.orange {
    background: #fff7ed;
    color: #ea580c;
}

.stat-label {
    font-size: 11px;
    color: var(--muted);
    font-weight: 700;
}

.stat-card h3 {
    font-size: 32px;
    font-weight: 800;
    letter-spacing: -1px;
    margin-bottom: 7px;
}

.stat-card > p {
    color: var(--muted);
    font-size: 11px;
    line-height: 1.6;
}

/* PROJECT TABLE */

.projects-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
}

.search-wrapper {
    display: flex;
    align-items: center;
    gap: 11px;
    margin: 20px;
    padding: 0 14px;
    height: 45px;
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--muted);
}

.search-wrapper:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.08);
}

.search-wrapper input {
    width: 100%;
    min-width: 0;
    border: none;
    outline: none;
    background: transparent;
    font-family: inherit;
    font-size: 12px;
    color: var(--text);
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

#projectsTable {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

#projectsTable th {
    background: #f8fafc;
    color: var(--muted);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 15px 18px;
    white-space: nowrap;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

#projectsTable td {
    padding: 17px 18px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 12px;
    line-height: 1.7;
    color: #475569;
    vertical-align: top;
}

#projectsTable tbody tr:last-child td {
    border-bottom: none;
}

#projectsTable tbody tr:hover {
    background: #fafffe;
}

#projectsTable .project-title {
    color: var(--text);
    font-weight: 700;
    min-width: 150px;
}

.leader-cell {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 135px;
}

.leader-dot {
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: var(--primary-light);
    color: var(--primary);
    font-size: 11px;
}

.project-count {
    flex-shrink: 0;
    padding: 8px 12px;
    border-radius: 9px;
    background: var(--primary-light);
    color: var(--primary-dark);
    font-size: 11px;
    font-weight: 800;
}

.empty-message {
    text-align: center;
    padding: 35px 20px;
    color: var(--muted);
    font-size: 13px;
}

.empty-message > i {
    font-size: 27px;
    margin-bottom: 12px;
    color: var(--primary);
}


.project-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text);
    font-weight: 700;
    transition: color 0.2s ease;
}

.project-link:hover {
    color: var(--primary);
}

.project-link i {
    font-size: 10px;
    color: var(--primary);
    opacity: 0;
    transition: opacity 0.2s ease;
}

.project-link:hover i {
    opacity: 1;
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
.leader-cell {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 150px;
}

.leader-avatar {
    width: 36px;
    height: 36px;
    flex-shrink: 0;
    border-radius: 50%;
    object-fit: cover;
    background: #f0fdfa;
    color: #0d9488;
}

.leader-avatar-fallback {
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.leader-cell span {
    overflow-wrap: anywhere;
}
/* BA TOOLS NAVIGATION */

.tools-section {
    margin-top: 34px;
}

.tools-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
}

.tool-card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
    padding: 22px;
    min-width: 0;
    min-height: 180px;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 16px;
    transition: transform 0.2s ease,
                border-color 0.2s ease,
                box-shadow 0.2s ease;
}

.tool-card:hover {
    transform: translateY(-3px);
    border-color: var(--primary);
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.05);
}

.tool-icon {
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 13px;
    background: var(--primary-light);
    color: var(--primary);
    font-size: 19px;
}

.tool-info {
    flex: 1;
}

.tool-info h3 {
    font-size: 14px;
    font-weight: 800;
    margin-bottom: 7px;
}

.tool-info p {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.7;
}

.tool-arrow {
    align-self: flex-end;
    color: var(--primary);
    font-size: 13px;
}

@media (max-width: 1000px) {
    .tools-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .tools-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .tool-card {
        padding: 17px;
        min-height: 170px;
    }
}

@media (max-width: 400px) {
    .tools-grid {
        grid-template-columns: 1fr;
    }

    .tool-card {
        min-height: auto;
    }
}

/* BA WORKSPACE DROPDOWN NAVIGATION */

.ba-navigation {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.ba-nav-group {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 13px;
    overflow: hidden;
    min-width: 0;
    transition: border-color 0.2s ease;
}

.ba-nav-group[open] {
    border-color: #99f6e4;
}

.ba-nav-group summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 19px;
    cursor: pointer;
    list-style: none;
    font-size: 13px;
    font-weight: 800;
}

.ba-nav-group summary::-webkit-details-marker {
    display: none;
}

.ba-nav-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.ba-nav-heading > i {
    display: grid;
    place-items: center;
    width: 37px;
    height: 37px;
    flex-shrink: 0;
    border-radius: 10px;
    background: var(--primary-light);
    color: var(--primary);
}

.ba-chevron {
    color: var(--muted);
    font-size: 12px;
    transition: transform 0.2s ease;
}

.ba-nav-group[open] .ba-chevron {
    transform: rotate(180deg);
}

.ba-nav-links {
    display: grid;
    gap: 4px;
    padding: 0 12px 12px;
}

.ba-nav-links a {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 12px;
    border-radius: 8px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    overflow-wrap: anywhere;
    transition: background 0.2s ease, color 0.2s ease;
}

.ba-nav-links a > i {
    width: 17px;
    flex-shrink: 0;
    text-align: center;
    color: var(--primary);
}

.ba-nav-links a:hover {
    background: var(--primary-light);
    color: var(--primary-dark);
}

@media (max-width: 650px) {
    .ba-navigation {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .ba-nav-group summary {
        padding: 15px;
    }

    .ba-nav-links a {
        padding: 13px 12px;
    }
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

<div class="header-actions">

    <!-- Notifications -->
    <a href="notifications.php"
       class="icon-button"
       title="Notifications"
       aria-label="Notifications">
        <i class="fa-regular fa-bell"></i>
    </a>

    <!-- Profile -->
    <a href="profile.php"
       class="icon-button"
       title="My Profile"
       aria-label="My Profile">
        <i class="fa-regular fa-user"></i>
    </a>

    <!-- Help & Support stays in the header -->
    <a href="support.php"
       class="icon-button"
       title="Help & Support"
       aria-label="Help and Support">
        <i class="fa-regular fa-circle-question"></i>
    </a>

    <!-- Logout -->
    <a href="logout.php"
       class="logout-button"
       title="Log out">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>Logout</span>
    </a>

</div>

</header>

<main class="page">

    <section class="welcome">

        <div>
            <span class="eyebrow">
                <i class="fa-solid fa-sparkles"></i>
                Business Analyst Workspace
            </span>

            <h1>
                Welcome back, <span><?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?></span>!
            </h1>

            <p>
                Welcome to your TidyTrack workspace. Stay organised,
                follow project progress, and keep your team's work moving forward.
            </p>
        </div>

        <div class="today-card">
            <div class="label">
                <i class="fa-regular fa-calendar"></i>
                TODAY
            </div>

            <div class="date">
                <?= date('l, j F Y') ?>
            </div>
        </div>

    </section>

    <!-- Displaying Projects here-->
    <section class="overview-section">

    <div class="section-heading">
        <div>
            <h2>Project overview</h2>
            <p>A quick look at your project workspace.</p>
        </div>
    </div>

    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-icon teal">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <span class="stat-label">All projects</span>
            </div>

            <h3><?= $totalProjects ?></h3>
            <p>Total projects in the system</p>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-icon green">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <span class="stat-label">Active</span>
            </div>

            <h3><?= $activeProjects ?></h3>
            <p>Projects running today</p>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-icon blue">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <span class="stat-label">Completed</span>
            </div>

            <h3><?= $completedProjects ?></h3>
            <p>Projects past their end date</p>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-icon orange">
                    <i class="fa-regular fa-calendar"></i>
                </div>
                <span class="stat-label">Upcoming</span>
            </div>

            <h3><?= $upcomingProjects ?></h3>
            <p>Projects starting later</p>
        </div>

    </div>

</section>

<!-- BA WORKSPACE NAVIGATION -->

<section class="tools-section">

    <div class="section-heading">
        <div>
            <h2>BA Workspace</h2>
            <p>Access your business analysis tools.</p>
        </div>
    </div>

    <div class="ba-navigation">

        <!-- Workspace -->
        <details class="ba-nav-group">
            <summary>
                <span class="ba-nav-heading">
                    <i class="fa-solid fa-house"></i>
                    Workspace
                </span>
                <i class="fa-solid fa-chevron-down ba-chevron"></i>
            </summary>

            <div class="ba-nav-links">
                <a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a href="#projects"><i class="fa-solid fa-folder-open"></i> Projects</a>
                <a href="profile.php"><i class="fa-solid fa-user"></i> My Profile</a>
                <a href="notifications.php"><i class="fa-solid fa-bell"></i> Notifications</a>
                <a href="profile-settings.php"><i class="fa-solid fa-gear"></i> Profile Settings</a>
            </div>
        </details>

        <!-- Business Analysis -->
        <details class="ba-nav-group">
            <summary>
                <span class="ba-nav-heading">
                    <i class="fa-solid fa-briefcase"></i>
                    Business Analysis
                </span>
                <i class="fa-solid fa-chevron-down ba-chevron"></i>
            </summary>

            <div class="ba-nav-links">
                <a href="requirements.php"><i class="fa-solid fa-list-check"></i> Requirements</a>
                <a href="documentation.php"><i class="fa-solid fa-file-lines"></i> BA Documentation</a>
                <a href="user-stories.php"><i class="fa-solid fa-book-open"></i> User Stories</a>
                <a href="process-flows.php"><i class="fa-solid fa-diagram-project"></i> Process Flows</a>
                <a href="attachments.php"><i class="fa-solid fa-paperclip"></i> Attachments & Deliverables</a>
                <a href="stakeholders.php"><i class="fa-solid fa-people-group"></i> Stakeholder Management</a>
                <a href="meeting-notes.php"><i class="fa-solid fa-note-sticky"></i> Meeting Notes</a>
                <a href="glossary.php"><i class="fa-solid fa-book"></i> Business Glossary</a>
            </div>
        </details>

        <!-- Testing & Quality -->
        <details class="ba-nav-group">
            <summary>
                <span class="ba-nav-heading">
                    <i class="fa-solid fa-vial"></i>
                    Testing & Quality
                </span>
                <i class="fa-solid fa-chevron-down ba-chevron"></i>
            </summary>

            <div class="ba-nav-links">
                <a href="test-cases.php"><i class="fa-solid fa-list-check"></i> Test Cases</a>
                <a href="test-execution.php"><i class="fa-solid fa-circle-check"></i> Test Execution</a>
                <a href="defects.php"><i class="fa-solid fa-bug"></i> Defect Tracking</a>
                <a href="api-testing.php"><i class="fa-solid fa-code"></i> API Testing</a>
                <a href="uat.php"><i class="fa-solid fa-clipboard-check"></i> User Acceptance Testing</a>
            </div>
        </details>

        <!-- Communication -->
        <details class="ba-nav-group">
            <summary>
                <span class="ba-nav-heading">
                    <i class="fa-solid fa-comments"></i>
                    Communication
                </span>
                <i class="fa-solid fa-chevron-down ba-chevron"></i>
            </summary>

            <div class="ba-nav-links">
                <a href="meetings.php"><i class="fa-solid fa-video"></i> Google Meet</a>
                <a href="google-chat.php"><i class="fa-brands fa-google"></i> Google Chat</a>
                <a href="email.php"><i class="fa-solid fa-envelope"></i> Email</a>
                <a href="whatsapp.php"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
                <a href="team-chat.php"><i class="fa-solid fa-users"></i> Team Discussions</a>
            </div>
        </details>

        <!-- Project Management -->
        <details class="ba-nav-group">
            <summary>
                <span class="ba-nav-heading">
                    <i class="fa-solid fa-list-check"></i>
                    Project Management
                </span>
                <i class="fa-solid fa-chevron-down ba-chevron"></i>
            </summary>

            <div class="ba-nav-links">
                <a href="task-tracking.php"><i class="fa-solid fa-list"></i> Task Tracking</a>
                <a href="milestones.php"><i class="fa-solid fa-flag-checkered"></i> Milestones</a>
                <a href="project-timeline.php"><i class="fa-solid fa-timeline"></i> Project Timeline</a>
                <a href="activity.php"><i class="fa-solid fa-clock-rotate-left"></i> Activity History</a>
                <a href="calendar.php"><i class="fa-solid fa-calendar-days"></i> BA Calendar</a>
            </div>
        </details>

        <!-- Reports -->
        <details class="ba-nav-group">
            <summary>
                <span class="ba-nav-heading">
                    <i class="fa-solid fa-chart-column"></i>
                    Reports & Analytics
                </span>
                <i class="fa-solid fa-chevron-down ba-chevron"></i>
            </summary>

            <div class="ba-nav-links">
                <a href="reports.php"><i class="fa-solid fa-chart-line"></i> Project Reports</a>
                <a href="testing-reports.php"><i class="fa-solid fa-chart-pie"></i> Testing Reports</a>
                <a href="defect-reports.php"><i class="fa-solid fa-bug"></i> Defect Reports</a>
                <a href="export-reports.php"><i class="fa-solid fa-file-export"></i> Export Reports</a>
                <a href="user-activity.php">
    <i class="fa-solid fa-users"></i>
    <span>User Activity Tracking</span>
</a>
            </div>
        </details>

    </div>

</section>


<section class="projects-section" id="projects">

    <div class="section-heading">
        <div>
            <h2>Projects</h2>
            <p>Explore project details and assigned team leaders.</p>
        </div>

        <span class="project-count">
            <?= count($projects) ?> projects
        </span>
    </div>

    <div class="projects-card">

        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>

            <input
                type="search"
                id="projectSearch"
                placeholder="Search projects or team leaders..."
                aria-label="Search projects"
            >
        </div>

        <?php if (!empty($projects)): ?>

            <div class="table-wrapper">
                <table id="projectsTable">

                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Team leader</th>
                            <th>Start date</th>
                            <th>End date</th>
                            <th>Deliverable</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($projects as $project): ?>

                            <tr>
                                <td class="project-title">
    <a
        href="project-details.php?id=<?= (int) $project['project_id'] ?>"
        class="project-link"
    >
        <?= htmlspecialchars(
            $project['project_name'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        ) ?>

        <i class="fa-solid fa-arrow-up-right-from-square"></i>
    </a>
</td>

                               <td>
    <div class="leader-cell">

        <?php if (!empty($project['leader_picture'])): ?>
            <img
                src="<?= htmlspecialchars($project['leader_picture'], ENT_QUOTES, 'UTF-8') ?>"
                alt="<?= htmlspecialchars($project['leader_name'] ?? 'Team Leader', ENT_QUOTES, 'UTF-8') ?>"
                class="leader-avatar"
                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
            >
        <?php endif; ?>

        <div
            class="leader-avatar leader-avatar-fallback"
            style="<?= !empty($project['leader_picture']) ? 'display:none;' : 'display:flex;' ?>"
        >
            <i class="fa-solid fa-user"></i>
        </div>

        <span>
            <?= htmlspecialchars($project['leader_name'] ?? 'Not assigned', ENT_QUOTES, 'UTF-8') ?>
        </span>

    </div>
</td>

                                <td>
                                    <?= htmlspecialchars(
                                        $project['start_date'] ?: '—',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $project['end_date'] ?: '—',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $project['deliverable'] ?: 'No description',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>
            </div>

            <p id="noProjectsFound" class="empty-message" style="display:none;">
                No matching projects found.
            </p>

        <?php else: ?>

            <div class="empty-message">
                <i class="fa-regular fa-folder-open"></i>
                <p>No projects found.</p>
            </div>

        <?php endif; ?>

    </div>

</section>

</main>

<footer class="footer">
    TidyTrack Business Analyst Portal
    &copy; <?= date('Y') ?>. All rights reserved.
</footer>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('projectSearch');
    const table = document.getElementById('projectsTable');
    const noResults = document.getElementById('noProjectsFound');

    if (!searchInput || !table) {
        return;
    }

    searchInput.addEventListener('input', function () {
        const searchTerm = this.value.toLowerCase().trim();
        const rows = table.querySelectorAll('tbody tr');

        let visibleRows = 0;

        rows.forEach(function (row) {
            const matches = row.textContent
                .toLowerCase()
                .includes(searchTerm);

            row.style.display = matches ? '' : 'none';

            if (matches) {
                visibleRows++;
            }
        });

        if (noResults) {
            noResults.style.display =
                visibleRows === 0 ? 'block' : 'none';
        }

        table.style.display =
            visibleRows === 0 ? 'none' : 'table';
    });
});
</script>

</body>
</html>