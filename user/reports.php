<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_role([ROLE_STAFF]);
require_permission('reports.view_own');
$canCreateReports = has_permission('reports.create');
$canDeleteReports = has_permission('reports.delete');

$pdo = db();
$userId = current_user()['id'];
$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$status = in_array($status, REPORT_STATUSES, true) ? $status : '';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$fromInput = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$toInput = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$from = $fromInput !== '' && valid_date($fromInput) ? $fromInput : '';
$to = $toInput !== '' && valid_date($toInput) ? $toInput : '';
$filterError = '';
if (($fromInput !== '' && $from === '') || ($toInput !== '' && $to === '')) {
    $filterError = 'أدخلي تاريخًا صالحًا.';
} elseif ($from !== '' && $to !== '' && $from > $to) {
    $filterError = 'تاريخ البداية يجب أن يسبق تاريخ النهاية.';
    $from = '';
    $to = '';
}
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'grid';
$view = in_array($view, ['grid', 'list'], true) ? $view : 'grid';

$baseConditions = ['r.user_id = :user_id'];
$baseParams = ['user_id' => $userId];
if ($search !== '') {
    $baseConditions[] = '(r.title LIKE :query_title OR r.body LIKE :query_body)';
    $baseParams['query_title'] = '%' . $search . '%';
    $baseParams['query_body'] = '%' . $search . '%';
}
if ($from !== '') {
    $baseConditions[] = 'DATE(r.created_at) >= :date_from';
    $baseParams['date_from'] = $from;
}
if ($to !== '') {
    $baseConditions[] = 'DATE(r.created_at) <= :date_to';
    $baseParams['date_to'] = $to;
}
$baseWhere = ' WHERE ' . implode(' AND ', $baseConditions);

$statusCounts = array_fill_keys(REPORT_STATUSES, 0);
$countQuery = $pdo->prepare('SELECT r.status, COUNT(*) AS total FROM reports r' . $baseWhere . ' GROUP BY r.status');
$countQuery->execute($baseParams);
foreach ($countQuery->fetchAll() as $countRow) {
    $statusCounts[$countRow['status']] = (int) $countRow['total'];
}
$allCount = array_sum($statusCounts);

$conditions = $baseConditions;
$params = $baseParams;
if ($status !== '') {
    $conditions[] = 'r.status = :status';
    $params['status'] = $status;
}
$where = ' WHERE ' . implode(' AND ', $conditions);
$count = $pdo->prepare('SELECT COUNT(*) FROM reports r' . $where);
$count->execute($params);
$total = (int) $count->fetchColumn();
$perPage = 12;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$query = $pdo->prepare(
    'SELECT r.id, r.title, r.body, r.status, r.created_at, r.updated_at,
       COALESCE(m.image_count, 0) AS image_count,
       COALESCE(m.video_count, 0) AS video_count,
       COALESCE(m.file_count, 0) AS file_count
     FROM reports r
     LEFT JOIN (
       SELECT report_id,
         SUM(type = "image") AS image_count,
         SUM(type = "video") AS video_count,
         SUM(type = "file") AS file_count
       FROM report_media GROUP BY report_id
     ) m ON m.report_id = r.id' . $where .
    ' ORDER BY r.updated_at DESC, r.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
);
$query->execute($params);
$reports = $query->fetchAll();

$queryParams = array_filter([
    'q' => $search,
    'from' => $from,
    'to' => $to,
    'view' => $view,
], static fn ($value) => $value !== '');
$tabUrl = static function (string $tabStatus) use ($queryParams): string {
    $parameters = $queryParams;
    if ($tabStatus !== '') {
        $parameters['status'] = $tabStatus;
    }
    return app_url('/user/reports.php' . ($parameters === [] ? '' : '?' . http_build_query($parameters)));
};
$viewUrl = static function (string $mode) use ($status, $queryParams): string {
    $parameters = $queryParams;
    $parameters['view'] = $mode;
    if ($status !== '') {
        $parameters['status'] = $status;
    }
    return app_url('/user/reports.php?' . http_build_query($parameters));
};
$clearParams = ['view' => $view];
if ($status !== '') {
    $clearParams['status'] = $status;
}
$clearUrl = app_url('/user/reports.php?' . http_build_query($clearParams));

