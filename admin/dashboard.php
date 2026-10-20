<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('admin.dashboard');

$pdo = db();
$canReviewReports = has_permission('reports.review');
$canViewAudit = has_permission('audit.view');
$canViewStatistics = has_permission('statistics.view');
$pending = [];
$activities = [];
if ($canReviewReports) {
    $pendingQuery = $pdo->prepare(
        'SELECT r.id, r.title, COALESCE(r.submitted_at, r.created_at) AS submitted_at, u.name AS author, u.role
         FROM reports r JOIN users u ON u.id = r.user_id
         WHERE r.status = "submitted" ORDER BY COALESCE(r.submitted_at, r.created_at) ASC LIMIT 8'
    );
    $pendingQuery->execute();
    $pending = $pendingQuery->fetchAll();
}
if ($canViewAudit) {
    $activityQuery = $pdo->prepare(
        'SELECT actor_name, action, description, created_at
         FROM audit_logs ORDER BY id DESC LIMIT 8'
    );
    $activityQuery->execute();
    $activities = $activityQuery->fetchAll();
}

$today = new DateTimeImmutable('today');
$weekdays = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
$months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
$todayLabel = $weekdays[(int) $today->format('w')] . '، ' . $today->format('j') . ' ' . $months[(int) $today->format('n') - 1] . ' ' . $today->format('Y');
$user = current_user();
$timeAgo = static function (string $date): string {
    $seconds = max(0, time() - strtotime($date));
    if ($seconds < 60) {
        return 'الآن';
    }
    if ($seconds < 3600) {
        return 'قبل ' . (int) floor($seconds / 60) . ' دقيقة';
    }
    if ($seconds < 86400) {
        return 'قبل ' . (int) floor($seconds / 3600) . ' ساعة';
    }
    if ($seconds < 604800) {
        return 'قبل ' . (int) floor($seconds / 86400) . ' يوم';
    }
    return date('Y/m/d', strtotime($date));
};
$title = 'نظرة عامة';
render_page_start($title, true);
?>
<div class="dashboard-welcome">
  <div>
    <span class="dashboard-eyebrow">لوحة الإدارة</span>
    <h1>صباح الخير، <?= e($user['name']) ?> <span aria-hidden="true">✦</span></h1>
    <p><?= e($todayLabel) ?> <span aria-hidden="true">·</span> إليك ملخص نشاط المنصة.</p>
  </div>
  <div class="heading-actions">
    <?php if (has_permission('reports.review')): ?><a class="button button-secondary" href="<?= e(app_url('/admin/reports.php')) ?>">إدارة التقارير</a><?php endif; ?>
    <?php if (has_permission('statistics.view')): ?><a class="button button-primary" href="<?= e(app_url('/admin/statistics.php')) ?>">الإحصائيات</a><?php endif; ?>
  </div>
</div>

<?php if ($canViewStatistics): ?><form method="get" class="dashboard-filter" data-chart-filter aria-label="فلترة بيانات لوحة التحكم">
  <span class="dashboard-filter-label">الفترة</span>
  <div class="dashboard-range-buttons" role="group" aria-label="الفترة الزمنية">
    <button type="button" data-chart-range="7d" aria-pressed="false">7 أيام</button>
    <button type="button" data-chart-range="30d" aria-pressed="true">30 يومًا</button>
    <button type="button" data-chart-range="90d" aria-pressed="false">3 شهور</button>
    <button type="button" data-chart-range="custom" aria-pressed="false">مخصص</button>
  </div>
  <label class="dashboard-custom-date hidden">من تاريخ<input type="date" data-custom-from></label>
  <label class="dashboard-custom-date hidden">إلى تاريخ<input type="date" data-custom-to></label>
  <button class="button button-secondary dashboard-apply-filter hidden" type="submit">تطبيق</button>
  <span class="dashboard-period-label" data-period-label></span>
</form>

