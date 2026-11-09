
<?php
session_start();
require_once '../dp.php';

if (
    empty($_SESSION['ba_authenticated']) ||
    empty($_SESSION['ba_user_id'])
) {
    header('Location: index.php');
    exit;
}

$currentUserId = (int) $_SESSION['ba_user_id'];

$authStmt = $conn->prepare("
    SELECT user_id, name
    FROM users
    WHERE user_id = ?
      AND LOWER(TRIM(department)) = 'ba team'
    LIMIT 1
");

$authStmt->bind_param('i', $currentUserId);
$authStmt->execute();
$currentUser = $authStmt->get_result()->fetch_assoc();
$authStmt->close();

if (!$currentUser) {
    session_destroy();
    header('Location: index.php');
    exit;
}

function usEscape($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function usRedirect($params = []) {
    $url = 'user-stories.php';

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    header('Location: ' . $url);
    exit;
}

function usValidChoice($value, $allowed, $default) {
    return in_array($value, $allowed, true)
        ? $value
        : $default;
}

$priorities = ['Low', 'Medium', 'High', 'Critical'];

$statuses = [
    'Draft',
    'Ready',
    'In Progress',
    'In Review',
    'Completed',
    'Blocked'
];

if (empty($_SESSION['us_csrf'])) {
    $_SESSION['us_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['us_csrf'];
$errors = [];
$successMessage = '';
$editStory = null;

/*
|--------------------------------------------------------------------------
| Projects
|--------------------------------------------------------------------------
*/

$projects = [];

$projectResult = $conn->query("
    SELECT project_id, project_name
    FROM projects_x
    ORDER BY project_name ASC
");

if ($projectResult) {
    while ($project = $projectResult->fetch_assoc()) {
        $projects[] = $project;
    }
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['us_success'])) {
    $successMessage = $_SESSION['us_success'];
    unset($_SESSION['us_success']);
}

/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $errors[] = 'Your session has expired. Please refresh the page and try again.';
    } else {

        $action = $_POST['action'] ?? '';

        /*
        |------------------------------------------------------------------
        | Delete a user story
        |------------------------------------------------------------------
        */

        if ($action === 'delete') {

            $storyId = filter_var(
                $_POST['story_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$storyId || $storyId < 1) {
                $errors[] = 'Invalid user story.';
            } else {
                $deleteStmt = $conn->prepare("
                    DELETE FROM ba_user_stories
                    WHERE story_id = ?
                ");

                $deleteStmt->bind_param('i', $storyId);

                if ($deleteStmt->execute()) {
                    $_SESSION['us_success'] = 'User story deleted successfully.';
                    $deleteStmt->close();
                    usRedirect();
                } else {
                    $errors[] = 'Unable to delete this user story.';
                    $deleteStmt->close();
                }
            }
        }

        /*
        |------------------------------------------------------------------
        | Create or update a user story
        |------------------------------------------------------------------
        */

        elseif ($action === 'save') {

            $storyId = filter_var(
                $_POST['story_id'] ?? 0,
                FILTER_VALIDATE_INT
            );

            $storyId = $storyId ?: 0;

            $projectId = filter_var(
                $_POST['project_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $title = trim($_POST['title'] ?? '');
            $userRole = trim($_POST['user_role'] ?? '');
            $description = trim($_POST['story_description'] ?? '');
            $acceptanceCriteria = trim(
                $_POST['acceptance_criteria'] ?? ''
            );

            $priority = usValidChoice(
                $_POST['priority'] ?? '',
                $priorities,
                'Medium'
            );

            $status = usValidChoice(
                $_POST['status'] ?? '',
                $statuses,
                'Draft'
            );

            if (!$projectId || $projectId < 1) {
                $errors[] = 'Please select a project.';
            } else {
                $projectCheck = $conn->prepare("
                    SELECT project_id
                    FROM projects_x
                    WHERE project_id = ?
                    LIMIT 1
                ");

                $projectCheck->bind_param('i', $projectId);
                $projectCheck->execute();

                $validProject = $projectCheck
                    ->get_result()
                    ->fetch_assoc();

                $projectCheck->close();

                if (!$validProject) {
                    $errors[] = 'The selected project does not exist.';
                }
            }

            if ($title === '') {
                $errors[] = 'Please enter a user story title.';
            }

            if ($userRole === '') {
                $errors[] = 'Please enter the user role.';
            }

            if ($description === '') {
                $errors[] = 'Please describe what the user wants to achieve.';
            }

            if (strlen($title) > 255) {
                $errors[] = 'The title cannot exceed 255 characters.';
            }

            if (strlen($userRole) > 150) {
                $errors[] = 'The user role cannot exceed 150 characters.';
            }

            /*
             * Check that the story exists before updating it.
             */

            if ($storyId > 0 && empty($errors)) {
                $checkStmt = $conn->prepare("
                    SELECT story_id
                    FROM ba_user_stories
                    WHERE story_id = ?
                    LIMIT 1
                ");

                $checkStmt->bind_param('i', $storyId);
                $checkStmt->execute();

                $existingStory = $checkStmt
                    ->get_result()
                    ->fetch_assoc();

                $checkStmt->close();

                if (!$existingStory) {
                    $errors[] = 'The user story you are editing could not be found.';
                }
            }

            if (empty($errors)) {

                if ($storyId > 0) {

                    $saveStmt = $conn->prepare("
                        UPDATE ba_user_stories
                        SET
                            project_id = ?,
                            title = ?,
                            user_role = ?,
                            story_description = ?,
                            acceptance_criteria = ?,
                            priority = ?,
                            status = ?
                        WHERE story_id = ?
                    ");

                    $saveStmt->bind_param(
                        'issssssi',
                        $projectId,
                        $title,
                        $userRole,
                        $description,
                        $acceptanceCriteria,
                        $priority,
                        $status,
                        $storyId
                    );

                    $message = 'User story updated successfully.';

                } else {

                    $saveStmt = $conn->prepare("
                        INSERT INTO ba_user_stories (
                            project_id,
                            title,
                            user_role,
                            story_description,
                            acceptance_criteria,
                            priority,
                            status,
                            created_by
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $saveStmt->bind_param(
                        'issssssi',
                        $projectId,
                        $title,
                        $userRole,
                        $description,
                        $acceptanceCriteria,
                        $priority,
                        $status,
                        $currentUserId
                    );

                    $message = 'User story created successfully.';
                }

                if ($saveStmt->execute()) {
                    $_SESSION['us_success'] = $message;
                    $saveStmt->close();
                    usRedirect();
                } else {
                    $errors[] = 'Unable to save the user story. Please try again.';
                    $saveStmt->close();
                }
            }

            /*
             * Keep the submitted values available if validation fails.
             */

            $editStory = [
                'story_id' => $storyId,
                'project_id' => $projectId,
                'title' => $title,
                'user_role' => $userRole,
                'story_description' => $description,
                'acceptance_criteria' => $acceptanceCriteria,
                'priority' => $priority,
                'status' => $status
            ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Edit an existing story
|--------------------------------------------------------------------------
*/

if (
    !$editStory &&
    isset($_GET['edit']) &&
    ctype_digit((string) $_GET['edit']) &&
    (int) $_GET['edit'] > 0
) {
    $editId = (int) $_GET['edit'];

    $editStmt = $conn->prepare("
        SELECT *
        FROM ba_user_stories
        WHERE story_id = ?
        LIMIT 1
    ");

    $editStmt->bind_param('i', $editId);
    $editStmt->execute();

    $editStory = $editStmt
        ->get_result()
        ->fetch_assoc();

    $editStmt->close();

    if (!$editStory) {
        $errors[] = 'The requested user story could not be found.';
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$filterProject = filter_var(
    $_GET['project'] ?? '',
    FILTER_VALIDATE_INT
);

$filterStatus = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';

if (!in_array($filterStatus, $statuses, true)) {
    $filterStatus = '';
}

if (!in_array($filterPriority, $priorities, true)) {
    $filterPriority = '';
}

if (!$filterProject || $filterProject < 1) {
    $filterProject = 0;
}

/*
|--------------------------------------------------------------------------
| Load user stories
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        s.*,
        p.project_name,
        creator.name AS creator_name
    FROM ba_user_stories s
    LEFT JOIN projects_x p
        ON p.project_id = s.project_id
    LEFT JOIN users creator
        ON creator.user_id = s.created_by
    WHERE 1 = 1
";

$types = '';
$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            s.title LIKE ?
            OR s.user_role LIKE ?
            OR s.story_description LIKE ?
            OR p.project_name LIKE ?
        )
    ";

    $searchLike = '%' . $search . '%';

    $types .= 'ssss';

    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
}

if ($filterProject > 0) {
    $sql .= ' AND s.project_id = ? ';
    $types .= 'i';
    $params[] = $filterProject;
}

if ($filterStatus !== '') {
    $sql .= ' AND s.status = ? ';
    $types .= 's';
    $params[] = $filterStatus;
}

if ($filterPriority !== '') {
    $sql .= ' AND s.priority = ? ';
    $types .= 's';
    $params[] = $filterPriority;
}

$sql .= "
    ORDER BY
        CASE s.priority
            WHEN 'Critical' THEN 1
            WHEN 'High' THEN 2
            WHEN 'Medium' THEN 3
            WHEN 'Low' THEN 4
        END,
        s.updated_at DESC
";

$listStmt = $conn->prepare($sql);

if (!empty($params)) {
    $listStmt->bind_param($types, ...$params);
}

$listStmt->execute();

$storiesResult = $listStmt->get_result();

$stories = [];

while ($story = $storiesResult->fetch_assoc()) {
    $stories[] = $story;
}

$listStmt->close();

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$stats = [
    'total' => 0,
    'completed' => 0,
    'progress' => 0,
    'blocked' => 0
];

$statsResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Completed') AS completed,
        SUM(status IN ('In Progress', 'In Review')) AS progress,
        SUM(status = 'Blocked') AS blocked
    FROM ba_user_stories
");

if ($statsResult) {
    $statsRow = $statsResult->fetch_assoc();

    if ($statsRow) {
        $stats['total'] = (int) ($statsRow['total'] ?? 0);
        $stats['completed'] = (int) ($statsRow['completed'] ?? 0);
        $stats['progress'] = (int) ($statsRow['progress'] ?? 0);
        $stats['blocked'] = (int) ($statsRow['blocked'] ?? 0);
    }
}

$completionRate = $stats['total'] > 0
    ? round(($stats['completed'] / $stats['total']) * 100)
    : 0;

function usPriorityClass($priority) {
    return strtolower((string) $priority);
}

function usStatusClass($status) {
    return strtolower(
        str_replace(' ', '-', (string) $status)
    );
}

$initials = '';

$nameParts = preg_split(
    '/\s+/',
    trim($currentUser['name'] ?? 'BA User')
);

foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}

if ($initials === '') {
    $initials = 'BA';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>User Stories | TidyTrack BA Portal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap"
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
            --surface: #ffffff;
            --text: #172b3a;
            --muted: #64748b;
            --border: #e2e8f0;
            --danger: #dc2626;
            --shadow: 0 8px 24px rgba(15, 23, 42, 0.045);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
        }

        button,
        input,
        textarea,
        select {
            font: inherit;
        }

        button,
        a {
            -webkit-tap-highlight-color: transparent;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .topbar {
            min-height: 70px;
            padding: 14px 5%;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .brand img {
            width: 42px;
            height: 42px;
            object-fit: contain;
        }

        .brand-name {
            font-size: 19px;
            font-weight: 800;
            letter-spacing: -0.6px;
        }

        .brand-subtitle {
            margin-top: 3px;
            color: var(--muted);
            font-size: 11px;
        }

        .user-area {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 39px;
            height: 39px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            color: var(--primary-dark);
            background: var(--primary-light);
            font-weight: 800;
        }

        .user-name {
            font-weight: 700;
            font-size: 13px;
        }

        .user-role {
            margin-top: 3px;
            color: var(--muted);
            font-size: 11px;
        }

        .container {
            width: min(1250px, 92%);
            margin: 0 auto;
            padding: 27px 0 45px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 9px;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 24px;
        }

        .breadcrumb a:hover {
            color: var(--primary-dark);
        }

        .breadcrumb .current {
            color: var(--text);
            font-weight: 700;
        }

        .hero {
            position: relative;
            overflow: hidden;
            padding: 29px;
            border-radius: 22px;
            background: linear-gradient(120deg, #0f766e, #0d9488 62%, #14b8a6);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
            margin-bottom: 24px;
            box-shadow: 0 12px 30px rgba(13, 148, 136, 0.15);
        }

        .hero::after {
            content: "";
            position: absolute;
            width: 230px;
            height: 230px;
            border: 40px solid rgba(255, 255, 255, 0.07);
            border-radius: 50%;
            right: 7%;
            top: -130px;
            pointer-events: none;
        }

        .hero-content,
        .hero-action {
            position: relative;
            z-index: 1;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 10px;
            border: 1px solid rgba(255,255,255,0.24);
            background: rgba(255,255,255,0.12);
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 13px;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(25px, 3vw, 34px);
            line-height: 1.2;
            letter-spacing: -1px;
        }

        .hero p {
            max-width: 540px;
            margin: 10px 0 0;
            line-height: 1.7;
            font-size: 13px;
            color: rgba(255,255,255,0.86);
        }

        .btn {
            border: 0;
            min-height: 42px;
            padding: 11px 16px;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            transition: transform .18s, box-shadow .18s, background .18s;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-white {
            background: #fff;
            color: var(--primary-dark);
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            white-space: nowrap;
        }

        .btn-primary {
            color: #fff;
            background: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-light {
            color: var(--text);
            background: #f1f5f9;
            border: 1px solid var(--border);
        }

        .btn-danger {
            color: #fff;
            background: var(--danger);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 26px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 17px;
            padding: 19px;
            box-shadow: var(--shadow);
            min-width: 0;
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .stat-icon {
            width: 39px;
            height: 39px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            border-radius: 12px;
            font-size: 15px;
        }

        .icon-teal {
            background: #ccfbf1;
            color: #0f766e;
        }

        .icon-green {
            background: #dcfce7;
            color: #15803d;
        }

        .icon-blue {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .icon-red {
            background: #fee2e2;
            color: #b91c1c;
        }

        .stat-value {
            margin-top: 15px;
            font-size: 29px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .stat-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 8px;
            color: var(--muted);
            font-size: 11px;
        }

        .progress-track {
            width: 100%;
            height: 5px;
            overflow: hidden;
            margin-top: 12px;
            background: #e2e8f0;
            border-radius: 20px;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 20px;
        }

        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin: 30px 0 17px;
        }

        .section-heading h2 {
            margin: 0;
            font-size: 18px;
            letter-spacing: -.4px;
        }

        .section-heading p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .section-count {
            padding: 7px 11px;
            border-radius: 20px;
            background: #e2e8f0;
            color: #475569;
            font-weight: 800;
            font-size: 11px;
        }

        .panel {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 17px;
            box-shadow: var(--shadow);
            margin-bottom: 23px;
        }

        .panel-header {
            padding: 19px 21px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .panel-title {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
        }

        .panel-subtitle {
            margin-top: 5px;
            color: var(--muted);
            font-size: 12px;
        }

        .form-content {
            padding: 22px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 19px;
        }

        .field {
            min-width: 0;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 800;
        }

        .required {
            color: #dc2626;
        }

        .input,
        .select,
        .textarea {
            width: 100%;
            padding: 12px 13px;
            color: var(--text);
            background: #fff;
            border: 1px solid #dbe3ec;
            border-radius: 10px;
            outline: none;
            transition: border-color .18s, box-shadow .18s;
        }

        .input,
        .select {
            min-height: 44px;
        }

        .textarea {
            min-height: 105px;
            resize: vertical;
            line-height: 1.6;
        }

        .input:focus,
        .select:focus,
        .textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13,148,136,.1);
        }

        .field-hint {
            margin-top: 7px;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.5;
        }

        .form-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 22px;
            padding-top: 19px;
            border-top: 1px solid var(--border);
        }

        .alert {
            padding: 13px 15px;
            border-radius: 11px;
            margin-bottom: 18px;
            font-size: 12px;
            line-height: 1.6;
        }

        .alert-success {
            color: #166534;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            color: #991b1b;
            background: #fee2e2;
            border: 1px solid #fecaca;
        }

        .filters {
            display: grid;
            grid-template-columns: minmax(200px, 1.7fr) repeat(3, minmax(135px, 1fr)) auto;
            gap: 11px;
            padding: 17px;
            border-bottom: 1px solid var(--border);
        }

        .search-wrap {
            position: relative;
        }

        .search-wrap i {
            position: absolute;
            top: 50%;
            left: 13px;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .search-wrap input {
            padding-left: 37px;
        }

        .filters .btn {
            min-height: 43px;
        }

        .story-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 17px;
        }

        .story-card {
            position: relative;
            display: flex;
            flex-direction: column;
            min-width: 0;
            padding: 19px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 17px;
            box-shadow: var(--shadow);
            transition: transform .18s, box-shadow .18s, border-color .18s;
        }

        .story-card:hover {
            transform: translateY(-3px);
            border-color: #99f6e4;
            box-shadow: 0 12px 30px rgba(15,23,42,.07);
        }

        .story-card[hidden] {
            display: none !important;
        }

        .story-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .story-number {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--primary-dark);
            background: #f0fdfa;
            border: 1px solid #ccfbf1;
            border-radius: 8px;
            padding: 6px 9px;
            font-size: 10px;
            font-weight: 800;
        }

        .priority {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }

        .priority::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }

        .priority.low {
            color: #64748b;
        }

        .priority.medium {
            color: #2563eb;
        }

        .priority.high {
            color: #ea580c;
        }

        .priority.critical {
            color: #dc2626;
        }

        .story-title {
            margin: 17px 0 10px;
            font-size: 16px;
            font-weight: 800;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        .project-label {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--muted);
            font-size: 11px;
            margin-bottom: 13px;
        }

        .project-label i {
            color: var(--primary);
        }

        .story-description {
            color: #64748b;
            font-size: 12px;
            line-height: 1.8;
            overflow-wrap: anywhere;
            white-space: pre-line;
            margin-bottom: 16px;
        }

        .user-role-box {
            padding: 12px;
            background: #f8fafc;
            border: 1px solid #edf2f7;
            border-radius: 10px;
            margin-bottom: 16px;
        }

        .mini-label {
            margin-bottom: 6px;
            color: #94a3b8;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .8px;
            text-transform: uppercase;
        }

        .role-text {
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.6;
        }

        .story-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: auto;
            padding-top: 15px;
            border-top: 1px solid #f1f5f9;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 800;
            line-height: 1.3;
        }

        .status.draft {
            background: #f1f5f9;
            color: #475569;
        }

        .status.ready {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status.in-progress {
            background: #fef3c7;
            color: #92400e;
        }

        .status.in-review {
            background: #ede9fe;
            color: #6d28d9;
        }

        .status.completed {
            background: #dcfce7;
            color: #166534;
        }

        .status.blocked {
            background: #fee2e2;
            color: #b91c1c;
        }

        .story-date {
            margin-left: auto;
            color: #94a3b8;
            font-size: 10px;
        }

        .story-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }

        .action-btn {
            min-width: 0;
            flex: 1;
            min-height: 37px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 7px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fff;
            color: #475569;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            transition: background .18s, color .18s;
        }

        .action-btn:hover {
            color: var(--primary-dark);
            background: #f0fdfa;
            border-color: #99f6e4;
        }

        .action-btn.delete:hover {
            color: #b91c1c;
            background: #fef2f2;
            border-color: #fecaca;
        }

        .empty-state {
            grid-column: 1 / -1;
            padding: 55px 20px;
            text-align: center;
            background: #fff;
            border: 1px dashed #cbd5e1;
            border-radius: 17px;
        }

        .empty-icon {
            width: 65px;
            height: 65px;
            margin: 0 auto 17px;
            display: grid;
            place-items: center;
            border-radius: 20px;
            background: #f0fdfa;
            color: var(--primary);
            font-size: 25px;
        }

        .empty-state h3 {
            margin: 0 0 8px;
            font-size: 17px;
        }

        .empty-state p {
            max-width: 390px;
            margin: 0 auto 18px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .footer {
            padding: 23px 12px;
            text-align: center;
            color: var(--muted);
            font-size: 11px;
            border-top: 1px solid var(--border);
            margin-top: 38px;
        }

        /* Story details modal */

        .modal {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(15,23,42,.55);
            backdrop-filter: blur(4px);
        }

        .modal.open {
            display: flex;
        }

        .modal-dialog {
            width: min(650px, 100%);
            max-height: 90vh;
            overflow-y: auto;
            background: #fff;
            border-radius: 19px;
            box-shadow: 0 25px 70px rgba(0,0,0,.2);
            animation: modalIn .2s ease;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            position: sticky;
            top: 0;
            z-index: 2;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            padding: 21px;
            background: #fff;
            border-bottom: 1px solid var(--border);
        }

        .modal-header h2 {
            margin: 0;
            font-size: 18px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .modal-close {
            width: 35px;
            height: 35px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            cursor: pointer;
        }

        .modal-body {
            padding: 22px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .detail-box {
            min-width: 0;
            padding: 14px;
            border: 1px solid var(--border);
            background: #f8fafc;
            border-radius: 12px;
        }

        .detail-value {
            font-size: 12px;
            font-weight: 700;
            line-height: 1.7;
            overflow-wrap: anywhere;
            white-space: pre-line;
        }

        .detail-section {
            margin-top: 21px;
        }

        .detail-section h3 {
            font-size: 13px;
            margin: 0 0 9px;
        }

        .detail-text {
            color: #475569;
            font-size: 12px;
            line-height: 1.9;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .modal-footer {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 17px 22px;
            border-top: 1px solid var(--border);
        }

        @media (max-width: 1050px) {
            .story-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filters {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filters .search-wrap {
                grid-column: 1 / -1;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .topbar {
                padding: 12px 4%;
                min-height: 65px;
            }

            .brand img {
                width: 35px;
                height: 35px;
            }

            .brand-name {
                font-size: 16px;
            }

            .brand-subtitle {
                font-size: 10px;
            }

            .user-name,
            .user-role {
                display: none;
            }

            .user-avatar {
                width: 36px;
                height: 36px;
            }

            .container {
                width: 92%;
                padding-top: 19px;
            }

            .breadcrumb {
                margin-bottom: 18px;
            }

            .hero {
                align-items: stretch;
                flex-direction: column;
                padding: 22px 19px;
                gap: 19px;
                border-radius: 17px;
            }

            .hero h1 {
                font-size: 27px;
            }

            .hero p {
                font-size: 12px;
            }

            .hero-action .btn {
                width: 100%;
            }

            .stats-grid {
                gap: 10px;
                margin-bottom: 22px;
            }

            .stat-card {
                padding: 14px;
                border-radius: 14px;
            }

            .stat-icon {
                width: 33px;
                height: 33px;
                border-radius: 10px;
            }

            .stat-label {
                font-size: 11px;
            }

            .stat-value {
                font-size: 25px;
                margin-top: 12px;
            }

            .stat-bottom {
                align-items: flex-start;
                flex-direction: column;
                gap: 5px;
            }

            .section-heading {
                margin-top: 24px;
            }

            .section-heading h2 {
                font-size: 17px;
            }

            .panel {
                border-radius: 14px;
            }

            .panel-header {
                padding: 16px;
            }

            .form-content {
                padding: 16px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .field.full {
                grid-column: auto;
            }

            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .form-actions .btn {
                width: 100%;
            }

            .filters {
                grid-template-columns: 1fr;
                padding: 13px;
                gap: 9px;
            }

            .filters .search-wrap {
                grid-column: auto;
            }

            .filters .btn {
                width: 100%;
            }

            .story-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .story-card {
                padding: 16px;
            }

            .story-title {
                font-size: 15px;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .modal {
                padding: 10px;
            }

            .modal-dialog {
                max-height: 94vh;
                border-radius: 15px;
            }

            .modal-header,
            .modal-body {
                padding: 17px;
            }

            .modal-footer {
                padding: 14px 17px;
            }

            .modal-footer .btn {
                flex: 1;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <a href="dashboard.php" class="brand">
        <img
            src="assets/images/logo.png"
            alt="TidyTrack logo"
            onerror="this.style.display='none'"
        >

        <div>
            <div class="brand-name">TidyTrack</div>
            <div class="brand-subtitle">Business Analyst Portal</div>
        </div>
    </a>

    <div class="user-area">
        <div>
            <div class="user-name">
                <?= usEscape($currentUser['name']) ?>
            </div>
            <div class="user-role">Business Analyst</div>
        </div>

        <div class="user-avatar">
            <?= usEscape($initials) ?>
        </div>
    </div>
</header>

<main class="container">

    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="dashboard.php">
            <i class="fa-solid fa-house"></i>
            Dashboard
        </a>

        <i class="fa-solid fa-chevron-right" style="font-size:9px"></i>

        <span class="current">User Stories</span>
    </nav>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i>
            <?= usEscape($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <strong>
                <i class="fa-solid fa-circle-exclamation"></i>
                Please check the following:
            </strong>

            <ul style="margin-bottom:0">
                <?php foreach ($errors as $error): ?>
                    <li><?= usEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Hero -->

    <section class="hero">
        <div class="hero-content">
            <div class="eyebrow">
                <i class="fa-solid fa-layer-group"></i>
                REQUIREMENTS & PLANNING
            </div>

            <h1>User Stories</h1>

            <p>
                Turn project requirements into clear user stories.
                Define what users need, set priorities, and track
                each story from draft to completion.
            </p>
        </div>

        <div class="hero-action">
            <a href="#story-form" class="btn btn-white">
                <i class="fa-solid fa-plus"></i>
                Create User Story
            </a>
        </div>
    </section>

    <!-- Statistics -->

    <section class="stats-grid">

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Total Stories</span>

                <span class="stat-icon icon-teal">
                    <i class="fa-solid fa-layer-group"></i>
                </span>
            </div>

            <div class="stat-value">
                <?= $stats['total'] ?>
            </div>

            <div class="stat-bottom">
                <span>All project stories</span>
            </div>

            <div class="progress-track">
                <div
                    class="progress-fill"
                    style="width:100%"
                ></div>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Completed</span>

                <span class="stat-icon icon-green">
                    <i class="fa-solid fa-circle-check"></i>
                </span>
            </div>

            <div class="stat-value">
                <?= $stats['completed'] ?>
            </div>

            <div class="stat-bottom">
                <span>Completion rate</span>
                <strong><?= $completionRate ?>%</strong>
            </div>

            <div class="progress-track">
                <div
                    class="progress-fill"
                    style="width:<?= $completionRate ?>%; background:#16a34a"
                ></div>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">In Progress</span>

                <span class="stat-icon icon-blue">
                    <i class="fa-solid fa-spinner"></i>
                </span>
            </div>

            <div class="stat-value">
                <?= $stats['progress'] ?>
            </div>

            <div class="stat-bottom">
                <span>Being worked on or reviewed</span>
            </div>

            <div class="progress-track">
                <div
                    class="progress-fill"
                    style="width:<?= $stats['total'] ? min(100, ($stats['progress'] / $stats['total']) * 100) : 0 ?>%; background:#3b82f6"
                ></div>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Blocked</span>

                <span class="stat-icon icon-red">
                    <i class="fa-solid fa-ban"></i>
                </span>
            </div>

            <div class="stat-value">
                <?= $stats['blocked'] ?>
            </div>

            <div class="stat-bottom">
                <span>Need attention</span>
            </div>

            <div class="progress-track">
                <div
                    class="progress-fill"
                    style="width:<?= $stats['total'] ? min(100, ($stats['blocked'] / $stats['total']) * 100) : 0 ?>%; background:#dc2626"
                ></div>
            </div>
        </article>

    </section>

    <!-- Create/Edit form -->

    <div class="section-heading" id="story-form">
        <div>
            <h2>
                <?= $editStory ? 'Edit User Story' : 'Create a User Story' ?>
            </h2>

            <p>
                Describe the user's goal and what the system should do.
            </p>
        </div>
    </div>

    <section class="panel">

        <div class="panel-header">
            <div>
                <h3 class="panel-title">
                    <i
                        class="fa-solid fa-pen-to-square"
                        style="color:var(--primary);margin-right:7px"
                    ></i>

                    Story Details
                </h3>

                <div class="panel-subtitle">
                    Fields marked with * are required.
                </div>
            </div>

            <?php if ($editStory): ?>
                <a href="user-stories.php" class="btn btn-light">
                    <i class="fa-solid fa-xmark"></i>
                    Cancel Edit
                </a>
            <?php endif; ?>
        </div>

        <div class="form-content">

            <form method="POST" action="user-stories.php#story-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= usEscape($csrfToken) ?>"
                >

                <input type="hidden" name="action" value="save">

                <input
                    type="hidden"
                    name="story_id"
                    value="<?= (int) ($editStory['story_id'] ?? 0) ?>"
                >

                <div class="form-grid">

                    <div class="field">
                        <label for="project_id">
                            Project <span class="required">*</span>
                        </label>

                        <select
                            class="select"
                            id="project_id"
                            name="project_id"
                            required
                        >
                            <option value="">Select a project</option>

                            <?php foreach ($projects as $project): ?>
                                <option
                                    value="<?= (int) $project['project_id'] ?>"
                                    <?= (int) ($editStory['project_id'] ?? 0) === (int) $project['project_id'] ? 'selected' : '' ?>
                                >
                                    <?= usEscape($project['project_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="title">
                            Story Title <span class="required">*</span>
                        </label>

                        <input
                            class="input"
                            type="text"
                            id="title"
                            name="title"
                            maxlength="255"
                            placeholder="e.g. User Login"
                            value="<?= usEscape($editStory['title'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="field full">
                        <label for="user_role">
                            User Role <span class="required">*</span>
                        </label>

                        <input
                            class="input"
                            type="text"
                            id="user_role"
                            name="user_role"
                            maxlength="150"
                            placeholder="e.g. registered user, administrator or project manager"
                            value="<?= usEscape($editStory['user_role'] ?? '') ?>"
                            required
                        >

                        <div class="field-hint">
                            Who needs this feature? Describe the user from their point of view.
                        </div>
                    </div>

                    <div class="field full">
                        <label for="story_description">
                            User Story <span class="required">*</span>
                        </label>

                        <textarea
                            class="textarea"
                            id="story_description"
                            name="story_description"
                            placeholder="As a registered user, I want to log in securely so that I can access my account."
                            required
                        ><?= usEscape($editStory['story_description'] ?? '') ?></textarea>

                        <div class="field-hint">
                            Suggested format: As a [user], I want [goal], so that [benefit].
                        </div>
                    </div>

                    <div class="field full">
                        <label for="acceptance_criteria">
                            Acceptance Criteria
                        </label>

                        <textarea
                            class="textarea"
                            id="acceptance_criteria"
                            name="acceptance_criteria"
                            placeholder="1. The user enters a valid email and password.&#10;2. Valid credentials open the dashboard.&#10;3. Invalid credentials display an error message."
                        ><?= usEscape($editStory['acceptance_criteria'] ?? '') ?></textarea>

                        <div class="field-hint">
                            List the conditions that must be met for the story to be accepted.
                            Use a new line for each condition.
                        </div>
                    </div>

                    <div class="field">
                        <label for="priority">
                            Priority
                        </label>

                        <select
                            class="select"
                            id="priority"
                            name="priority"
                        >
                            <?php foreach ($priorities as $priority): ?>
                                <option
                                    value="<?= usEscape($priority) ?>"
                                    <?= ($editStory['priority'] ?? 'Medium') === $priority ? 'selected' : '' ?>
                                >
                                    <?= usEscape($priority) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="status">
                            Status
                        </label>

                        <select
                            class="select"
                            id="status"
                            name="status"
                        >
                            <?php foreach ($statuses as $status): ?>
                                <option
                                    value="<?= usEscape($status) ?>"
                                    <?= ($editStory['status'] ?? 'Draft') === $status ? 'selected' : '' ?>
                                >
                                    <?= usEscape($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <div class="form-actions">

                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i>

                        <?= $editStory ? 'Update User Story' : 'Save User Story' ?>
                    </button>

                    <button
                        type="reset"
                        class="btn btn-light"
                    >
                        <i class="fa-solid fa-rotate-left"></i>
                        Reset Form
                    </button>

                    <?php if ($editStory): ?>
                        <a
                            href="user-stories.php"
                            class="btn btn-light"
                        >
                            Cancel
                        </a>
                    <?php endif; ?>

                </div>
            </form>
        </div>
    </section>

    <!-- Story library -->

    <div class="section-heading">
        <div>
            <h2>Story Library</h2>

            <p>
                Browse and manage the user stories across your projects.
            </p>
        </div>

        <span class="section-count">
            <?= count($stories) ?>
            <?= count($stories) === 1 ? 'STORY' : 'STORIES' ?>
        </span>
    </div>

    <section class="panel">

        <form method="GET" action="user-stories.php" class="filters">

            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    class="input"
                    type="search"
                    name="search"
                    placeholder="Search stories..."
                    value="<?= usEscape($search) ?>"
                >
            </div>

            <select
                class="select"
                name="project"
                aria-label="Filter by project"
            >
                <option value="">All Projects</option>

                <?php foreach ($projects as $project): ?>
                    <option
                        value="<?= (int) $project['project_id'] ?>"
                        <?= $filterProject === (int) $project['project_id'] ? 'selected' : '' ?>
                    >
                        <?= usEscape($project['project_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                class="select"
                name="status"
                aria-label="Filter by status"
            >
                <option value="">All Statuses</option>

                <?php foreach ($statuses as $status): ?>
                    <option
                        value="<?= usEscape($status) ?>"
                        <?= $filterStatus === $status ? 'selected' : '' ?>
                    >
                        <?= usEscape($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                class="select"
                name="priority"
                aria-label="Filter by priority"
            >
                <option value="">All Priorities</option>

                <?php foreach ($priorities as $priority): ?>
                    <option
                        value="<?= usEscape($priority) ?>"
                        <?= $filterPriority === $priority ? 'selected' : '' ?>
                    >
                        <?= usEscape($priority) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-filter"></i>
                Filter
            </button>

        </form>
    </section>

    <section class="story-grid" id="storyGrid">

        <?php if (empty($stories)): ?>

            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fa-solid fa-layer-group"></i>
                </div>

                <h3>No user stories found</h3>

                <p>
                    There are no stories matching your filters.
                    Create a new story or adjust your search to see more results.
                </p>

                <?php if ($search !== '' || $filterProject || $filterStatus || $filterPriority): ?>
                    <a href="user-stories.php" class="btn btn-light">
                        <i class="fa-solid fa-filter-circle-xmark"></i>
                        Clear Filters
                    </a>
                <?php else: ?>
                    <a href="#story-form" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i>
                        Create First Story
                    </a>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <?php foreach ($stories as $story): ?>

                <?php
                $storyJson = json_encode(
                    [
                        'id' => (int) $story['story_id'],
                        'title' => $story['title'],
                        'project' => $story['project_name'] ?? 'Unknown project',
                        'role' => $story['user_role'],
                        'description' => $story['story_description'],
                        'criteria' => $story['acceptance_criteria'] ?? '',
                        'priority' => $story['priority'],
                        'status' => $story['status'],
                        'creator' => $story['creator_name'] ?? 'Unknown',
                        'created' => $story['created_at'],
                        'updated' => $story['updated_at']
                    ],
                    JSON_HEX_TAG |
                    JSON_HEX_APOS |
                    JSON_HEX_QUOT |
                    JSON_HEX_AMP
                );
                ?>

                <article class="story-card">

                    <div class="story-card-top">

                        <span class="story-number">
                            <i class="fa-solid fa-hashtag"></i>
                            STORY-<?= (int) $story['story_id'] ?>
                        </span>

                        <span class="priority <?= usPriorityClass($story['priority']) ?>">
                            <?= usEscape($story['priority']) ?>
                        </span>

                    </div>

                    <h3 class="story-title">
                        <?= usEscape($story['title']) ?>
                    </h3>

                    <div class="project-label">
                        <i class="fa-solid fa-folder-open"></i>

                        <span>
                            <?= usEscape($story['project_name'] ?? 'Unknown project') ?>
                        </span>
                    </div>

                    <div class="user-role-box">
                        <div class="mini-label">User role</div>

                        <div class="role-text">
                            <i
                                class="fa-solid fa-user"
                                style="color:var(--primary);margin-right:5px"
                            ></i>

                            <?= usEscape($story['user_role']) ?>
                        </div>
                    </div>

                    <div class="story-description">
                        <?= usEscape(
                            mb_strimwidth(
                                $story['story_description'],
                                0,
                                170,
                                '...'
                            )
                        ) ?>
                    </div>

                    <div class="story-meta">

                        <span class="status <?= usStatusClass($story['status']) ?>">
                            <i class="fa-solid fa-circle" style="font-size:6px"></i>
                            <?= usEscape($story['status']) ?>
                        </span>

                        <span class="story-date">
                            <i class="fa-regular fa-calendar"></i>

                            <?= usEscape(
                                date(
                                    'd M Y',
                                    strtotime($story['updated_at'])
                                )
                            ) ?>
                        </span>

                    </div>

                    <div class="story-actions">

                        <button
                            type="button"
                            class="action-btn view-story"
                            data-story="<?= usEscape($storyJson) ?>"
                        >
                            <i class="fa-regular fa-eye"></i>
                            View
                        </button>

                        <a
                            class="action-btn"
                            href="user-stories.php?edit=<?= (int) $story['story_id'] ?>#story-form"
                        >
                            <i class="fa-solid fa-pen"></i>
                            Edit
                        </a>

                        <form
                            method="POST"
                            action="user-stories.php"
                            onsubmit="return confirm('Are you sure you want to delete this user story? This action cannot be undone.');"
                            style="display:flex;flex:1;min-width:0"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= usEscape($csrfToken) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="delete"
                            >

                            <input
                                type="hidden"
                                name="story_id"
                                value="<?= (int) $story['story_id'] ?>"
                            >

                            <button
                                type="submit"
                                class="action-btn delete"
                                style="width:100%"
                            >
                                <i class="fa-regular fa-trash-can"></i>
                                Delete
                            </button>
                        </form>

                    </div>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</main>

<footer class="footer">
    TidyTrack Business Analyst Portal &copy; <?= date('Y') ?>
    <br>
    <span style="display:inline-block;margin-top:5px">
        Manage requirements. Build better solutions.
    </span>
</footer>

<!-- Story details modal -->

<div
    class="modal"
    id="storyModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="modalTitle"
>
    <div class="modal-dialog">

        <div class="modal-header">
            <div>
                <div
                    class="mini-label"
                    id="modalStoryNumber"
                    style="margin-bottom:8px"
                >
                    STORY DETAILS
                </div>

                <h2 id="modalTitle">User Story</h2>
            </div>

            <button
                type="button"
                class="modal-close"
                id="closeStoryModal"
                aria-label="Close story details"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body">

            <div class="detail-grid">

                <div class="detail-box">
                    <div class="mini-label">Project</div>
                    <div class="detail-value" id="modalProject"></div>
                </div>

                <div class="detail-box">
                    <div class="mini-label">User Role</div>
                    <div class="detail-value" id="modalRole"></div>
                </div>

                <div class="detail-box">
                    <div class="mini-label">Priority</div>
                    <div class="detail-value" id="modalPriority"></div>
                </div>

                <div class="detail-box">
                    <div class="mini-label">Status</div>
                    <div class="detail-value" id="modalStatus"></div>
                </div>

                <div class="detail-box">
                    <div class="mini-label">Created By</div>
                    <div class="detail-value" id="modalCreator"></div>
                </div>

                <div class="detail-box">
                    <div class="mini-label">Last Updated</div>
                    <div class="detail-value" id="modalUpdated"></div>
                </div>

            </div>

            <div class="detail-section">
                <h3>
                    <i
                        class="fa-solid fa-align-left"
                        style="color:var(--primary);margin-right:6px"
                    ></i>
                    User Story Description
                </h3>

                <div class="detail-text" id="modalDescription"></div>
            </div>

            <div class="detail-section">
                <h3>
                    <i
                        class="fa-solid fa-list-check"
                        style="color:var(--primary);margin-right:6px"
                    ></i>
                    Acceptance Criteria
                </h3>

                <div class="detail-text" id="modalCriteria"></div>
            </div>

        </div>

        <div class="modal-footer">
            <a href="#" class="btn btn-primary" id="modalEdit">
                <i class="fa-solid fa-pen"></i>
                Edit Story
            </a>

            <button
                type="button"
                class="btn btn-light"
                id="modalCloseBottom"
            >
                Close
            </button>
        </div>

    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('storyModal');
    const closeButton = document.getElementById('closeStoryModal');
    const closeBottom = document.getElementById('modalCloseBottom');

    let lastFocusedElement = null;

    function setText(id, value) {
        document.getElementById(id).textContent =
            value || 'Not provided';
    }

    function openModal(story) {
        lastFocusedElement = document.activeElement;

        setText('modalStoryNumber', 'STORY-' + story.id);
        setText('modalTitle', story.title);
        setText('modalProject', story.project);
        setText('modalRole', story.role);
        setText('modalPriority', story.priority);
        setText('modalStatus', story.status);
        setText('modalCreator', story.creator);
        setText('modalUpdated', story.updated);
        setText('modalDescription', story.description);
        setText('modalCriteria', story.criteria);

        document.getElementById('modalEdit').href =
            'user-stories.php?edit=' +
            encodeURIComponent(story.id) +
            '#story-form';

        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        closeButton.focus();
    }

    function closeModal() {
        modal.classList.remove('open');
        document.body.style.overflow = '';

        if (lastFocusedElement) {
            lastFocusedElement.focus();
        }
    }

    document.querySelectorAll('.view-story').forEach(button => {
        button.addEventListener('click', () => {
            try {
                const story = JSON.parse(
                    button.dataset.story || '{}'
                );

                openModal(story);
            } catch (error) {
                alert('Unable to open this user story.');
            }
        });
    });

    closeButton.addEventListener('click', closeModal);
    closeBottom.addEventListener('click', closeModal);

    modal.addEventListener('click', event => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', event => {
        if (
            event.key === 'Escape' &&
            modal.classList.contains('open')
        ) {
            closeModal();
        }
    });
})();
</script>

</body>
</html>