$tabs = [
    '' => ['label' => 'الكل', 'count' => $allCount, 'class' => 'reports-count-all'],
    'draft' => ['label' => 'مسودة', 'count' => $statusCounts['draft'], 'class' => ''],
    'submitted' => ['label' => 'مرسل', 'count' => $statusCounts['submitted'], 'class' => 'reports-count-submitted'],
    'approved' => ['label' => 'معتمد', 'count' => $statusCounts['approved'], 'class' => 'reports-count-approved'],
    'rejected' => ['label' => 'مرفوض', 'count' => $statusCounts['rejected'], 'class' => 'reports-count-rejected'],
];
$activeTabIndex = array_search($status, array_keys($tabs), true);
$title = 'تقاريري';
render_page_start($title, true);
?>
<div class="page-heading reports-heading">
  <div><h1>تقاريري</h1><p>تابعي تقاريرك ومرفقاتها وحالة مراجعتها.</p></div>
  <?php if ($canCreateReports): ?><a class="button button-primary" href="<?= e(app_url('/user/report_create.php')) ?>">＋ تقرير جديد</a><?php endif; ?>
</div>

<section class="user-reports-page" data-user-reports>
  <nav class="user-reports-tabs" aria-label="تصفية التقارير حسب الحالة" style="--active-index:<?= (int) $activeTabIndex ?>">
    <?php foreach ($tabs as $tabStatus => $tab): ?>
      <a class="user-reports-tab <?= $status === $tabStatus ? 'is-active' : '' ?>" href="<?= e($tabUrl($tabStatus)) ?>" <?= $status === $tabStatus ? 'aria-current="page"' : '' ?>>
        <span><?= e($tab['label']) ?></span>
        <span class="user-reports-tab-count <?= e($tab['class']) ?>"><?= number_format($tab['count'], 0, '.', ',') ?></span>
      </a>
    <?php endforeach; ?>
    <span class="user-reports-tab-indicator" aria-hidden="true"></span>
  </nav>

  <form method="get" class="user-reports-filters card" data-user-reports-filter>
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <label class="user-reports-filter-field">بحث في العنوان والنص
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="اكتبي كلمة للبحث">
    </label>
    <label class="user-reports-filter-field">من تاريخ
      <input type="date" name="from" value="<?= e($from) ?>">
    </label>
    <label class="user-reports-filter-field">إلى تاريخ
      <input type="date" name="to" value="<?= e($to) ?>">
    </label>
    <div class="user-reports-filter-actions">
      <button class="button button-secondary" type="submit">تطبيق الفلاتر</button>
      <a class="button button-quiet" href="<?= e($clearUrl) ?>">مسح</a>
    </div>
    <?php if ($filterError !== ''): ?><p class="user-reports-filter-error" role="alert"><?= e($filterError) ?></p><?php endif; ?>
  </form>

  <section class="user-reports-results card">
    <div class="user-reports-results-heading">
      <div><h2><?= $status !== '' ? e(REPORT_STATUS_LABELS[$status]) : 'جميع التقارير' ?></h2><span><?= number_format($total, 0, '.', ',') ?> تقرير</span></div>
      <div class="user-reports-view-toggle" role="group" aria-label="طريقة عرض التقارير">
        <a class="<?= $view === 'grid' ? 'is-active' : '' ?>" href="<?= e($viewUrl('grid')) ?>" aria-label="عرض شبكي" title="شبكة بطاقات" <?= $view === 'grid' ? 'aria-current="true"' : '' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>
        </a>
        <a class="<?= $view === 'list' ? 'is-active' : '' ?>" href="<?= e($viewUrl('list')) ?>" aria-label="عرض قائمة" title="قائمة" <?= $view === 'list' ? 'aria-current="true"' : '' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5h12M9 12h12M9 19h12"/><path d="M3 5h.01M3 12h.01M3 19h.01"/></svg>
        </a>
      </div>
    </div>
    <?php if ($reports === []): ?>
      <div class="empty-state user-reports-empty"><span class="empty-icon">▤</span><h2>لا توجد تقارير مطابقة</h2><p>جرّبي تغيير البحث أو التاريخ<?= $canCreateReports ? '، أو ابدئي تقريرًا جديدًا.' : '.' ?></p><?php if ($canCreateReports): ?><a class="button button-primary" href="<?= e(app_url('/user/report_create.php')) ?>">إنشاء تقرير جديد</a><?php endif; ?></div>
    <?php else: ?>
      <div class="user-report-collection <?= $view === 'list' ? 'is-list' : 'is-grid' ?>">
        <?php foreach ($reports as $index => $report):
            $editable = in_array($report['status'], ['draft', 'rejected'], true);
            $date = date('Y/m/d', strtotime($report['created_at']));
            $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $report['body'])) ?? '');
            if (mb_strlen($excerpt) > 155) {
                $excerpt = mb_substr($excerpt, 0, 155) . '…';
            }
        ?>
          <article class="user-report-card" data-report-card style="--card-index:<?= (int) $index ?>">
            <div class="user-report-card-main">
              <div class="user-report-card-top">
                <span class="badge <?= e(status_badge_class($report['status'])) ?>"><?= e(REPORT_STATUS_LABELS[$report['status']]) ?></span>
                <time datetime="<?= e(date('Y-m-d', strtotime($report['created_at']))) ?>"><?= e($date) ?></time>
              </div>
              <h3><a href="<?= e(app_url('/user/report_view.php?id=' . $report['id'])) ?>"><?= e($report['title']) ?></a></h3>
              <p class="user-report-excerpt"><?= e($excerpt !== '' ? $excerpt : 'لا يوجد نص مرفق بهذا التقرير.') ?></p>
              <div class="user-report-media" aria-label="مرفقات التقرير">
                <span title="الصور">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="m21 15-5-5L5 20"/></svg>
                  <?= (int) $report['image_count'] ?>
                </span>
                <span title="الفيديوهات">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="13" height="14" rx="2"/><path d="m16 10 5-3v10l-5-3z"/></svg>
                  <?= (int) $report['video_count'] ?>
                </span>
                <span title="الملفات">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10z"/><path d="M13 3v7h7M8 15h8M8 18h8"/></svg>
                  <?= (int) $report['file_count'] ?>
                </span>
              </div>
            </div>
            <div class="user-report-actions">
              <?php if ($editable && ($canCreateReports || $canDeleteReports)): ?>
                <?php if ($canCreateReports): ?><a class="user-report-action user-report-edit" href="<?= e(app_url('/user/report_create.php?id=' . $report['id'])) ?>" aria-label="تعديل التقرير">تعديل</a><?php endif; ?>
                <?php if ($canDeleteReports): ?>
                  <form method="post" action="<?= e(app_url('/api/report_actions.php')) ?>" class="inline-form" data-report-delete>
                    <?= csrf_field() ?>
                    <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="user-report-action user-report-delete" type="submit" aria-label="حذف التقرير">حذف</button>
                  </form>
                <?php endif; ?>
              <?php else: ?>
                <a class="user-report-action user-report-view" href="<?= e(app_url('/user/report_view.php?id=' . $report['id'])) ?>" title="التقرير للعرض فقط" aria-label="عرض فقط — لا يمكن تعديل هذا التقرير">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/></svg>
                  <span>عرض</span>
                </a>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if ($pages > 1): ?>
        <nav class="pagination user-reports-pagination" aria-label="ترقيم الصفحات">
          <?php if ($page > 1): ?>
            <a href="<?= e(app_url('/user/reports.php?' . http_build_query(array_merge($queryParams, $status !== '' ? ['status' => $status] : [], ['page' => $page - 1])))) ?>" aria-label="الصفحة السابقة">‹</a>
          <?php endif; ?>
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a class="<?= $i === $page ? 'current' : '' ?>" href="<?= e(app_url('/user/reports.php?' . http_build_query(array_merge($queryParams, $status !== '' ? ['status' => $status] : [], ['page' => $i])))) ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
          <?php endfor; ?>
          <?php if ($page < $pages): ?>
            <a href="<?= e(app_url('/user/reports.php?' . http_build_query(array_merge($queryParams, $status !== '' ? ['status' => $status] : [], ['page' => $page + 1])))) ?>" aria-label="الصفحة التالية">›</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</section>

<dialog class="user-report-delete-dialog" data-report-delete-dialog aria-labelledby="report-delete-title" aria-describedby="report-delete-description">
  <div class="user-report-delete-dialog-content">
    <span class="user-report-delete-icon" aria-hidden="true">!</span>
    <h2 id="report-delete-title">حذف التقرير؟</h2>
    <p id="report-delete-description">سيتم حذف التقرير ومرفقاته نهائيًا، ولا يمكن التراجع عن هذا الإجراء.</p>
    <div class="user-report-delete-dialog-actions">
      <button class="button button-quiet" type="button" data-report-delete-cancel>إلغاء</button>
      <button class="button button-danger" type="button" data-report-delete-confirm>نعم، احذف التقرير</button>
    </div>
  </div>
</dialog>
<?php render_page_end(); ?>
