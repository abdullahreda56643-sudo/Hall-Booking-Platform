<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_role([ROLE_STAFF]);
require_permission('reports.view_own');
$canEditReports = has_permission('reports.create');
$canDeleteReports = has_permission('reports.delete');
$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$reportId) {
    http_response_code(400);
    exit('معرّف التقرير غير صالح.');
}
$pdo = db();
$statement = $pdo->prepare(
    'SELECT r.*, u.name AS author_name FROM reports r
     JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.user_id = ?'
);
$statement->execute([$reportId, current_user()['id']]);
$report = $statement->fetch();
if (!$report) {
    http_response_code(404);
    exit('التقرير غير موجود.');
}
$media = report_media($pdo, $reportId);
$images = array_values(array_filter($media, static fn (array $item): bool => $item['type'] === 'image'));
$videos = array_values(array_filter($media, static fn (array $item): bool => $item['type'] === 'video'));
$files = array_values(array_filter($media, static fn (array $item): bool => $item['type'] === 'file'));
$editable = in_array($report['status'], ['draft', 'rejected'], true);
$locked = in_array($report['status'], ['submitted', 'approved'], true);
$formatDate = static function (?string $value): string {
    return $value ? date('Y/m/d · H:i', strtotime($value)) : '—';
};
$formatSize = static function (int $bytes): string {
    return $bytes >= 1048576
        ? number_format($bytes / 1048576, 1) . ' MB'
        : number_format(max(1, $bytes / 1024), 0) . ' KB';
};
$title = 'عرض التقرير';
render_page_start($title, true);
?>
<div class="report-view" data-report-review data-status="<?= e($report['status']) ?>">
  <div class="page-heading report-view-heading">
    <div>
      <a class="report-back-link" href="<?= e(app_url('/user/reports.php')) ?>">← العودة إلى تقاريري</a>
      <h1>تفاصيل التقرير</h1>
      <p>محتوى التقرير ومراحل مراجعته.</p>
    </div>
    <div class="report-view-heading-actions">
      <?php if ($editable && $canEditReports): ?>
        <a class="button button-secondary" href="<?= e(app_url('/user/report_create.php?id=' . $reportId)) ?>">
          تعديل التقرير
        </a>
      <?php endif; ?>
      <?php if ($locked): ?>
        <span class="report-readonly-lock" aria-label="التقرير للقراءة فقط" title="التقرير للقراءة فقط">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/></svg>
          للقراءة فقط
        </span>
      <?php endif; ?>
    </div>
  </div>

  <section class="card report-paper report-view-paper report-enter">
    <div class="report-paper-heading">
      <span class="report-section-kicker">تقرير رقم <?= (int) $report['id'] ?></span>
      <span class="badge <?= e(status_badge_class($report['status'])) ?>"><?= e(REPORT_STATUS_LABELS[$report['status']] ?? $report['status']) ?></span>
    </div>
    <h2 class="report-title"><?= e($report['title']) ?></h2>
    <?php if (($report['class_name'] ?? '') !== '' || $report['beneficiaries_count'] !== null): ?>
      <div class="report-detail-meta">
        <?php if (($report['class_name'] ?? '') !== ''): ?><span><small>الصف</small><strong><?= e($report['class_name']) ?></strong></span><?php endif; ?>
        <?php if ($report['beneficiaries_count'] !== null): ?><span><small>عدد المستفيدات</small><strong><?= (int) $report['beneficiaries_count'] ?></strong></span><?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="report-view-meta">
      <span><small>تاريخ الإنشاء</small><time datetime="<?= e(date('c', strtotime($report['created_at']))) ?>"><?= e($formatDate($report['created_at'])) ?></time></span>
      <?php if ($report['submitted_at']): ?>
        <span><small>تاريخ الإرسال</small><time datetime="<?= e(date('c', strtotime($report['submitted_at']))) ?>"><?= e($formatDate($report['submitted_at'])) ?></time></span>
      <?php endif; ?>
    </div>
  </section>

  <section class="card report-status-card report-enter" style="--report-enter-delay:70ms" aria-label="مراحل التقرير">
    <div class="report-status-heading"><div><span class="report-section-kicker">رحلة التقرير</span><h2>حالة المراجعة</h2></div>
      <?php if ($locked): ?>
        <span class="report-readonly-label"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>مقفل للتعديل</span>
      <?php endif; ?>
    </div>
    <ol class="report-stepper report-stepper-<?= e($report['status']) ?>">
      <li class="report-step <?= in_array($report['status'], ['submitted', 'approved', 'rejected'], true) ? 'is-complete' : '' ?> <?= $report['status'] === 'draft' ? 'is-current' : '' ?>">
        <span class="report-step-marker"><span>1</span></span>
        <span class="report-step-copy"><strong>مسودة</strong><small><?= e($formatDate($report['created_at'])) ?></small></span>
      </li>
      <li class="report-step <?= in_array($report['status'], ['approved', 'rejected'], true) ? 'is-complete' : '' ?> <?= $report['status'] === 'submitted' ? 'is-current' : '' ?>">
        <span class="report-step-marker"><span>2</span></span>
        <span class="report-step-copy"><strong>مرسل للاعتماد</strong><small><?= e($formatDate($report['submitted_at'])) ?></small></span>
      </li>
      <li class="report-step <?= in_array($report['status'], ['approved', 'rejected'], true) ? 'is-complete is-current' : '' ?> report-step-final">
        <span class="report-step-marker"><span><?= $report['status'] === 'approved' ? '✓' : ($report['status'] === 'rejected' ? '×' : '3') ?></span></span>
        <span class="report-step-copy"><strong><?= $report['status'] === 'rejected' ? 'مرفوض' : 'معتمد' ?></strong><small><?= e($formatDate($report['reviewed_at'])) ?></small></span>
      </li>
    </ol>
  </section>

  <?php if ($report['status'] === 'rejected'): ?>
    <section class="report-rejection-card report-enter" style="--report-enter-delay:110ms">
      <div class="report-rejection-icon" aria-hidden="true">!</div>
      <div class="report-rejection-copy">
        <h2>يحتاج التقرير إلى تعديل</h2>
        <p><?= !empty($report['admin_note']) ? nl2br(e($report['admin_note'])) : 'لم تُرفق ملاحظة إضافية من الإدارة.' ?></p>
      </div>
      <?php if ($canEditReports): ?><a class="button report-rejection-button" href="<?= e(app_url('/user/report_create.php?id=' . $reportId)) ?>">تعديل وإعادة الإرسال <span aria-hidden="true">←</span></a><?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="card report-reading-card report-enter" style="--report-enter-delay:150ms">
    <div class="report-section-heading">
      <div><span class="report-section-kicker">محتوى التقرير</span><h2>النص الكامل</h2></div>
    </div>
    <div class="report-reading-body report-user-reading"><?= nl2br(e($report['body'])) ?></div>
  </section>

  <?php if ($images !== []): ?>
    <section class="card report-media-section report-enter" style="--report-enter-delay:190ms">
      <div class="report-section-heading">
        <div><span class="report-section-kicker">معرض الصور</span><h2>الصور المرفقة</h2></div>
        <span class="report-section-count"><?= count($images) ?> صور</span>
      </div>
      <div class="report-image-grid">
        <?php foreach ($images as $index => $item): $href = app_url('/' . $item['file_path']); ?>
          <button class="report-image-button" type="button" data-lightbox-open data-image-index="<?= $index ?>" data-image-src="<?= e($href) ?>" data-image-alt="<?= e($item['original_name']) ?>" aria-label="تكبير الصورة: <?= e($item['original_name']) ?>">
            <img src="<?= e($href) ?>" alt="<?= e($item['original_name']) ?>" loading="lazy">
            <span class="report-image-zoom" aria-hidden="true">تكبير الصورة</span>
          </button>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($videos !== []): ?>
    <section class="card report-media-section report-enter" style="--report-enter-delay:230ms">
      <div class="report-section-heading">
        <div><span class="report-section-kicker">مشاهدة المحتوى</span><h2>مقاطع الفيديو</h2></div>
        <span class="report-section-count"><?= count($videos) ?> مقاطع</span>
      </div>
      <div class="report-video-list">
        <?php foreach ($videos as $item): $href = app_url('/' . $item['file_path']); ?>
          <article class="report-video-card">
            <video controls preload="metadata" playsinline>
              <source src="<?= e($href) ?>" type="<?= e($item['mime_type']) ?>">
              المتصفح لا يدعم تشغيل الفيديو.
            </video>
            <div class="report-video-caption">
              <span class="report-file-icon report-file-video" aria-hidden="true">▶</span>
              <span><strong><?= e($item['original_name']) ?></strong><small><?= e($formatSize((int) $item['size'])) ?></small></span>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($files !== []): ?>
    <section class="card report-media-section report-enter" style="--report-enter-delay:270ms">
      <div class="report-section-heading">
        <div><span class="report-section-kicker">ملفات التقرير</span><h2>المرفقات والوثائق</h2></div>
        <span class="report-section-count"><?= count($files) ?> ملفات</span>
      </div>
      <div class="report-attachment-list">
        <?php foreach ($files as $item):
            $href = app_url('/' . $item['file_path']);
            $extension = strtoupper(pathinfo($item['original_name'], PATHINFO_EXTENSION));
            $fileTone = $extension === 'PDF' ? 'pdf' : ($extension === 'XLSX' ? 'sheet' : 'document');
        ?>
          <article class="report-attachment">
            <span class="report-file-icon report-file-<?= e($fileTone) ?>" aria-hidden="true"><?= e($extension ?: 'FILE') ?></span>
            <span class="report-attachment-copy"><strong title="<?= e($item['original_name']) ?>"><?= e($item['original_name']) ?></strong><small><?= e($extension ?: 'ملف') ?> · <?= e($formatSize((int) $item['size'])) ?></small></span>
            <a class="report-download-button" href="<?= e($href) ?>" download="<?= e($item['original_name']) ?>" aria-label="تنزيل <?= e($item['original_name']) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>
              <span>تنزيل</span>
            </a>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($editable && $canDeleteReports): ?>
    <div class="report-view-bottom-actions">
      <a class="button button-quiet" href="<?= e(app_url('/user/reports.php')) ?>">العودة إلى تقاريري</a>
      <form method="post" action="<?= e(app_url('/api/report_actions.php')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="report_id" value="<?= (int) $reportId ?>">
        <input type="hidden" name="action" value="delete">
        <button class="button button-danger" type="submit" data-confirm="حذف التقرير ومرفقاته نهائيًا؟ لا يمكن التراجع عن هذا الإجراء.">حذف التقرير</button>
      </form>
    </div>
  <?php endif; ?>

  <dialog class="report-lightbox" data-report-lightbox aria-label="عارض الصور">
    <button class="report-lightbox-close" type="button" data-lightbox-close aria-label="إغلاق">×</button>
    <button class="report-lightbox-nav report-lightbox-prev" type="button" data-lightbox-prev aria-label="الصورة السابقة">‹</button>
    <figure><img data-lightbox-image alt=""><figcaption data-lightbox-caption></figcaption></figure>
    <button class="report-lightbox-nav report-lightbox-next" type="button" data-lightbox-next aria-label="الصورة التالية">›</button>
    <span class="report-lightbox-counter" data-lightbox-counter></span>
  </dialog>
</div>
<?php render_page_end(); ?>
