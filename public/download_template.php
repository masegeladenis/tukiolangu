<?php
/**
 * Generate and download sample Excel template
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Create new Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Participants');

// Set headers
$headers = ['Name', 'Email', 'Phone', 'Ticket Type', 'Guests'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $col++;
}

// Style headers
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '111827'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
];
$sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

// Add sample data
$sampleData = [
    ['John Doe', 'john.doe@email.com', '+255712345678', 'Single', 1],
    ['Jane Smith', 'jane.smith@email.com', '+255723456789', 'Double', 2],
    ['Michael Johnson', 'michael.j@email.com', '+255734567890', 'VIP', 5],
    ['Sarah Williams', 'sarah.w@email.com', '+255745678901', 'Single', 1],
    ['David Brown', 'david.b@email.com', '+255756789012', 'Double', 2],
];

$row = 2;
foreach ($sampleData as $data) {
    $sheet->setCellValue('A' . $row, $data[0]);
    $sheet->setCellValue('B' . $row, $data[1]);
    $sheet->setCellValue('C' . $row, $data[2]);
    $sheet->setCellValue('D' . $row, $data[3]);
    $sheet->setCellValue('E' . $row, $data[4]);
    $row++;
}

// Style data rows
$dataStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'E5E7EB'],
        ],
    ],
];
$sheet->getStyle('A2:E6')->applyFromArray($dataStyle);

// Auto-size columns
foreach (range('A', 'E') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Add instructions sheet
$instructionSheet = $spreadsheet->createSheet();
$instructionSheet->setTitle('Instructions');

$instructions = [
    ['Tukio Langu App - Excel Template Instructions'],
    [''],
    ['Column Descriptions:'],
    [''],
    ['Name', 'Full name of the participant (Required)'],
    ['Email', 'Email address for notifications (Optional)'],
    ['Phone', 'Phone number with country code (Optional)'],
    ['Ticket Type', 'Type of ticket: Single, Double, VIP, etc. (Required)'],
    ['Guests', 'Number of guests allowed for this ticket (Required, minimum 1)'],
    [''],
    ['Notes:'],
    ['- First row must contain headers exactly as shown'],
    ['- Delete sample data before adding your participants'],
    ['- Ticket types should match types defined in your event'],
    ['- Guest count determines how many people can check in with one QR code'],
];

$row = 1;
foreach ($instructions as $line) {
    if (is_array($line) && count($line) > 1) {
        $instructionSheet->setCellValue('A' . $row, $line[0]);
        $instructionSheet->setCellValue('B' . $row, $line[1]);
    } else {
        $instructionSheet->setCellValue('A' . $row, is_array($line) ? $line[0] : $line);
    }
    $row++;
}

// Style title
$instructionSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$instructionSheet->getStyle('A3')->getFont()->setBold(true);
$instructionSheet->getStyle('A11')->getFont()->setBold(true);

// Auto-size instruction columns
$instructionSheet->getColumnDimension('A')->setWidth(20);
$instructionSheet->getColumnDimension('B')->setWidth(60);

// Set first sheet as active
$spreadsheet->setActiveSheetIndex(0);

// Generate file
$filename = 'tukio_participant_template.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Write to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
