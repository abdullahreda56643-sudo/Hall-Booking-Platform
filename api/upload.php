<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_role([ROLE_STAFF]);
require_permission('reports.create', true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('طريقة الطلب غير مسموحة.');
}
verify_csrf();

$reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
if (!$reportId) {
    http_response_code(400);
    exit('معرّف التقرير غير صالح.');
}
$pdo = db();
$reportQuery = $pdo->prepare('SELECT id FROM reports WHERE id = ? AND user_id = ? AND status IN ("draft", "rejected")');
$reportQuery->execute([$reportId, current_user()['id']]);
if (!$reportQuery->fetch()) {
    http_response_code(403);
    exit('لا يمكن إضافة ملفات لهذا التقرير.');
}
try {
    ensure_report_media_order_column($pdo);
    $pdo->beginTransaction();
    $lockedReport = $pdo->prepare('SELECT id FROM reports WHERE id = ? AND user_id = ? AND status IN ("draft", "rejected") FOR UPDATE');
    $lockedReport->execute([$reportId, current_user()['id']]);
    if (!$lockedReport->fetch()) {
        throw new RuntimeException('تغيّرت حالة التقرير؛ لا يمكن إضافة ملفات إليه.');
    }
    $incomingCount = count($_FILES['media']['name'] ?? []);
    $countQuery = $pdo->prepare('SELECT COUNT(*) FROM report_media WHERE report_id = ?');
    $countQuery->execute([$reportId]);
    if ((int) $countQuery->fetchColumn() + $incomingCount > 10) {
        throw new RuntimeException('الحد الأقصى 10 ملفات لكل تقرير.');
    }
    store_report_uploads($pdo, $reportId, $_FILES['media'] ?? []);
    $attachmentCount = $incomingCount;
    if ($attachmentCount > 0) {
        audit_log($pdo, 'report_attachments_uploaded', 'report', $reportId, 'رفع مرفقات تقرير', ['attachment_count' => $attachmentCount]);
    }
    $pdo->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'files' => count($_FILES['media']['name'] ?? [])], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
