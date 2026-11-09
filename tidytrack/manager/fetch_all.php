<?php
session_start();
require_once __DIR__ . '/../dp.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error' => 'Unauthorized']);
    exit;
}

$type = $_GET['type'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

/* ---------- TOTAL COUNT ---------- */
switch($type) {
    case 'inProgress':
$countQuery = "SELECT COUNT(*) AS total FROM tasks
  WHERE status IN ('InProgress','In Progress','inprogress','in progress','Pending','pending')";

        break;

    case 'completedTasks':
        $countQuery = "SELECT COUNT(*) AS total FROM tasks
          WHERE status IN ('Completed','completed','Done','done')";
        break;

    case 'activeProjects':
        $countQuery = "SELECT COUNT(*) AS total FROM projects_x WHERE end_date >= CURDATE()";
        break;

    case 'completedProjects':
        $countQuery = "SELECT COUNT(*) AS total FROM projects_x WHERE end_date < CURDATE()";
        break;

    case 'users':
        $countQuery = "SELECT COUNT(*) AS total FROM users";
        break;

    default:
        echo json_encode(['ok'=>true,'data'=>[],'total'=>0,'pages'=>0,'currentPage'=>1,'limit'=>$limit]);
        exit;
}

$countResult = $conn->query($countQuery);
if (!$countResult) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Count query failed','sql_error'=>$conn->error]);
    exit;
}

$totalItems = (int)($countResult->fetch_assoc()['total'] ?? 0);
$totalPages = $totalItems > 0 ? (int)ceil($totalItems / $limit) : 0;
if ($totalPages > 0 && $page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

/* ---------- PAGINATED DATA ---------- */
$data = [];
$q = null;

switch($type) {
    case 'inProgress':
        $q = $conn->query("
            SELECT
                t.task_id AS task_id,
                t.taskTitle AS task,
                t.assignTo AS assignee,
                t.dueDate AS due,
                t.status
            FROM tasks t
WHERE t.status IN ('InProgress','In Progress','inprogress','in progress','Pending','pending')
            ORDER BY t.dueDate IS NULL, t.dueDate DESC
            LIMIT $limit OFFSET $offset
        ");
        break;

    case 'completedTasks':
        $q = $conn->query("
            SELECT
                t.task_id AS task_id,
                t.taskTitle AS task,
                t.assignTo AS assignee,
                t.dueDate AS due,
                t.status
            FROM tasks t
            WHERE t.status IN ('Completed','completed','Done','done')
            ORDER BY t.dueDate IS NULL, t.dueDate DESC
            LIMIT $limit OFFSET $offset
        ");
        break;

    case 'activeProjects':
        $q = $conn->query("
            SELECT p.project_id,
                   p.project_name AS project,
                   u.name AS leader,
                   p.start_date AS start,
                   p.end_date AS end,
                   (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.project_id) AS tasks
            FROM projects_x p
            LEFT JOIN users u ON u.user_id=p.teamLeadder_id
            WHERE p.end_date >= CURDATE()
            ORDER BY p.start_date DESC
            LIMIT $limit OFFSET $offset
        ");
        break;

    case 'completedProjects':
        $q = $conn->query("
            SELECT p.project_id,
                   p.project_name AS project,
                   u.name AS leader,
                   p.start_date AS start,
                   p.end_date AS end,
                   (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.project_id) AS tasks,
                   (SELECT COUNT(*) FROM sub_projects s WHERE s.project_id=p.project_id) AS subs
            FROM projects_x p
            LEFT JOIN users u ON u.user_id=p.teamLeadder_id
            WHERE p.end_date < CURDATE()
            ORDER BY p.end_date DESC
            LIMIT $limit OFFSET $offset
        ");
        break;

    case 'users':
        $q = $conn->query("
            SELECT
                user_id,
                name,
                email,
                role,
                department,
                status,
                (SELECT COUNT(*) FROM tasks t WHERE t.created_by=users.user_id OR t.created_by=users.name) as task_count,
                (SELECT COUNT(*) FROM projects_x p WHERE p.teamLeadder_id=users.user_id) as project_count
            FROM users
            ORDER BY name ASC
            LIMIT $limit OFFSET $offset
        ");
        break;
}

if (!$q) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Data query failed','sql_error'=>$conn->error]);
    exit;
}

while($r = $q->fetch_assoc()) {
    $data[] = $r;
}

echo json_encode([
    'ok' => true,
    'data' => $data,
    'total' => $totalItems,
    'pages' => $totalPages,
    'currentPage' => $page,
    'limit' => $limit
]);
?>