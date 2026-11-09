<?php
session_start();
include '../dp.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['manager','admin'])) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

if (!isset($_POST['task_id']) || !isset($_POST['status'])) {
    http_response_code(400);
    echo "Invalid request";
    exit;
}

$taskID = intval($_POST['task_id']);
$status = $_POST['status'];

$allowed = ['Todo','Inprogress','Inreview','Onhold','Completed'];
if (!in_array($status, $allowed)) {
    http_response_code(400);
    echo "Invalid Status";
    exit;
}

$stmt = $conn->prepare("UPDATE tasks SET status = ? WHERE task_id = ?");
$stmt->bind_param("si", $status, $taskID);

if ($stmt->execute()) {
    echo "OK";
} else {
    echo "DB ERROR";
}

$stmt->close();
$conn->close();
