<?php
// test_api.php
header('Content-Type: application/json');
echo json_encode(['message' => 'API is working', 'timestamp' => time()]);
?>