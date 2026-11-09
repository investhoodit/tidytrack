<?php
session_start();
include '../dp.php';

// Access control (AJAX-friendly)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
    http_response_code(401);
    echo "<div style='padding:12px;color:#b91c1c'>Unauthorized</div>";
    exit;
}

$type = $_GET['type'] ?? null;

function h($s){
return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// CSS styling
$css = "<style>
.scroll-area {
    width: 100%;
    max-height: 500px;
    overflow-x: auto;
    overflow-y: auto;
    padding: 10px;
    box-sizing: border-box;
    border: 1px solid #ccc;
    border-radius: 6px;
    background-color: #f9f9f9;
}
.table-modal {
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
}
.table-modal th, .table-modal td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
    word-wrap: break-word;
    white-space: normal;
}
.table-modal tr:nth-child(even) {
    background-color: #f2f2f2;
}
@media screen and (max-width: 768px) {
    .table-modal, .table-modal thead, .table-modal tbody,
    .table-modal th, .table-modal td, .table-modal tr {
        display: block;
        width: 100%;
    }
    .table-modal thead tr {
        display: none;
    }
    .table-modal tr {
        margin-bottom: 15px;
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 10px;
        background-color: #fff;
    }
    .table-modal td {
        border: none;
        padding: 6px 8px;
        position: relative;
        padding-left: 50%;
    }
    .table-modal td::before {
        content: attr(data-label);
        position: absolute;
        left: 10px;
        top: 6px;
        font-weight: bold;
        white-space: nowrap;
    }
}
</style>";

