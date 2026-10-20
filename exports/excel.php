<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('reports.export');
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    exit('مكتبة التصدير غير مثبتة. شغّل composer install أولًا.');
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
try {
    $selectedIds = selected_report_ids($_GET);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    exit($error->getMessage());
}
if ($reportId) {
    $query = db()->prepare(
        'SELECT r.id, r.title, r.class_name, r.beneficiaries_count, r.body, r.created_at, u.name AS author_name, u.role, r.status,
           r.submitted_at, r.reviewed_at, r.admin_note
         FROM reports r JOIN users u ON u.id = r.user_id WHERE r.id = ?'
    );
    $query->execute([$reportId]);
} else {
    [$where, $params] = report_filters($_GET, true);
    if ($selectedIds !== null) {
        if ($selectedIds === []) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . '1 = 0';
        } else {
            $selectedPlaceholders = [];
            foreach ($selectedIds as $index => $id) {
                $key = 'selected_' . $index;
                $selectedPlaceholders[] = ':' . $key;
                $params[$key] = $id;
            }
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . 'r.id IN (' . implode(', ', $selectedPlaceholders) . ')';
        }
    }
    $query = db()->prepare(
        'SELECT r.id, r.title, r.class_name, r.beneficiaries_count, r.body, r.created_at, u.name AS author_name, u.role, r.status,
           r.submitted_at, r.reviewed_at, r.admin_note
         FROM reports r JOIN users u ON u.id = r.user_id' . $where . ' ORDER BY r.created_at DESC LIMIT 10000'
    );
    $query->execute($params);
}
$reports = $query->fetchAll();
if ($reportId && $reports === []) {
    http_response_code(404);
    exit('التقرير غير موجود.');
}
audit_log(db(), 'reports_exported_excel', 'report', $reportId ?: null, 'تصدير تقارير بصيغة Excel', [
    'report_count' => count($reports),
    'report_id' => $reportId ?: null,
    'selected_count' => $selectedIds === null ? null : count($selectedIds),
]);

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator(APP_NAME)
    ->setTitle($reportId ? 'تقرير رقم ' . $reportId : 'سجل التقارير')
    ->setSubject('تصدير التقارير')
    ->setDescription('تقرير مُصدّر من ' . APP_NAME);
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('التقارير');
$sheet->setRightToLeft(true);
$sheet->mergeCells('A1:K1');
$sheet->setCellValue('A1', $reportId ? 'التقرير التفصيلي' : 'سجل التقارير');
$sheet->mergeCells('A2:K2');
$sheet->setCellValue('A2', APP_NAME . '  ·  تاريخ التصدير: ' . date('Y/m/d H:i') . '  ·  عدد النتائج: ' . count($reports));

$statusCounts = ['submitted' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($reports as $report) {
    if (isset($statusCounts[$report['status']])) {
        $statusCounts[$report['status']]++;
    }
}
$summaryCards = [
    'A4:B4' => ['إجمالي التقارير', count($reports), 'E8EAFE', '3730A3'],
    'C4:D4' => ['بانتظار المراجعة', $statusCounts['submitted'], 'FFF3D6', '92400E'],
    'E4:F4' => ['تقارير معتمدة', $statusCounts['approved'], 'DCFCE7', '166534'],
    'G4:I4' => ['تقارير مرفوضة', $statusCounts['rejected'], 'FFE4E6', '9F1239'],
];
foreach ($summaryCards as $range => [$label, $count, $background, $foreground]) {
    $sheet->mergeCells($range);
    $cell = explode(':', $range)[0];
    $sheet->setCellValue($cell, $label . ': ' . $count);
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => $foreground], 'size' => 10],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $background]],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);
}

