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
    $tableEsc = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
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

function getUserEmailByName(mysqli $conn, string $name): string {
    $email = '';
    $stmt = $conn->prepare("SELECT email FROM users WHERE TRIM(name) = TRIM(?) LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $email = trim($row['email'] ?? '');
        }
        $stmt->close();
    }
    return $email;
}

function sendDefectNotificationEmail(
    string $toEmail,
    string $developerName,
    string $testCase,
    string $projectName,
    string $title,
    string $description,
    string $priority,
    string $status,
    string $raisedBy
): bool {
    if ($toEmail === '') return false;
    $subject = "New Defect Assigned - {$testCase}";
    $message = "
    <html>
    <head><title>New Defect Assigned</title></head>
    <body style='font-family: Arial, sans-serif;'>
        <h2>New Defect Assigned</h2>
        <p>Hello {$developerName},</p>
        <p>A new defect has been assigned to you.</p>
        <table border='1' cellpadding='8' style='border-collapse: collapse;'>
            <tr><th>Test Case</th><td>{$testCase}</td></tr>
            <tr><th>Project</th><td>{$projectName}</td></tr>
            <tr><th>Title</th><td>{$title}</td></tr>
            <tr><th>Description</th><td>{$description}</td></tr>
            <tr><th>Priority</th><td>{$priority}</td></tr>
            <tr><th>Status</th><td>{$status}</td></tr>
            <tr><th>Raised By</th><td>{$raisedBy}</td></tr>
        </table>
        <p>Please log in to the system to view and work on the defect.</p>
        <p>Regards,<br>TidyTrack System</p>
    </body>
    </html>
    ";
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: noreply@investhoodprojects.com\r\n";
    return mail($toEmail, $subject, $message, $headers);
}

