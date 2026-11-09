<?php
require '../dp.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['user_id'])) {
    $id = intval($_POST['id']);
    $user_id = intval($_POST['user_id']);

    $stmt = $conn->prepare("DELETE FROM timesheets WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();

    header("Location: timesheet_list.php?user_id=$user_id");
    exit;
} else {
    echo "Invalid request.";
}

