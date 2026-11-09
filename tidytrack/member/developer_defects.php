<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

include '../dp.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = trim($_SESSION['name'] ?? 'User');
$userRole = strtolower(trim($_SESSION['role'] ?? 'member'));

$allowedRoles = [
    'frontend developer',
    'backend developer',
    'fullstack developer',
    'developer',
    'member'
];

if (!in_array($userRole, $allowedRoles)) {
    die("Access denied. This page is for developers only.");
}

function esc($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool {
    $r = $conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($table)."'");
    $ok = $r && $r->num_rows > 0;
    if ($r) $r->free();
    return $ok;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    $ok = $r && $r->num_rows > 0;
    if ($r) $r->free();
    return $ok;
}

function logDefectActivity(
    mysqli $conn,
    int $defectId,
    int $userId,
    string $type,
    string $message,
    ?string $field = null,
    ?string $oldValue = null,
    ?string $newValue = null
): void {
    if (!tableExists($conn, 'defect_activity')) return;
    $stmt = $conn->prepare("
        INSERT INTO defect_activity
            (defect_id, activity_type, field_name, old_value, new_value, message, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $stmt->bind_param("isssssi", $defectId, $type, $field, $oldValue, $newValue, $message, $userId);
    $stmt->execute();
    $stmt->close();
}

function getUserEmailById(mysqli $conn, int $userId): string {
    $email = '';
    $stmt = $conn->prepare("SELECT email FROM users WHERE user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $email = trim($row['email'] ?? '');
        }
        $stmt->close();
    }
    return $email;
}

function sendDefectCompletedEmailToBa(
    string $toEmail,
    string $baName,
    string $developerName,
    string $testCase,
    string $projectName,
    string $title,
    string $description,
    string $status,
    string $estimatedTime,
    string $developerComment
): bool {
    if ($toEmail === '') {
        return false;
    }

    $subject = "Defect Completed Update - {$testCase}";

    $message = "
    <html>
    <head>
        <title>Defect Completed Update</title>
    </head>
    <body style='font-family: Arial, sans-serif; color: #333;'>
        <h2 style='color: #2563eb;'>Defect Completed Update</h2>
        <p>Hello " . htmlspecialchars($baName) . ",</p>
        <p>The developer has updated a defect and marked it as Resolved.</p>

        <table cellpadding='8' cellspacing='0' border='1' style='border-collapse: collapse; width: 100%; max-width: 750px;'>
            <tr>
                <td><strong>Test Case</strong></td>
                <td>" . htmlspecialchars($testCase) . "</td>
            </tr>
            <tr>
                <td><strong>Project</strong></td>
                <td>" . htmlspecialchars($projectName) . "</td>
            </tr>
            <tr>
                <td><strong>Title</strong></td>
                <td>" . htmlspecialchars($title) . "</td>
            </tr>
            <tr>
                <td><strong>Description</strong></td>
                <td>" . nl2br(htmlspecialchars($description)) . "</td>
            </tr>
            <tr>
                <td><strong>Developer</strong></td>
                <td>" . htmlspecialchars($developerName) . "</td>
            </tr>
            <tr>
                <td><strong>Status</strong></td>
                <td>" . htmlspecialchars($status) . "</td>
            </tr>
            <tr>
                <td><strong>Estimated Time</strong></td>
                <td>" . htmlspecialchars($estimatedTime) . "</td>
            </tr>
            <tr>
                <td><strong>Developer Comments</strong></td>
                <td>" . nl2br(htmlspecialchars($developerComment !== '' ? $developerComment : 'No comment provided.')) . "</td>
            </tr>
        </table>

        <p style='margin-top: 20px;'>Please log in to the system to review the defect.</p>
        <p>Regards,<br>TidyTrack System</p>
    </body>
    </html>
    ";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: noreply@investhoodprojects.com\r\n";
    $headers .= "Reply-To: noreply@investhoodprojects.com\r\n";

    return mail($toEmail, $subject, $message, $headers);
}

$hasDeveloperCommentColumn = columnExists($conn, 'project_defects', 'developer_comment');

$success = '';
$error   = '';

/*
|--------------------------------------------------------------------------
| Update defect by developer
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_defect'])) {
    $defect_id          = (int)($_POST['defect_id'] ?? 0);
    $status             = trim($_POST['status'] ?? 'Open');
    $estimated_time     = trim($_POST['estimated_time'] ?? '');
    $recommendation     = trim($_POST['recommendation'] ?? '');
    $developer_comment  = trim($_POST['developer_comment'] ?? '');
    $date_work_begins   = trim($_POST['date_work_begins'] ?? '');
    $further_steps      = trim($_POST['further_steps'] ?? '');
    $date_work_resolved = trim($_POST['date_work_resolved'] ?? '');

    // make sure developer only updates defects assigned to them
    $check = $conn->prepare("
        SELECT defect_id, linked_task_id, created_by, test_case, project_name, title, description, status AS previous_status
        FROM project_defects
        WHERE defect_id = ?
          AND assigned = ?
        LIMIT 1
    ");
    $check->bind_param("is", $defect_id, $userName);
    $check->execute();
    $allowedRow = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$allowedRow) {
        $error = "You are not allowed to update this defect.";
    } else {
        $linked_task_id = !empty($allowedRow['linked_task_id']) ? (int)$allowedRow['linked_task_id'] : null;
        $previous_status = strtolower(trim((string)($allowedRow['previous_status'] ?? '')));
        $current_status  = strtolower(trim($status));

        if ($hasDeveloperCommentColumn) {
            $stmt = $conn->prepare("
                UPDATE project_defects
                SET
                    status = ?,
                    estimated_time = ?,
                    recommendation = ?,
                    developer_comment = ?,
                    date_work_begins = ?,
                    further_steps = ?,
                    date_work_resolved = ?
                WHERE defect_id = ?
                  AND assigned = ?
            ");

            $stmt->bind_param(
                "sssssssis",
                $status,
                $estimated_time,
                $recommendation,
                $developer_comment,
                $date_work_begins,
                $further_steps,
                $date_work_resolved,
                $defect_id,
                $userName
            );
        } else {
            $stmt = $conn->prepare("
                UPDATE project_defects
                SET
                    status = ?,
                    estimated_time = ?,
                    recommendation = ?,
                    date_work_begins = ?,
                    further_steps = ?,
                    date_work_resolved = ?
                WHERE defect_id = ?
                  AND assigned = ?
            ");

            $stmt->bind_param(
                "ssssssis",
                $status,
                $estimated_time,
                $recommendation,
                $date_work_begins,
                $further_steps,
                $date_work_resolved,
                $defect_id,
                $userName
            );
        }

        if ($stmt->execute()) {
            $success = "Defect updated successfully.";
            logDefectActivity(
                $conn,
                $defect_id,
                $userId,
                'updated',
                "{$userName} updated progress, dates or developer notes"
            );
            if ($previous_status !== $current_status) {
                logDefectActivity(
                    $conn,
                    $defect_id,
                    $userId,
                    'field_change',
                    "{$userName} changed status",
                    'status',
                    (string)($allowedRow['previous_status'] ?? ''),
                    $status
                );
            }

            if (!$hasDeveloperCommentColumn && $developer_comment !== '') {
                $success .= " Comment column was not found in the database, so the comment was not saved separately.";
            }

            if ($linked_task_id) {
                if (strtolower($status) === 'resolved') {
                    $sync = $conn->prepare("UPDATE tasks SET status = 'In Review' WHERE task_id = ?");
                    $sync->bind_param("i", $linked_task_id);
                    $sync->execute();
                    $sync->close();
                } elseif (strtolower($status) === 'in progress') {
                    $sync = $conn->prepare("UPDATE tasks SET status = 'In Progress' WHERE task_id = ?");
                    $sync->bind_param("i", $linked_task_id);
                    $sync->execute();
                    $sync->close();
                }
            }

            // notify BA only when status changes to resolved
            if ($current_status === 'resolved' && $previous_status !== 'resolved') {
                $baUserId   = (int)($allowedRow['created_by'] ?? 0);
                $baEmail    = $baUserId ? getUserEmailById($conn, $baUserId) : '';
                $baName     = 'BA Team';
                $devCommentForEmail = $developer_comment !== '' ? $developer_comment : $recommendation;

                if ($baUserId > 0) {
                    $nameStmt = $conn->prepare("SELECT name FROM users WHERE user_id = ? LIMIT 1");
                    if ($nameStmt) {
                        $nameStmt->bind_param("i", $baUserId);
                        $nameStmt->execute();
                        $nameRes = $nameStmt->get_result();
                        if ($nameRes && $nameRow = $nameRes->fetch_assoc()) {
                            $baName = trim($nameRow['name'] ?? 'BA Team');
                        }
                        $nameStmt->close();
                    }
                }

                if ($baEmail !== '') {
                    $mailSent = sendDefectCompletedEmailToBa(
                        $baEmail,
                        $baName,
                        $userName,
                        (string)($allowedRow['test_case'] ?? ''),
                        (string)($allowedRow['project_name'] ?? ''),
                        (string)($allowedRow['title'] ?? ''),
                        (string)($allowedRow['description'] ?? ''),
                        $status,
                        $estimated_time,
                        $devCommentForEmail
                    );

                    if ($mailSent) {
                        $success .= " BA notification email sent successfully.";
                    } else {
                        $error = "Defect updated, but the BA notification email could not be sent.";
                    }
                } else {
                    $error = "Defect updated, but the BA email address was not found.";
                }
            } elseif ($current_status === 'resolved' && $previous_status === 'resolved') {
                $success .= " Status was already Resolved, so no new BA email was sent.";
            }
        } else {
            $error = "Failed to update defect: " . $stmt->error;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Add task-style comment
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $defect_id = (int)($_POST['defect_id'] ?? 0);
    $commentText = trim($_POST['comment_text'] ?? '');

    $accessStmt = $conn->prepare("
        SELECT defect_id
        FROM project_defects
        WHERE defect_id = ? AND assigned = ?
        LIMIT 1
    ");
    $commentAllowed = false;
    if ($accessStmt) {
        $accessStmt->bind_param("is", $defect_id, $userName);
        $accessStmt->execute();
        $commentAllowed = (bool)$accessStmt->get_result()->fetch_assoc();
        $accessStmt->close();
    }

    if (!$commentAllowed) {
        $error = "You are not allowed to comment on this bug.";
    } elseif ($commentText === '') {
        $error = "Please enter a comment.";
    } elseif (!tableExists($conn, 'defect_comments')) {
        $error = "Run the latest bug_tracking_schema.sql to enable comments.";
    } else {
        $commentStmt = $conn->prepare("
            INSERT INTO defect_comments (defect_id, user_id, comment_text)
            VALUES (?, ?, ?)
        ");
        if ($commentStmt) {
            $commentStmt->bind_param("iis", $defect_id, $userId, $commentText);
            if ($commentStmt->execute()) {
                logDefectActivity($conn, $defect_id, $userId, 'comment', "{$userName} added a comment");
                $success = "Comment added successfully.";
            } else {
                $error = "Could not add the comment.";
            }
            $commentStmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$search        = trim($_GET['search'] ?? '');
$filterProject = trim($_GET['project'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');

/*
|--------------------------------------------------------------------------
| Load Projects for Filter
|--------------------------------------------------------------------------
*/
$projects = [];
$resProjects = $conn->query("
    SELECT DISTINCT project_name
    FROM project_defects
    WHERE assigned = '".$conn->real_escape_string($userName)."'
      AND project_name IS NOT NULL
      AND project_name <> ''
    ORDER BY project_name ASC
");
if ($resProjects) {
    while ($row = $resProjects->fetch_assoc()) {
        $projects[] = $row['project_name'];
    }
}

/*
|--------------------------------------------------------------------------
| Task History
|--------------------------------------------------------------------------
*/
$taskHistoryMap = [];
if (tableExists($conn, 'task_actions')) {
    $hist = $conn->query("
        SELECT task_id, action_type, old_value, new_value, created_at
        FROM task_actions
        ORDER BY created_at DESC
    ");

    if ($hist) {
        while ($h = $hist->fetch_assoc()) {
            $tid = (int)$h['task_id'];
            if (!isset($taskHistoryMap[$tid])) $taskHistoryMap[$tid] = [];
            if (count($taskHistoryMap[$tid]) < 10) {
                $taskHistoryMap[$tid][] = $h;
            }
        }
    }
}

$defectActivityMap = [];
if (tableExists($conn, 'defect_activity')) {
    $activityStmt = $conn->prepare("
        SELECT a.*, COALESCE(u.name, 'System') AS actor_name
        FROM defect_activity a
        INNER JOIN project_defects d ON d.defect_id = a.defect_id
        LEFT JOIN users u ON u.user_id = a.created_by
        WHERE d.assigned = ?
        ORDER BY a.created_at DESC, a.activity_id DESC
        LIMIT 1000
    ");
    $activityRes = null;
    if ($activityStmt) {
        $activityStmt->bind_param("s", $userName);
        $activityStmt->execute();
        $activityRes = $activityStmt->get_result();
    }
    if ($activityRes) {
        while ($activity = $activityRes->fetch_assoc()) {
            $did = (int)$activity['defect_id'];
            if (!isset($defectActivityMap[$did])) $defectActivityMap[$did] = [];
            if (count($defectActivityMap[$did]) < 50) $defectActivityMap[$did][] = $activity;
        }
    }
    if ($activityStmt) $activityStmt->close();
}

$defectCommentMap = [];
if (tableExists($conn, 'defect_comments')) {
    $commentStmt = $conn->prepare("
        SELECT c.*, COALESCE(u.name, 'Unknown user') AS author_name
        FROM defect_comments c
        INNER JOIN project_defects d ON d.defect_id = c.defect_id
        LEFT JOIN users u ON u.user_id = c.user_id
        WHERE d.assigned = ?
        ORDER BY c.created_at ASC, c.comment_id ASC
        LIMIT 1000
    ");
    $commentRes = null;
    if ($commentStmt) {
        $commentStmt->bind_param("s", $userName);
        $commentStmt->execute();
        $commentRes = $commentStmt->get_result();
    }
    if ($commentRes) {
        while ($comment = $commentRes->fetch_assoc()) {
            $did = (int)$comment['defect_id'];
            if (!isset($defectCommentMap[$did])) $defectCommentMap[$did] = [];
            $defectCommentMap[$did][] = $comment;
        }
    }
    if ($commentStmt) $commentStmt->close();
}

/*
|--------------------------------------------------------------------------
| Load only defects assigned to current developer
|--------------------------------------------------------------------------
*/
$where = ["d.assigned = ?"];
$params = [$userName];
$types  = "s";

if ($search !== '') {
    $where[] = "(d.test_case LIKE ? OR d.title LIKE ? OR d.description LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($filterProject !== '') {
    $where[] = "d.project_name = ?";
    $params[] = $filterProject;
    $types .= "s";
}

if ($filterStatus !== '') {
    $where[] = "d.status = ?";
    $params[] = $filterStatus;
    $types .= "s";
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$developerCommentSelect = $hasDeveloperCommentColumn ? ", d.developer_comment" : ", '' AS developer_comment";

$sql = "
    SELECT
        d.*,
        COALESCE(NULLIF(TRIM(u.name), ''), 'Unknown user') AS raised_by_name,
        t.taskTitle AS linked_task_title,
        t.status AS linked_task_status,
        t.dueDate AS linked_task_dueDate,
        t.created_at AS linked_task_created_at
        {$developerCommentSelect}
    FROM project_defects d
    LEFT JOIN users u ON u.user_id = d.created_by
    LEFT JOIN tasks t ON t.task_id = d.linked_task_id
    $whereSql
    ORDER BY d.defect_id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$defects = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assigned Bugs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="theme.css">
    <style>
        /* ── Reset & Base ── */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f3f6fc;
            color: #1e293b;
            line-height: 1.5;
            padding: 24px;
        }

        .page {
            max-width: 1600px;
            margin: 0 auto;
        }

        /* ── Cards ── */
        .card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #e9edf4;
            transition: box-shadow 0.2s;
        }

        .card:hover {
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06);
        }

        /* ── Header ── */
        .header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .header h1 {
            font-size: 26px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.3px;
        }

        .header p {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            background: #f1f5f9;
            color: #334155;
        }

        .btn i {
            font-size: 14px;
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.25);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #d1d9e6;
        }

        .btn-outline:hover {
            background: #f8fafc;
        }

        /* ── Messages ── */
        .message {
            padding: 14px 18px;
            border-radius: 14px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .message.success {
            background: #e6f7e6;
            color: #0b5e3b;
            border-left: 4px solid #28a745;
        }

        .message.error {
            background: #fde8e8;
            color: #a11f1f;
            border-left: 4px solid #dc3545;
        }

        /* ── Filters ── */
        .filters-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 14px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        input[type="text"],
        input[type="date"],
        textarea,
        select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 12px;
            background: #fafcff;
            font-size: 14px;
            transition: border 0.2s, box-shadow 0.2s;
            outline: none;
            font-family: inherit;
        }

        input:focus,
        textarea:focus,
        select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
            background: #fff;
        }

        textarea {
            min-height: 70px;
            resize: vertical;
        }

        .btn-filter {
            padding: 10px 28px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.2s;
            height: 44px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-filter:hover {
            background: #1d4ed8;
        }

        /* ── Defect Cards Grid ── */
        .defects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
            gap: 24px;
            margin-top: 8px;
        }

        .defect-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e9edf4;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            transition: box-shadow 0.2s, transform 0.15s;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .defect-card:hover {
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        /* Card Header */
        .card-header {
            padding: 18px 20px 14px;
            background: #fafcff;
            border-bottom: 1px solid #e9edf4;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .card-header .title-group {
            flex: 1;
        }

        .card-header .title-group h3 {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.3;
            margin-bottom: 4px;
        }

        .card-header .title-group .meta {
            font-size: 13px;
            color: #64748b;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .card-header .title-group .meta span i {
            margin-right: 4px;
            color: #94a3b8;
        }

        .card-header .status-badge {
            flex-shrink: 0;
            padding: 5px 14px;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
            letter-spacing: 0.2px;
            background: #e9edf4;
            color: #475569;
        }

        .status-badge.open {
            background: #fee2e2;
            color: #b91c1c;
        }
        .status-badge.in-progress {
            background: #fef9c3;
            color: #854d0e;
        }
        .status-badge.pending-review {
            background: #fde68a;
            color: #92400e;
        }
        .status-badge.resolved {
            background: #d1fae5;
            color: #065f46;
        }

        /* Card Body */
        .card-body {
            padding: 16px 20px;
            flex: 1;
        }

        .card-body .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 16px;
        }

        .card-body .detail-item {
            font-size: 13px;
            color: #334155;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .card-body .detail-item .label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: #94a3b8;
            letter-spacing: 0.3px;
        }

        .card-body .detail-item .value {
            padding: 6px 10px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e9edf4;
            font-size: 13px;
            color: #0f172a;
            word-break: break-word;
            min-height: 34px;
        }

        .card-body .detail-item .value .task-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }

        .card-body .detail-item .value .task-badge i {
            margin-right: 4px;
        }

        .card-body .detail-item .value a {
            color: #2563eb;
            text-decoration: none;
        }

        .card-body .detail-item .value a:hover {
            text-decoration: underline;
        }

        /* Card Footer (Update Form) */
        .card-footer {
            padding: 16px 20px 20px;
            border-top: 1px solid #e9edf4;
            background: #fafcff;
        }

        .card-footer .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 16px;
        }

        .card-footer .form-grid .full-width {
            grid-column: 1 / -1;
        }

        .card-footer .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .card-footer .form-group label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .card-footer .form-group .mini-input,
        .card-footer .form-group .mini-select,
        .card-footer .form-group .mini-textarea,
        .card-footer .form-group .mini-date {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #d1d9e6;
            border-radius: 10px;
            font-size: 13px;
            background: #ffffff;
            transition: border 0.15s, box-shadow 0.15s;
            font-family: inherit;
            outline: none;
        }

        .card-footer .form-group .mini-input:focus,
        .card-footer .form-group .mini-select:focus,
        .card-footer .form-group .mini-textarea:focus,
        .card-footer .form-group .mini-date:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
        }

        .card-footer .form-group .mini-textarea {
            min-height: 60px;
            resize: vertical;
        }

        .card-footer .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #e9edf4;
        }

        .card-footer .form-actions .btn-action {
            padding: 8px 18px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-save {
            background: #d1fae5;
            color: #065f46;
        }
        .btn-save:hover {
            background: #a7f3d0;
        }

        .btn-view {
            background: #eff6ff;
            color: #1d4ed8;
        }
        .btn-view:hover {
            background: #dbeafe;
        }

        /* ── Modal ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
            opacity: 0;
            transition: opacity 0.25s;
        }

        .modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .modal {
            width: 100%;
            max-width: 1100px;
            max-height: 90vh;
            overflow-y: auto;
            background: #ffffff;
            border-radius: 28px;
            box-shadow: 0 40px 80px rgba(0, 0, 0, 0.2);
            transform: scale(0.96);
            transition: transform 0.25s, opacity 0.25s;
            opacity: 0;
        }

        .modal-overlay.active .modal {
            transform: scale(1);
            opacity: 1;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 28px;
            border-bottom: 1px solid #e9edf4;
            position: sticky;
            top: 0;
            background: #ffffff;
            z-index: 10;
            border-radius: 28px 28px 0 0;
        }

        .modal-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
        }

        .close-btn {
            border: none;
            background: #f1f5f9;
            color: #475569;
            width: 40px;
            height: 40px;
            border-radius: 14px;
            cursor: pointer;
            font-size: 18px;
            transition: background 0.15s;
        }

        .close-btn:hover {
            background: #e2e8f0;
        }

        .modal-body {
            padding: 28px;
        }

        .modal-detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }

        .modal-detail-card {
            background: #f8fafc;
            border: 1px solid #e9edf4;
            border-radius: 18px;
            padding: 20px;
        }

        .modal-detail-card h3 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-detail-item {
            display: flex;
            padding: 6px 0;
            border-bottom: 1px solid #e9edf4;
            font-size: 14px;
        }

        .modal-detail-item:last-child {
            border-bottom: 0;
        }

        .modal-detail-item strong {
            min-width: 140px;
            color: #475569;
            font-weight: 600;
        }

        .modal-detail-item span {
            color: #0f172a;
        }

        .history-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .history-item {
            background: #ffffff;
            border: 1px solid #e9edf4;
            border-radius: 14px;
            padding: 14px 18px;
            font-size: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px 20px;
            align-items: center;
        }

        .history-item .action {
            font-weight: 600;
            color: #2563eb;
        }

        .history-item .old {
            color: #b91c1c;
        }

        .history-item .new {
            color: #065f46;
        }

        .history-time {
            margin-left: auto;
            font-size: 12px;
            color: #94a3b8;
        }

        .empty-note {
            color: #94a3b8;
            font-size: 14px;
            padding: 12px 0;
        }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .filters-grid {
                grid-template-columns: 1fr 1fr;
            }
            .modal-detail-grid {
                grid-template-columns: 1fr;
            }
            .defects-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            body { padding: 12px; }
            .card { padding: 16px; }
            .header { flex-direction: column; align-items: start; }
            .filters-grid {
                grid-template-columns: 1fr;
            }
            .btn-filter {
                width: 100%;
                justify-content: center;
            }
            .modal { max-width: 100%; }
            .modal-body { padding: 16px; }
            .card-body .detail-grid,
            .card-footer .form-grid {
                grid-template-columns: 1fr;
            }
            .card-footer .form-grid .full-width {
                grid-column: 1;
            }
        }
        
        /* Home button */
.home-btn {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #fff;
    border: 1px solid #d1d9e6;
    color: #1d3557;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 16px;
    transition: all .15s;
    flex-shrink: 0;
}
.home-btn:hover {
    background: #1d3557;
    color: #fff;
    border-color: #1d3557;
}

/* ===================== MOBILE ===================== */
@media (max-width: 768px) {
    body {
        padding: 12px !important;
    }

    .page {
        max-width: 100%;
    }

    .card {
        padding: 14px !important;
        border-radius: 14px !important;
        margin-bottom: 14px !important;
    }

    .header {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 12px !important;
    }

    .header h1 {
        font-size: 1.25rem !important;
    }

    .header p {
        font-size: 13px !important;
    }

    .header-actions {
        width: 100%;
        justify-content: flex-start;
    }

    /* Filters stack */
    .filters-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }

    .btn-filter {
        width: 100% !important;
        justify-content: center;
        height: 46px !important;
    }

    /* One column cards */
    .defects-grid {
        grid-template-columns: 1fr !important;
        gap: 14px !important;
    }

    .defect-card {
        border-radius: 14px !important;
    }

    .card-header {
        flex-direction: column !important;
        align-items: flex-start !important;
        padding: 14px !important;
        gap: 10px !important;
    }

    .card-header .title-group h3 {
        font-size: 15px !important;
    }

    .card-header .meta {
        flex-direction: column !important;
        gap: 4px !important;
        font-size: 12px !important;
    }

    .card-body {
        padding: 12px 14px !important;
    }

    .card-body .detail-grid {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }

    .card-footer {
        padding: 12px 14px 16px !important;
    }

    .card-footer .form-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }

    .card-footer .form-grid .full-width {
        grid-column: 1 !important;
    }

    .card-footer .form-actions {
        flex-direction: column !important;
        gap: 8px !important;
    }

    .card-footer .form-actions .btn-action {
        width: 100%;
        justify-content: center;
        padding: 12px !important;
        font-size: 14px !important;
    }

    /* Modal full screen-ish */
    .modal-overlay {
        padding: 8px !important;
        align-items: flex-end !important;
    }

    .modal {
        max-width: 100% !important;
        max-height: 92vh !important;
        border-radius: 18px 18px 0 0 !important;
    }

    .modal-header {
        padding: 14px 16px !important;
        border-radius: 18px 18px 0 0 !important;
    }

    .modal-header h2 {
        font-size: 16px !important;
    }

    .modal-body {
        padding: 14px !important;
    }

    .modal-detail-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
        margin-bottom: 14px !important;
    }

    .modal-detail-card {
        padding: 14px !important;
        border-radius: 12px !important;
    }

    .modal-detail-item {
        flex-direction: column !important;
        gap: 2px !important;
        font-size: 13px !important;
    }

    .modal-detail-item strong {
        min-width: auto !important;
        font-size: 11px !important;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: #94a3b8 !important;
    }

    .history-item {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 6px !important;
        padding: 12px !important;
    }

    .history-time {
        margin-left: 0 !important;
    }

    .message {
        font-size: 13px !important;
        padding: 12px 14px !important;
    }
}

