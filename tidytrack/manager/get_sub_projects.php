<?php
error_reporting(0); // suppress notices/warnings
include '../dp.php';

$project_id = intval($_GET['project_id'] ?? 0);
$data = [];

if ($project_id) {
    $stmt = $conn->prepare("SELECT sub_project_id AS id, sub_project_name FROM sub_projects WHERE project_id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode($data);
exit; // stop any extra output
