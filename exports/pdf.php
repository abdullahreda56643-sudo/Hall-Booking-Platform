<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('reports.export');
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    exit('مكتبة التصدير غير مثبتة. شغّل composer install أولًا.');
}
require_once $autoload;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

$pdo = db();
$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
try {
    $selectedIds = selected_report_ids($_GET);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    exit($error->getMessage());
}
if ($reportId) {
    $query = $pdo->prepare(
        'SELECT r.*, u.name AS author_name, u.role FROM reports r
         JOIN users u ON u.id = r.user_id WHERE r.id = ?'
    );
    $query->execute([$reportId]);
    $reports = $query->fetchAll();
    if ($reports === []) {
        http_response_code(404);
        exit('التقرير غير موجود.');
    }
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
    $query = $pdo->prepare(
        'SELECT r.*, u.name AS author_name, u.role FROM reports r
         JOIN users u ON u.id = r.user_id' . $where . ' ORDER BY r.created_at DESC LIMIT 1000'
    );
    $query->execute($params);
    $reports = $query->fetchAll();
}
audit_log($pdo, 'reports_exported_pdf', 'report', $reportId ?: null, 'تصدير تقارير بصيغة PDF', [
    'report_count' => count($reports),
    'report_id' => $reportId ?: null,
    'selected_count' => $selectedIds === null ? null : count($selectedIds),
]);

$formatDate = static fn (?string $value): string => $value ? date('Y/m/d · H:i', strtotime($value)) : '—';
$statusClasses = [
    'submitted' => 'status-submitted',
    'approved' => 'status-approved',
    'rejected' => 'status-rejected',
];
$content = '';
if ($reportId) {
    $report = $reports[0];
    $statusClass = $statusClasses[$report['status']] ?? 'status-default';
    $content = '<section class="report-detail">'
        . '<div class="detail-heading"><div><span class="eyebrow">تقرير رقم ' . (int) $report['id'] . '</span>'
        . '<h1>' . e($report['title']) . '</h1></div><span class="status ' . $statusClass . '">'
        . e(REPORT_STATUS_LABELS[$report['status']] ?? $report['status']) . '</span></div>'
        . '<table class="metadata"><tbody><tr><th>مقدم التقرير</th><td>' . e($report['author_name']) . '</td>'
        . '<th>الدور</th><td>' . e(ROLE_LABELS[$report['role']] ?? $report['role']) . '</td></tr>'
        . '<tr><th>الصف</th><td>' . e($report['class_name'] ?? '—') . '</td>'
        . '<th>عدد المستفيدات</th><td>' . e($report['beneficiaries_count'] === null ? '—' : (string) $report['beneficiaries_count']) . '</td></tr>'
        . '<tr><th>تاريخ الإنشاء</th><td>' . e($formatDate($report['created_at'] ?? null)) . '</td>'
        . '<th>تاريخ الإرسال</th><td>' . e($formatDate($report['submitted_at'] ?? null)) . '</td></tr>'
        . '<tr><th>تاريخ المراجعة</th><td colspan="3">' . e($formatDate($report['reviewed_at'] ?? null)) . '</td></tr></tbody></table>'
        . '<h2 class="section-heading">محتوى التقرير</h2><div class="report-body">'
        . nl2br(e($report['body'])) . '</div>';
    if (!empty($report['admin_note'])) {
        $noteTitle = $report['status'] === 'rejected' ? 'سبب الرفض' : 'ملاحظة الإدارة';
        $content .= '<div class="admin-note"><strong>' . e($noteTitle) . '</strong><p>'
            . nl2br(e($report['admin_note'])) . '</p></div>';
    }
    $content .= '</section>';
} else {
    $rows = '';
    foreach ($reports as $report) {
        $statusClass = $statusClasses[$report['status']] ?? 'status-default';
        $rows .= '<tr><td class="number">' . (int) $report['id'] . '</td><td>' . e($report['title']) . '</td><td>'
            . e($report['class_name'] ?? '—') . '</td><td>'
            . e($report['beneficiaries_count'] === null ? '—' : (string) $report['beneficiaries_count'])
            . '</td><td>' . e($report['author_name']) . '</td><td>' . e(ROLE_LABELS[$report['role']] ?? '')
            . '</td><td><span class="status ' . $statusClass . '">'
            . e(REPORT_STATUS_LABELS[$report['status']] ?? $report['status']) . '</span></td><td>'
            . e($formatDate($report['submitted_at'] ?? null)) . '</td><td>'
            . e($formatDate($report['reviewed_at'] ?? null)) . '</td><td>'
            . nl2br(e($report['admin_note'] ?? '—')) . '</td></tr>';
    }
    $content = '<div class="summary"><strong>' . count($reports) . '</strong><span>إجمالي التقارير</span>'
        . '<span class="summary-date">تاريخ التصدير: ' . e(date('Y/m/d · H:i')) . '</span></div>'
        . '<table class="report-list"><thead><tr><th>رقم</th><th>عنوان التقرير</th><th>الصف</th>'
        . '<th>عدد المستفيدات</th><th>مقدم التقرير</th><th>الدور</th><th>الحالة</th><th>تاريخ الإرسال</th>'
        . '<th>تاريخ المراجعة</th><th>ملاحظة الإدارة</th></tr></thead><tbody>'
        . ($rows !== '' ? $rows : '<tr><td class="empty" colspan="10">لا توجد تقارير مطابقة للفلاتر المحددة.</td></tr>')
        . '</tbody></table>';
}

