<?php
require_once __DIR__ . '/../dp.php';

$type = $_GET['type'] ?? '';
$id   = (int)$_GET['id'];

if (in_array($type,['inProgress','completedTasks'])) {
  $res = $conn->query("SELECT * FROM tasks WHERE id=$id");
} else {
  $res = $conn->query("SELECT * FROM projects_x WHERE id=$id");
}

echo json_encode($res->fetch_assoc());
