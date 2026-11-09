<?php
require_once("phpjasperxml/PHPJasperXML.inc.php");
require_once("phpjasperxml/tcpdf/tcpdf.php");

include '../dp.php';

// Create connection
$conn = new mysqli($server, $user, $pass, $db);

// Load JRXML
$xml = simplexml_load_file("teamLeaderReportjrxml.jrxml");

// Initialize PHPJasperXML
$PHPJasperXML = new PHPJasperXML();
$PHPJasperXML->arrayParameter = array("createdBy" => "Constance Mahlangu");
$PHPJasperXML->load_xml_file("teamLeaderReportjrxmlteamLeaderReportjrxml.jrxml");
$PHPJasperXML->transferDBtoArray($server, $user, $pass, $db);

// Output PDF directly to browser
$PHPJasperXML->outpage("I");  // "I" for inline display
?>


