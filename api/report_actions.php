<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_role([ROLE_ADMIN, ROLE_STAFF]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('طريقة الطلب غير مسموحة.');
}
verify_csrf();
$jsonDecisionRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

$action = (string) ($_POST['action'] ?? '');
$reportsRedirect = static function (): string {
    if (!has_permission('reports.review') || (string) ($_POST['return_to'] ?? '') !== 'reports') {
        return '/admin/reports.php';
    }
    $returnParams = [];
    parse_str((string) ($_POST['return_query'] ?? ''), $returnParams);
    $allowedParams = ['status', 'q', 'role', 'user_id', 'from', 'to', 'page'];
    $returnParams = array_intersect_key($returnParams, array_flip($allowedParams));
    foreach ($returnParams as $key => $value) {
        if (!is_string($value) && !is_int($value)) {
            unset($returnParams[$key]);
        }
    }
    $query = http_build_query($returnParams);
    return '/admin/reports.php' . ($query === '' ? '' : '?' . $query);
};
if ($action === 'approve_selected') {
    require_permission('reports.review');
    $reportIds = $_POST['report_ids'] ?? [];
    if (!is_array($reportIds) || $reportIds === [] || count($reportIds) > 500) {
        http_response_code(400);
        exit('حددي تقريرًا واحدًا على الأقل للاعتماد.');
    }
    $reportIds = array_values(array_unique($reportIds));
    foreach ($reportIds as $id) {
        if ((!is_string($id) && !is_int($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
            http_response_code(400);
            exit('معرّف تقرير محدد غير صالح.');
        }
    }
    $reportIds = array_map('intval', $reportIds);
    $pdo = db();
    $pdo->beginTransaction();
    $placeholders = implode(', ', array_fill(0, count($reportIds), '?'));
    $lookup = $pdo->prepare('SELECT id, user_id, title, status FROM reports WHERE id IN (' . $placeholders . ') FOR UPDATE');
    $lookup->execute($reportIds);
    $reports = $lookup->fetchAll();
    if (count($reports) !== count($reportIds)) {
        $pdo->rollBack();
        http_response_code(404);
        exit('أحد التقارير المحددة غير موجود.');
    }
    $update = $pdo->prepare(
        'UPDATE reports SET status = "approved", admin_note = NULL, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = "submitted"'
    );
    $approvedCount = 0;
    foreach ($reports as $report) {
        if ($report['status'] !== 'submitted') {
            continue;
        }
        $update->execute([current_user()['id'], $report['id']]);
        if ($update->rowCount() !== 1) {
            continue;
        }
        audit_log($pdo, 'report_approved', 'report', (int) $report['id'], 'اعتماد تقرير', [
            'title' => $report['title'],
            'previous_status' => 'submitted',
            'new_status' => 'approved',
        ]);
        create_notification(
            $pdo,
            (int) $report['user_id'],
            'report',
            'تم اعتماد التقرير',
            'تم اعتماد تقريرك: ' . $report['title'],
            '/user/report_view.php?id=' . (int) $report['id'],
            'report',
            (int) $report['id']
        );
        $approvedCount++;
    }
    if ($approvedCount === 0) {
        $pdo->rollBack();
        flash('error', 'لا توجد تقارير محددة بانتظار الاعتماد.');
        redirect($reportsRedirect());
    }
    $pdo->commit();
    flash('success', 'تم اعتماد ' . $approvedCount . ' تقرير.');
    redirect($reportsRedirect());
}
$reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
$note = trim((string) ($_POST['admin_note'] ?? ''));
if (!$reportId || !in_array($action, ['submit', 'approve', 'reject', 'delete'], true)) {
    http_response_code(400);
    exit('بيانات الإجراء غير صالحة.');
}
if ($action === 'approve' || $action === 'reject') {
    require_permission('reports.review', $jsonDecisionRequest);
}
if ($action === 'submit' && current_user()['role'] !== ROLE_STAFF) {
    http_response_code(403);
    exit('هذا الإجراء غير متاح لهذا الدور.');
}
if ($action === 'delete' && !in_array(current_user()['role'], [ROLE_ADMIN, ROLE_STAFF], true)) {
    http_response_code(403);
    exit('هذا الإجراء غير متاح لهذا الدور.');
}
if (current_user()['role'] === ROLE_STAFF) {
    if ($action === 'submit') {
        require_permission('reports.submit', $jsonDecisionRequest);
    } elseif ($action === 'delete') {
        require_permission('reports.delete', $jsonDecisionRequest);
    }
}
if ($action === 'reject' && mb_strlen($note) < 3) {
    if ($jsonDecisionRequest) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'سبب الرفض يجب أن يتكون من 3 أحرف على الأقل.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    flash('error', 'سبب الرفض إلزامي.');
    redirect($reportsRedirect() === '/admin/reports.php' && (string) ($_POST['return_to'] ?? '') !== 'reports'
        ? '/admin/report_view.php?id=' . $reportId
        : $reportsRedirect());
}

$pdo = db();
$pdo->beginTransaction();
$lookup = $pdo->prepare('SELECT id, user_id, title, status FROM reports WHERE id = ? FOR UPDATE');
$lookup->execute([$reportId]);
$report = $lookup->fetch();
if (!$report) {
    $pdo->rollBack();
    http_response_code(404);
    exit('التقرير غير موجود.');
}
$role = current_user()['role'];
if ($role !== ROLE_ADMIN && !has_permission('reports.review') && (int) $report['user_id'] !== current_user()['id']) {
    $pdo->rollBack();
    http_response_code(403);
    exit('ليس لديك صلاحية لعرض هذا التقرير.');
}

$destination = $role === ROLE_ADMIN || (($action === 'approve' || $action === 'reject') && has_permission('reports.review'))
    ? ((string) ($_POST['return_to'] ?? '') === 'dashboard'
        ? '/admin/dashboard.php'
        : ((string) ($_POST['return_to'] ?? '') === 'reports' ? $reportsRedirect() : '/admin/report_view.php?id=' . $reportId))
    : (has_permission('reports.view_own') ? '/user/report_view.php?id=' . $reportId : role_home($role));
if ($action === 'submit' && in_array($report['status'], ['draft', 'rejected'], true)) {
    $update = $pdo->prepare(
        'UPDATE reports SET status = "submitted", submitted_at = NOW(), admin_note = NULL,
          reviewed_by = NULL, reviewed_at = NULL WHERE id = ?'
    );
    $update->execute([$reportId]);
    notify_permission_holders(
        $pdo,
        'reports.review',
        'report',
        'تقرير جديد بانتظار المراجعة',
        'أرسل ' . current_user()['name'] . ' التقرير: ' . $report['title'],
        '/admin/report_view.php?id=' . $reportId,
        'report',
        $reportId
    );
    $message = 'تم إرسال التقرير للاعتماد.';
} elseif ($action === 'approve' && $report['status'] === 'submitted') {
    $update = $pdo->prepare(
        'UPDATE reports SET status = "approved", admin_note = NULL, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
    );
    $update->execute([current_user()['id'], $reportId]);
    create_notification(
        $pdo,
        (int) $report['user_id'],
        'report',
        'تم اعتماد التقرير',
        'تم اعتماد تقريرك: ' . $report['title'],
        '/user/report_view.php?id=' . $reportId,
        'report',
        $reportId
    );
    $message = 'تم اعتماد التقرير.';
} elseif ($action === 'reject' && $report['status'] === 'submitted') {
    $update = $pdo->prepare(
        'UPDATE reports SET status = "rejected", admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
    );
    $update->execute([$note, current_user()['id'], $reportId]);
    create_notification(
        $pdo,
        (int) $report['user_id'],
        'report',
        'التقرير بحاجة إلى تعديل',
        'تم رفض تقريرك "' . $report['title'] . '". السبب: ' . $note,
        '/user/report_view.php?id=' . $reportId,
        'report',
        $reportId
    );
    $message = 'تم رفض التقرير وإرسال السبب إلى صاحبه.';
} elseif (
    $action === 'delete'
    && (
        $role === ROLE_ADMIN
        || (
            (int) $report['user_id'] === current_user()['id']
            && in_array($report['status'], ['draft', 'rejected'], true)
        )
    )
) {
    $media = report_media($pdo, $reportId);
    audit_log($pdo, 'report_deleted', 'report', $reportId, 'حذف تقرير', [
        'title' => $report['title'],
        'previous_status' => $report['status'],
        'attachment_count' => count($media),
    ]);
    $pdo->prepare('DELETE FROM notifications WHERE entity_type = \'report\' AND entity_id = ?')->execute([$reportId]);
    $pdo->prepare('DELETE FROM reports WHERE id = ?')->execute([$reportId]);
    $pdo->commit();
    $uploadDirectory = realpath(UPLOAD_DIR);
    foreach ($media as $item) {
        $path = realpath(BASE_PATH . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $item['file_path']));
        if ($path === false || $uploadDirectory === false || !str_starts_with($path, $uploadDirectory . DIRECTORY_SEPARATOR)) {
            if ($path !== false) {
                error_log('تم تخطي مسار مرفق تقرير غير آمن: ' . $path);
            }
            continue;
        }
        if (is_file($path) && !unlink($path)) {
            error_log('تعذر حذف ملف تقرير: ' . $path);
        }
    }
    flash('success', 'تم حذف التقرير.');
    redirect($role === ROLE_ADMIN ? $reportsRedirect() : '/user/reports.php');
} else {
    $pdo->rollBack();
    if ($jsonDecisionRequest) {
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'لا يمكن تنفيذ الإجراء على حالة التقرير الحالية.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    flash('error', 'لا يمكن تنفيذ الإجراء على حالة التقرير الحالية.');
    redirect($destination);
}
$auditActions = [
    'submit' => ['report_submitted', 'إرسال تقرير للاعتماد'],
    'approve' => ['report_approved', 'اعتماد تقرير'],
    'reject' => ['report_rejected', 'رفض تقرير'],
];
[$auditAction, $auditDescription] = $auditActions[$action];
audit_log($pdo, $auditAction, 'report', $reportId, $auditDescription, [
    'title' => $report['title'],
    'previous_status' => $report['status'],
    'new_status' => $action === 'submit' ? 'submitted' : ($action === 'approve' ? 'approved' : 'rejected'),
]);
$pdo->commit();
if ($jsonDecisionRequest && in_array($action, ['approve', 'reject'], true)) {
    $reviewedAtQuery = $pdo->prepare('SELECT DATE_FORMAT(reviewed_at, "%Y-%m-%d %H:%i") FROM reports WHERE id = ?');
    $reviewedAtQuery->execute([$reportId]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $action === 'approve' ? 'approved' : 'rejected',
        'message' => $message,
        'reviewed_at' => $reviewedAtQuery->fetchColumn(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
flash('success', $message);
redirect($destination);