@media (max-width: 480px) {
    body {
        padding: 8px !important;
    }

    .header h1 {
        font-size: 1.1rem !important;
    }

    .card-header .title-group h3 {
        font-size: 14px !important;
    }
}
    </style>
</head>
<body>
<div class="page">

    <!-- Header -->
    <div class="card">
        <div class="header">
            <div>
                <h1><i class="fa-solid fa-bug" style="color: #2563eb; margin-right: 10px;"></i>My Assigned Bugs</h1>
                <p>View and update only the bugs assigned to you</p>
            </div>
            <div class="header-actions">
    <a href="member_dashboard.php" class="home-btn" title="Dashboard">
        <i class="fas fa-home"></i>
    </a>
</div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($success): ?>
        <div class="message success"><i class="fa-solid fa-check-circle"></i> <?php echo esc($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo esc($error); ?></div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card">
        <form method="GET" class="filters-grid">
            <div class="filter-group">
                <label for="search"><i class="fa-solid fa-search"></i> Search</label>
                <input type="text" id="search" name="search" placeholder="Test case, title, description…" value="<?php echo esc($search); ?>">
            </div>
            <div class="filter-group">
                <label for="project"><i class="fa-solid fa-folder"></i> Project</label>
                <select id="project" name="project">
                    <option value="">All Projects</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?php echo esc($p); ?>" <?php echo ($filterProject === $p ? 'selected' : ''); ?>>
                            <?php echo esc($p); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="status"><i class="fa-solid fa-flag"></i> Status</label>
                <select id="status" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['Open','In Progress','Pending Review','Resolved'] as $st): ?>
                        <option value="<?php echo esc($st); ?>" <?php echo ($filterStatus === $st ? 'selected' : ''); ?>>
                            <?php echo esc($st); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-filter" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
        </form>
    </div>

    <!-- Defects Grid -->
    <div class="defects-grid">
        <?php if ($defects && $defects->num_rows > 0): ?>
            <?php while ($row = $defects->fetch_assoc()):
                $historyJson = json_encode($taskHistoryMap[(int)$row['linked_task_id']] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
                $detailJson = json_encode([
                    'defect_id' => $row['defect_id'],
                    'test_case' => $row['test_case'],
                    'raised_by_name' => $row['raised_by_name'],
                    'project_name' => $row['project_name'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'date_identified' => $row['date_identified'],
                    'status' => $row['status'],
                    'priority' => $row['priority'],
                    'assigned' => $row['assigned'],
                    'estimated_time' => $row['estimated_time'],
                    'recommendation' => $row['recommendation'],
                    'developer_comment' => $row['developer_comment'] ?? '',
                    'risk' => $row['risk'],
                    'date_work_begins' => $row['date_work_begins'],
                    'further_steps' => $row['further_steps'],
                    'date_work_resolved' => $row['date_work_resolved'],
                    'linked_task_id' => $row['linked_task_id'],
                    'linked_task_title' => $row['linked_task_title'],
                    'linked_task_status' => $row['linked_task_status'],
                    'linked_task_dueDate' => $row['linked_task_dueDate'],
                    'linked_task_created_at' => $row['linked_task_created_at'],
                    'attachment' => $row['attachment'],
                ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

                $statusClass = strtolower(str_replace(' ', '-', $row['status']));
            ?>
                <div class="defect-card">
                    <!-- Card Header -->
                    <div class="card-header">
                        <div class="title-group">
                            <h3><?php echo esc($row['title']); ?></h3>
                            <div class="meta">
                                <span><i class="fa-regular fa-file-lines"></i> <?php echo esc($row['test_case']); ?></span>
                                <span><i class="fa-regular fa-folder"></i> <?php echo esc($row['project_name']); ?></span>
                                <span><i class="fa-regular fa-user"></i> Added by: <?php echo esc($row['raised_by_name'] ?: 'Unknown user'); ?></span>
                            </div>
                        </div>
                        <span class="status-badge <?php echo $statusClass; ?>"><?php echo esc($row['status']); ?></span>
                    </div>

                    <!-- Card Body -->
                    <div class="card-body">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="label">Added By</span>
                                <div class="value"><i class="fa-regular fa-user" style="margin-right:6px;color:#64748b;"></i><?php echo esc($row['raised_by_name'] ?: 'Unknown user'); ?></div>
                            </div>
                            <div class="detail-item">
                                <span class="label">Priority</span>
                                <div class="value"><?php echo esc($row['priority']); ?></div>
                            </div>
                            <div class="detail-item">
                                <span class="label">Description</span>
                                <div class="value"><?php echo nl2br(esc($row['description'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <span class="label">Linked Task</span>
                                <div class="value">
                                    <?php if (!empty($row['linked_task_title'])): ?>
                                        <span class="task-badge"><i class="fa-regular fa-rectangle-list"></i> <?php echo esc($row['linked_task_title']); ?></span>
                                        <div style="font-size:12px; margin-top:4px; color:#64748b;">
                                            Status: <?php echo esc($row['linked_task_status'] ?: '-'); ?> &bull; Due: <?php echo esc($row['linked_task_dueDate'] ?: '-'); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="empty-note">No linked task</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <span class="label">Attachment</span>
                                <div class="value">
                                    <?php if (!empty($row['attachment'])): ?>
                                        <a href="../<?php echo esc($row['attachment']); ?>" target="_blank"><i class="fa-solid fa-paperclip"></i> View File</a>
                                    <?php else: ?>
                                        <span class="empty-note">No file</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card Footer (Update Form) -->
                    <form method="POST" class="card-footer">
                        <input type="hidden" name="defect_id" value="<?php echo (int)$row['defect_id']; ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="status_<?php echo $row['defect_id']; ?>">Status</label>
                                <select class="mini-select" id="status_<?php echo $row['defect_id']; ?>" name="status">
                                    <?php foreach (['Open','In Progress','Pending Review','Resolved'] as $st): ?>
                                        <option value="<?php echo esc($st); ?>" <?php echo ($row['status'] === $st ? 'selected' : ''); ?>>
                                            <?php echo esc($st); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="estimated_time_<?php echo $row['defect_id']; ?>">Estimated Time</label>
                                <input class="mini-input" type="text" id="estimated_time_<?php echo $row['defect_id']; ?>" name="estimated_time" value="<?php echo esc($row['estimated_time']); ?>" placeholder="e.g. 2 days">
                            </div>

                            <div class="form-group">
                                <label for="date_work_begins_<?php echo $row['defect_id']; ?>">Date Work Begins</label>
                                <input class="mini-date" type="date" id="date_work_begins_<?php echo $row['defect_id']; ?>" name="date_work_begins" value="<?php echo esc($row['date_work_begins']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="further_steps_<?php echo $row['defect_id']; ?>">Further Steps</label>
                                <select class="mini-select" id="further_steps_<?php echo $row['defect_id']; ?>" name="further_steps">
                                    <option value="">Select</option>
                                    <option value="Yes" <?php echo ($row['further_steps'] === 'Yes' ? 'selected' : ''); ?>>Yes</option>
                                    <option value="No" <?php echo ($row['further_steps'] === 'No' ? 'selected' : ''); ?>>No</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="date_work_resolved_<?php echo $row['defect_id']; ?>">Date Resolved</label>
                                <input class="mini-date" type="date" id="date_work_resolved_<?php echo $row['defect_id']; ?>" name="date_work_resolved" value="<?php echo esc($row['date_work_resolved']); ?>">
                            </div>

                            <div class="form-group full-width">
                                <label for="recommendation_<?php echo $row['defect_id']; ?>">Recommendation / Progress</label>
                                <textarea class="mini-textarea" id="recommendation_<?php echo $row['defect_id']; ?>" name="recommendation" placeholder="Add progress or recommendation"><?php echo esc($row['recommendation']); ?></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="developer_comment_<?php echo $row['defect_id']; ?>">Developer Comments (for BA)</label>
                                <textarea class="mini-textarea" id="developer_comment_<?php echo $row['defect_id']; ?>" name="developer_comment" placeholder="Write your comments for the BA"><?php echo esc($row['developer_comment'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn-action btn-view view-row-btn" data-detail='<?php echo $detailJson; ?>' data-history='<?php echo $historyJson; ?>'>
                                <i class="fa-solid fa-eye"></i> View
                            </button>
                            <button type="submit" name="update_defect" class="btn-action btn-save">
                                <i class="fa-solid fa-floppy-disk"></i> Save
                            </button>
                        </div>
                    </form>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; text-align:center; padding:60px 0; color:#94a3b8;">
                <i class="fa-regular fa-circle-check" style="font-size:32px; display:block; margin-bottom:12px;"></i>
                No defects assigned to you.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fa-regular fa-rectangle-list" style="color:#2563eb;"></i> Bug Details & Activity</h2>
            <button class="close-btn" type="button" id="closeViewModal" aria-label="Close modal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body">
            <div class="modal-detail-grid">
                <div class="modal-detail-card">
                    <h3><i class="fa-solid fa-bug" style="color:#dc3545;"></i> Defect Details</h3>
                    <div id="defectDetails"></div>
                </div>

                <div class="modal-detail-card">
                    <h3><i class="fa-regular fa-rectangle-list" style="color:#2563eb;"></i> Linked Task Details</h3>
                    <div id="taskDetails"></div>
                </div>
            </div>

            <div class="modal-detail-card">
                <h3><i class="fa-regular fa-clock" style="color:#f59e0b;"></i> Task History</h3>
                <div id="taskHistory"></div>
            </div>

            <div class="modal-detail-card">
                <h3><i class="fa-solid fa-wave-square" style="color:#7c3aed;"></i> Bug Activity</h3>
                <div id="bugActivity"></div>
            </div>

            <div class="modal-detail-card">
                <h3><i class="fa-regular fa-comments" style="color:#059669;"></i> Comments</h3>
                <div id="bugComments"></div>
                <form method="POST" style="margin-top:14px;">
                    <input type="hidden" name="defect_id" id="commentDefectId">
                    <textarea name="comment_text" required placeholder="Write a comment or progress update…" style="width:100%;min-height:90px;border:1px solid #cbd5e1;border-radius:12px;padding:12px;resize:vertical;"></textarea>
                    <div style="display:flex;justify-content:flex-end;margin-top:9px;">
                        <button type="submit" name="add_comment" class="btn-action btn-save"><i class="fa-solid fa-paper-plane"></i> Comment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="theme_switcher.js"></script>
<script>
    // ── Modal Logic ──
    const viewModal = document.getElementById('viewModal');
    const closeViewModal = document.getElementById('closeViewModal');
    const defectDetails = document.getElementById('defectDetails');
    const taskDetails = document.getElementById('taskDetails');
    const taskHistory = document.getElementById('taskHistory');
    const bugActivity = document.getElementById('bugActivity');
    const bugComments = document.getElementById('bugComments');
    const defectActivityMap = <?php echo json_encode($defectActivityMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
    const defectCommentMap = <?php echo json_encode($defectCommentMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

    function openViewModal() {
        viewModal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeView() {
        viewModal.classList.remove('active');
        document.body.style.overflow = '';
    }

    closeViewModal.addEventListener('click', closeView);
    viewModal.addEventListener('click', function(e) {
        if (e.target === viewModal) closeView();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeView();
    });

    // ── Helper to render detail item ──
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
    }

    function detailItem(label, value) {
        let display = (value && value !== '') ? escapeHtml(value) : '—';
        if (label === 'Attachment' && value) {
            display = `<a href="../${encodeURI(String(value))}" target="_blank">View File</a>`;
        }
        return `<div class="modal-detail-item"><strong>${escapeHtml(label)}:</strong> <span>${display}</span></div>`;
    }

    // ── View button click ──
    document.querySelectorAll('.view-row-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const detail = JSON.parse(this.dataset.detail || '{}');
            const history = JSON.parse(this.dataset.history || '[]');
            const activity = defectActivityMap[detail.defect_id] || [];
            const comments = defectCommentMap[detail.defect_id] || [];
            document.getElementById('commentDefectId').value = detail.defect_id || '';

            // Populate defect details
            const fields = [
                ['Defect ID', 'defect_id'],
                ['Test Case', 'test_case'],
                ['Added By', 'raised_by_name'],
                ['Project Name', 'project_name'],
                ['Title', 'title'],
                ['Description', 'description'],
                ['Date Identified', 'date_identified'],
                ['Status', 'status'],
                ['Priority', 'priority'],
                ['Assigned To', 'assigned'],
                ['Estimated Time', 'estimated_time'],
                ['Recommendation', 'recommendation'],
                ['Developer Comment', 'developer_comment'],
                ['Risk', 'risk'],
                ['Date Work Begins', 'date_work_begins'],
                ['Further Steps', 'further_steps'],
                ['Date Work Resolved', 'date_work_resolved'],
                ['Attachment', 'attachment']
            ];
            defectDetails.innerHTML = fields.map(([label, key]) =>
                detailItem(label, detail[key])
            ).join('');

            // Linked task
            if (detail.linked_task_id) {
                taskDetails.innerHTML = `
                    ${detailItem('Task ID', detail.linked_task_id)}
                    ${detailItem('Task Title', detail.linked_task_title)}
                    ${detailItem('Task Status', detail.linked_task_status)}
                    ${detailItem('Due Date', detail.linked_task_dueDate)}
                    ${detailItem('Created At', detail.linked_task_created_at)}
                `;
            } else {
                taskDetails.innerHTML = `<div class="empty-note">No linked task for this defect.</div>`;
            }

            // History
            if (history.length > 0) {
                taskHistory.innerHTML = `
                    <div class="history-list">
                        ${history.map(h => `
                            <div class="history-item">
                                <span class="action">${h.action_type || 'Update'}</span>
                                <span>from <span class="old">${h.old_value || '—'}</span> to <span class="new">${h.new_value || '—'}</span></span>
                                <span class="history-time">${h.created_at || ''}</span>
                            </div>
                        `).join('')}
                    </div>
                `;
            } else {
                taskHistory.innerHTML = `<div class="empty-note">No task history found.</div>`;
            }

            bugActivity.innerHTML = activity.length ? `
                <div class="history-list">
                    ${activity.map(item => `
                        <div class="history-item">
                            <span class="action">${escapeHtml(item.actor_name || 'System')}</span>
                            <span>${escapeHtml(item.message || 'Updated bug')}</span>
                            ${item.field_name ? `<span>${escapeHtml(item.old_value || '—')} → ${escapeHtml(item.new_value || '—')}</span>` : ''}
                            <span class="history-time">${escapeHtml(item.created_at || '')}</span>
                        </div>
                    `).join('')}
                </div>` : `<div class="empty-note">No bug activity recorded yet.</div>`;

            bugComments.innerHTML = comments.length ? comments.map(comment => `
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:9px;">
                    <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:6px;">
                        <strong>${escapeHtml(comment.author_name || 'Unknown user')}</strong>
                        <small style="color:#94a3b8;">${escapeHtml(comment.created_at || '')}</small>
                    </div>
                    <div style="white-space:pre-wrap;">${escapeHtml(comment.comment_text || '')}</div>
                </div>
            `).join('') : `<div class="empty-note">No comments yet.</div>`;

            openViewModal();
        });
    });
</script>
</body>
</html>