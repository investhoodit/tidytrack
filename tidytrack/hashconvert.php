<?php
$pla ="testuser123";
$hashedPassword= password_hash($pla, PASSWORD_DEFAULT);

echo $hashedPassword;
?>