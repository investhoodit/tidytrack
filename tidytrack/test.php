<?php
$files=glob("*.php");
foreach($files as $file){
    echo"<a href='$file'>$file</a><br>";
}
?>