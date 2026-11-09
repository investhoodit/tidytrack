<?php
// ajax_headers.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://cpanel.investhoodarcade.com/');   
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
?>