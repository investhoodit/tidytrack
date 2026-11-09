<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$host = getenv('TIDYTRACK_DB_HOST');
$user = getenv('TIDYTRACK_DB_USER');
$password = getenv('TIDYTRACK_DB_PASSWORD');
$database = getenv('TIDYTRACK_DB_NAME');

if ($host === false || $user === false || $password === false || $database === false) {
    error_log('TidyTrack database configuration is incomplete.');
    http_response_code(500);
    exit('Database configuration is incomplete.');
}

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// 1. Set PHP timezone for consistency (important for date() functions)
date_default_timezone_set('Africa/Johannesburg');

// 2. Set MySQL timezone to ensure NOW() and TIMESTAMPdiff are accurate
if (!$conn->query("SET time_zone = '+02:00'")) {
    error_log("Failed to set MySQL timezone: " . $conn->error);
}
?>