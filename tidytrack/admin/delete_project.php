<?php
session_start();
require '../dp.php';

if (!isset($_GET['id'])) {
    die("Project ID not provided.");
}

$projectID = intval($_GET['id']);

// Optional: Delete associated tasks (if needed)
$delete_tasks = "DELETE FROM tasks WHERE task_id = ?";
$stmt = $conn->prepare($delete_tasks);
$stmt->bind_param("i", $projectID);
$stmt->execute();
$stmt->close();

// Delete project
$delete_project = "DELETE FROM projects_x WHERE project_id = ?";
$stmt = $conn->prepare($delete_project);
$stmt->bind_param("i", $projectID);
$stmt->execute();

header("Location: admin_index.php"); // Change to your project listing file
exit;
?>
