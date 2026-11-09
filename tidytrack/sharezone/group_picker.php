<?php
session_start();
require_once '../dp.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userId   = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$userRole = strtolower($_SESSION['role']);

// Only Leaders and Members allowed
if ($userRole === 'admin' || $userRole === 'manager') {
    die("
    <div style='
        font-family: -apple-system, sans-serif;
        text-align:center; margin:80px auto; max-width:500px; padding:40px;
        background:rgba(255,255,255,0.9); border-radius:24px; box-shadow:0 20px 60px rgba(0,0,0,0.15);
    '>
        <h2 style='color:#ff3b30; margin-bottom:16px;'>Access Restricted</h2>
        <p style='color:#1d1d1f; font-size:17px; line-height:1.5;'>
            Group Hubs are available only to <strong>Leaders</strong> and <strong>Members</strong>.<br>
            Admins and Managers do not have direct access to project collaboration spaces.
        </p>
        <a href='index.php' style='
            display:inline-block; margin-top:32px; padding:12px 32px;
            background:#007aff; color:white; border-radius:999px; text-decoration:none;
            font-weight:600; font-size:16px;
        '>Return to Hub Selection</a>
    </div>");
}

// Fetch accessible projects
if ($userRole === 'leader') {
    $stmt = $conn->prepare("
        SELECT project_id, project_name, deliverable 
        FROM projects_x 
        WHERE teamLeadder_id = ?
        ORDER BY project_name ASC
    ");
    $stmt->bind_param("i", $userId);
} else { // member
    $stmt = $conn->prepare("
        SELECT DISTINCT p.project_id, p.project_name, p.deliverable
        FROM projects_x p
        JOIN tasks t ON p.project_id = t.project_id
        WHERE t.assignTo = ?
        ORDER BY p.project_name ASC
    ");
    $stmt->bind_param("s", $userName);
}

$stmt->execute();
$projectsResult = $stmt->get_result();
$projects = [];
while ($row = $projectsResult->fetch_assoc()) {
    $projects[] = $row;
}
$hasProjects = count($projects) > 0;

// For each project → fetch team members (avatars + count)
$teamMembersByProject = [];
foreach ($projects as &$proj) {
    $projId = $proj['project_id'];

    // Get distinct assigned users (from tasks) + leader if applicable
    $teamStmt = $conn->prepare("
        SELECT DISTINCT u.user_id, u.name, u.profile_picture
        FROM tasks t
        JOIN users u ON t.assignTo = u.name
        WHERE t.project_id = ?
        UNION
        SELECT u.user_id, u.name, u.profile_picture
        FROM projects_x p
        JOIN users u ON p.teamLeadder_id = u.user_id
        WHERE p.project_id = ?
    ");
    $teamStmt->bind_param("ii", $projId, $projId);
    $teamStmt->execute();
    $teamResult = $teamStmt->get_result();

    $members = [];
    while ($m = $teamResult->fetch_assoc()) {
        $members[] = $m;
    }

    $teamMembersByProject[$projId] = $members;
    $proj['member_count'] = count($members);
    $teamStmt->close();
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Group Project | ShareZone</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg: linear-gradient(135deg, #f5f5f7 0%, #e5e5ea 100%);
            --card: rgba(255,255,255,0.84);
            --accent: #007aff;
            --accent-dark: #0062cc;
            --text: #1d1d1f;
            --text-sec: #6e6e73;
            --border: rgba(0,0,0,0.10);
            --shadow: 0 12px 48px rgba(0,0,0,0.12);
            --radius: 24px;
            --blur: blur(24px);
        }

        * { box-sizing:border-box; margin:0; padding:0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 40px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container { width: 100%; max-width: 720px; }

        .header {
            text-align: center;
            margin-bottom: 48px;
        }
        .header i { font-size: 64px; color: var(--accent); margin-bottom: 16px; }
        .header h1 {
            font-size: 36px;
            font-weight: 800;
            letter-spacing: -0.8px;
            margin-bottom: 8px;
        }
        .header p {
            font-size: 18px;
            color: var(--text-sec);
            max-width: 520px;
            margin: 0 auto;
        }

        .project-grid {
            display: grid;
            gap: 24px;
        }

        .project-card {
            background: var(--card);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px;
            box-shadow: var(--shadow);
            transition: all 0.32s cubic-bezier(0.16,1,0.3,1);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .project-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 24px 64px rgba(0,0,0,0.16);
            border-color: rgba(0,122,255,0.3);
        }

        .project-icon {
            font-size: 48px;
            color: var(--accent);
            margin-bottom: 16px;
        }

        .project-name {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .project-deliverable {
            font-size: 15px;
            color: var(--text-sec);
            line-height: 1.45;
            margin-bottom: 16px;
        }

        .team-avatars {
            display: flex;
            align-items: center;
            gap: -10px;
            margin-top: 12px;
        }

        .team-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            background: #e0e0e0;
        }

        .team-avatar-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #007aff, #00c6ff);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: bold;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .team-more {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(0,122,255,0.1);
            color: var(--accent);
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .team-count {
            font-size: 14px;
            color: var(--text-sec);
            margin-left: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--card);
            backdrop-filter: var(--blur);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .empty-icon { font-size: 64px; color: #ff9500; margin-bottom: 24px; }
        .empty-text { font-size: 19px; font-weight: 600; margin-bottom: 12px; }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 40px;
            padding: 12px 28px;
            background: rgba(255,255,255,0.92);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--text);
            font-weight: 600;
            font-size: 16px;
            text-decoration: none;
            transition: all 0.25s ease;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        }

        .back-btn:hover {
            transform: translateY(-2px);
            background: white;
            box-shadow: 0 12px 32px rgba(0,0,0,0.12);
        }

        @media (max-width: 600px) {
            .header h1 { font-size: 28px; }
            .project-card { padding: 24px; }
        }
    </style>
</head>
<body>

<div class="container">

    <div class="header">
        <i class="fas fa-users-rectangle"></i>
        <h1><?= $userRole === 'leader' ? 'Your Leadership Projects' : 'Your Assigned Projects' ?></h1>
        <p>
            <?php if ($userRole === 'leader'): ?>
                Select a project you lead to enter its collaboration hub.
            <?php else: ?>
                Choose a project where you're assigned to collaborate with your team.
            <?php endif; ?>
        </p>
    </div>

    <?php if ($hasProjects): ?>
        <div class="project-grid">
            <?php foreach ($projects as $proj): 
                $projId = $proj['project_id'];
                $members = $teamMembersByProject[$projId] ?? [];
                $count = $proj['member_count'] ?? 0;
                $visibleMembers = array_slice($members, 0, 5); // show up to 5 avatars
                $extra = $count > 5 ? $count - 5 : 0;
            ?>
                <a href="group_hub.php?project_id=<?= $projId ?>" class="project-card">
                    <div class="project-icon">
                        <i class="fas fa-folder-tree"></i>
                    </div>
                    <div class="project-name"><?= htmlspecialchars($proj['project_name']) ?></div>
                    <div class="project-deliverable">
                        <?= htmlspecialchars($proj['deliverable'] ?? 'Team collaboration space • Files & notes shared here expire after 7 days.') ?>
                    </div>

                    <?php if ($count > 0): ?>
                        <div style="display:flex; align-items:center; margin-top:16px;">
                            <div class="team-avatars">
                                <?php foreach ($visibleMembers as $m): 
                                    if ($m['profile_picture']): ?>
                                        <img src="<?= htmlspecialchars($m['profile_picture']) ?>" 
                                             class="team-avatar" 
                                             alt="<?= htmlspecialchars($m['name']) ?>">
                                    <?php else: ?>
                                        <div class="team-avatar-placeholder">
                                            <?= strtoupper(substr($m['name'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <?php if ($extra > 0): ?>
                                    <div class="team-more">+<?= $extra ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="team-count">
                                <?= $count ?> member<?= $count === 1 ? '' : 's' ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div style="text-align:center; margin-top:48px;">
            <a href="index.php" class="back-btn">
                <i class="fas fa-chevron-left"></i> Back to Hub Selection
            </a>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-layer-group empty-icon"></i>
            <div class="empty-text">No Active Group Projects</div>
            <p style="color:var(--text-sec); font-size:16px; margin:16px 0 32px;">
                <?php if ($userRole === 'leader'): ?>
                    You are not currently leading any projects.<br>
                    Contact your admin to get assigned as a team leader.
                <?php else: ?>
                    You have no tasks assigned to you yet.<br>
                    Once you're added to a project, it will appear here.
                <?php endif; ?>
            </p>
            <a href="index.php" class="back-btn">
                <i class="fas fa-chevron-left"></i> Back to Hub Selection
            </a>
        </div>
    <?php endif; ?>

</div>

</body>
</html>