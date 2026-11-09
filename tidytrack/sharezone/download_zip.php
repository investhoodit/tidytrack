<?php
session_start();
require_once '../dp.php';

if (!isset($_GET['id'])) exit;

$id = intval($_GET['id']);
$res = $conn->query("SELECT * FROM sharezone_data WHERE id = $id");
$file = $res->fetch_assoc();

if ($file && $file['file_path']) {
    $filePath = $file['file_path'];
    $fileName = $file['file_name'];
    
    $zipName = "TidyTrack_" . time() . ".zip";
    $zip = new ZipArchive();
    
    if ($zip->open($zipName, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($filePath, $fileName);
        $zip->close();
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.$zipName.'"');
        readfile($zipName);
        unlink($zipName); // Delete temp zip after download
        exit;
    }
}
?>