$headers = ['رقم التقرير', 'العنوان', 'الصف', 'عدد المستفيدات', 'محتوى التقرير', 'مقدم التقرير', 'الدور', 'الحالة', 'تاريخ الإرسال', 'تاريخ المراجعة', 'ملاحظة الإدارة'];
$headerRow = 6;
$firstDataRow = $headerRow + 1;
$sheet->fromArray($headers, null, 'A' . $headerRow);
foreach ($reports as $index => $report) {
    $row = $firstDataRow + $index;
    $sheet->setCellValueExplicit('A' . $row, (int) $report['id'], DataType::TYPE_NUMERIC);
    $values = [
        'B' => $report['title'],
        'C' => $report['class_name'],
        'E' => $report['body'],
        'F' => $report['author_name'],
        'G' => ROLE_LABELS[$report['role']] ?? $report['role'],
        'H' => REPORT_STATUS_LABELS[$report['status']] ?? $report['status'],
        'K' => $report['admin_note'],
    ];
    foreach ($values as $column => $value) {
        if ($value !== null) {
            $sheet->setCellValueExplicit($column . $row, (string) $value, DataType::TYPE_STRING);
        }
    }
    if ($report['beneficiaries_count'] !== null) {
        $sheet->setCellValueExplicit('D' . $row, (int) $report['beneficiaries_count'], DataType::TYPE_NUMERIC);
    }
    foreach (['I' => $report['submitted_at'], 'J' => $report['reviewed_at']] as $column => $value) {
        if ($value !== null && $value !== '') {
            $sheet->setCellValue($column . $row, ExcelDate::PHPToExcel(new DateTimeImmutable($value)));
        }
    }
    $sheet->getRowDimension($row)->setRowHeight(-1);
    if ($index % 2 === 1) {
        $sheet->getStyle('A' . $row . ':K' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F7F9FC');
    }
    $statusStyle = match ($report['status']) {
        'submitted' => ['FFF3D6', '92400E'],
        'approved' => ['DCFCE7', '166534'],
        'rejected' => ['FFE4E6', '9F1239'],
        default => ['E2E8F0', '334155'],
    };
    $sheet->getStyle('H' . $row)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => $statusStyle[1]]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $statusStyle[0]]],
    ]);
}

$lastRow = max($headerRow, count($reports) + $headerRow);
$sheet->getStyle('A1:K1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 20, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '312E81']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getStyle('A2:K2')->applyFromArray([
    'font' => ['size' => 10, 'color' => ['rgb' => '475569']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getStyle('A' . $headerRow . ':K' . $headerRow)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_RIGHT,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true,
    ],
    'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '312E81']]],
]);
if ($lastRow >= $firstDataRow) {
    $dataRange = 'A' . $firstDataRow . ':K' . $lastRow;
    $sheet->getStyle($dataRange)->applyFromArray([
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
            'vertical' => Alignment::VERTICAL_TOP,
            'wrapText' => true,
        ],
        'borders' => [
            'bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'DCE3EC']],
            'right' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E8EDF3']],
        ],
    ]);
    $sheet->getStyle('I' . $firstDataRow . ':J' . $lastRow)->getNumberFormat()->setFormatCode('yyyy/mm/dd hh:mm');
    $sheet->getStyle('A' . $firstDataRow . ':A' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}
$sheet->getRowDimension(1)->setRowHeight(38);
$sheet->getRowDimension(2)->setRowHeight(26);
$sheet->getRowDimension(4)->setRowHeight(32);
$sheet->getRowDimension($headerRow)->setRowHeight(32);
$widths = ['A' => 13, 'B' => 32, 'C' => 18, 'D' => 18, 'E' => 55, 'F' => 24, 'G' => 16, 'H' => 19, 'I' => 22, 'J' => 22, 'K' => 42];
foreach ($widths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}
$sheet->freezePane('A' . $firstDataRow);
$sheet->setAutoFilter('A' . $headerRow . ':K' . $lastRow);
$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);
$sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow);
$sheet->getPageMargins()->setTop(0.55)->setBottom(0.55)->setLeft(0.3)->setRight(0.3);
$sheet->getHeaderFooter()->setOddFooter('&L' . APP_NAME . '&Cصفحة &P من &N&Rتاريخ التصدير: ' . date('Y/m/d'));

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . ($reportId ? 'report-' . $reportId : 'reports-' . date('Ymd')) . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