/* -----------------------------------------------------
   UNIFIED PROJECT FETCHER (from Code 2)
----------------------------------------------------- */
function fetch_all_projects($conn) {

    $stmt = $conn->prepare("
        SELECT p.*, 
               u.name AS leader_name,
               c.name AS creator_name
        FROM projects_x p
        LEFT JOIN users u ON p.teamLeadder_id = u.user_id
        LEFT JOIN users c ON p.created_by = c.user_id
        ORDER BY p.start_date DESC
    ");
    $stmt->execute();
    $projects_res = $stmt->get_result();
    $projects = [];

    while ($project = $projects_res->fetch_assoc()) {

        /* ---- Project Tasks ---- */
        $t = $conn->prepare("
            SELECT t.*, u.name AS assigned_user
            FROM tasks t
            LEFT JOIN users u 
                ON t.assignTo = u.user_id
            WHERE t.project_id = ?
            ORDER BY t.dueDate ASC
        ");
        $t->bind_param("i", $project['project_id']);
        $t->execute();
        $project['tasks'] = $t->get_result()->fetch_all(MYSQLI_ASSOC);
        $t->close();

        /* ---- Sub-Projects ---- */
        $sp = $conn->prepare("
            SELECT s.*, 
                   u.name AS leader_name, 
                   c.name AS creator_name
            FROM sub_projects s
            LEFT JOIN users u ON s.teamLeadder_id = u.user_id
            LEFT JOIN users c ON s.created_by = c.user_id
            WHERE s.project_id = ?
            ORDER BY s.start_date ASC
        ");
        $sp->bind_param("i", $project['project_id']);
        $sp->execute();
        $sub_res = $sp->get_result();

        $sub_projects = [];
        while ($s = $sub_res->fetch_assoc()) {

            /* ---- Sub-project Tasks ---- */
            $st = $conn->prepare("
                SELECT t.*, u.name AS assigned_user
                FROM tasks t
                LEFT JOIN users u 
                    ON t.assignTo = u.user_id
                WHERE t.sub_project_id = ?
                ORDER BY t.dueDate ASC
            ");
            $st->bind_param("i", $s['sub_project_id']);
            $st->execute();
            $s['tasks'] = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            $sub_projects[] = $s;
        }

        $project['sub_projects'] = $sub_projects;
        $projects[] = $project;
    }

    return $projects;
}

/* ========================================================
   1. IN-PROGRESS TASKS (UNCHANGED)
======================================================== */
if ($type === 'In progress') {

    $sql = "SELECT t.task_id, t.taskTitle, t.dueDate, t.status, 
                   u.name AS assigned
            FROM tasks t 
            LEFT JOIN users u ON u.name = t.assignTo
            WHERE t.status = 'In progress'
            ORDER BY t.dueDate ASC";

    $res = $conn->query($sql);

    echo $css;

    if ($res->num_rows === 0) {
        echo "<p>No in-progress tasks found.</p>";
        exit;
    }

    echo "<div class='scroll-area'>
            <table class='table-modal'>
                <thead>
                    <tr>
                        <th>Task</th><th>Assigned To</th><th>Due Date</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>";

    while ($row = $res->fetch_assoc()) {
        echo "<tr>
                <td data-label='Task'>".h($row['taskTitle'])."</td>
                <td data-label='Assigned To'>".h($row['assigned'] ?? 'N/A')."</td>
                <td data-label='Due Date'>".h($row['dueDate'])."</td>
                <td data-label='Status'>".h($row['status'])."</td>
              </tr>";
    }

    echo "</tbody></table></div>";
    exit;
}

/* ========================================================
   2. ACTIVE PROJECTS (REPLACED USING CODE 2 STRUCTURE)
======================================================== */
if ($type === 'activeprojects') {

    echo $css;

    $today = date('Y-m-d');
    $projects = fetch_all_projects($conn);

    $active = array_filter($projects, fn($p) => $p['end_date'] >= $today);

    if (empty($active)) {
        echo "<p>No active projects found.</p>";
        exit;
    }

    echo "<div class='scroll-area'>
            <table class='table-modal'>
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Leader</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Tasks</th>
                        <th>Subprojects</th>
                    </tr>
                </thead>
                <tbody>";

    foreach ($active as $p) {
        echo "<tr>
                <td data-label='Project'>".h($p['project_name'])."</td>
                <td data-label='Leader'>".h($p['leader_name'])."</td>
                <td data-label='Start'>".h($p['start_date'])."</td>
                <td data-label='End'>".h($p['end_date'])."</td>
              <td data-label='Tasks'>".
    (empty($p['tasks']) 
        ? "No tasks" 
        : implode(", ", array_map(fn($t) => h($t['taskTitle']), $p['tasks']))
    )
."</td>

              <td data-label='Subprojects'>".
    implode(", ", array_map(fn($s) => h($s['sub_project_name']), $p['sub_projects']))
."</td>

              </tr>";
    }

    echo "</tbody></table></div>";
    exit;
}

/* ========================================================
   3. COMPLETED PROJECTS (REPLACED USING CODE 2 STRUCTURE)
======================================================== */
if ($type === 'completedprojects') {

    echo $css;

    $today = date('Y-m-d');
    $projects = fetch_all_projects($conn);

    $completed = array_filter($projects, fn($p) => $p['end_date'] < $today);

    if (empty($completed)) {
        echo "<p>No completed projects found.</p>";
        exit;
    }

    echo "<div class='scroll-area'>
            <table class='table-modal'>
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Leader</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Tasks</th>
                        <th>Subprojects</th>
                    </tr>
                </thead>
                <tbody>";

    foreach ($completed as $p) {
        echo "<tr>
                <td data-label='Project'>".h($p['project_name'])."</td>
                <td data-label='Leader'>".h($p['leader_name'])."</td>
                <td data-label='Start'>".h($p['start_date'])."</td>
                <td data-label='End'>".h($p['end_date'])."</td>
            <td data-label='Tasks'>".
    (empty($p['tasks']) 
        ? "No tasks" 
        : implode(", ", array_map(fn($t) => h($t['taskTitle']), $p['tasks']))
    )
."</td>

             <td data-label='Subprojects'>".
    implode(", ", array_map(fn($s) => h($s['sub_project_name']), $p['sub_projects']))
."</td>

              </tr>";
    }

    echo "</tbody></table></div>";
    exit;
}

/* ========================================================
   4. USERS (UNCHANGED)
======================================================== */
if ($type === 'users') {

    $sql = "SELECT user_id, name, email, role, status 
            FROM users 
            ORDER BY FIELD(role,'admin','manager','leader','member'), name ASC";

    $res = $conn->query($sql);

    echo $css;

    if ($res->num_rows === 0) {
        echo "<p>No users found.</p>";
        exit;
    }

    echo "<div class='scroll-area'>
            <table class='table-modal'>
                <thead>
                    <tr>
                        <th>Name</th><th>Email</th><th>Role</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>";

    while ($u = $res->fetch_assoc()) {
        echo "<tr>
                <td data-label='Name'>".h($u['name'])."</td>
                <td data-label='Email'>".h($u['email'])."</td>
                <td data-label='Role'>".h(ucfirst($u['role']))."</td>
                <td data-label='Status'>".h($u['status'])."</td>
              </tr>";
    }

    echo "</tbody></table></div>";
    exit;
}

/* ========================================================
   5. SUBPROJECTS (NOW BASED ON CODE 2 NESTED STRUCTURE)
======================================================== */
if ($type === 'subprojects') {

    echo $css;

    $projects = fetch_all_projects($conn);

    $rows = [];

    foreach ($projects as $p) {
        foreach ($p['sub_projects'] as $sp) {
            $rows[] = [
                'name' => $sp['sub_project_name'],
                'parent' => $p['project_name'],
                'start' => $sp['start_date'],
                'end' => $sp['end_date']
            ];
        }
    }

    if (empty($rows)) {
        echo "<p>No subprojects found.</p>";
        exit;
    }

    echo "<div class='scroll-area'>
            <table class='table-modal'>
                <thead>
                    <tr>
                        <th>Subproject</th>
                        <th>Parent Project</th>
                        <th>Start</th>
                        <th>End</th>
                    </tr>
                </thead>
                <tbody>";

    foreach ($rows as $sp) {
        echo "<tr>
                <td data-label='Subproject'>".h($sp['name'])."</td>
                <td data-label='Parent Project'>".h($sp['parent'])."</td>
                <td data-label='Start'>".h($sp['start'])."</td>
                <td data-label='End'>".h($sp['end'])."</td>
              </tr>";
    }

    echo "</tbody></table></div>";
    exit;
}

/* ========================================================
   INVALID REQUEST
======================================================== */
echo $css;
echo "<p>Invalid request.</p>";
exit;

?>