$orientation = $reportId ? 'P' : 'L';
$html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><style>
body{font-family:"dejavusans";direction:rtl;color:#172033;font-size:10px;line-height:1.65}
.brand{border-bottom:3px solid #4f46e5;padding:0 0 13px;margin-bottom:20px}
.brand-name{font-size:11px;font-weight:bold;color:#4f46e5;margin:0 0 4px}
.brand-title{font-size:19px;font-weight:bold;color:#172033;margin:0}
.brand-subtitle{font-size:9px;color:#64748b;margin:3px 0 0}
.summary{background:#f1f5ff;border:1px solid #dbe4ff;border-right:4px solid #4f46e5;border-radius:5px;padding:10px 13px;margin:0 0 14px}
.summary strong{font-size:16px;color:#4338ca;margin-left:5px}.summary-date{float:left;color:#64748b;font-size:9px}
table{width:100%;border-collapse:collapse}
.report-list{table-layout:fixed;font-size:8px}
.report-list thead{display:table-header-group}
.report-list th{background:#312e81;color:#fff;font-weight:bold;padding:8px 6px}
.report-list td{padding:7px 6px;vertical-align:top;overflow-wrap:break-word}
.report-list th,.report-list td{border:1px solid #dbe2ef;text-align:right}
.report-list tbody tr:nth-child(even){background:#f8faff}
.report-list tbody tr{page-break-inside:avoid}
.report-list .number{text-align:center;color:#64748b}
.report-list .empty{text-align:center;padding:22px;color:#64748b}
.status{display:inline-block;border-radius:9px;padding:2px 7px;font-size:8px;font-weight:bold;white-space:nowrap}
.status-submitted{background:#fff4d6;color:#92400e}.status-approved{background:#dcfce7;color:#166534}
.status-rejected{background:#ffe4e6;color:#9f1239}.status-default{background:#e2e8f0;color:#334155}
.detail-heading{display:table;width:100%;margin-bottom:15px}
.detail-heading>div,.detail-heading>.status{display:table-cell;vertical-align:middle}
.detail-heading>.status{text-align:center;width:90px}
.eyebrow{font-size:9px;color:#4f46e5;font-weight:bold}
.detail-heading h1{font-size:18px;margin:3px 0 0}
.metadata{margin:12px 0 20px;border:1px solid #dbe2ef}
.metadata th,.metadata td{padding:8px;border:1px solid #dbe2ef;text-align:right}
.metadata th{width:18%;background:#f1f5f9;color:#475569;font-weight:bold}
.metadata td{width:32%;color:#172033}
.section-heading{font-size:12px;color:#312e81;border-right:3px solid #4f46e5;padding-right:8px;margin:18px 0 8px}
.report-body{font-size:10px;line-height:2;border:1px solid #e2e8f0;background:#fbfcff;padding:13px;overflow-wrap:break-word}
.admin-note{margin-top:17px;padding:10px 12px;background:#fff8e8;border-right:3px solid #f59e0b}
.admin-note strong{color:#92400e}.admin-note p{margin:5px 0 0;line-height:1.8;overflow-wrap:break-word}
.report-detail{page-break-inside:auto}
</style></head><body dir="rtl"><header class="brand"><p class="brand-name">' . e(APP_NAME) . '</p><h2 class="brand-title">'
    . ($reportId ? 'تقرير تفصيلي' : 'سجل التقارير') . '</h2><p class="brand-subtitle">وثيقة رسمية · '
    . e(date('Y/m/d')) . '</p></header>' . $content . '</body></html>';
$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4-' . $orientation,
    'default_font' => 'dejavusans',
    'autoScriptToLang' => true,
    'autoLangToFont' => true,
    'useSubstitutions' => true,
    'directionality' => 'rtl',
    'margin_left' => 14,
    'margin_right' => 14,
    'margin_top' => 16,
    'margin_bottom' => 20,
    'margin_footer' => 8,
]);
$mpdf->SetHTMLFooter(
    '<div dir="rtl" style="border-top:1px solid #dbe2ef;padding-top:5px;text-align:center;color:#64748b;font-size:8pt">'
    . e(APP_NAME) . ' · صفحة {PAGENO} من {nbpg}</div>'
);
$mpdf->WriteHTML($html);
$mpdf->Output(
    $reportId ? 'report-' . $reportId . '.pdf' : 'reports-' . date('Ymd') . '.pdf',
    Destination::DOWNLOAD
);
