<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('audit.view');

$pdo = db();
$actionLabels = [
    'login_success' => 'تسجيل دخول ناجح',
    'login_failed' => 'محاولة دخول فاشلة',
    'logout' => 'تسجيل خروج',
    'booking_created' => 'إنشاء حجز',
    'booking_cancelled' => 'إلغاء حجز',
    'report_created' => 'إنشاء تقرير',
    'report_updated' => 'تعديل تقرير',
    'report_submitted' => 'إرسال تقرير',
    'report_approved' => 'اعتماد تقرير',
    'report_rejected' => 'رفض تقرير',
    'report_deleted' => 'حذف تقرير',
    'report_attachments_uploaded' => 'رفع مرفقات',
    'user_created' => 'إنشاء مستخدم',
    'user_updated' => 'تعديل مستخدم',
    'user_activated' => 'تفعيل مستخدم',
    'user_deactivated' => 'تعطيل مستخدم',
    'user_password_reset' => 'إعادة تعيين كلمة مرور',
    'user_deleted' => 'حذف مستخدم',
    'profile_updated' => 'تحديث الملف الشخصي',
    'reports_exported_pdf' => 'تصدير تقارير PDF',
    'reports_exported_excel' => 'تصدير تقارير Excel',
];
$entityLabels = [
    'authentication' => 'الدخول',
    'booking' => 'حجز',
    'report' => 'تقرير',
    'user' => 'مستخدم',
];
$conditions = [];
$params = [];
$action = (string) ($_GET['action'] ?? '');
$entity = (string) ($_GET['entity'] ?? '');
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));

if (isset($actionLabels[$action])) {
    $conditions[] = 'action = :action';
    $params['action'] = $action;
}
if (isset($entityLabels[$entity])) {
    $conditions[] = 'entity_type = :entity';
    $params['entity'] = $entity;
}
if (valid_date($from)) {
    $conditions[] = 'created_at >= :from_date';
    $params['from_date'] = $from . ' 00:00:00';
}
if (valid_date($to)) {
    $conditions[] = 'created_at < :to_date';
    $params['to_date'] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
}
if ($search !== '') {
    $conditions[] = '(actor_name LIKE :actor OR description LIKE :description OR entity_id = :entity_id OR ip_address LIKE :ip_address)';
    $params['actor'] = '%' . $search . '%';
    $params['description'] = '%' . $search . '%';
    $params['entity_id'] = ctype_digit($search) ? (int) $search : 0;
    $params['ip_address'] = '%' . $search . '%';
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
$countQuery = $pdo->prepare('SELECT COUNT(*) FROM audit_logs' . $where);
$countQuery->execute($params);
$total = (int) $countQuery->fetchColumn();
$perPage = 25;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$logsQuery = $pdo->prepare(
    'SELECT id, actor_name, actor_role, action, entity_type, entity_id, description, details, ip_address, user_agent, created_at
     FROM audit_logs' . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
);
$logsQuery->execute($params);
$logs = $logsQuery->fetchAll();
$title = 'سجل التدقيق';
render_page_start($title, true);
?>
<div class="page-heading">
  <div><h1>سجل التدقيق</h1><p>متابعة الأنشطة والتغييرات التي تمت في المنصة.</p></div>
  <span class="badge badge-violet"><?= $total ?> نشاط</span>
</div>
<form method="get" class="filters card">
  <label class="filter-field">بحث<input type="search" name="q" value="<?= e($search) ?>" placeholder="الفاعل أو وصف النشاط أو عنوان IP"></label>
  <label class="filter-field">النشاط<select name="action">
    <option value="">كل الأنشطة</option>
    <?php foreach ($actionLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $action === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select></label>
  <label class="filter-field">القسم<select name="entity">
    <option value="">كل الأقسام</option>
    <?php foreach ($entityLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $entity === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select></label>
  <label class="filter-field">من تاريخ<input type="date" name="from" value="<?= e($from) ?>"></label>
  <label class="filter-field">إلى تاريخ<input type="date" name="to" value="<?= e($to) ?>"></label>
  <button class="button button-secondary" type="submit">تطبيق</button>
  <a class="button button-quiet" href="<?= e(app_url('/admin/audit_logs.php')) ?>">مسح</a>
</form>
<section class="card card-pad">
  <?php if ($logs === []): ?>
    <div class="empty-state"><span class="empty-icon">◷</span><h2>لا توجد أنشطة مطابقة</h2><p>ستظهر هنا الأنشطة المسجلة في المنصة.</p></div>
  <?php else: ?>
    <div class="table-wrap mobile-cards"><table class="data-table">
      <thead><tr><th>التاريخ والوقت</th><th>الفاعل</th><th>النشاط</th><th>القسم</th><th>عنوان IP</th><th>التفاصيل</th></tr></thead>
      <tbody><?php foreach ($logs as $log): ?>
        <tr>
          <td data-label="التاريخ والوقت"><?= e($log['created_at']) ?></td>
          <td data-label="الفاعل"><?= e($log['actor_name']) ?><?php if ($log['actor_role']): ?><br><small><?= e(ROLE_LABELS[$log['actor_role']] ?? $log['actor_role']) ?></small><?php endif; ?></td>
          <td data-label="النشاط"><strong><?= e($actionLabels[$log['action']] ?? $log['action']) ?></strong><br><small><?= e($log['description']) ?></small></td>
          <td data-label="القسم"><?= e($entityLabels[$log['entity_type']] ?? $log['entity_type']) ?><?= $log['entity_id'] !== null ? ' #' . (int) $log['entity_id'] : '' ?></td>
          <td data-label="عنوان IP"><?= e($log['ip_address']) ?></td>
          <td data-label="التفاصيل">
            <details>
              <summary class="link-button">عرض</summary>
              <p><?= e($log['details'] ?: 'لا توجد تفاصيل إضافية.') ?></p>
              <?php if ($log['user_agent'] !== ''): ?><small><?= e($log['user_agent']) ?></small><?php endif; ?>
            </details>
          </td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php if ($pages > 1): ?><nav class="pagination"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $page === $i ? 'current' : '' ?>" href="<?= e(app_url('/admin/audit_logs.php?' . http_build_query(array_merge($_GET, ['page' => $i])))) ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?>
  <?php endif; ?>
</section>
<?php render_page_end(); ?>
