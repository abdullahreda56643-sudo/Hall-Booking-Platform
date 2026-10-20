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
header('Content-Type: application/json; charset=utf-8');
$reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
$action = (string) ($_POST['action'] ?? '');
if (!$reportId || !in_array($action, ['upload', 'delete', 'order'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'بيانات الطلب غير صالحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
ensure_report_media_order_column($pdo);
$reportLock = $pdo->prepare('SELECT id FROM reports WHERE id = ? AND user_id = ? AND status IN ("draft", "rejected")');
$reportLock->execute([$reportId, current_user()['id']]);
if (!$reportLock->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'لا يمكن تعديل مرفقات هذا التقرير.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();
    $reportLock->execute([$reportId, current_user()['id']]);
    if (!$reportLock->fetchColumn()) {
        throw new RuntimeException('تغيّرت حالة التقرير؛ أعد تحميل الصفحة.');
    }

    if ($action === 'upload') {
        $incomingCount = count($_FILES['media']['name'] ?? []);
        if ($incomingCount !== 1) {
            throw new RuntimeException('ارفعي ملفًا واحدًا في كل طلب.');
        }
        $countQuery = $pdo->prepare('SELECT COUNT(*) FROM report_media WHERE report_id = ?');
        $countQuery->execute([$reportId]);
        if ((int) $countQuery->fetchColumn() + $incomingCount > 10) {
            throw new RuntimeException('الحد الأقصى 10 ملفات لكل تقرير.');
        }
        store_report_uploads($pdo, $reportId, $_FILES['media'] ?? []);
        $mediaId = (int) $pdo->lastInsertId();
        audit_log($pdo, 'report_attachments_uploaded', 'report', $reportId, 'رفع مرفق تقرير', ['attachment_count' => $incomingCount]);
        $mediaQuery = $pdo->prepare('SELECT * FROM report_media WHERE id = ? AND report_id = ?');
        $mediaQuery->execute([$mediaId, $reportId]);
        $media = $mediaQuery->fetch();
        $pdo->commit();
        echo json_encode(['ok' => true, 'media' => $media], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $mediaId = filter_input(INPUT_POST, 'media_id', FILTER_VALIDATE_INT);
        if (!$mediaId) {
            throw new RuntimeException('معرّف المرفق غير صالح.');
        }
        $mediaQuery = $pdo->prepare('SELECT * FROM report_media WHERE id = ? AND report_id = ? FOR UPDATE');
        $mediaQuery->execute([$mediaId, $reportId]);
        $media = $mediaQuery->fetch();
        if (!$media) {
            throw new RuntimeException('المرفق غير موجود.');
        }
        $delete = $pdo->prepare('DELETE FROM report_media WHERE id = ? AND report_id = ?');
        $delete->execute([$mediaId, $reportId]);
        $pdo->commit();

        $baseDirectory = realpath(UPLOAD_DIR);
        $absolutePath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $media['file_path']));
        $warning = '';
        if ($baseDirectory && $absolutePath && str_starts_with($absolutePath, $baseDirectory . DIRECTORY_SEPARATOR) && is_file($absolutePath) && !unlink($absolutePath)) {
            error_log('تعذر حذف ملف مرفق التقرير: ' . $absolutePath);
            $warning = 'حُذف المرفق من التقرير، لكن تعذر تنظيف الملف المخزن. يرجى إبلاغ الدعم.';
        }
        echo json_encode(['ok' => true, 'warning' => $warning], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $requestedOrder = $_POST['order'] ?? null;
    if (!is_array($requestedOrder) || count($requestedOrder) > 10) {
        throw new RuntimeException('ترتيب المرفقات غير صالح.');
    }
    $ids = [];
    foreach ($requestedOrder as $id) {
        if ((!is_string($id) && !is_int($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
            throw new RuntimeException('ترتيب المرفقات غير صالح.');
        }
        $ids[] = (int) $id;
    }
    if (count($ids) !== count(array_unique($ids))) {
        throw new RuntimeException('ترتيب المرفقات يحتوي على عناصر مكررة.');
    }
    $currentQuery = $pdo->prepare('SELECT id FROM report_media WHERE report_id = ? ORDER BY sort_order, id FOR UPDATE');
    $currentQuery->execute([$reportId]);
    $currentIds = array_map('intval', $currentQuery->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) !== count($currentIds) || array_diff($ids, $currentIds) !== []) {
        throw new RuntimeException('تغيّرت قائمة المرفقات؛ حدّث الصفحة وحاول مرة أخرى.');
    }
    $updateOrder = $pdo->prepare('UPDATE report_media SET sort_order = ? WHERE id = ? AND report_id = ?');
    foreach ($ids as $position => $id) {
        $updateOrder->execute([$position, $id, $reportId]);
    }
    $pdo->commit();
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