<?php
$stats = [
    ['total', 'إجمالي التقارير', 'icon-violet', 'file-text'],
    ['submitted', 'التقارير المعلّقة', 'icon-amber', 'clock-3'],
    ['approved', 'التقارير المعتمدة', 'icon-green', 'check-circle-2'],
    ['rejected', 'التقارير المرفوضة', 'icon-rose', 'x-circle'],
    ['active_users', 'المستخدمون النشطون', 'icon-cyan', 'users'],
    ['week_bookings', 'حجوزات الأسبوع', 'icon-violet', 'calendar-check'],
];
$icons = [
    'file-text' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    'clock-3' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5h5"/>',
    'check-circle-2' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
    'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'calendar-check' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18m-13 5 2 2 4-4"/>',
];
?>
<section class="dashboard-kpis" aria-label="مؤشرات الأداء">
  <?php foreach ($stats as [$key, $label, $iconClass, $icon]): ?>
    <article class="card dashboard-kpi" data-kpi="<?= e($key) ?>">
      <div class="dashboard-kpi-top">
        <span class="dashboard-kpi-icon <?= e($iconClass) ?>" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$icon] ?></svg>
        </span>
        <span class="dashboard-kpi-trend" data-chart-change="<?= e($key) ?>"><span aria-hidden="true">—</span> بانتظار البيانات</span>
      </div>
      <span class="dashboard-kpi-label"><?= e($label) ?></span>
      <strong class="dashboard-kpi-value is-loading" data-chart-value="<?= e($key) ?>">—</strong>
      <div class="dashboard-kpi-footer">
        <span class="dashboard-kpi-comparison">مقارنة بالفترة السابقة</span>
        <span class="dashboard-sparkline" data-chart-sparkline="<?= e($key) ?>" aria-hidden="true"></span>
      </div>
    </article>
  <?php endforeach; ?>
</section>

<section class="dashboard-charts" aria-label="تحليلات المنصة">
  <article class="card dashboard-chart-card dashboard-chart-wide">
    <h2 class="section-title">التقارير المرسلة عبر الزمن <small>حسب الفترة المحددة</small></h2>
    <div class="chart-wrap chart-loading"><canvas data-chart="sent" aria-label="مخطط التقارير المرسلة عبر الزمن"></canvas></div>
  </article>
  <article class="card dashboard-chart-card">
    <h2 class="section-title">توزيع الحالات</h2>
    <div class="chart-wrap chart-loading dashboard-doughnut-wrap"><canvas data-chart="status" aria-label="مخطط توزيع حالات التقارير"></canvas></div>
    <div class="dashboard-chart-legend" data-status-legend></div>
  </article>
  <article class="card dashboard-chart-card">
    <h2 class="section-title">ازدحام الحصص والأيام</h2>
    <div class="chart-wrap chart-loading dashboard-heatmap-wrap"><canvas data-chart="bookings" aria-label="خريطة حرارية للحجوزات حسب اليوم والحصة"></canvas></div>
  </article>
  <article class="card dashboard-chart-card">
    <h2 class="section-title">نسبة الاعتماد الشهرية</h2>
    <div class="chart-wrap chart-loading"><canvas data-chart="approval" aria-label="مخطط نسبة اعتماد التقارير شهريًا"></canvas></div>
  </article>
</section>
<?php endif; ?>

