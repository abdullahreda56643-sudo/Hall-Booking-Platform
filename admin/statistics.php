<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('statistics.view');
$title = 'الإحصائيات';
render_page_start($title, true);
$range = (string) ($_GET['range'] ?? '30d');
if (!in_array($range, ['7d', '30d', '90d', 'custom'], true)) {
    $range = '30d';
}
?>
<div class="page-heading"><div><h1>الإحصائيات والتحليلات</h1><p>تحليل التقارير والحجوزات خلال الفترة المختارة.</p></div></div>
<form method="get" class="filters card" data-chart-filter>
  <label class="filter-field">الفترة<select name="range" data-chart-range>
    <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>آخر 7 أيام</option>
    <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>آخر 30 يومًا</option>
    <option value="90d" <?= $range === '90d' ? 'selected' : '' ?>>آخر 3 شهور</option>
    <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>فترة مخصصة</option>
  </select></label>
  <label class="filter-field custom-date <?= $range === 'custom' ? '' : 'hidden' ?>">من تاريخ<input type="date" name="from" value="<?= e((string) ($_GET['from'] ?? '')) ?>" data-custom-from></label>
  <label class="filter-field custom-date <?= $range === 'custom' ? '' : 'hidden' ?>">إلى تاريخ<input type="date" name="to" value="<?= e((string) ($_GET['to'] ?? '')) ?>" data-custom-to></label>
  <button class="button button-secondary" type="submit">تطبيق</button>
  <span class="field-hint" data-period-label></span>
</form>
<div class="stats-grid" style="margin-top:17px">
  <?php foreach ([['total','إجمالي التقارير'],['submitted','بانتظار المراجعة'],['approved','معتمدة'],['rejected','مرفوضة']] as [$key,$label]): ?>
    <article class="card stat-card"><span class="stat-label"><?= e($label) ?></span><strong class="stat-value" data-chart-value="<?= e($key) ?>">—</strong><span class="stat-hint" data-chart-change="<?= e($key) ?>">مقارنة بالفترة السابقة</span></article>
  <?php endforeach; ?>
</div>
<div class="dashboard-grid">
  <section class="card card-pad"><h2 class="section-title">التقارير المرسلة عبر الزمن</h2><div class="chart-wrap"><canvas data-chart="sent"></canvas></div></section>
  <section class="card card-pad"><h2 class="section-title">توزيع التقارير حسب الحالة</h2><div class="chart-wrap"><canvas data-chart="status"></canvas></div></section>
  <section class="card card-pad"><h2 class="section-title">التقارير حسب المستخدم (أعلى 10)</h2><div class="chart-wrap"><canvas data-chart="users"></canvas></div></section>
  <section class="card card-pad"><h2 class="section-title">تقارير المعلمات حسب الحالة</h2><div class="chart-wrap"><canvas data-chart="roles"></canvas></div></section>
  <section class="card card-pad"><h2 class="section-title">ازدحام الأيام والحصص</h2><div class="chart-wrap"><canvas data-chart="bookings"></canvas></div></section>
  <section class="card card-pad"><h2 class="section-title">نسبة اعتماد التقارير شهريًا</h2><div class="chart-wrap"><canvas data-chart="approval"></canvas></div></section>
</div>
<?php render_page_end(); ?>
