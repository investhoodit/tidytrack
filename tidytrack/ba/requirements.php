<?php
session_start();
include '../dp.php';

date_default_timezone_set('Africa/Johannesburg');

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
| SETTINGS
|--------------------------------------------------------------------------
*/

$statuses = [
    'Draft',
    'In Review',
    'Approved',
    'Rejected',
    'Implemented'
];

$priorities = [
    'Low',
    'Medium',
    'High',
    'Critical'
];

if (empty($_SESSION['ba_requirements_csrf'])) {
    $_SESSION['ba_requirements_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['ba_requirements_csrf'];

$message = '';
$messageType = 'success';

function reqEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function reqRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD EXISTING PROJECTS
|--------------------------------------------------------------------------
*/

$projects = [];

$projectSql = "
    SELECT project_id, project_name
    FROM projects_x
    ORDER BY project_name ASC
";

$projectResult = $conn->query($projectSql);

if ($projectResult) {
    while ($row = $projectResult->fetch_assoc()) {
        $projects[(int)$row['project_id']] = $row['project_name'];
    }

    $projectResult->free();
}

/*
|--------------------------------------------------------------------------
| HANDLE CREATE, UPDATE AND DELETE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $message = 'Your session token is invalid. Refresh the page and try again.';
        $messageType = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        /*
        |------------------------------------------------------------------
        | ADD OR UPDATE REQUIREMENT
        |------------------------------------------------------------------
        */

        if ($action === 'save') {

            $requirementId = filter_var(
                $_POST['requirement_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $projectId = filter_var(
                $_POST['project_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $acceptanceCriteria = trim(
                $_POST['acceptance_criteria'] ?? ''
            );

            $priority = $_POST['priority'] ?? 'Medium';
            $status = $_POST['status'] ?? 'Draft';

            $formErrors = [];

            if (!$projectId || !isset($projects[$projectId])) {
                $formErrors[] = 'Please select a valid project.';
            }

            if ($title === '') {
                $formErrors[] = 'The requirement title is required.';
            }

            if (mb_strlen($title) > 255) {
                $formErrors[] = 'The title cannot exceed 255 characters.';
            }

            if ($description === '') {
                $formErrors[] = 'The requirement description is required.';
            }

            if (!in_array($priority, $priorities, true)) {
                $formErrors[] = 'Please select a valid priority.';
            }

            if (!in_array($status, $statuses, true)) {
                $formErrors[] = 'Please select a valid status.';
            }

            if (
                isset($_POST['requirement_id']) &&
                $_POST['requirement_id'] !== '' &&
                !$requirementId
            ) {
                $formErrors[] = 'The requirement ID is invalid.';
            }

            if (empty($formErrors)) {

                if ($requirementId) {

                    /*
                    | Update existing requirement.
                    */

                    $checkStmt = $conn->prepare(
                        "SELECT requirement_id
                         FROM ba_requirements
                         WHERE requirement_id = ?
                         LIMIT 1"
                    );

                    $checkStmt->bind_param('i', $requirementId);
                    $checkStmt->execute();

                    $existingRequirement = $checkStmt
                        ->get_result()
                        ->fetch_assoc();

                    $checkStmt->close();

                    if (!$existingRequirement) {

                        $message = 'The requirement could not be found.';
                        $messageType = 'error';

                    } else {

                        $updateStmt = $conn->prepare("
                            UPDATE ba_requirements
                            SET
                                project_id = ?,
                                title = ?,
                                description = ?,
                                acceptance_criteria = ?,
                                priority = ?,
                                status = ?
                            WHERE requirement_id = ?
                        ");

                        $updateStmt->bind_param(
                            'isssssi',
                            $projectId,
                            $title,
                            $description,
                            $acceptanceCriteria,
                            $priority,
                            $status,
                            $requirementId
                        );

                        if ($updateStmt->execute()) {
                            $updateStmt->close();

                            reqRedirect(
                                'requirements.php?saved=updated'
                            );

                        } else {
                            $message = 'Unable to update the requirement. Please try again.';
                            $messageType = 'error';
                            $updateStmt->close();
                        }
                    }

                } else {

                    /*
                    | Create new requirement.
                    */

                    $createdBy = (int)$_SESSION['ba_user_id'];

                    $insertStmt = $conn->prepare("
                        INSERT INTO ba_requirements (
                            project_id,
                            title,
                            description,
                            acceptance_criteria,
                            priority,
                            status,
                            created_by
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    $insertStmt->bind_param(
                        'isssssi',
                        $projectId,
                        $title,
                        $description,
                        $acceptanceCriteria,
                        $priority,
                        $status,
                        $createdBy
                    );

                    if ($insertStmt->execute()) {
                        $insertStmt->close();

                        reqRedirect(
                            'requirements.php?saved=created'
                        );

                    } else {
                        $message = 'Unable to save the requirement. Please try again.';
                        $messageType = 'error';
                        $insertStmt->close();
                    }
                }

            } else {
                $message = implode(' ', $formErrors);
                $messageType = 'error';
            }
        }

        /*
        |------------------------------------------------------------------
        | DELETE REQUIREMENT
        |------------------------------------------------------------------
        */

        elseif ($action === 'delete') {

            $requirementId = filter_var(
                $_POST['requirement_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$requirementId) {

                $message = 'Invalid requirement ID.';
                $messageType = 'error';

            } else {

                $deleteStmt = $conn->prepare("
                    DELETE FROM ba_requirements
                    WHERE requirement_id = ?
                ");

                $deleteStmt->bind_param('i', $requirementId);

                if ($deleteStmt->execute()) {

                    $deletedRows = $deleteStmt->affected_rows;
                    $deleteStmt->close();

                    if ($deletedRows > 0) {
                        reqRedirect('requirements.php?saved=deleted');
                    }

                    $message = 'The requirement was not found.';
                    $messageType = 'error';

                } else {
                    $message = 'Unable to delete the requirement.';
                    $messageType = 'error';
                    $deleteStmt->close();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGES
|--------------------------------------------------------------------------
*/

if (isset($_GET['saved'])) {
    switch ($_GET['saved']) {
        case 'created':
            $message = 'Requirement created successfully.';
            break;

        case 'updated':
            $message = 'Requirement updated successfully.';
            break;

        case 'deleted':
            $message = 'Requirement deleted successfully.';
            break;
    }
}

/*
|--------------------------------------------------------------------------
| EDIT OR VIEW MODE
|--------------------------------------------------------------------------
*/

$editRequirement = null;
$viewRequirement = null;

$editId = filter_input(
    INPUT_GET,
    'edit',
    FILTER_VALIDATE_INT
);

$viewId = filter_input(
    INPUT_GET,
    'view',
    FILTER_VALIDATE_INT
);

if ($editId || $viewId) {

    $selectedId = $editId ?: $viewId;

    $detailStmt = $conn->prepare("
        SELECT
            r.requirement_id,
            r.project_id,
            r.title,
            r.description,
            r.acceptance_criteria,
            r.priority,
            r.status,
            r.created_by,
            r.created_at,
            r.updated_at,
            u.name AS creator_name
        FROM ba_requirements r
        LEFT JOIN users u ON u.user_id = r.created_by
        WHERE r.requirement_id = ?
        LIMIT 1
    ");

    $detailStmt->bind_param('i', $selectedId);
    $detailStmt->execute();

    $selectedRequirement = $detailStmt
        ->get_result()
        ->fetch_assoc();

    $detailStmt->close();

    if ($selectedRequirement) {
        if ($editId) {
            $editRequirement = $selectedRequirement;
        } else {
            $viewRequirement = $selectedRequirement;
        }
    } else {
        $message = 'The selected requirement could not be found.';
        $messageType = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| SEARCH AND FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$filterProject = filter_var(
    $_GET['project'] ?? null,
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

if ($filterProject && !isset($projects[$filterProject])) {
    $filterProject = null;
}

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = '(r.title LIKE ? OR r.description LIKE ? OR r.acceptance_criteria LIKE ?)';

    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;

    $types .= 'sss';
}

if ($filterProject) {
    $where[] = 'r.project_id = ?';
    $params[] = $filterProject;
    $types .= 'i';
}

if ($filterStatus !== '') {
    $where[] = 'r.status = ?';
    $params[] = $filterStatus;
    $types .= 's';
}

if ($filterPriority !== '') {
    $where[] = 'r.priority = ?';
    $params[] = $filterPriority;
    $types .= 's';
}

$whereSql = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/*
|--------------------------------------------------------------------------
| REQUIREMENT STATISTICS
|--------------------------------------------------------------------------
*/

$statistics = [
    'total' => 0,
    'Draft' => 0,
    'In Review' => 0,
    'Approved' => 0,
    'Rejected' => 0,
    'Implemented' => 0
];

$statsResult = $conn->query("
    SELECT status, COUNT(*) AS total
    FROM ba_requirements
    GROUP BY status
");

if ($statsResult) {
    while ($row = $statsResult->fetch_assoc()) {
        $statusKey = $row['status'];
        $count = (int)$row['total'];

        $statistics['total'] += $count;

        if (isset($statistics[$statusKey])) {
            $statistics[$statusKey] = $count;
        }
    }

    $statsResult->free();
}

/*
|--------------------------------------------------------------------------
| FETCH REQUIREMENTS
|--------------------------------------------------------------------------
*/

$requirements = [];

$listSql = "
    SELECT
        r.requirement_id,
        r.project_id,
        r.title,
        r.description,
        r.acceptance_criteria,
        r.priority,
        r.status,
        r.created_at,
        r.updated_at,
        p.project_name,
        u.name AS creator_name
    FROM ba_requirements r
    LEFT JOIN projects_x p ON p.project_id = r.project_id
    LEFT JOIN users u ON u.user_id = r.created_by
    $whereSql
    ORDER BY r.updated_at DESC, r.requirement_id DESC
";

$listStmt = $conn->prepare($listSql);

if ($listStmt) {
    if (!empty($params)) {
        $listStmt->bind_param($types, ...$params);
    }

    $listStmt->execute();

    $listResult = $listStmt->get_result();

    while ($row = $listResult->fetch_assoc()) {
        $requirements[] = $row;
    }

    $listStmt->close();
}

/*
|--------------------------------------------------------------------------
| FORM VALUES
|--------------------------------------------------------------------------
*/

$formData = [
    'requirement_id' => $editRequirement['requirement_id'] ?? '',
    'project_id' => $editRequirement['project_id'] ?? '',
    'title' => $editRequirement['title'] ?? '',
    'description' => $editRequirement['description'] ?? '',
    'acceptance_criteria' => $editRequirement['acceptance_criteria'] ?? '',
    'priority' => $editRequirement['priority'] ?? 'Medium',
    'status' => $editRequirement['status'] ?? 'Draft'
];

$showForm = $editRequirement !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Requirements | TidyTrack BA Portal</title>

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
            --red: #b91c1c;
            --red-bg: #fee2e2;
            --purple: #7e22ce;
            --purple-bg: #f3e8ff;
            --shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input,
        select,
        textarea {
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
            position: sticky;
            top: 0;
            z-index: 1000;
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

        .back-button,
        .primary-button,
        .secondary-button,
        .danger-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: .2s;
        }

        .back-button,
        .secondary-button {
            border: 1px solid var(--border);
            background: var(--white);
            color: var(--text);
        }

        .back-button:hover,
        .secondary-button:hover {
            border-color: var(--primary);
            color: var(--primary-dark);
        }

        .primary-button {
            border: 1px solid var(--primary);
            background: var(--primary);
            color: var(--white);
        }

        .primary-button:hover {
            background: var(--primary-dark);
        }

        .danger-button {
            border: 1px solid #fecaca;
            background: var(--white);
            color: var(--red);
        }

        .danger-button:hover {
            background: var(--red-bg);
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
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            margin: 0 0 8px;
            font-size: clamp(24px, 3vw, 30px);
            letter-spacing: -.7px;
        }

        .page-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .heading-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .heading-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border-radius: 13px;
            background: var(--primary-light);
            color: var(--primary-dark);
            font-size: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 18px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: var(--shadow);
        }

        .stat-icon {
            width: 43px;
            height: 43px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border-radius: 11px;
            background: #f0fdfa;
            color: var(--primary);
        }

        .stat-icon.review {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .stat-icon.approved {
            background: var(--success-bg);
            color: var(--success);
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            line-height: 1.2;
        }

        .stat-label {
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
        }

        .card {
            margin-bottom: 22px;
            padding: 22px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
        }

        .card-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }

        .card-heading h2 {
            margin: 0;
            font-size: 17px;
        }

        .card-heading p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .alert {
            margin-bottom: 20px;
            padding: 13px 15px;
            border: 1px solid #bbf7d0;
            border-radius: 9px;
            background: var(--success-bg);
            color: var(--success);
            font-size: 13px;
            line-height: 1.6;
        }

        .alert.error {
            border-color: #fecaca;
            background: var(--red-bg);
            color: var(--red);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: minmax(180px, 2fr) repeat(3, minmax(130px, 1fr)) auto;
            gap: 11px;
            align-items: end;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin-bottom: 16px;
        }

        .filter-grid .field {
            margin-bottom: 0;
        }

        .field label {
            color: #334155;
            font-size: 12px;
            font-weight: 700;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            min-width: 0;
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            outline: none;
            background: var(--white);
            color: var(--text);
            font-size: 13px;
            transition: .2s;
        }

        .field textarea {
            min-height: 125px;
            resize: vertical;
            line-height: 1.7;
        }

        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, .1);
        }

        .field small {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.5;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0 16px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 5px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .requirements-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .requirements-table th {
            padding: 13px 12px;
            background: #f8fafc;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .5px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .requirements-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #edf2f7;
            font-size: 12px;
            vertical-align: middle;
        }

        .requirements-table tbody tr:hover {
            background: #fbfefd;
        }

        .requirements-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .requirement-title {
            display: block;
            margin-bottom: 5px;
            color: var(--text);
            font-size: 13px;
            font-weight: 700;
        }

        .requirement-description {
            max-width: 300px;
            color: var(--muted);
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .project-name {
            display: inline-block;
            padding: 6px 8px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #475569;
            font-size: 11px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 9px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-draft {
            color: #475569;
            background: #e2e8f0;
        }

        .status-in-review {
            color: var(--warning);
            background: var(--warning-bg);
        }

        .status-approved {
            color: var(--success);
            background: var(--success-bg);
        }

        .status-rejected {
            color: var(--red);
            background: var(--red-bg);
        }

        .status-implemented {
            color: var(--blue);
            background: var(--blue-bg);
        }

        .priority-low {
            color: #475569;
            background: #f1f5f9;
        }

        .priority-medium {
            color: var(--blue);
            background: var(--blue-bg);
        }

        .priority-high {
            color: var(--warning);
            background: var(--warning-bg);
        }

        .priority-critical {
            color: var(--red);
            background: var(--red-bg);
        }

        .row-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .icon-button {
            width: 33px;
            height: 33px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 7px;
            background: var(--white);
            color: var(--muted);
            cursor: pointer;
            transition: .2s;
        }

        .icon-button:hover {
            color: var(--primary-dark);
            border-color: var(--primary);
        }

        .icon-button.delete:hover {
            color: var(--red);
            border-color: #fecaca;
            background: var(--red-bg);
        }

        .empty-state {
            padding: 42px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            margin-bottom: 14px;
            font-size: 32px;
            color: #94a3b8;
        }

        .empty-state h3 {
            margin: 0 0 8px;
            color: var(--text);
            font-size: 15px;
        }

        .empty-state p {
            margin: 0 0 18px;
            font-size: 12px;
            line-height: 1.7;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .detail-item {
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fbfdff;
            min-width: 0;
        }

        .detail-label {
            margin-bottom: 9px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .detail-value {
            font-size: 13px;
            line-height: 1.8;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .footer {
            padding: 22px 6%;
            background: var(--white);
            border-top: 1px solid var(--border);
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        @media (max-width: 1000px) {
            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-grid .filter-actions {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 700px) {
            .topbar {
                padding: 13px 5%;
            }

            .page {
                padding: 25px 5% 38px;
            }

            .page-heading {
                align-items: flex-start;
            }

            .heading-actions {
                flex-direction: column;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .stat-card {
                padding: 13px;
                gap: 9px;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
            }

            .stat-number {
                font-size: 21px;
            }

            .stat-label {
                font-size: 11px;
            }

            .card {
                padding: 16px;
            }

            .form-grid,
            .details-grid {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: auto;
            }

            .table-wrapper {
                overflow: visible;
            }

            .requirements-table,
            .requirements-table tbody,
            .requirements-table tr,
            .requirements-table td {
                display: block;
                width: 100%;
            }

            .requirements-table thead {
                display: none;
            }

            .requirements-table tbody tr {
                padding: 13px 0;
                border-bottom: 1px solid var(--border);
            }

            .requirements-table tbody tr:last-child {
                border-bottom: 0;
            }

            .requirements-table td {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 14px;
                padding: 7px 0;
                border: 0;
                text-align: right;
            }

            .requirements-table td::before {
                content: attr(data-label);
                flex: 0 0 95px;
                color: var(--muted);
                font-size: 10px;
                font-weight: 700;
                text-align: left;
                text-transform: uppercase;
            }

            .requirements-table td:first-child {
                padding-top: 3px;
            }

            .requirements-table td:last-child {
                padding-bottom: 3px;
            }

            .requirement-description {
                max-width: none;
                text-align: right;
            }

            .row-actions {
                justify-content: flex-end;
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
                width: 40px;
                height: 40px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid .filter-actions {
                grid-column: auto;
            }

            .heading-actions .primary-button {
                padding: 10px;
            }

            .requirements-table td::before {
                flex-basis: 80px;
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
        <span>Dashboard</span>
    </a>

</header>

<main class="page">

    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
        <span>Business Analysis</span>
        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
        <span class="current">Requirements</span>
    </div>

    <div class="page-heading">

        <div>
            <h1>Requirements</h1>
            <p>
                Document, review and manage project requirements in one place.
            </p>
        </div>

        <div class="heading-actions">
            <a href="#requirement-form" class="primary-button">
                <i class="fa-solid fa-plus"></i>
                Add Requirement
            </a>

            <div class="heading-icon">
                <i class="fa-solid fa-clipboard-list"></i>
            </div>
        </div>

    </div>

    <?php if ($message !== ''): ?>
        <div class="alert <?= $messageType === 'error' ? 'error' : '' ?>">
            <i class="fa-solid <?= $messageType === 'error'
                ? 'fa-circle-exclamation'
                : 'fa-circle-check' ?>"></i>

            <?= reqEscape($message) ?>
        </div>
    <?php endif; ?>

    <!-- REQUIREMENT STATISTICS -->

    <section class="stats-grid">

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-clipboard-list"></i>
            </div>

            <div>
                <div class="stat-number">
                    <?= (int)$statistics['total'] ?>
                </div>
                <div class="stat-label">Total Requirements</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon review">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>

            <div>
                <div class="stat-number">
                    <?= (int)$statistics['In Review'] ?>
                </div>
                <div class="stat-label">In Review</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon approved">
                <i class="fa-solid fa-circle-check"></i>
            </div>

            <div>
                <div class="stat-number">
                    <?= (int)$statistics['Approved'] ?>
                </div>
                <div class="stat-label">Approved</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-pen-to-square"></i>
            </div>

            <div>
                <div class="stat-number">
                    <?= (int)$statistics['Draft'] ?>
                </div>
                <div class="stat-label">Drafts</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2;color:#b91c1c;">
                <i class="fa-solid fa-ban"></i>
            </div>

            <div>
                <div class="stat-number">
                    <?= (int)$statistics['Rejected'] ?>
                </div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:#dbeafe;color:#1d4ed8;">
                <i class="fa-solid fa-flag-checkered"></i>
            </div>

            <div>
                <div class="stat-number">
                    <?= (int)$statistics['Implemented'] ?>
                </div>
                <div class="stat-label">Implemented</div>
            </div>
        </div>

    </section>

    <!-- ADD / EDIT FORM -->

    <?php if ($showForm): ?>

        <section class="card" id="requirement-form">

            <div class="card-heading">
                <div>
                    <h2>
                        <i class="fa-solid <?= $editRequirement
                            ? 'fa-pen-to-square'
                            : 'fa-plus-circle' ?>"
                           style="color:#0d9488;margin-right:7px;"></i>

                        Edit Requirement
                    </h2>

                    <p>Update the selected requirement below.</p>
                </div>

                <a href="requirements.php" class="secondary-button">
                    <i class="fa-solid fa-xmark"></i>
                    Cancel
                </a>
            </div>

            <?php if (empty($projects)): ?>

                <div class="alert error">
                    No projects were found. Create a project before adding requirements.
                </div>

            <?php else: ?>

                <form method="POST" action="requirements.php">

                    <input type="hidden" name="csrf_token"
                           value="<?= reqEscape($csrfToken) ?>">

                    <input type="hidden" name="action" value="save">

                    <input type="hidden" name="requirement_id"
                           value="<?= (int)$formData['requirement_id'] ?>">

                    <div class="form-grid">

                        <div class="field">
                            <label for="project_id">Project *</label>

                            <select id="project_id" name="project_id" required>
                                <option value="">Select project</option>

                                <?php foreach ($projects as $projectId => $projectName): ?>
                                    <option
                                        value="<?= (int)$projectId ?>"
                                        <?= (int)$formData['project_id'] === $projectId
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= reqEscape($projectName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="title">Requirement Title *</label>

                            <input
                                type="text"
                                id="title"
                                name="title"
                                maxlength="255"
                                value="<?= reqEscape($formData['title']) ?>"
                                placeholder="e.g. User login functionality"
                                required
                            >
                        </div>

                        <div class="field">
                            <label for="priority">Priority *</label>

                            <select id="priority" name="priority" required>
                                <?php foreach ($priorities as $priority): ?>
                                    <option
                                        value="<?= reqEscape($priority) ?>"
                                        <?= $formData['priority'] === $priority
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= reqEscape($priority) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="status">Status *</label>

                            <select id="status" name="status" required>
                                <?php foreach ($statuses as $status): ?>
                                    <option
                                        value="<?= reqEscape($status) ?>"
                                        <?= $formData['status'] === $status
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= reqEscape($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field full-width">
                            <label for="description">
                                Requirement Description *
                            </label>

                            <textarea
                                id="description"
                                name="description"
                                placeholder="Explain what the system needs to do and why."
                                required
                            ><?= reqEscape($formData['description']) ?></textarea>

                            <small>
                                Describe the business need or functionality being requested.
                            </small>
                        </div>

                        <div class="field full-width">
                            <label for="acceptance_criteria">
                                Acceptance Criteria
                            </label>

                            <textarea
                                id="acceptance_criteria"
                                name="acceptance_criteria"
                                placeholder="Explain how the team will confirm that this requirement is complete."
                            ><?= reqEscape($formData['acceptance_criteria']) ?></textarea>

                            <small>
                                You can list multiple criteria on separate lines.
                            </small>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" class="primary-button">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Update Requirement
                        </button>

                        <a href="requirements.php" class="secondary-button">
                            Cancel
                        </a>
                    </div>

                </form>

            <?php endif; ?>

        </section>

    <?php endif; ?>

    <!-- VIEW REQUIREMENT -->

    <?php if ($viewRequirement): ?>

        <section class="card">

            <div class="card-heading">

                <div>
                    <h2><?= reqEscape($viewRequirement['title']) ?></h2>
                    <p>
                        Requirement #<?= (int)$viewRequirement['requirement_id'] ?>
                    </p>
                </div>

                <div class="heading-actions">
                    <a
                        href="?edit=<?= (int)$viewRequirement['requirement_id'] ?>#requirement-form"
                        class="primary-button"
                    >
                        <i class="fa-solid fa-pen"></i>
                        Edit
                    </a>

                    <a href="requirements.php" class="secondary-button">
                        <i class="fa-solid fa-xmark"></i>
                        Close
                    </a>
                </div>

            </div>

            <div class="details-grid">

                <div class="detail-item">
                    <div class="detail-label">Project</div>
                    <div class="detail-value">
                        <?= reqEscape(
                            $projects[(int)$viewRequirement['project_id']]
                            ?? 'Project unavailable'
                        ) ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Priority</div>
                    <div class="detail-value">
                        <?= reqEscape($viewRequirement['priority']) ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <?= reqEscape($viewRequirement['status']) ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Created By</div>
                    <div class="detail-value">
                        <?= reqEscape(
                            $viewRequirement['creator_name']
                            ?? 'Unknown'
                        ) ?>
                    </div>
                </div>

                <div class="detail-item full-width">
                    <div class="detail-label">Description</div>
                    <div class="detail-value"><?= reqEscape($viewRequirement['description']) ?></div>
                </div>

                <div class="detail-item full-width">
                    <div class="detail-label">Acceptance Criteria</div>
                    <div class="detail-value"><?= reqEscape(
                        $viewRequirement['acceptance_criteria']
                        ?: 'No acceptance criteria added.'
                    ) ?></div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Created At</div>
                    <div class="detail-value">
                        <?= reqEscape($viewRequirement['created_at']) ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Last Updated</div>
                    <div class="detail-value">
                        <?= reqEscape($viewRequirement['updated_at']) ?>
                    </div>
                </div>

            </div>

        </section>

    <?php endif; ?>

    <!-- SEARCH AND FILTERS -->

    <section class="card">

        <div class="card-heading">
            <div>
                <h2>
                    <i class="fa-solid fa-magnifying-glass"
                       style="color:#0d9488;margin-right:7px;"></i>
                    Find Requirements
                </h2>

                <p>Search by title or description and filter your results.</p>
            </div>
        </div>

        <form method="GET" action="requirements.php">

            <div class="filter-grid">

                <div class="field">
                    <label for="search">Search</label>

                    <input
                        type="search"
                        id="search"
                        name="search"
                        value="<?= reqEscape($search) ?>"
                        placeholder="Search requirements..."
                    >
                </div>

                <div class="field">
                    <label for="filter_project">Project</label>

                    <select id="filter_project" name="project">
                        <option value="">All Projects</option>

                        <?php foreach ($projects as $projectId => $projectName): ?>
                            <option
                                value="<?= (int)$projectId ?>"
                                <?= $filterProject === $projectId
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= reqEscape($projectName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="filter_status">Status</label>

                    <select id="filter_status" name="status">
                        <option value="">All Statuses</option>

                        <?php foreach ($statuses as $status): ?>
                            <option
                                value="<?= reqEscape($status) ?>"
                                <?= $filterStatus === $status
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= reqEscape($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="filter_priority">Priority</label>

                    <select id="filter_priority" name="priority">
                        <option value="">All Priorities</option>

                        <?php foreach ($priorities as $priority): ?>
                            <option
                                value="<?= reqEscape($priority) ?>"
                                <?= $filterPriority === $priority
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= reqEscape($priority) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="primary-button">
                        <i class="fa-solid fa-filter"></i>
                        Filter
                    </button>

                    <a href="requirements.php"
                       class="secondary-button"
                       style="margin-top:7px;">
                        Reset
                    </a>
                </div>

            </div>

        </form>

    </section>

    <!-- REQUIREMENTS LIST -->

    <section class="card">

        <div class="card-heading">
            <div>
                <h2>All Requirements</h2>

                <p>
                    <?= count($requirements) ?>
                    <?= count($requirements) === 1
                        ? 'requirement found'
                        : 'requirements found' ?>
                </p>
            </div>
        </div>

        <?php if (empty($requirements)): ?>

            <div class="empty-state">
                <i class="fa-regular fa-clipboard"></i>

                <h3>No requirements found</h3>

                <p>
                    Add a requirement or change your search and filters.
                </p>

                <a href="#requirement-form" class="primary-button">
                    <i class="fa-solid fa-plus"></i>
                    Add Requirement
                </a>
            </div>

        <?php else: ?>

            <div class="table-wrapper">

                <table class="requirements-table">

                    <thead>
                        <tr>
                            <th>Requirement</th>
                            <th>Project</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($requirements as $requirement): ?>

                            <?php
                                $statusClass = 'status-' . strtolower(
                                    str_replace(' ', '-', $requirement['status'])
                                );

                                $priorityClass = 'priority-' . strtolower(
                                    $requirement['priority']
                                );
                            ?>

                            <tr>

                                <td data-label="Requirement">

                                    <a
                                        class="requirement-title"
                                        href="?view=<?= (int)$requirement['requirement_id'] ?>"
                                    >
                                        <?= reqEscape($requirement['title']) ?>
                                    </a>

                                    <div class="requirement-description">
                                        <?php
                                            $description = $requirement['description'];

                                            if (mb_strlen($description) > 110) {
                                                $description = mb_substr(
                                                    $description,
                                                    0,
                                                    110
                                                ) . '...';
                                            }
                                        ?>

                                        <?= reqEscape($description) ?>
                                    </div>

                                </td>

                                <td data-label="Project">
                                    <span class="project-name">
                                        <?= reqEscape(
                                            $requirement['project_name']
                                            ?? 'Project unavailable'
                                        ) ?>
                                    </span>
                                </td>

                                <td data-label="Priority">
                                    <span class="badge <?= reqEscape($priorityClass) ?>">
                                        <?= reqEscape($requirement['priority']) ?>
                                    </span>
                                </td>

                                <td data-label="Status">
                                    <span class="badge <?= reqEscape($statusClass) ?>">
                                        <?= reqEscape($requirement['status']) ?>
                                    </span>
                                </td>

                                <td data-label="Last Updated">
                                    <?= reqEscape(
                                        date(
                                            'd M Y',
                                            strtotime($requirement['updated_at'])
                                        )
                                    ) ?>
                                </td>

                                <td data-label="Actions">

                                    <div class="row-actions">

                                        <a
                                            href="?view=<?= (int)$requirement['requirement_id'] ?>"
                                            class="icon-button"
                                            title="View requirement"
                                            aria-label="View requirement"
                                        >
                                            <i class="fa-solid fa-eye"></i>
                                        </a>

                                        <a
                                            href="?edit=<?= (int)$requirement['requirement_id'] ?>#requirement-form"
                                            class="icon-button"
                                            title="Edit requirement"
                                            aria-label="Edit requirement"
                                        >
                                            <i class="fa-solid fa-pen"></i>
                                        </a>

                                        <form
                                            method="POST"
                                            action="requirements.php"
                                            class="delete-form"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= reqEscape($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="requirement_id"
                                                value="<?= (int)$requirement['requirement_id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="icon-button delete"
                                                title="Delete requirement"
                                                aria-label="Delete requirement"
                                            >
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

<footer class="footer">
    TidyTrack Business Analyst Portal &copy; <?= date('Y') ?>
</footer>

<script>
document.querySelectorAll('.delete-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        const confirmed = confirm(
            'Are you sure you want to delete this requirement? This action cannot be undone.'
        );

        if (!confirmed) {
            event.preventDefault();
        }
    });
});
</script>

</body>
</html>