<?php if ($canReviewReports || $canViewAudit): ?><section class="card dashboard-pending-card">
  <?php if ($canReviewReports): ?>
  <div class="dashboard-section-heading">
    <div><span class="dashboard-eyebrow">تحتاج مراجعتك</span><h2>تقارير بانتظار الاعتماد</h2></div>
    <?php if (has_permission('reports.review')): ?><a class="link-button" href="<?= e(app_url('/admin/reports.php?status=submitted')) ?>">عرض كل التقارير <span aria-hidden="true">←</span></a><?php endif; ?>
  </div>
  <?php if ($pending === []): ?>
    <div class="dashboard-empty">
      <span class="dashboard-empty-art" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none"><rect x="14" y="10" width="36" height="44" rx="8"/><path d="M23 23h18M23 31h18M23 39h9"/><path d="m39 43 4 4 8-9"/></svg></span>
      <h3>كل شيء مراجع!</h3><p>لا توجد تقارير معلّقة حاليًا. ستظهر التقارير الجديدة هنا.</p>
    </div>
  <?php else: ?>
    <div class="dashboard-pending-list">
      <?php foreach ($pending as $report):
          preg_match('/^./u', (string) $report['author'], $initialMatch);
          $initial = $initialMatch[0] ?? '؟';
      ?>
        <article class="dashboard-pending-item">
          <span class="dashboard-avatar" aria-hidden="true"><?= e($initial) ?></span>
          <div class="dashboard-report-copy">
            <a class="dashboard-report-title" href="<?= e(app_url('/admin/report_view.php?id=' . $report['id'])) ?>"><?= e($report['title']) ?></a>
            <span><?= e($report['author']) ?> <span aria-hidden="true">·</span> <?= e(ROLE_LABELS[$report['role']] ?? '') ?></span>
          </div>
          <time class="dashboard-report-time" datetime="<?= e($report['submitted_at']) ?>" title="<?= e($report['submitted_at']) ?>"><?= e($timeAgo($report['submitted_at'])) ?></time>
          <div class="dashboard-quick-actions">
            <form method="post" action="<?= e(app_url('/api/report_actions.php')) ?>">
              <?= csrf_field() ?><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="dashboard">
              <button class="button button-success button-sm" type="submit" data-confirm="اعتماد هذا التقرير؟">اعتماد سريع</button>
            </form>
            <button class="button button-quiet button-sm dashboard-reject-trigger" type="button" data-dashboard-reject-trigger="<?= (int) $report['id'] ?>">رفض سريع</button>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($canViewAudit): ?>
  <div class="dashboard-activity">
    <div class="dashboard-section-heading">
      <div><span class="dashboard-eyebrow">ما الجديد؟</span><h2>آخر النشاطات</h2></div>
      <?php if (has_permission('audit.view')): ?><a class="link-button" href="<?= e(app_url('/admin/audit_logs.php')) ?>">سجل التدقيق <span aria-hidden="true">←</span></a><?php endif; ?>
    </div>
    <?php if ($activities === []): ?>
      <div class="dashboard-empty dashboard-empty-compact">
        <span class="dashboard-empty-art" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none"><circle cx="32" cy="32" r="21"/><path d="M32 19v14l9 5"/></svg></span>
        <h3>لا توجد نشاطات بعد</h3><p>سيظهر هنا سجل الإجراءات الأخيرة على المنصة.</p>
      </div>
    <?php else: ?>
      <ol class="dashboard-timeline">
        <?php foreach ($activities as $activity): ?>
          <li class="dashboard-timeline-item">
            <span class="dashboard-timeline-dot" aria-hidden="true"></span>
            <div><p><strong><?= e($activity['actor_name']) ?></strong> <?= e($activity['description']) ?></p><time datetime="<?= e($activity['created_at']) ?>" title="<?= e($activity['created_at']) ?>"><?= e($timeAgo($activity['created_at'])) ?></time></div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section><?php endif; ?>

<?php if ($canReviewReports): ?>
<dialog class="dashboard-reject-dialog" data-dashboard-reject-dialog aria-labelledby="dashboard-reject-title">
  <form method="post" action="<?= e(app_url('/api/report_actions.php')) ?>" class="dashboard-reject-form">
    <?= csrf_field() ?>
    <input type="hidden" name="report_id" value="" data-dashboard-reject-id>
    <input type="hidden" name="action" value="reject">
    <input type="hidden" name="return_to" value="dashboard">
    <h2 id="dashboard-reject-title">رفض التقرير</h2>
    <p>اكتبي سبب الرفض ليصل إلى كاتب التقرير.</p>
    <label for="dashboard-reject-note">سبب الرفض</label>
    <textarea id="dashboard-reject-note" name="admin_note" required minlength="3" rows="4"></textarea>
    <div class="dashboard-reject-actions">
      <button class="button button-quiet" type="button" data-dashboard-reject-close>إلغاء</button>
      <button class="button button-danger" type="submit" data-confirm="رفض التقرير وإرسال السبب إلى صاحبه؟">تأكيد الرفض</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
<?php render_page_end(); ?>