/*
|--------------------------------------------------------------------------
| Current user access
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("SELECT role, department FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$userMeta = $stmt->get_result()->fetch_assoc();
$stmt->close();

$currentRole = strtolower(trim($userMeta['role'] ?? ''));
$currentDepartment = strtolower(trim($userMeta['department'] ?? ''));

$isBA = ($currentDepartment === 'ba team');
$isDeveloper = in_array($currentDepartment, [
    'frontend developer',
    'backend developer',
    'fullstack developer'
]);
$isICT = ($currentDepartment === 'ict team');
$isManager = in_array($currentRole, ['manager', 'admin']);

$canViewAll = $isBA || $isManager || $isICT;
$canCreateDefect = $isBA || $isManager || $isICT;
$canAssignDefect = $isBA || $isManager || $isICT;
$canEditAllFields = $isBA || $isManager || $isICT;
$canDeveloperUpdate = $isDeveloper;

if (!$canViewAll && !$canDeveloperUpdate) {
    die("Access denied.");
}

$success = '';
$error   = '';

$uploadDir = '../uploads/defects/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/*
|--------------------------------------------------------------------------
| Load Projects
|--------------------------------------------------------------------------
*/
$projects = [];
$resProjects = $conn->query("
    SELECT DISTINCT project_name
    FROM projects_x
    WHERE project_name IS NOT NULL AND project_name <> ''
    ORDER BY project_name ASC
");
if ($resProjects) {
    while ($row = $resProjects->fetch_assoc()) {
        $projects[] = $row['project_name'];
    }
}

/*
|--------------------------------------------------------------------------
| Load Tasks by Project
|--------------------------------------------------------------------------
*/
$projectTaskMap = [];

$taskSql = "
    SELECT t.task_id, t.taskTitle, p.project_name
    FROM tasks t
    INNER JOIN projects_x p ON p.project_id = t.project_id
    WHERE p.project_name IS NOT NULL
      AND p.project_name <> ''
    ORDER BY p.project_name ASC, t.taskTitle ASC
";
$taskRes = $conn->query($taskSql);
if ($taskRes) {
    while ($row = $taskRes->fetch_assoc()) {
        $project = $row['project_name'];
        if (!isset($projectTaskMap[$project])) {
            $projectTaskMap[$project] = [];
        }

        $exists = false;
        foreach ($projectTaskMap[$project] as $existingTask) {
            if ((int)$existingTask['task_id'] === (int)$row['task_id']) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $projectTaskMap[$project][] = [
                'task_id' => (int)$row['task_id'],
                'taskTitle' => $row['taskTitle']
            ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Developers by Project
|--------------------------------------------------------------------------
*/
$projectUserMap = [];

$userSql = "
    SELECT DISTINCT u.user_id, u.name, u.department, p.project_name
    FROM users u
    INNER JOIN tasks t ON t.assignTo = u.name
    INNER JOIN projects_x p ON p.project_id = t.project_id
    WHERE p.project_name IS NOT NULL
      AND p.project_name <> ''
      AND LOWER(TRIM(u.department)) IN ('fullstack developer', 'frontend developer', 'backend developer', 'ict team')
    ORDER BY p.project_name ASC, u.name ASC
";
$userRes = $conn->query($userSql);
if ($userRes) {
    while ($row = $userRes->fetch_assoc()) {
        $project = $row['project_name'];
        if (!isset($projectUserMap[$project])) {
            $projectUserMap[$project] = [];
        }

        $userItem = [
            'user_id' => (int)$row['user_id'],
            'name' => $row['name'],
            'department' => $row['department']
        ];

        $exists = false;
        foreach ($projectUserMap[$project] as $existingUser) {
            if ($existingUser['name'] === $userItem['name']) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $projectUserMap[$project][] = $userItem;
        }
    }
}

// Fallback developers for projects without tasks
$devFallback = $conn->query("SELECT user_id, name, department FROM users WHERE LOWER(TRIM(department)) IN ('fullstack developer','frontend developer','backend developer','ict team') OR LOWER(TRIM(role)) IN ('developer','member') ORDER BY name ASC");
$allDevelopers = [];
if ($devFallback) {
    while ($u = $devFallback->fetch_assoc()) {
        $allDevelopers[] = ['user_id' => (int)$u['user_id'], 'name' => $u['name'], 'department' => $u['department'] ?: 'Developer'];
    }
}
foreach ($projects as $projName) {
    if (!isset($projectUserMap[$projName]) || empty($projectUserMap[$projName])) {
        $projectUserMap[$projName] = $allDevelopers;
    }
}

/*
|--------------------------------------------------------------------------
| Generate Next Test Case
|--------------------------------------------------------------------------
*/
function generateNextTestCase(mysqli $conn): string {
    $prefix = 'TC-';
    $nextNumber = 1;
    $res = $conn->query("SELECT test_case FROM project_defects WHERE test_case LIKE 'TC-%' ORDER BY defect_id DESC LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $testCase = $row['test_case'] ?? '';
        if ($testCase !== '' && preg_match('/TC-(\d+)/', $testCase, $m)) {
            $nextNumber = (int)$m[1] + 1;
        }
    }
    return $prefix . str_pad((string)$nextNumber, 3, '0', STR_PAD_LEFT);
}

$nextTestCase = generateNextTestCase($conn);

/*
|--------------------------------------------------------------------------
| Save New Defect
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_defect'])) {
    if (!$canCreateDefect) {
        $error = "You are not allowed to add defects.";
    } else {
        $test_case          = trim($_POST['test_case'] ?? '');
        $project_name       = trim($_POST['project_name'] ?? '');
        $title              = trim($_POST['title'] ?? '');
        $description        = trim($_POST['description'] ?? '');
        $date_identified    = trim($_POST['date_identified'] ?? date('Y-m-d'));
        $status             = trim($_POST['status'] ?? 'Open');
        $priority           = trim($_POST['priority'] ?? 'Medium');
        $assigned           = trim($_POST['assigned'] ?? '');
        $estimated_time     = trim($_POST['estimated_time'] ?? '');
        $recommendation     = trim($_POST['recommendation'] ?? '');
        $risk               = trim($_POST['risk'] ?? '');
        $date_work_begins   = trim($_POST['date_work_begins'] ?? '');
        $further_steps      = trim($_POST['further_steps'] ?? '');
        $date_work_resolved = trim($_POST['date_work_resolved'] ?? '');
        $linked_task_id     = !empty($_POST['linked_task_id']) ? (int)$_POST['linked_task_id'] : null;

        $attachment = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['attachment']['tmp_name']);
            finfo_close($finfo);

            $allowed = [
                'image/jpeg', 'image/png', 'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];

            if (in_array($mime, $allowed)) {
                $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('defect_') . '.' . $ext;
                $dest = $uploadDir . $filename;

                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
                    $attachment = 'uploads/defects/' . $filename;
                } else {
                    $error = "Failed to move uploaded file.";
                }
            } else {
                $error = "Invalid file type. Allowed: images, PDF, DOC, DOCX.";
            }
        }

        if ($test_case === '' || $project_name === '' || $title === '' || $description === '') {
            $error = "Please fill in Test Case, Project Name, Title, and Description.";
        } elseif (!$error) {
            $stmt = $conn->prepare("
                INSERT INTO project_defects (
                    test_case, project_name, title, description,
                    date_identified, status, priority, assigned, estimated_time,
                    recommendation, risk, date_work_begins, further_steps,
                    date_work_resolved, linked_task_id, created_by, attachment
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "ssssssssssssssiis",
                $test_case,
                $project_name,
                $title,
                $description,
                $date_identified,
                $status,
                $priority,
                $assigned,
                $estimated_time,
                $recommendation,
                $risk,
                $date_work_begins,
                $further_steps,
                $date_work_resolved,
                $linked_task_id,
                $userId,
                $attachment
            );

            if ($stmt->execute()) {
                $newDefectId = (int)$stmt->insert_id;
                logDefectActivity(
                    $conn,
                    $newDefectId,
                    $userId,
                    'created',
                    "{$userName} created {$test_case}"
                );
                if ($assigned !== '') {
                    logDefectActivity(
                        $conn,
                        $newDefectId,
                        $userId,
                        'assignment',
                        "{$userName} assigned the bug to {$assigned}",
                        'assigned',
                        '',
                        $assigned
                    );
                }
                $success = "Defect saved successfully.";
                // Send email notification
                if (!empty($assigned)) {
                    $toEmail = getUserEmailByName($conn, $assigned);
                    if ($toEmail) {
                        sendDefectNotificationEmail($toEmail, $assigned, $test_case, $project_name, $title, $description, $priority, $status, $userName);
                    }
                }
                $nextTestCase = generateNextTestCase($conn);
            } else {
                $error = "Failed to save defect: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Update Defect
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_defect'])) {
    $defect_id = (int)($_POST['defect_id'] ?? 0);
    $beforeDefect = null;
    if ($defect_id > 0) {
        $beforeStmt = $conn->prepare("
            SELECT status, priority, assigned, estimated_time, recommendation,
                   date_work_begins, further_steps, date_work_resolved
            FROM project_defects
            WHERE defect_id = ?
            LIMIT 1
        ");
        if ($beforeStmt) {
            $beforeStmt->bind_param("i", $defect_id);
            $beforeStmt->execute();
            $beforeDefect = $beforeStmt->get_result()->fetch_assoc();
            $beforeStmt->close();
        }
    }

    if ($canEditAllFields) {
        $test_case          = trim($_POST['test_case'] ?? '');
        $project_name       = trim($_POST['project_name'] ?? '');
        $title              = trim($_POST['title'] ?? '');
        $description        = trim($_POST['description'] ?? '');
        $date_identified    = trim($_POST['date_identified'] ?? '');
        $status             = trim($_POST['status'] ?? 'Open');
        $priority           = trim($_POST['priority'] ?? 'Medium');
        $assigned           = trim($_POST['assigned'] ?? '');
        $estimated_time     = trim($_POST['estimated_time'] ?? '');
        $recommendation     = trim($_POST['recommendation'] ?? '');
        $risk               = trim($_POST['risk'] ?? '');
        $date_work_begins   = trim($_POST['date_work_begins'] ?? '');
        $further_steps      = trim($_POST['further_steps'] ?? '');
        $date_work_resolved = trim($_POST['date_work_resolved'] ?? '');
        $linked_task_id     = !empty($_POST['linked_task_id']) ? (int)$_POST['linked_task_id'] : null;

        $attachment = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['attachment']['tmp_name']);
            finfo_close($finfo);

            $allowed = [
                'image/jpeg', 'image/png', 'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];

            if (in_array($mime, $allowed)) {
                $old = $conn->query("SELECT attachment FROM project_defects WHERE defect_id = $defect_id")->fetch_assoc();
                if ($old && !empty($old['attachment']) && file_exists('../' . $old['attachment'])) {
                    unlink('../' . $old['attachment']);
                }

                $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('defect_') . '.' . $ext;
                $dest = $uploadDir . $filename;

                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
                    $attachment = 'uploads/defects/' . $filename;
                } else {
                    $error = "Failed to move uploaded file.";
                }
            } else {
                $error = "Invalid file type. Allowed: images, PDF, DOC, DOCX.";
            }
        }

        if (!$attachment && !$error) {
            $stmt = $conn->prepare("
                UPDATE project_defects
                SET
                    test_case = ?,
                    project_name = ?,
                    title = ?,
                    description = ?,
                    date_identified = ?,
                    status = ?,
                    priority = ?,
                    assigned = ?,
                    estimated_time = ?,
                    recommendation = ?,
                    risk = ?,
                    date_work_begins = ?,
                    further_steps = ?,
                    date_work_resolved = ?,
                    linked_task_id = ?
                WHERE defect_id = ?
            ");
            $stmt->bind_param(
                "ssssssssssssssii",
                $test_case,
                $project_name,
                $title,
                $description,
                $date_identified,
                $status,
                $priority,
                $assigned,
                $estimated_time,
                $recommendation,
                $risk,
                $date_work_begins,
                $further_steps,
                $date_work_resolved,
                $linked_task_id,
                $defect_id
            );
        } elseif (!$error) {
            $stmt = $conn->prepare("
                UPDATE project_defects
                SET
                    test_case = ?,
                    project_name = ?,
                    title = ?,
                    description = ?,
                    date_identified = ?,
                    status = ?,
                    priority = ?,
                    assigned = ?,
                    estimated_time = ?,
                    recommendation = ?,
                    risk = ?,
                    date_work_begins = ?,
                    further_steps = ?,
                    date_work_resolved = ?,
                    linked_task_id = ?,
                    attachment = ?
                WHERE defect_id = ?
            ");
            $stmt->bind_param(
                "ssssssssssssssisi",
                $test_case,
                $project_name,
                $title,
                $description,
                $date_identified,
                $status,
                $priority,
                $assigned,
                $estimated_time,
                $recommendation,
                $risk,
                $date_work_begins,
                $further_steps,
                $date_work_resolved,
                $linked_task_id,
                $attachment,
                $defect_id
            );
        }

        if (!$error && $stmt->execute()) {
            $success = "Defect updated successfully.";
            $trackedChanges = [
                'status' => $status,
                'priority' => $priority,
                'assigned' => $assigned,
                'estimated_time' => $estimated_time,
                'recommendation' => $recommendation,
                'date_work_begins' => $date_work_begins,
                'further_steps' => $further_steps,
                'date_work_resolved' => $date_work_resolved
            ];
            foreach ($trackedChanges as $field => $newValue) {
                $oldValue = (string)($beforeDefect[$field] ?? '');
                if ($oldValue !== (string)$newValue) {
                    logDefectActivity(
                        $conn,
                        $defect_id,
                        $userId,
                        $field === 'assigned' ? 'assignment' : 'field_change',
                        "{$userName} changed " . str_replace('_', ' ', $field),
                        $field,
                        $oldValue,
                        (string)$newValue
                    );
                }
            }
            if ($attachment) {
                logDefectActivity(
                    $conn,
                    $defect_id,
                    $userId,
                    'attachment',
                    "{$userName} replaced the attachment"
                );
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
        } elseif (!$error) {
            $error = "Failed to update defect: " . $stmt->error;
        }

        if (isset($stmt)) $stmt->close();
    }
    elseif ($canDeveloperUpdate) {
        $status             = trim($_POST['status'] ?? 'Open');
        $estimated_time     = trim($_POST['estimated_time'] ?? '');
        $recommendation     = trim($_POST['recommendation'] ?? '');
        $date_work_begins   = trim($_POST['date_work_begins'] ?? '');
        $further_steps      = trim($_POST['further_steps'] ?? '');
        $date_work_resolved = trim($_POST['date_work_resolved'] ?? '');

        $check = $conn->prepare("
            SELECT defect_id, linked_task_id
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

            if ($stmt->execute()) {
                $success = "Defect updated successfully.";
                $developerChanges = [
                    'status' => $status,
                    'estimated_time' => $estimated_time,
                    'recommendation' => $recommendation,
                    'date_work_begins' => $date_work_begins,
                    'further_steps' => $further_steps,
                    'date_work_resolved' => $date_work_resolved
                ];
                foreach ($developerChanges as $field => $newValue) {
                    $oldValue = (string)($beforeDefect[$field] ?? '');
                    if ($oldValue !== (string)$newValue) {
                        logDefectActivity(
                            $conn,
                            $defect_id,
                            $userId,
                            'field_change',
                            "{$userName} changed " . str_replace('_', ' ', $field),
                            $field,
                            $oldValue,
                            (string)$newValue
                        );
                    }
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
            } else {
                $error = "Failed to update defect: " . $stmt->error;
            }

            $stmt->close();
        }
    }
    else {
        $error = "You are not allowed to update this defect.";
    }
}

/*
|--------------------------------------------------------------------------
| Upload Attachment Only
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_attachment'])) {
    if (!$canEditAllFields) {
        $error = "You are not allowed to upload attachments here.";
    } else {
        $defect_id = (int)($_POST['defect_id'] ?? 0);

        if ($defect_id && isset($_FILES['file'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
            finfo_close($finfo);

            $allowed = [
                'image/jpeg', 'image/png', 'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];

            if (in_array($mime, $allowed)) {
                $old = $conn->query("SELECT attachment FROM project_defects WHERE defect_id = $defect_id")->fetch_assoc();
                if ($old && !empty($old['attachment']) && file_exists('../' . $old['attachment'])) {
                    unlink('../' . $old['attachment']);
                }

                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('defect_') . '.' . $ext;
                $dest = $uploadDir . $filename;

                if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                    $attachment = 'uploads/defects/' . $filename;
                    $upd = $conn->prepare("UPDATE project_defects SET attachment = ? WHERE defect_id = ?");
                    $upd->bind_param("si", $attachment, $defect_id);

                    if ($upd->execute()) {
                        $success = "Attachment uploaded successfully.";
                        logDefectActivity(
                            $conn,
                            $defect_id,
                            $userId,
                            'attachment',
                            "{$userName} uploaded a new attachment"
                        );
                    } else {
                        $error = "Database update failed.";
                    }
                    $upd->close();
                } else {
                    $error = "File move failed.";
                }
            } else {
                $error = "Invalid file type.";
            }
        } else {
            $error = "No file or defect ID.";
        }
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

    $commentAllowed = $canViewAll;
    if (!$commentAllowed && $canDeveloperUpdate && $defect_id > 0) {
        $accessStmt = $conn->prepare("SELECT defect_id FROM project_defects WHERE defect_id = ? AND assigned = ? LIMIT 1");
        if ($accessStmt) {
            $accessStmt->bind_param("is", $defect_id, $userName);
            $accessStmt->execute();
            $commentAllowed = (bool)$accessStmt->get_result()->fetch_assoc();
            $accessStmt->close();
        }
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
| Filters and Query
|--------------------------------------------------------------------------
*/
$search         = trim($_GET['search'] ?? '');
$filterProject  = trim($_GET['project'] ?? '');
$filterStatus   = trim($_GET['status'] ?? '');
$filterAssigned = trim($_GET['assigned'] ?? '');

$where = [];
$params = [];
$types  = '';

if (!$canViewAll && $canDeveloperUpdate) {
    $where[] = "d.assigned = ?";
    $params[] = $userName;
    $types .= 's';
}

if ($search !== '') {
    $where[] = "(d.test_case LIKE ? OR d.title LIKE ? OR d.description LIKE ? OR d.project_name LIKE ? OR d.assigned LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sssss';
}

if ($filterProject !== '') {
    $where[] = "d.project_name = ?";
    $params[] = $filterProject;
    $types .= 's';
}

if ($filterStatus !== '') {
    $where[] = "d.status = ?";
    $params[] = $filterStatus;
    $types .= 's';
}

if ($filterAssigned !== '' && $canViewAll) {
    $where[] = "d.assigned = ?";
    $params[] = $filterAssigned;
    $types .= 's';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        d.*,
        COALESCE(NULLIF(TRIM(u.name), ''), 'Unknown user') AS raised_by_name,
        t.taskTitle AS linked_task_title,
        t.status AS linked_task_status,
        t.dueDate AS linked_task_dueDate,
        t.created_at AS linked_task_created_at
    FROM project_defects d
    LEFT JOIN users u ON u.user_id = d.created_by
    LEFT JOIN tasks t ON t.task_id = d.linked_task_id
    $whereSql
    ORDER BY d.defect_id DESC
";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$defectsResult = $stmt->get_result();

$allDefects = [];
if ($defectsResult) {
    while ($row = $defectsResult->fetch_assoc()) {
        $allDefects[] = $row;
    }
}
$stmt->close();

// Build project stats based on filtered defects
$defectsByProject = [];
foreach ($allDefects as $defect) {
    $proj = $defect['project_name'];
    if (!isset($defectsByProject[$proj])) {
        $defectsByProject[$proj] = ['total'=>0, 'open'=>0, 'in_progress'=>0, 'resolved'=>0, 'critical'=>0];
    }
    $defectsByProject[$proj]['total']++;
    $status = strtolower($defect['status']);
    if ($status == 'open') $defectsByProject[$proj]['open']++;
    elseif ($status == 'in progress') $defectsByProject[$proj]['in_progress']++;
    elseif ($status == 'resolved') $defectsByProject[$proj]['resolved']++;
    $priority = strtolower($defect['priority']);
    if ($priority == 'high' || $priority == 'critical') $defectsByProject[$proj]['critical']++;
}

// Which projects to display in cards (only those with at least one defect matching filters)
$displayProjects = [];
foreach ($projects as $projName) {
    if (isset($defectsByProject[$projName]) && $defectsByProject[$projName]['total'] > 0) {
        $displayProjects[] = $projName;
    }
}
// If no filters and no defects exist, show all projects (optional)
if (empty($displayProjects) && empty($where)) {
    $displayProjects = $projects;
}

// Task history map (for view modal)
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

// ClickUp-style bug activity and conversation maps.
$defectActivityMap = [];
if (tableExists($conn, 'defect_activity')) {
    $activitySql = "
        SELECT a.*, COALESCE(u.name, 'System') AS actor_name
        FROM defect_activity a
        " . (!$canViewAll ? "INNER JOIN project_defects d ON d.defect_id = a.defect_id" : "") . "
        LEFT JOIN users u ON u.user_id = a.created_by
        " . (!$canViewAll ? "WHERE d.assigned = ?" : "") . "
        ORDER BY a.created_at DESC, a.activity_id DESC
        LIMIT 1000
    ";
    $activityStmt = $conn->prepare($activitySql);
    $activityRes = null;
    if ($activityStmt) {
        if (!$canViewAll) $activityStmt->bind_param("s", $userName);
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
    $commentSql = "
        SELECT c.*, COALESCE(u.name, 'Unknown user') AS author_name
        FROM defect_comments c
        " . (!$canViewAll ? "INNER JOIN project_defects d ON d.defect_id = c.defect_id" : "") . "
        LEFT JOIN users u ON u.user_id = c.user_id
        " . (!$canViewAll ? "WHERE d.assigned = ?" : "") . "
        ORDER BY c.created_at ASC, c.comment_id ASC
        LIMIT 1000
    ";
    $commentStmt = $conn->prepare($commentSql);
    $commentRes = null;
    if ($commentStmt) {
        if (!$canViewAll) $commentStmt->bind_param("s", $userName);
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

// KPI totals based on filtered defects
$totalDefects = count($allDefects);
$openDefects = count(array_filter($allDefects, fn($d) => strtolower($d['status']) == 'open'));
$resolvedDefects = count(array_filter($allDefects, fn($d) => strtolower($d['status']) == 'resolved'));
$criticalDefects = count(array_filter($allDefects, fn($d) => strtolower($d['priority']) == 'high' || strtolower($d['priority']) == 'critical'));

$pageTitle = 'Bug Tracker';
$pageSubtitle = 'Project-centric view of all reported bugs.';
if ($isManager) {
    $pageTitle = 'Bug Management';
    $pageSubtitle = 'View, capture and manage all bugs across projects.';
} elseif ($canDeveloperUpdate && !$canViewAll) {
    $pageTitle = 'My Assigned Bugs';
    $pageSubtitle = 'View and update bugs assigned to you.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc($pageTitle); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: #f0f4f9;
            color: #0f172a;
            padding: 24px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .title h1 {
            font-size: 1.8rem;
            font-weight: 700;
            letter-spacing: -0.3px;
        }
        .title p {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 4px;
        }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .view-switcher {
            display: inline-flex;
            padding: 4px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: white;
        }
        .view-btn {
            border: 0;
            background: transparent;
            color: #64748b;
            border-radius: 9px;
            padding: 7px 11px;
            cursor: pointer;
            font-weight: 700;
            font-size: 12px;
        }
        .view-btn.active {
            background: #111827;
            color: white;
        }
        .btn {
            border: none;
            border-radius: 12px;
            padding: 10px 16px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
        }
        .btn-outline {
            background: white;
            border: 1px solid #e2e8f0;
            color: #334155;
        }
        .btn-outline:hover {
            background: #f8fafc;
        }
        .filters {
            background: white;
            border-radius: 20px;
            padding: 16px 20px;
            margin-bottom: 28px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        .filters form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            align-items: end;
        }
        .filter-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 5px;
        }
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            font-size: 0.85rem;
        }
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .kpi-card {
            background: white;
            border-radius: 20px;
            padding: 18px 16px;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }
        .kpi-card.blue::before { background: #2563eb; }
        .kpi-card.green::before { background: #059669; }
        .kpi-card.amber::before { background: #d97706; }
        .kpi-card.rose::before { background: #dc2626; }
        .kpi-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }
        .kpi-sub {
            font-size: 0.7rem;
            color: #64748b;
            margin-top: 6px;
        }
        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        .project-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .project-card {
            background: white;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            padding: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .project-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -12px rgba(0,0,0,0.15);
            border-color: #2563eb;
        }
        .board-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(260px, 1fr));
            gap: 16px;
            overflow-x: auto;
            align-items: start;
            padding-bottom: 12px;
            margin-bottom: 36px;
        }
        .board-column {
            min-width: 260px;
            padding: 12px;
            border-radius: 18px;
            background: #eef2f7;
            border: 1px solid #e2e8f0;
        }
        .board-column-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 4px 12px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #475569;
        }
        .board-count {
            min-width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: white;
            color: #475569;
        }
        .board-card {
            width: 100%;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 10px;
            text-align: left;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            transition: transform .18s, box-shadow .18s;
        }
        .board-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -16px rgba(15, 23, 42, .6);
        }
        .board-card-key {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
        }
        .board-card-title {
            color: #0f172a;
            font-size: 14px;
            font-weight: 750;
            line-height: 1.35;
            margin: 7px 0 12px;
        }
        .board-card-reporter {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 10px;
            font-size: 11px;
            color: #64748b;
        }
        .board-card-reporter i {
            color: #2563eb;
        }
        .board-card-meta {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: center;
            font-size: 11px;
            color: #64748b;
        }
        .developer-update {
            margin-top: 10px;
            padding: 9px 10px;
            border-radius: 10px;
            background: #f5f3ff;
            border: 1px solid #ddd6fe;
            color: #5b21b6;
            font-size: 11px;
            line-height: 1.4;
        }
        .developer-update strong {
            display: block;
            margin-bottom: 3px;
            color: #6d28d9;
        }
        .list-shell {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            overflow: hidden;
            margin-bottom: 36px;
        }
        .master-list {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .master-list th {
            padding: 12px 14px;
            background: #f8fafc;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .45px;
            font-size: 10px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        .master-list td {
            padding: 13px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .master-list tr:last-child td { border-bottom: 0; }
        .master-list tbody tr { cursor: pointer; }
        .master-list tbody tr:hover { background: #f8fafc; }
        .list-title { color: #0f172a; font-weight: 750; }
        .status-chip {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 8px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 11px;
            font-weight: 750;
        }
        .priority-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
            background: #94a3b8;
        }
        .priority-dot.high, .priority-dot.critical { background: #ef4444; }
        .priority-dot.medium { background: #f59e0b; }
        .priority-dot.low { background: #22c55e; }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .project-name {
            font-weight: 700;
            font-size: 1.1rem;
        }
        .defect-badge {
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 30px;
            padding: 4px 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .deliverable {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 18px;
            line-height: 1.4;
        }
        .defect-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0 8px;
        }
        .pill {
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .pill.open { background: #fee2e2; color: #dc2626; }
        .pill.progress { background: #fef3c7; color: #d97706; }
        .pill.resolved { background: #dcfce7; color: #059669; }
        .pill.critical { background: #ffedd5; color: #ea580c; }
        .card-footer {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
            font-size: 0.7rem;
            color: #64748b;
            display: flex;
            justify-content: space-between;
        }

        /* Modal Styles (from code1) */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: 28px;
            width: 100%;
            max-width: 1300px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }
        .modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 2;
        }
        .modal-header h2 {
            font-size: 1.3rem;
            font-weight: 700;
        }
        .close-btn {
            background: #f1f5f9;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1.1rem;
        }
        .modal-body { padding: 24px; }
        .defect-table-wrap { overflow-x: auto; }
        .defect-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        .defect-table th,
        .defect-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
            text-align: left;
        }
        .defect-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #334155;
        }
        .row-actions {
            display: flex;
            gap: 6px;
        }
        .icon-btn {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .icon-edit { background: #eff6ff; color: #1d4ed8; }
        .icon-save { background: #dcfce7; color: #166534; }
        .icon-view { background: #f3f4f6; color: #374151; }
        .icon-attach { background: #fef9c3; color: #854d0e; }

        /* Add / Edit defect form styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
        }
        .form-grid .full { grid-column: span 2; }
        .form-grid .full-wide { grid-column: span 4; }
        .field { margin-bottom: 8px; }
        .field label {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
        }
        .field input,
        .field select,
        .field textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            background: #f8fafc;
            font-size: 13px;
            outline: none;
        }
        .readonly-box {
            padding: 8px 10px;
            border: 1px solid #d7dee7;
            border-radius: 10px;
            background: #f8fafc;
            font-size: 12px;
            color: #334155;
        }
        .message {
            padding: 12px 16px;
            border-radius: 14px;
            margin-bottom: 20px;
        }
        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }

        @media (max-width: 800px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full,
            .form-grid .full-wide { grid-column: span 1; }
            .filters form { grid-template-columns: 1fr; }
            .board-grid { grid-template-columns: repeat(4, 270px); }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="title">
            <h1>🐞 <?php echo esc($pageTitle); ?></h1>
            <p><?php echo esc($pageSubtitle); ?></p>
        </div>
        <div class="actions">
            <div class="view-switcher" aria-label="Bug view">
                <button type="button" class="view-btn active" id="boardViewBtn"><i class="fas fa-table-columns"></i> Board</button>
                <button type="button" class="view-btn" id="listViewBtn"><i class="fas fa-list"></i> List</button>
                <button type="button" class="view-btn" id="projectViewBtn"><i class="fas fa-folder-tree"></i> Projects</button>
            </div>
            <a href="member_dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <?php if ($canCreateDefect): ?>
                <button class="btn btn-primary" id="globalAddDefectBtn"><i class="fas fa-plus"></i> Add Defect</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="message success"><?php echo esc($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message error"><?php echo esc($error); ?></div>
    <?php endif; ?>

    <div class="filters">
        <form method="GET" id="filterForm">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Test case, title, project, developer..." value="<?php echo esc($search); ?>">
            </div>
            <div class="filter-group">
                <label>Project</label>
                <select name="project">
                    <option value="">All Projects</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?php echo esc($p); ?>" <?php echo ($filterProject === $p ? 'selected' : ''); ?>><?php echo esc($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['Open','In Progress','Pending Review','Resolved'] as $st): ?>
                        <option value="<?php echo esc($st); ?>" <?php echo ($filterStatus === $st ? 'selected' : ''); ?>><?php echo esc($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Assigned</label>
                <select name="assigned">
                    <option value="">All Developers</option>
                    <?php
                    $assignedUsers = array_unique(array_column($allDefects, 'assigned'));
                    foreach ($assignedUsers as $dev):
                        if ($dev): ?>
                            <option value="<?php echo esc($dev); ?>" <?php echo ($filterAssigned === $dev ? 'selected' : ''); ?>><?php echo esc($dev); ?></option>
                    <?php endif; endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <button type="submit" class="btn btn-primary" style="width:100%"><i class="fas fa-filter"></i> Apply Filters</button>
            </div>
        </form>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-strip">
        <div class="kpi-card blue"><div class="kpi-label">Total Defects</div><div class="kpi-value"><?php echo $totalDefects; ?></div><div class="kpi-sub">matching filters</div></div>
        <div class="kpi-card rose"><div class="kpi-label">Open</div><div class="kpi-value"><?php echo $openDefects; ?></div><div class="kpi-sub">awaiting fix</div></div>
        <div class="kpi-card green"><div class="kpi-label">Resolved</div><div class="kpi-value"><?php echo $resolvedDefects; ?></div><div class="kpi-sub">closed</div></div>
        <div class="kpi-card amber"><div class="kpi-label">Critical/High</div><div class="kpi-value"><?php echo $criticalDefects; ?></div><div class="kpi-sub">urgent priority</div></div>
    </div>

    <!-- ClickUp-style status board -->
    <div id="boardView">
        <div class="section-title"><span>Workflow Board</span></div>
        <div class="board-grid">
            <?php foreach (['Open', 'In Progress', 'Pending Review', 'Resolved'] as $boardStatus):
                $statusDefects = array_values(array_filter(
                    $allDefects,
                    fn($d) => strcasecmp((string)$d['status'], $boardStatus) === 0
                ));
            ?>
                <section class="board-column">
                    <div class="board-column-head">
                        <span><?php echo esc($boardStatus); ?></span>
                        <span class="board-count"><?php echo count($statusDefects); ?></span>
                    </div>
                    <?php if (!$statusDefects): ?>
                        <div style="padding:18px 8px;text-align:center;color:#94a3b8;font-size:12px;">No bugs</div>
                    <?php else: ?>
                        <?php foreach ($statusDefects as $bug):
                            $priorityClass = strtolower((string)$bug['priority']);
                        ?>
                            <button type="button" class="board-card" onclick="viewDefectDetail(<?php echo (int)$bug['defect_id']; ?>)">
                                <div class="board-card-key"><?php echo esc($bug['test_case']); ?> · <?php echo esc($bug['project_name']); ?></div>
                                <div class="board-card-title"><?php echo esc($bug['title']); ?></div>
                                <?php if (!empty($bug['developer_comment'])): ?>
                                    <div class="developer-update">
                                        <strong><i class="fas fa-code"></i> Developer update</strong>
                                        <?php echo esc(strlen($bug['developer_comment']) > 120 ? substr($bug['developer_comment'], 0, 120) . '…' : $bug['developer_comment']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="board-card-reporter">
                                    <i class="fas fa-user-plus"></i>
                                    <span>Added by <?php echo esc($bug['raised_by_name'] ?: 'Unknown user'); ?></span>
                                </div>
                                <div class="board-card-meta">
                                    <span><span class="priority-dot <?php echo esc($priorityClass); ?>"></span><?php echo esc($bug['priority']); ?></span>
                                    <span><i class="fas fa-user-circle"></i> <?php echo esc($bug['assigned'] ?: 'Unassigned'); ?></span>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ClickUp-style list view -->
    <div id="listView" style="display:none">
        <div class="section-title"><span>Bug List</span></div>
        <div class="list-shell" style="overflow-x:auto;">
            <table class="master-list">
                <thead>
                    <tr>
                        <th>Key</th>
                        <th>Bug</th>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Assignee</th>
                        <th>Added By</th>
                        <th>Developer Comment</th>
                        <th>Reported</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$allDefects): ?>
                        <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:30px;">No bugs match the filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($allDefects as $bug): ?>
                            <tr onclick="viewDefectDetail(<?php echo (int)$bug['defect_id']; ?>)">
                                <td><strong><?php echo esc($bug['test_case']); ?></strong></td>
                                <td class="list-title"><?php echo esc($bug['title']); ?></td>
                                <td><?php echo esc($bug['project_name']); ?></td>
                                <td><span class="status-chip"><?php echo esc($bug['status']); ?></span></td>
                                <td><span class="priority-dot <?php echo esc(strtolower((string)$bug['priority'])); ?>"></span><?php echo esc($bug['priority']); ?></td>
                                <td><?php echo esc($bug['assigned'] ?: 'Unassigned'); ?></td>
                                <td><?php echo esc($bug['raised_by_name'] ?: 'Unknown user'); ?></td>
                                <td><?php echo !empty($bug['developer_comment']) ? esc(strlen($bug['developer_comment']) > 100 ? substr($bug['developer_comment'], 0, 100) . '…' : $bug['developer_comment']) : '—'; ?></td>
                                <td><?php echo esc($bug['date_identified']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Project Cards -->
    <div id="projectView" style="display:none">
    <div class="section-title"><span>Projects Overview</span></div>
    <div class="project-grid" id="projectGrid">
        <?php if (empty($displayProjects)): ?>
            <div style="grid-column:1/-1; text-align:center; padding:40px; color:#64748b;">No projects match the selected filters.</div>
        <?php else: ?>
            <?php foreach ($displayProjects as $projName):
                $stats = $defectsByProject[$projName] ?? ['total'=>0, 'open'=>0, 'in_progress'=>0, 'resolved'=>0, 'critical'=>0];
            ?>
            <div class="project-card" data-project="<?php echo esc($projName); ?>">
                <div class="card-header">
                    <span class="project-name"><?php echo esc($projName); ?></span>
                    <span class="defect-badge"><?php echo $stats['total']; ?> defects</span>
                </div>
                <div class="deliverable"><?php echo esc($projName); ?> • Click to manage defects</div>
                <div class="defect-pills">
                    <?php if ($stats['open'] > 0): ?><span class="pill open"><?php echo $stats['open']; ?> Open</span><?php endif; ?>
                    <?php if ($stats['in_progress'] > 0): ?><span class="pill progress"><?php echo $stats['in_progress']; ?> In Progress</span><?php endif; ?>
                    <?php if ($stats['resolved'] > 0): ?><span class="pill resolved"><?php echo $stats['resolved']; ?> Resolved</span><?php endif; ?>
                    <?php if ($stats['critical'] > 0): ?><span class="pill critical"><?php echo $stats['critical']; ?> Critical</span><?php endif; ?>
                </div>
                <div class="card-footer">
                    <span><i class="fas fa-chart-line"></i> Click for details</span>
                    <i class="fas fa-chevron-right"></i>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    </div>
</div>

<!-- Modal: Project Defects List (appears when clicking a project card) -->
<div class="modal-overlay" id="projectDefectModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitle">Defects for <span></span></h2>
            <button class="close-btn" id="closeProjectModal"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <?php if ($canCreateDefect): ?>
                <div style="margin-bottom: 20px; display: flex; justify-content: flex-end;">
                    <button class="btn btn-primary" id="addDefectInModalBtn"><i class="fas fa-plus"></i> Add Defect</button>
                </div>
            <?php endif; ?>
            <div class="defect-table-wrap">
                <table class="defect-table" id="defectTable">
                    <thead>
                        <tr><th>Test Case</th><th>Title</th><th>Status</th><th>Priority</th><th>Assigned</th><th>Added By</th><th>Description</th><th>Developer Comment</th><th>Actions</th></tr>
                    </thead>
                    <tbody id="defectTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Defect Modal (from code1) -->
<?php if ($canCreateDefect): ?>
<div class="modal-overlay" id="defectModal">
    <div class="modal" style="max-width: 800px;">
        <div class="modal-header">
            <h2>Add New Defect</h2>
            <button class="close-btn" type="button" id="closeDefectModal"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="defectForm" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="field"><label>Test Case ID</label><input type="text" name="test_case" value="<?php echo esc($nextTestCase); ?>" readonly></div>
                    <div class="field"><label>Project Name</label><select name="project_name" id="project_name" required><option value="">Select Project</option><?php foreach ($projects as $p): ?><option value="<?php echo esc($p); ?>"><?php echo esc($p); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Defect Title</label><input type="text" name="title" required></div>
                    <div class="field"><label>Date Identified</label><input type="date" name="date_identified" value="<?php echo date('Y-m-d'); ?>"></div>
                    <div class="field full-wide"><label>Description</label><textarea name="description" required></textarea></div>
                    <div class="field"><label>Status</label><select name="status"><option>Open</option><option>In Progress</option><option>Pending Review</option><option>Resolved</option></select></div>
                    <div class="field"><label>Priority</label><select name="priority"><option>High</option><option selected>Medium</option><option>Low</option></select></div>
                    <div class="field"><label>Assign Developer</label><select name="assigned" id="assigned_add"><option value="">Select project first</option></select></div>
                    <div class="field"><label>Estimated Time</label><input type="text" name="estimated_time" placeholder="e.g. 2 days"></div>
                    <div class="field full"><label>Recommendation</label><textarea name="recommendation"></textarea></div>
                    <div class="field"><label>Risk</label><input type="text" name="risk" placeholder="e.g. High impact"></div>
                    <div class="field"><label>Date Work Begins</label><input type="date" name="date_work_begins"></div>
                    <div class="field"><label>Further Steps Needed?</label><select name="further_steps"><option value="">Select</option><option>Yes</option><option>No</option></select></div>
                    <div class="field"><label>Date Work Resolved</label><input type="date" name="date_work_resolved"></div>
                    <div class="field"><label>Link Task</label><select name="linked_task_id" id="linked_task_add"><option value="">Select project first</option></select></div>
                    <div class="field full"><label>Attachment</label><input type="file" name="attachment" accept="image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></div>
                </div>
                <div class="modal-footer" style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" class="btn btn-outline" id="cancelDefectModal">Cancel</button>
                    <button type="submit" name="save_defect" class="btn btn-primary">Save Defect</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Defect Modal (from code1) -->
<div class="modal-overlay" id="editModal">
    <div class="modal" style="max-width: 900px;">
        <div class="modal-header">
            <h2>Edit Defect</h2>
            <button class="close-btn" type="button" id="closeEditModal"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="editDefectForm" enctype="multipart/form-data">
                <input type="hidden" name="defect_id" id="edit_defect_id">
                <div class="form-grid">
                    <div class="field"><label>Test Case</label><input type="text" name="test_case" id="edit_test_case" readonly></div>
                    <div class="field"><label>Project Name</label><select name="project_name" id="edit_project_name"><option value="">Select Project</option><?php foreach ($projects as $p): ?><option value="<?php echo esc($p); ?>"><?php echo esc($p); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Title</label><input type="text" name="title" id="edit_title"></div>
                    <div class="field"><label>Date Identified</label><input type="date" name="date_identified" id="edit_date_identified"></div>
                    <div class="field full-wide"><label>Description</label><textarea name="description" id="edit_description"></textarea></div>
                    <div class="field"><label>Status</label><select name="status" id="edit_status"><option>Open</option><option>In Progress</option><option>Pending Review</option><option>Resolved</option></select></div>
                    <div class="field"><label>Priority</label><select name="priority" id="edit_priority"><option>High</option><option>Medium</option><option>Low</option></select></div>
                    <div class="field"><label>Assign Developer</label><select name="assigned" id="edit_assigned"></select></div>
                    <div class="field"><label>Estimated Time</label><input type="text" name="estimated_time" id="edit_estimated_time"></div>
                    <div class="field full"><label>Recommendation</label><textarea name="recommendation" id="edit_recommendation"></textarea></div>
                    <div class="field"><label>Risk</label><input type="text" name="risk" id="edit_risk"></div>
                    <div class="field"><label>Date Work Begins</label><input type="date" name="date_work_begins" id="edit_date_work_begins"></div>
                    <div class="field"><label>Further Steps</label><select name="further_steps" id="edit_further_steps"><option value="">Select</option><option>Yes</option><option>No</option></select></div>
                    <div class="field"><label>Date Work Resolved</label><input type="date" name="date_work_resolved" id="edit_date_work_resolved"></div>
                    <div class="field"><label>Link Task</label><select name="linked_task_id" id="edit_linked_task_id"><option value="">None</option></select></div>
                    <div class="field full"><label>Replace Attachment</label><input type="file" name="attachment" accept="image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></div>
                </div>
                <div class="modal-footer" style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" class="btn btn-outline" id="cancelEditModal">Cancel</button>
                    <button type="submit" name="update_defect" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Defect Modal (from code1) -->
<div class="modal-overlay" id="viewModal">
    <div class="modal" style="max-width: 900px;">
        <div class="modal-header">
            <h2>Bug Details & Activity</h2>
            <button class="close-btn" type="button" id="closeViewModal"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="viewModalBody"></div>
    </div>
</div>

<!-- Attachment Upload Modal -->
<div class="modal-overlay" id="attachModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h2>Upload Attachment</h2>
            <button class="close-btn" id="closeAttachModal"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="attachForm" enctype="multipart/form-data">
                <input type="hidden" name="defect_id" id="attachDefectId">
                <label>Choose File</label>
                <input type="file" name="file" accept="image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" class="btn btn-outline" id="cancelAttachModal">Cancel</button>
                    <button type="submit" name="upload_attachment" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Data from PHP
const allDefects = <?php echo json_encode($allDefects, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const projectTaskMap = <?php echo json_encode($projectTaskMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const projectUserMap = <?php echo json_encode($projectUserMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const taskHistoryMap = <?php echo json_encode($taskHistoryMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const defectActivityMap = <?php echo json_encode($defectActivityMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const defectCommentMap = <?php echo json_encode($defectCommentMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const canEditAllFields = <?php echo $canEditAllFields ? 'true' : 'false'; ?>;
const canDeveloperUpdate = <?php echo $canDeveloperUpdate ? 'true' : 'false'; ?>;
const currentUserName = <?php echo json_encode($userName); ?>;

let currentProject = null;
let currentDefects = [];

// Helper: escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Populate developers dropdown
function populateDevelopers(selectElement, projectName, currentSelected = null) {
    const users = projectUserMap[projectName] || [];
    selectElement.innerHTML = '<option value="">Select Developer</option>';
    users.forEach(user => {
        const option = document.createElement('option');
        option.value = user.name;
        option.textContent = `${user.name} (${user.department})`;
        if (currentSelected && user.name === currentSelected) option.selected = true;
        selectElement.appendChild(option);
    });
}

function populateTasks(selectElement, projectName, currentSelected = null) {
    const tasks = projectTaskMap[projectName] || [];
    selectElement.innerHTML = '<option value="">Link to Task (Optional)</option>';
    tasks.forEach(task => {
        const option = document.createElement('option');
        option.value = task.task_id;
        option.textContent = `${task.taskTitle} (#${task.task_id})`;
        if (currentSelected && String(task.task_id) === String(currentSelected)) option.selected = true;
        selectElement.appendChild(option);
    });
}

// Open project defect modal
function openProjectModal(projectName) {
    currentProject = projectName;
    document.getElementById('modalTitle').innerHTML = `Defects for <span style="color:#2563eb">${escapeHtml(projectName)}</span>`;
    currentDefects = allDefects.filter(d => d.project_name === projectName);
    renderDefectTable();
    document.getElementById('projectDefectModal').classList.add('active');
}

function renderDefectTable() {
    const tbody = document.getElementById('defectTableBody');
    if (currentDefects.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center">No bugs found for this project with the current filters.</td></tr>';
        return;
    }
    let html = '';
    currentDefects.forEach(defect => {
        html += `<tr id="row-${defect.defect_id}">
            <td><div class="readonly-box">${escapeHtml(defect.test_case)}</div></td>
            <td><div class="readonly-box">${escapeHtml(defect.title)}</div></td>
            <td><div class="readonly-box">${escapeHtml(defect.status)}</div></td>
            <td><div class="readonly-box">${escapeHtml(defect.priority)}</div></td>
            <td><div class="readonly-box">${escapeHtml(defect.assigned || 'Unassigned')}</div></td>
            <td><div class="readonly-box">${escapeHtml(defect.raised_by_name || 'Unknown user')}</div></td>
            <td><div class="readonly-box">${escapeHtml(defect.description)}</div></td>
            <td><div class="readonly-box">${escapeHtml(defect.developer_comment || 'No developer comment yet')}</div></td>
            <td class="row-actions">
                <button class="icon-btn icon-edit" onclick="openEditDefectModal(${defect.defect_id})"><i class="fas fa-pen"></i></button>
                <button class="icon-btn icon-attach" onclick="openAttachModal(${defect.defect_id})"><i class="fas fa-paperclip"></i></button>
                <button class="icon-btn icon-view" onclick="viewDefectDetail(${defect.defect_id})"><i class="fas fa-eye"></i></button>
            </td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

// Open edit modal from code1 (populate fields)
function openEditDefectModal(defectId) {
    const defect = allDefects.find(d => d.defect_id == defectId);
    if (!defect) return;
    document.getElementById('edit_defect_id').value = defect.defect_id;
    document.getElementById('edit_test_case').value = defect.test_case || '';
    document.getElementById('edit_project_name').value = defect.project_name || '';
    document.getElementById('edit_title').value = defect.title || '';
    document.getElementById('edit_description').value = defect.description || '';
    document.getElementById('edit_date_identified').value = defect.date_identified || '';
    document.getElementById('edit_status').value = defect.status || 'Open';
    document.getElementById('edit_priority').value = defect.priority || 'Medium';
    document.getElementById('edit_estimated_time').value = defect.estimated_time || '';
    document.getElementById('edit_recommendation').value = defect.recommendation || '';
    document.getElementById('edit_risk').value = defect.risk || '';
    document.getElementById('edit_date_work_begins').value = defect.date_work_begins || '';
    document.getElementById('edit_further_steps').value = defect.further_steps || '';
    document.getElementById('edit_date_work_resolved').value = defect.date_work_resolved || '';

    // Populate dependent selects
    if (defect.project_name) {
        populateDevelopers(document.getElementById('edit_assigned'), defect.project_name, defect.assigned);
        populateTasks(document.getElementById('edit_linked_task_id'), defect.project_name, defect.linked_task_id);
    }
    document.getElementById('editModal').classList.add('active');
}

function openAttachModal(defectId) {
    document.getElementById('attachDefectId').value = defectId;
    document.getElementById('attachModal').classList.add('active');
}

function viewDefectDetail(defectId) {
    const defect = allDefects.find(d => d.defect_id == defectId);
    if (!defect) return;
    const taskHistory = taskHistoryMap[defect.linked_task_id] || [];
    const bugActivity = defectActivityMap[defect.defect_id] || [];
    const comments = defectCommentMap[defect.defect_id] || [];
    let html = `<div style="background:#f8fafc; border-radius:16px; padding:16px; margin-bottom:16px;">
        <h3>Defect Details</h3>
        <p><strong>Test Case:</strong> ${escapeHtml(defect.test_case)}</p>
        <p><strong>Title:</strong> ${escapeHtml(defect.title)}</p>
        <p><strong>Description:</strong> ${escapeHtml(defect.description)}</p>
        <p><strong>Status:</strong> ${escapeHtml(defect.status)}</p>
        <p><strong>Priority:</strong> ${escapeHtml(defect.priority)}</p>
        <p><strong>Added By:</strong> ${escapeHtml(defect.raised_by_name || 'Unknown user')}</p>
        <p><strong>Assigned:</strong> ${escapeHtml(defect.assigned || 'Unassigned')}</p>
        <p><strong>Estimated Time:</strong> ${escapeHtml(defect.estimated_time)}</p>
        <p><strong>Recommendation:</strong> ${escapeHtml(defect.recommendation)}</p>
        <p><strong>Risk:</strong> ${escapeHtml(defect.risk)}</p>
        <p><strong>Attachment:</strong> ${defect.attachment ? `<a href="../${defect.attachment}" target="_blank">View File</a>` : 'None'}</p>
    </div>
    <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:16px;padding:16px;margin-bottom:16px;">
        <h3 style="color:#6d28d9;margin-bottom:8px;"><i class="fas fa-code"></i> Latest Developer Update</h3>
        <div style="white-space:pre-wrap;color:#4c1d95;">${escapeHtml(defect.developer_comment || 'No developer comment has been submitted yet.')}</div>
    </div>`;
    if (defect.linked_task_id) {
        html += `<div style="background:#f8fafc; border-radius:16px; padding:16px; margin-bottom:16px;">
            <h3>Linked Task</h3>
            <p><strong>Task Title:</strong> ${escapeHtml(defect.linked_task_title)}</p>
            <p><strong>Status:</strong> ${escapeHtml(defect.linked_task_status)}</p>
            <p><strong>Due Date:</strong> ${escapeHtml(defect.linked_task_dueDate)}</p>
            <p><strong>Created:</strong> ${escapeHtml(defect.linked_task_created_at)}</p>
        </div>`;
        if (taskHistory.length) {
            html += `<div><h3>Task History</h3>`;
            taskHistory.forEach(h => {
                html += `<div style="border-left:3px solid #2563eb; padding-left:12px; margin-bottom:12px;">
                    <strong>${escapeHtml(h.action_type)}</strong>: ${escapeHtml(h.old_value)} → ${escapeHtml(h.new_value)}<br>
                    <small>${escapeHtml(h.created_at)}</small>
                </div>`;
            });
            html += `</div>`;
        }
    } else {
        html += `<div>No linked task.</div>`;
    }
    html += `<div style="margin-top:18px;">
        <h3 style="margin-bottom:10px;">Activity</h3>
        <div style="border-left:2px solid #e2e8f0;padding-left:14px;">`;
    if (bugActivity.length) {
        bugActivity.forEach(item => {
            const change = item.field_name
                ? `<div style="font-size:12px;color:#64748b;margin-top:3px;">${escapeHtml(item.old_value || '—')} → ${escapeHtml(item.new_value || '—')}</div>`
                : '';
            html += `<div style="position:relative;margin-bottom:14px;">
                <span style="position:absolute;left:-20px;top:4px;width:10px;height:10px;border-radius:50%;background:#7c3aed;border:2px solid white;"></span>
                <strong>${escapeHtml(item.actor_name)}</strong>
                <span>${escapeHtml(item.message)}</span>
                ${change}
                <div style="font-size:11px;color:#94a3b8;margin-top:3px;">${escapeHtml(item.created_at)}</div>
            </div>`;
        });
    } else {
        html += `<div style="color:#94a3b8;font-size:13px;margin-bottom:12px;">No activity recorded yet.</div>`;
    }
    html += `</div></div><div style="margin-top:20px;"><h3 style="margin-bottom:10px;">Comments</h3>`;
    if (comments.length) {
        comments.forEach(comment => {
            html += `<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:9px;">
                <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:6px;">
                    <strong>${escapeHtml(comment.author_name)}</strong>
                    <small style="color:#94a3b8;">${escapeHtml(comment.created_at)}</small>
                </div>
                <div style="white-space:pre-wrap;">${escapeHtml(comment.comment_text)}</div>
            </div>`;
        });
    } else {
        html += `<div style="color:#94a3b8;font-size:13px;margin-bottom:10px;">No comments yet.</div>`;
    }
    html += `<form method="POST" style="margin-top:12px;">
            <input type="hidden" name="defect_id" value="${Number(defect.defect_id)}">
            <textarea name="comment_text" required placeholder="Write a comment or progress update…" style="width:100%;min-height:90px;border:1px solid #cbd5e1;border-radius:12px;padding:12px;resize:vertical;"></textarea>
            <div style="display:flex;justify-content:flex-end;margin-top:9px;">
                <button type="submit" name="add_comment" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Comment</button>
            </div>
        </form></div>`;
    document.getElementById('viewModalBody').innerHTML = html;
    document.getElementById('viewModal').classList.add('active');
}

function openAddDefectModal(projectName = null) {
    const form = document.getElementById('defectForm');
    if (form) form.reset();
    if (projectName) {
        const projSelect = document.getElementById('project_name');
        projSelect.value = projectName;
        const changeEvent = new Event('change');
        projSelect.dispatchEvent(changeEvent);
    }
    document.getElementById('defectModal').classList.add('active');
}

// Event listeners
document.querySelectorAll('.project-card').forEach(card => {
    card.addEventListener('click', () => {
        const projectName = card.getAttribute('data-project');
        openProjectModal(projectName);
    });
});

function setTrackerView(view) {
    const showBoard = view === 'board';
    const showList = view === 'list';
    const showProjects = view === 'projects';
    document.getElementById('boardView').style.display = showBoard ? '' : 'none';
    document.getElementById('listView').style.display = showList ? '' : 'none';
    document.getElementById('projectView').style.display = showProjects ? '' : 'none';
    document.getElementById('boardViewBtn').classList.toggle('active', showBoard);
    document.getElementById('listViewBtn').classList.toggle('active', showList);
    document.getElementById('projectViewBtn').classList.toggle('active', showProjects);
    localStorage.setItem('bugTrackerView', view);
}
document.getElementById('boardViewBtn')?.addEventListener('click', () => setTrackerView('board'));
document.getElementById('listViewBtn')?.addEventListener('click', () => setTrackerView('list'));
document.getElementById('projectViewBtn')?.addEventListener('click', () => setTrackerView('projects'));
setTrackerView(localStorage.getItem('bugTrackerView') || 'board');

document.getElementById('closeProjectModal')?.addEventListener('click', () => {
    document.getElementById('projectDefectModal').classList.remove('active');
});
document.getElementById('globalAddDefectBtn')?.addEventListener('click', () => openAddDefectModal());
document.getElementById('addDefectInModalBtn')?.addEventListener('click', () => openAddDefectModal(currentProject));
document.getElementById('closeDefectModal')?.addEventListener('click', () => document.getElementById('defectModal').classList.remove('active'));
document.getElementById('cancelDefectModal')?.addEventListener('click', () => document.getElementById('defectModal').classList.remove('active'));
document.getElementById('closeEditModal')?.addEventListener('click', () => document.getElementById('editModal').classList.remove('active'));
document.getElementById('cancelEditModal')?.addEventListener('click', () => document.getElementById('editModal').classList.remove('active'));
document.getElementById('closeViewModal')?.addEventListener('click', () => document.getElementById('viewModal').classList.remove('active'));
document.getElementById('closeAttachModal')?.addEventListener('click', () => document.getElementById('attachModal').classList.remove('active'));
document.getElementById('cancelAttachModal')?.addEventListener('click', () => document.getElementById('attachModal').classList.remove('active'));

// Dynamic dropdowns for add modal
const projSelectAdd = document.getElementById('project_name');
if (projSelectAdd) {
    projSelectAdd.addEventListener('change', function() {
        const proj = this.value;
        if (proj) {
            populateDevelopers(document.getElementById('assigned_add'), proj);
            populateTasks(document.getElementById('linked_task_add'), proj);
        } else {
            document.getElementById('assigned_add').innerHTML = '<option value="">Select project first</option>';
            document.getElementById('linked_task_add').innerHTML = '<option value="">Select project first</option>';
        }
    });
}

// Close modals when clicking overlay
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) overlay.classList.remove('active');
    });
});

// Close with Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => modal.classList.remove('active'));
    }
});
</script>
</body>
</html>