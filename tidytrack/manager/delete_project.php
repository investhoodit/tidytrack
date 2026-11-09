<?php
session_start();
require_once __DIR__ . '/../dp.php';
@include __DIR__ . '/../profile.php';

$userID      = $_SESSION['user_id'] ?? null;
$currentRole = $currentRole ?? ($_SESSION['role'] ?? 'manager');

if (!$userID || $currentRole !== 'manager') {
    die("Unauthorized");
}

$itemType = $_POST['item_type'] ?? '';
$id       = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$id || !in_array($itemType, ['project', 'sub_project'], true)) {
    die("Invalid request");
}

/* -------------------------
   Helper: Safe SQL prepare
-------------------------- */
function safePrepare($conn, $sql) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("SQL ERROR: " . $conn->error . "<br><strong>Query:</strong> $sql");
    }
    return $stmt;
}

/* =======================================================
   DELETE PROJECT
======================================================= */
if ($itemType === 'project') {

    // Ensure this project belongs to this manager
    $stmt = safePrepare($conn, "SELECT project_id FROM projects_x WHERE project_id = ? AND created_by = ?");
    $stmt->bind_param("ii", $id, $userID);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        die("Project not found or access denied");
    }
    $stmt->close();

    // Delete tasks under this project
    $stmt = safePrepare($conn, "DELETE FROM tasks WHERE project_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // Delete tasks under sub-projects of this project
    $stmt = safePrepare($conn, "
        DELETE FROM tasks 
        WHERE sub_project_id IN (SELECT sub_project_id FROM sub_projects WHERE project_id = ?)
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // Delete sub-projects
    $stmt = safePrepare($conn, "DELETE FROM sub_projects WHERE project_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // Delete main project
    $stmt = safePrepare($conn, "DELETE FROM projects_x WHERE project_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

}

/* =======================================================
   DELETE SUB-PROJECT
======================================================= */
elseif ($itemType === 'sub_project') {

    // Ensure this sub-project belongs to the manager’s project
    $stmt = safePrepare($conn, "
        SELECT s.sub_project_id
        FROM sub_projects s
        JOIN projects_x p ON s.project_id = p.project_id
        WHERE s.sub_project_id = ? AND p.created_by = ?
    ");
    $stmt->bind_param("ii", $id, $userID);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        die("Sub-project not found or access denied");
    }
    $stmt->close();

    // Delete tasks under this sub-project
    $stmt = safePrepare($conn, "DELETE FROM tasks WHERE sub_project_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // Delete sub-project
    $stmt = safePrepare($conn, "DELETE FROM sub_projects WHERE sub_project_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

header("Location: Project_viewing.php");
exit;
