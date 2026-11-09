<?php
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$report = $_POST['leader_report'] ?? null;
$today = date('Y-m-d');

if ($userId && $report) {

    $stmt = $conn->prepare("INSERT INTO daily_tasks (user_id, task, sent_date) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $report, $today);

    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        // If duplicate entry error, ignore it
        if ($e->getCode() != 1062) {
            throw $e; // Only throw if it's NOT duplicate error
        }
    }

    $stmt->close();
}

// Always logout regardless
header("Location: ../logout.php");
exit;