<?php
session_start();
include 'dp.php';

if (!isset($_SESSION['temp_email']) || !isset($_POST['role'])) {
    header("Location: index.php");
    exit();
}

$email = $_SESSION['temp_email'];
$name = $_SESSION['temp_name'];
$role = $_POST['role'];

// Prevent duplicates
$stmt_check = $conn->prepare("SELECT * FROM users WHERE email = ?");
$stmt_check->bind_param("s", $email);
$stmt_check->execute();
$result = $stmt_check->get_result();

if ($result->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO users (name, email, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $name, $email, $role);
    $stmt->execute();
    $stmt->close();
}

// Set session for dashboard
$_SESSION['name'] = $name;
$_SESSION['role'] = $role;

// Clear temp
unset($_SESSION['temp_email']);
unset($_SESSION['temp_name']);

header("Location: index.php");
exit();
