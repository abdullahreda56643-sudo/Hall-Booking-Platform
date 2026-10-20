<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('reports.review');
$canExportReports = has_permission('reports.export');
$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$reportId) {
    http_response_code(400);
    exit('معرّف التقرير غير صالح.');
}
$statement = db()->prepare(
    'SELECT r.*, u.name AS author_name, u.username, u.role, reviewer.name AS reviewer_name
     FROM reports r JOIN users u ON u.id = r.user_id
     LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by WHERE r.id = ?'
);
$statement->execute([$reportId]);
$report = $statement->fetch();
if (!$report) {
    http_response_code(404);
    exit('التقرير غير موجود.');
}
$media = report_media(db(), $reportId);
$images = array_values(array_filter($media, static fn (array $item): bool => $item['type'] === 'image'));
$videos = array_values(array_filter($media, static fn (array $item): bool => $item['type'] === 'video'));
$files = array_values(array_filter($media, static fn (array $item): bool => $item['type'] === 'file'));
$canReview = $report['status'] === 'submitted';
$formatDate = static function (?string $value): string {
    return $value ? date('Y/m/d · H:i', strtotime($value)) : '—';
};
$formatSize = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return number_format(max(1, $bytes / 1024), 0) . ' KB';
};
$title = 'مراجعة التقرير';
render_page_start($title, true);
?>
<div class="report-review" data-report-review data-status="<?= e($report['status']) ?>">
  <div class="page-heading report-review-heading">
    <div>
      <a class="report-back-link" href="<?= e(app_url('/admin/reports.php')) ?>">← العودة إلى مركز التقارير</a>
      <h1>مراجعة التقرير</h1>
      <p>اقرئي المحتوى والمرفقات، ثم سجّلي قرار المراجعة.</p>
    </div>
  </div>

  <div class="report-review-grid">
    <main class="report-review-content" aria-label="محتوى التقرير">
      <section class="card report-paper report-enter">
        <div class="report-paper-heading">
          <span class="report-section-kicker">تقرير رقم <?= (int) $report['id'] ?></span>
          <span class="badge <?= e(status_badge_class($report['status'])) ?>" data-report-status><?= e(REPORT_STATUS_LABELS[$report['status']]) ?></span>
        </div>
        <h2 class="report-title"><?= e($report['title']) ?></h2>
        <?php if (($report['class_name'] ?? '') !== '' || $report['beneficiaries_count'] !== null): ?>
          <div class="report-detail-meta">
            <?php if (($report['class_name'] ?? '') !== ''): ?><span><small>الصف</small><strong><?= e($report['class_name']) ?></strong></span><?php endif; ?>
            <?php if ($report['beneficiaries_count'] !== null): ?><span><small>عدد المستفيدات</small><strong><?= (int) $report['beneficiaries_count'] ?></strong></span><?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="report-author-row">
          <span class="report-author-avatar" aria-hidden="true"><?= e(mb_substr($report['author_name'], 0, 1)) ?></span>
          <span class="report-author-copy">
            <strong><?= e($report['author_name']) ?></strong>
            <small><?= e(ROLE_LABELS[$report['role']] ?? '') ?></small>
          </span>
          <span class="report-meta-separator" aria-hidden="true"></span>
          <span class="report-submitted-meta">
            <small>تاريخ الإرسال</small>
            <time datetime="<?= e($report['submitted_at'] ?? $report['created_at']) ?>"><?= e($formatDate($report['submitted_at'])) ?></time>
          </span>
        </div>
        <div class="report-paper-rule"></div>
        <div class="report-reading-body"><?= nl2br(e($report['body'])) ?></div>
        <?php if (!empty($report['admin_note'])): ?>
          <div class="report-review-note">
            <strong><?= $report['status'] === 'rejected' ? 'سبب الرفض' : 'ملاحظة الإدارة' ?></strong>
            <p><?= nl2br(e($report['admin_note'])) ?></p>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($images !== []): ?>
        <section class="card report-media-section report-enter" style="--report-enter-delay:80ms">
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
        <section class="card report-media-section report-enter" style="--report-enter-delay:140ms">
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
        <section class="card report-media-section report-enter" style="--report-enter-delay:200ms">
          <div class="report-section-heading">
            <div><span class="report-section-kicker">ملفات التقرير</span><h2>المرفقات والوثائق</h2></div>
            <span class="report-section-count"><?= count($files) ?> ملفات</span>
          </div>
          <div class="report-attachment-list">
            <?php foreach ($files as $item):
                $href = app_url('/' . $item['file_path']);
                $extension = strtoupper(pathinfo($item['original_name'], PATHINFO_EXTENSION));
                $fileTone = $extension === 'PDF' ? 'pdf' : (in_array($extension, ['XLS', 'XLSX', 'CSV'], true) ? 'sheet' : 'document');
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
    </main>

    <aside class="report-review-sidebar" aria-label="إجراءات التقرير">
      <section class="card report-side-card report-decision-card report-enter" style="--report-enter-delay:60ms">
        <div class="report-side-heading">
          <span class="report-side-icon report-decision-icon" aria-hidden="true">✓</span>
          <div><span class="report-section-kicker">الإجراء المطلوب</span><h2>قرار الاعتماد</h2></div>
        </div>
        <p class="report-decision-intro" data-decision-intro>
          <?= $canReview ? 'راجعي التقرير بعناية قبل اعتماد القرار.' : 'تم تسجيل القرار النهائي لهذا التقرير.' ?>
        </p>
        <?php if ($report['status'] === 'approved'): ?>
          <div class="report-decision-result report-result-approved" data-decision-result>
            <span class="report-success-mark" aria-hidden="true"><svg viewBox="0 0 48 48"><path d="m13 25 7 7 15-16"/></svg></span>
            <span><strong>تم اعتماد التقرير</strong><small>قرار نهائي</small></span>
          </div>
        <?php elseif ($report['status'] === 'rejected'): ?>
          <div class="report-decision-result report-result-rejected" data-decision-result>
            <span class="report-result-symbol" aria-hidden="true">×</span>
            <span><strong>تم رفض التقرير</strong><small>قرار نهائي</small></span>
          </div>
        <?php endif; ?>
        <div class="report-decision-actions" data-decision-actions <?= $canReview ? '' : 'hidden' ?>>
          <form id="review-approve-form" method="post" action="<?= e(app_url('/api/report_actions.php')) ?>" data-report-decision>
            <?= csrf_field() ?>
            <input type="hidden" name="report_id" value="<?= $reportId ?>">
            <input type="hidden" name="action" value="approve">
            <button class="report-approve-button" type="submit" data-confirm="هل تريدين اعتماد هذا التقرير؟">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
              اعتماد التقرير
            </button>
          </form>
          <button class="report-reject-button" type="button" data-reject-dialog-open>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
            رفض التقرير
          </button>
        </div>
        <?php if ($report['status'] === 'approved' && $report['reviewer_name']): ?>
          <p class="report-reviewed-by">اعتمده <?= e($report['reviewer_name']) ?></p>
        <?php elseif ($report['status'] === 'rejected' && $report['reviewer_name']): ?>
          <p class="report-reviewed-by">راجعه <?= e($report['reviewer_name']) ?></p>
        <?php endif; ?>
      </section>

      <section class="card report-side-card report-timeline-card report-enter" style="--report-enter-delay:130ms">
        <div class="report-side-heading">
          <span class="report-side-icon report-timeline-icon" aria-hidden="true">◷</span>
          <div><span class="report-section-kicker">رحلة التقرير</span><h2>الخط الزمني</h2></div>
        </div>
        <ol class="report-timeline">
          <li class="is-complete">
            <span class="report-timeline-dot" aria-hidden="true"></span>
            <span><strong>أُنشئ التقرير</strong><time datetime="<?= e($report['created_at']) ?>"><?= e($formatDate($report['created_at'])) ?></time></span>
          </li>
          <li class="<?= $report['submitted_at'] ? 'is-complete' : '' ?>">
            <span class="report-timeline-dot" aria-hidden="true"></span>
            <span><strong>أُرسل للمراجعة</strong><time datetime="<?= e($report['submitted_at'] ?? '') ?>"><?= e($formatDate($report['submitted_at'])) ?></time></span>
          </li>
          <li class="<?= $report['reviewed_at'] ? 'is-complete' : '' ?>" data-reviewed-step>
            <span class="report-timeline-dot" aria-hidden="true"></span>
            <span><strong>تمت المراجعة</strong><time datetime="<?= e($report['reviewed_at'] ?? '') ?>" data-reviewed-at><?= e($formatDate($report['reviewed_at'])) ?></time></span>
          </li>
        </ol>
      </section>

      <?php if ($canExportReports): ?><section class="card report-side-card report-export-card report-enter" style="--report-enter-delay:200ms">
        <div class="report-side-heading">
          <span class="report-side-icon report-export-icon" aria-hidden="true">⇩</span>
          <div><span class="report-section-kicker">نسخة للاحتفاظ</span><h2>تصدير هذا التقرير</h2></div>
        </div>
        <div class="report-export-actions">
          <a href="<?= e(app_url('/exports/pdf.php?id=' . $reportId)) ?>">
            <span class="report-export-file report-export-pdf" aria-hidden="true">PDF</span><span><strong>تصدير PDF</strong><small>نسخة قابلة للطباعة</small></span><span class="report-export-arrow" aria-hidden="true">←</span>
          </a>
          <a href="<?= e(app_url('/exports/excel.php?id=' . $reportId)) ?>">
            <span class="report-export-file report-export-xlsx" aria-hidden="true">XLS</span><span><strong>تصدير Excel</strong><small>بيانات التقرير</small></span><span class="report-export-arrow" aria-hidden="true">←</span>
          </a>
        </div>
      </section><?php endif; ?>
    </aside>
  </div>

  <?php if ($canReview): ?>
    <div class="report-mobile-decision-bar" data-mobile-decision-bar>
      <button class="report-approve-button" type="submit" form="review-approve-form" data-confirm="هل تريدين اعتماد هذا التقرير؟">✓ اعتماد</button>
      <button class="report-reject-button" type="button" data-reject-dialog-open>رفض التقرير</button>
    </div>
  <?php endif; ?>

  <dialog class="report-lightbox" data-report-lightbox aria-label="عارض صور التقرير">
    <button class="report-lightbox-close" type="button" data-lightbox-close aria-label="إغلاق عارض الصور">×</button>
    <button class="report-lightbox-nav report-lightbox-prev" type="button" data-lightbox-prev aria-label="الصورة السابقة">‹</button>
    <figure><img src="" alt="" data-lightbox-image><figcaption data-lightbox-caption></figcaption></figure>
    <button class="report-lightbox-nav report-lightbox-next" type="button" data-lightbox-next aria-label="الصورة التالية">›</button>
    <span class="report-lightbox-counter" data-lightbox-counter></span>
  </dialog>

  <?php if ($canReview): ?>
    <dialog class="report-reject-dialog" data-report-reject-dialog aria-labelledby="report-reject-title">
      <form method="post" action="<?= e(app_url('/api/report_actions.php')) ?>" data-report-decision>
        <?= csrf_field() ?>
        <input type="hidden" name="report_id" value="<?= $reportId ?>">
        <input type="hidden" name="action" value="reject">
        <div class="report-reject-dialog-content">
          <button class="report-reject-dialog-close" type="button" data-reject-dialog-close aria-label="إغلاق">×</button>
          <span class="report-reject-dialog-icon" aria-hidden="true">!</span>
          <span class="report-section-kicker">قرار المراجعة</span>
          <h2 id="report-reject-title">رفض التقرير</h2>
          <p>اكتبي سببًا واضحًا ليتمكن صاحب التقرير من معرفة ما يحتاج إلى تعديل.</p>
          <label class="report-reject-label" for="report-reject-note">سبب الرفض <span>*</span></label>
          <textarea id="report-reject-note" name="admin_note" required minlength="3" maxlength="2000" rows="4" placeholder="اكتبي سبب الرفض هنا..." data-reject-note></textarea>
          <div class="report-reject-counter"><span data-reject-count>٠</span> / ٢٠٠٠ حرف</div>
          <button class="report-reject-submit" type="submit" data-confirm="تأكيد رفض التقرير وإرسال السبب إلى صاحبه؟">تأكيد الرفض</button>
        </div>
      </form>
    </dialog>
  <?php endif; ?>
</div>
<?php render_page_end(); ?>
