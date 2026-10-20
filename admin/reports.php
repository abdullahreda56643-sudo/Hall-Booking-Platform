<?php
require_once dirname(__DIR__) . '/includes/auth.php';
if (!has_permission('reports.review')) {
    require_permission('reports.export');
    $title = 'تصدير التقارير';
    render_page_start($title, true);
    ?>
    <div class="page-heading">
      <div><h1>تصدير التقارير</h1><p>نزّلي نسخة من سجل التقارير بصيغة PDF أو Excel.</p></div>
    </div>
    <section class="card card-pad" aria-label="خيارات تصدير التقارير">
      <h2 class="section-title">اختاري صيغة التصدير</h2>
      <div class="heading-actions">
        <a class="button button-primary" href="<?= e(app_url('/exports/pdf.php')) ?>">تنزيل بصيغة PDF</a>
        <a class="button button-secondary" href="<?= e(app_url('/exports/excel.php')) ?>">تنزيل بصيغة Excel</a>
      </div>
    </section>
    <?php
    render_page_end();
    exit;
}
require_permission('reports.review');
$canExportReports = has_permission('reports.export');
$pdo = db();
$statusCountsQuery = $pdo->query(
    'SELECT status, COUNT(*) AS total FROM reports WHERE status <> "draft" GROUP BY status'
);
$statusCounts = ['all' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($statusCountsQuery->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
    $statusCounts['all'] += (int) $row['total'];
}

$activeStatus = (string) ($_GET['status'] ?? '');
if (!in_array($activeStatus, ['', 'submitted', 'approved', 'rejected'], true)) {
    $activeStatus = '';
}
$_GET['status'] = $activeStatus;
[$where, $params] = report_filters($_GET, true);
$countQuery = $pdo->prepare('SELECT COUNT(*) FROM reports r JOIN users u ON u.id = r.user_id' . $where);
$countQuery->execute($params);
$total = (int) $countQuery->fetchColumn();
$perPage = 20;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$query = $pdo->prepare(
    'SELECT r.id, r.title, r.status, r.created_at, r.submitted_at,
            u.name AS author_name, u.role,
            COALESCE(media.image_count, 0) AS image_count,
            COALESCE(media.video_count, 0) AS video_count,
            COALESCE(media.file_count, 0) AS file_count
     FROM reports r JOIN users u ON u.id = r.user_id
     LEFT JOIN (
       SELECT report_id,
              SUM(type = "image") AS image_count,
              SUM(type = "video") AS video_count,
              SUM(type = "file") AS file_count
       FROM report_media GROUP BY report_id
     ) media ON media.report_id = r.id' . $where .
    ' ORDER BY r.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
);
$query->execute($params);
$reports = $query->fetchAll();
$usersQuery = $pdo->prepare('SELECT id, name, role FROM users WHERE role = ? ORDER BY name');
$usersQuery->execute([ROLE_STAFF]);
$users = $usersQuery->fetchAll();
$filterQuery = http_build_query(array_diff_key($_GET, ['page' => true, 'selected' => true]));
$filtersActive = array_filter(['q', 'role', 'user_id', 'from', 'to'], static fn (string $key): bool => !empty($_GET[$key]));
$tabs = [
    ['status' => '', 'label' => 'الكل', 'count' => $statusCounts['all'], 'tone' => 'all'],
    ['status' => 'submitted', 'label' => 'بانتظار الاعتماد', 'count' => $statusCounts['submitted'], 'tone' => 'submitted'],
    ['status' => 'approved', 'label' => 'معتمدة', 'count' => $statusCounts['approved'], 'tone' => 'approved'],
    ['status' => 'rejected', 'label' => 'مرفوضة', 'count' => $statusCounts['rejected'], 'tone' => 'rejected'],
];
$title = 'مركز التقارير';
render_page_start($title, true);
?>
<div class="reports-page" data-reports-page>
  <div class="page-heading reports-heading">
    <div>
      <span class="reports-eyebrow">إدارة ومراجعة</span>
      <h1>مركز التقارير</h1>
      <p>تابعي التقارير الواردة، راجعيها، وصدّري النتائج التي تحتاجين إليها.</p>
    </div>
    <?php if ($canExportReports): ?><details class="reports-export-menu">
      <summary class="button button-primary">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 15v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/></svg>
        تصدير
        <span aria-hidden="true">⌄</span>
      </summary>
      <div class="reports-export-options">
        <a href="<?= e(app_url('/exports/pdf.php' . ($filterQuery ? '?' . $filterQuery : ''))) ?>" data-report-export data-filename="reports-<?= date('Ymd') ?>.pdf">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
          <span>تصدير PDF</span>
          <svg class="reports-file-action" aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 15v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/></svg>
        </a>
        <a href="<?= e(app_url('/exports/excel.php' . ($filterQuery ? '?' . $filterQuery : ''))) ?>" data-report-export data-filename="reports-<?= date('Ymd') ?>.xlsx">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5Z"/><path d="M4 6h16M9 10l6 7m0-7-6 7"/></svg>
          <span>تصدير Excel</span>
          <svg class="reports-file-action" aria-hidden="true" viewBox="0 0 24 24"><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5Z"/><path d="M4 6h16M9 10l6 7m0-7-6 7"/></svg>
        </a>
      </div>
    </details><?php endif; ?>
  </div>

  <nav class="reports-tabs" aria-label="تصفية التقارير حسب الحالة" style="--active-index:<?= max(0, array_search($activeStatus, ['', 'submitted', 'approved', 'rejected'], true)) ?>">
    <?php foreach ($tabs as $tab): ?>
      <?php $tabQuery = http_build_query(array_merge(array_diff_key($_GET, ['page' => true, 'status' => true]), $tab['status'] === '' ? [] : ['status' => $tab['status']])); ?>
      <a class="reports-tab <?= $activeStatus === $tab['status'] ? 'is-active' : '' ?>" href="<?= e(app_url('/admin/reports.php' . ($tabQuery ? '?' . $tabQuery : ''))) ?>" <?= $activeStatus === $tab['status'] ? 'aria-current="page"' : '' ?>>
        <span><?= e($tab['label']) ?></span><strong class="reports-tab-count reports-count-<?= e($tab['tone']) ?>"><?= number_format($tab['count']) ?></strong>
      </a>
    <?php endforeach; ?>
    <span class="reports-tab-indicator" aria-hidden="true"></span>
  </nav>

  <details class="reports-filter-card card" <?= $filtersActive !== [] ? 'open' : '' ?>>
    <summary class="reports-filter-summary">
      <span><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 5h16M7 12h10m-7 7h4"/><circle cx="8" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="11" cy="19" r="1"/></svg>الفلاتر</span>
      <span class="reports-filter-toggle">بحث وتصفية <b aria-hidden="true">⌄</b></span>
    </summary>
    <form method="get" class="reports-filters">
      <input type="hidden" name="status" value="<?= e($activeStatus) ?>">
      <label class="reports-filter-field reports-search-field">بحث نصي
        <span><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="العنوان أو النص أو الكاتب"></span>
      </label>
      <label class="reports-filter-field">المستخدم
        <select name="user_id"><option value="">كل المستخدمين</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>" <?= (string) ($_GET['user_id'] ?? '') === (string) $user['id'] ? 'selected' : '' ?>><?= e($user['name']) ?></option><?php endforeach; ?></select>
      </label>
      <label class="reports-filter-field">الدور
        <select name="role"><option value="">كل الأدوار</option><option value="<?= e(ROLE_STAFF) ?>" <?= ($_GET['role'] ?? '') === ROLE_STAFF ? 'selected' : '' ?>><?= e(ROLE_LABELS[ROLE_STAFF]) ?></option></select>
      </label>
      <label class="reports-filter-field">من تاريخ<input type="date" name="from" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></label>
      <label class="reports-filter-field">إلى تاريخ<input type="date" name="to" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></label>
      <div class="reports-filter-actions">
        <button class="button button-primary" type="submit">تطبيق الفلاتر</button>
        <a class="button button-quiet" href="<?= e(app_url('/admin/reports.php' . ($activeStatus !== '' ? '?status=' . rawurlencode($activeStatus) : ''))) ?>">مسح الفلاتر</a>
      </div>
    </form>
  </details>

  <section class="reports-table-card card">
    <div class="reports-table-heading">
      <div><h2>سجل التقارير</h2><span><?= number_format($total) ?> تقرير</span></div>
      <small>حددي تقارير لتنفيذ إجراء جماعي</small>
    </div>
    <?php if ($reports === []): ?>
      <div class="empty-state"><span class="empty-icon">▤</span><h2>لا توجد تقارير مطابقة</h2><p>جرّبي تغيير الفلاتر أو البحث بكلمات مختلفة.</p></div>
    <?php else: ?>
      <form id="reports-bulk-form" method="post" action="<?= e(app_url('/api/report_actions.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="approve_selected"><input type="hidden" name="return_to" value="reports"><input type="hidden" name="return_query" value="<?= e($filterQuery) ?>">
      </form>
      <div class="table-wrap mobile-cards reports-table-wrap">
        <table class="data-table reports-table">
          <thead><tr>
            <th class="reports-select-cell"><input type="checkbox" aria-label="تحديد كل تقارير الصفحة" data-reports-select-all></th>
            <th>رقم التقرير</th><th>التقرير</th><th>الكاتب</th><th>التاريخ</th><th>الحالة</th><th>الإجراءات</th>
          </tr></thead>
          <tbody>
          <?php foreach ($reports as $index => $report): ?>
            <?php $hasMedia = (int) $report['image_count'] + (int) $report['video_count'] + (int) $report['file_count'] > 0; ?>
            <tr style="--row-index:<?= (int) $index ?>">
              <td class="reports-select-cell" data-label="تحديد"><input type="checkbox" name="report_ids[]" value="<?= (int) $report['id'] ?>" aria-label="تحديد التقرير رقم <?= (int) $report['id'] ?>" data-report-select form="reports-bulk-form"></td>
              <td data-label="رقم التقرير"><span class="reports-number">#<?= (int) $report['id'] ?></span></td>
              <td data-label="التقرير" class="reports-title-cell">
                <a class="reports-title-link" href="<?= e(app_url('/admin/report_view.php?id=' . $report['id'])) ?>"><?= e($report['title']) ?></a>
                <?php if ($hasMedia): ?><span class="reports-attachments" aria-label="مرفقات التقرير">
                  <?php if ((int) $report['image_count'] > 0): ?><span title="<?= (int) $report['image_count'] ?> صور" aria-label="صور"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><?= (int) $report['image_count'] ?></span><?php endif; ?>
                  <?php if ((int) $report['video_count'] > 0): ?><span title="<?= (int) $report['video_count'] ?> فيديو" aria-label="فيديو"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="13" height="14" rx="2"/><path d="m16 10 5-3v10l-5-3"/></svg><?= (int) $report['video_count'] ?></span><?php endif; ?>
                  <?php if ((int) $report['file_count'] > 0): ?><span title="<?= (int) $report['file_count'] ?> ملفات" aria-label="ملفات"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></svg><?= (int) $report['file_count'] ?></span><?php endif; ?>
                </span><?php endif; ?>
              </td>
              <td data-label="الكاتب"><span class="reports-author">
                <span class="reports-avatar" aria-hidden="true"><?= e(mb_substr($report['author_name'], 0, 1)) ?></span>
                <span><strong><?= e($report['author_name']) ?></strong><small><?= e(ROLE_LABELS[$report['role']] ?? '') ?></small></span>
              </span></td>
              <td data-label="التاريخ"><time datetime="<?= e($report['created_at']) ?>"><?= e(date('Y/m/d', strtotime($report['created_at']))) ?></time></td>
              <td data-label="الحالة"><span class="badge <?= e(status_badge_class($report['status'])) ?>"><?= e(REPORT_STATUS_LABELS[$report['status']]) ?></span></td>
              <td data-label="الإجراءات"><div class="reports-row-actions">
                <a class="reports-action-view" href="<?= e(app_url('/admin/report_view.php?id=' . $report['id'])) ?>" aria-label="عرض التقرير رقم <?= (int) $report['id'] ?>">عرض</a>
                <?php if ($report['status'] === 'submitted'): ?>
                  <form method="post" action="<?= e(app_url('/api/report_actions.php')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="reports"><input type="hidden" name="return_query" value="<?= e($filterQuery) ?>">
                    <button class="reports-action-approve" type="submit" aria-label="اعتماد التقرير رقم <?= (int) $report['id'] ?>">اعتماد</button>
                  </form>
                  <button class="reports-action-reject" type="button" data-reports-reject="<?= (int) $report['id'] ?>" aria-label="رفض التقرير رقم <?= (int) $report['id'] ?>">رفض</button>
                <?php endif; ?>
                <form method="post" action="<?= e(app_url('/api/report_actions.php')) ?>">
                  <?= csrf_field() ?><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return_to" value="reports"><input type="hidden" name="return_query" value="<?= e($filterQuery) ?>">
                  <button class="reports-action-delete" type="submit" data-confirm="سيتم حذف التقرير رقم <?= (int) $report['id'] ?> ومرفقاته نهائيًا. هل تريدين المتابعة؟" aria-label="حذف التقرير رقم <?= (int) $report['id'] ?>">حذف</button>
                </form>
              </div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pages > 1): ?><nav class="pagination" aria-label="ترقيم الصفحات">
        <?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'current' : '' ?>" href="<?= e(app_url('/admin/reports.php?' . http_build_query(array_merge($_GET, ['page' => $i])))) ?>"><?= $i ?></a><?php endfor; ?>
      </nav><?php endif; ?>
    <?php endif; ?>
  </section>

  <dialog class="reports-reject-dialog" data-reports-reject-dialog aria-labelledby="reports-reject-title">
    <form id="reports-reject-form" method="post" action="<?= e(app_url('/api/report_actions.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="report_id" value=""><input type="hidden" name="return_to" value="reports"><input type="hidden" name="return_query" value="<?= e($filterQuery) ?>">
      <div class="reports-dialog-content">
        <span class="reports-dialog-icon" aria-hidden="true">!</span>
        <h2 id="reports-reject-title">رفض التقرير</h2>
        <p>اكتبي سبب الرفض ليظهر لصاحب التقرير.</p>
        <label for="reports-reject-note">سبب الرفض <span>*</span></label>
        <textarea id="reports-reject-note" name="admin_note" rows="4" required minlength="3" maxlength="2000" placeholder="وضّحي سبب الرفض وما يلزم تعديله"></textarea>
        <div class="reports-dialog-actions">
          <button class="button button-danger" type="submit">تأكيد الرفض</button>
          <button class="button button-quiet" type="button" data-reports-reject-close>إلغاء</button>
        </div>
      </div>
    </form>
  </dialog>

  <div class="reports-bulk-bar" data-reports-bulk-bar aria-hidden="true">
    <span><strong data-reports-selected-count>0</strong> محدد</span>
    <div>
      <button class="button button-success" type="submit" form="reports-bulk-form" data-reports-bulk-approve>اعتماد المحدد</button>
      <?php if ($canExportReports): ?><button class="button button-secondary" type="button" data-reports-bulk-export>تصدير المحدد</button><?php endif; ?>
    </div>
    <button class="reports-bulk-clear" type="button" data-reports-clear aria-label="إلغاء التحديد">×</button>
  </div>
</div>
<?php render_page_end(); ?>
