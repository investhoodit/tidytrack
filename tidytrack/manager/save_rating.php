<?php
header('Content-Type: application/json');

// Database connection
$conn = new mysqli("localhost", "root", "", "tsm_db");
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// Validate request
if (!isset($_POST['employee_id']) || empty($_POST['employee_id'])) {
    echo json_encode(["status" => "error", "message" => "No employee selected"]);
    exit;
}

$employee_id = intval($_POST['employee_id']);
$feedback    = isset($_POST['feedback']) ? $conn->real_escape_string($_POST['feedback']) : '';
$manager_id  = 1; // static until login system is used

// Remove old ratings for this employee (optional if you only want latest ratings saved)
$conn->query("DELETE FROM employee_ratings WHERE employee_id = $employee_id");

// Insert new ratings
if (isset($_POST['rating']) && is_array($_POST['rating'])) {
    foreach ($_POST['rating'] as $criteria => $rating) {
        $criteria_safe = $conn->real_escape_string($criteria);
        $rating_val    = intval($rating);

        $conn->query("
            INSERT INTO employee_ratings (employee_id, manager_id, criteria, rating, feedback, created_at)
            VALUES ($employee_id, $manager_id, '$criteria_safe', $rating_val, '$feedback', NOW())
        ");
    }
}

echo json_encode(["status" => "success", "message" => "Ratings successfully saved!"]);
exit;
?>
