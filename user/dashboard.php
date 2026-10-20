<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_role([ROLE_STAFF]);
if (!has_permission('reports.view_own')) {
    redirect(role_home(current_user()['role']));
}
$canCreateReports = has_permission('reports.create');

$pdo = db();
$user = current_user();
$counts = array_fill_keys(REPORT_STATUSES, 0);
$countStatement = $pdo->prepare('SELECT status, COUNT(*) AS total FROM reports WHERE user_id = ? GROUP BY status');
$countStatement->execute([$user['id']]);
foreach ($countStatement->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['total'];
}

$latestStatement = $pdo->prepare(
    'SELECT id, title, status, updated_at FROM reports WHERE user_id = ? ORDER BY updated_at DESC LIMIT 6'
);
$latestStatement->execute([$user['id']]);
$latest = $latestStatement->fetchAll();

$rejectedStatement = $pdo->prepare(
    'SELECT id, title, admin_note FROM reports WHERE user_id = ? AND status = "rejected" ORDER BY updated_at DESC LIMIT 1'
);
$rejectedStatement->execute([$user['id']]);
$rejected = $rejectedStatement->fetch();

$currentMonth = new DateTimeImmutable('first day of this month');
$firstMonth = $currentMonth->modify('-5 months');
$trendStatement = $pdo->prepare(
    'SELECT DATE_FORMAT(created_at, "%Y-%m") AS month_key, COUNT(*) AS total
     FROM reports WHERE user_id = ? AND created_at >= ? AND created_at < ?
     GROUP BY DATE_FORMAT(created_at, "%Y-%m")'
);
$trendStatement->execute([
    $user['id'],
    $firstMonth->format('Y-m-d 00:00:00'),
    $currentMonth->modify('+1 month')->format('Y-m-d 00:00:00'),
]);
$monthlyCounts = [];
foreach ($trendStatement->fetchAll() as $row) {
    $monthlyCounts[$row['month_key']] = (int) $row['total'];
}

$monthNames = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
$monthLabels = [];
$monthValues = [];
for ($month = $firstMonth; $month <= $currentMonth; $month = $month->modify('+1 month')) {
    $key = $month->format('Y-m');
    $monthLabels[] = $monthNames[(int) $month->format('n')];
    $monthValues[] = $monthlyCounts[$key] ?? 0;
}

