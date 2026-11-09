<?php
//include library;
include('library/tcpdf.php');

//make TCPDF object
$pdf = new TCPDF('P', 'mm', 'A4');

//remove default header and footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
//add page
$pdf->AddPage();

//add content (student list)
//title
$pdf->setFont('helvetica', '', 14);
$pdf->Cell(190,10,"University of Insert Name Here",1,1,'C');

$pdf->setFont('helvetica', '', 8);
$pdf->Cell(190,5,"Student List",1,1,'C');

$pdf->setFont('helvetica', '', 10);
$pdf->Cell(30,5,"Class",1);
$pdf->Cell(160,5,": Programming 101",1);
$pdf->Ln();
$pdf->Cell(30,5,"Teacher Name",1);
$pdf->Cell(160,5,": Prof. John Smith",1);
$pdf->Ln();
$pdf->Ln(2);

//make the table
$html="
<table>
<tr>
<th>heading</th>
<th>heading</th>
</tr>
<tr>
<td>content</td>
<td>content</td>
</tr>
</table>
<style>
table{
border-collapse: collapse;
}
th, td {
border: 1px solid #888;
}
table tr th{
background-color: #888;
color: #fff;
font-weight: bold;
}
</style>
";

//write HTML content
$pdf->writeHTMLCell(192,0,9,'',$html,0);
//output
$pdf->Output();
?>