$dashboardCharts = [
    'statuses' => array_values($counts),
    'months' => $monthLabels,
    'monthlyCounts' => $monthValues,
];
$title = 'لوحة التحكم';
render_page_start($title, true);
?>
<div class="staff-dashboard" data-staff-dashboard data-chart-data="<?= e(json_encode($dashboardCharts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)) ?>">
  <div class="page-heading staff-dashboard-heading dashboard-reveal">
    <div>
      <span class="staff-dashboard-eyebrow">مساحة تقاريرك</span>
      <h1>مرحبًا، <?= e($user['name']) ?></h1>
      <p>دورك: <?= e(ROLE_LABELS[$user['role']] ?? '') ?> · تابعي تقاريرك وابدئي بإنجاز جديد.</p>
    </div>
    <?php if ($canCreateReports): ?><a class="button button-primary staff-new-report" href="<?= e(app_url('/user/report_create.php')) ?>">
      <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8m-4-4h8"/></svg>
      تقرير جديد
    </a><?php endif; ?>
  </div>

  <?php if ($rejected): ?>
    <section class="staff-rejected-alert dashboard-reveal" aria-label="تنبيه تقرير مرفوض">
      <span class="staff-alert-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01"/><path d="M10.3 3.9 2.6 17.2A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.8L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
      </span>
      <div class="staff-alert-copy">
        <strong>يحتاج هذا التقرير إلى تعديل</strong>
        <span class="staff-alert-title"><?= e($rejected['title']) ?></span>
        <p><b>سبب الرفض:</b> <?= e($rejected['admin_note'] ?: 'راجعي التقرير وعدّليه قبل إعادة إرساله.') ?></p>
      </div>
      <a class="button staff-alert-action" href="<?= e(app_url('/user/report_create.php?id=' . $rejected['id'])) ?>">
        تعديل وإعادة الإرسال
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M7 17 17 7M7 7h10v10"/></svg>
      </a>
    </section>
  <?php endif; ?>

  <section class="stats-grid staff-stats-grid" aria-label="ملخص التقارير">
    <?php
    $statusCards = [
        'draft' => ['مسودات', 'staff-stat-slate'],
        'submitted' => ['مرسلة', 'staff-stat-amber'],
        'approved' => ['معتمدة', 'staff-stat-green'],
        'rejected' => ['مرفوضة', 'staff-stat-rose'],
    ];
    foreach ($statusCards as $status => [$label, $tone]):
    ?>
      <article class="card staff-stat-card dashboard-reveal <?= e($tone) ?>">
        <span class="staff-stat-icon" aria-hidden="true">
          <?php if ($status === 'draft'): ?>
            <svg viewBox="0 0 24 24"><path d="M12 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10"/><path d="M16 3h5v5m-9 7 8-8 3 3-8 8-4 1 1-4Z"/></svg>
          <?php elseif ($status === 'submitted'): ?>
            <svg viewBox="0 0 24 24"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
          <?php elseif ($status === 'approved'): ?>
            <svg viewBox="0 0 24 24"><path d="M21.8 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m9 11 3 3L22 4"/></svg>
          <?php else: ?>
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6m0-6 6 6"/></svg>
          <?php endif; ?>
        </span>
        <span class="staff-stat-label"><?= e($label) ?></span>
        <strong class="staff-stat-value" data-dashboard-count="<?= (int) $counts[$status] ?>"><?= (int) $counts[$status] ?></strong>
        <span class="staff-stat-hint">إجمالي تقاريرك</span>
        <span class="staff-stat-decoration" aria-hidden="true"><?= e(sprintf('%02d', $counts[$status])) ?></span>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="staff-charts-grid" aria-label="إحصاءات التقارير">
    <article class="card staff-chart-card dashboard-reveal">
      <div class="staff-section-heading">
        <div><span class="staff-section-kicker">نظرة سريعة</span><h2>توزيع الحالات</h2></div>
        <span class="staff-chart-mark" aria-hidden="true">◌</span>
      </div>
      <div class="staff-donut-wrap">
        <canvas data-staff-chart="statuses" role="img" aria-label="رسم دائري يوضح توزيع تقاريرك حسب الحالة"></canvas>
        <span class="staff-donut-total"><strong><?= array_sum($counts) ?></strong><small>كل التقارير</small></span>
      </div>
      <div class="staff-chart-legend" aria-hidden="true">
        <?php foreach ($statusCards as $status => [$label, $tone]): ?>
          <span class="<?= e($tone) ?>"><i></i><?= e($label) ?><b><?= (int) $counts[$status] ?></b></span>
        <?php endforeach; ?>
      </div>
    </article>
    <article class="card staff-chart-card staff-trend-card dashboard-reveal">
      <div class="staff-section-heading">
        <div><span class="staff-section-kicker">نشاطك مؤخرًا</span><h2>تقاريري في آخر 6 شهور</h2></div>
        <span class="staff-trend-mark" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 14 4-4 4 4 6-7"/></svg>
        </span>
      </div>
      <div class="staff-trend-wrap">
        <canvas data-staff-chart="monthly" role="img" aria-label="رسم أعمدة لعدد التقارير التي أنشأتها خلال آخر ستة أشهر"></canvas>
      </div>
    </article>
  </section>

  <section class="card staff-latest-card dashboard-reveal">
    <div class="staff-latest-heading">
      <div><span class="staff-section-kicker">متابعة سهلة</span><h2>آخر تقاريري</h2></div>
      <a class="staff-all-reports" href="<?= e(app_url('/user/reports.php')) ?>">عرض كل التقارير <span aria-hidden="true">←</span></a>
    </div>
    <?php if ($latest === []): ?>
      <div class="staff-empty-state">
        <span class="staff-empty-art" aria-hidden="true">
          <svg viewBox="0 0 64 64"><rect x="13" y="9" width="38" height="46" rx="8"/><path d="M23 23h18M23 31h18m-18 8h9"/><path d="M45 43v12m-6-6h12"/></svg>
        </span>
        <h3>مساحتك جاهزة لأول إنجاز</h3>
        <p>اكتبي تقريرك الأول واحتفظي به كمسودة أو أرسليه مباشرةً للاعتماد.</p>
        <?php if ($canCreateReports): ?><a class="button button-primary" href="<?= e(app_url('/user/report_create.php')) ?>">
          <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8m-4-4h8"/></svg>
          اكتبي أول تقرير
        </a><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="staff-report-list">
        <?php foreach ($latest as $index => $report): ?>
          <a class="staff-report-item" href="<?= e(app_url('/user/report_view.php?id=' . $report['id'])) ?>">
            <span class="staff-report-symbol" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6m-12 5h8m-8 4h8"/></svg>
            </span>
            <span class="staff-report-copy"><strong><?= e($report['title']) ?></strong><small>آخر تحديث · <?= e(date('Y/m/d', strtotime($report['updated_at']))) ?></small></span>
            <span class="badge <?= e(status_badge_class($report['status'])) ?>"><?= e(REPORT_STATUS_LABELS[$report['status']]) ?></span>
            <svg class="staff-report-arrow" aria-hidden="true" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php render_page_end(); ?>
