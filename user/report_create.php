<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_role([ROLE_STAFF]);
$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
require_permission('reports.create', $isAjax);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_action'] ?? '') === 'submit') {
    require_permission('reports.submit', $isAjax);
}

$pdo = db();
$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
$report = [
    'title' => '',
    'class_name' => '',
    'beneficiaries_count' => '',
    'body' => '',
    'status' => 'draft',
    'admin_note' => null,
];
$error = '';
$allowedEdit = false;

if ($reportId) {
    $query = $pdo->prepare('SELECT * FROM reports WHERE id = ? AND user_id = ?');
    $query->execute([$reportId, current_user()['id']]);
    $found = $query->fetch();
    if (!$found) {
        http_response_code(404);
        exit('التقرير غير موجود.');
    }
    if (!in_array($found['status'], ['draft', 'rejected'], true)) {
        http_response_code(403);
        exit('لا يمكن تعديل تقرير تم إرساله أو اعتماده.');
    }
    $report = $found;
    $allowedEdit = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $report['title'] = trim((string) ($_POST['title'] ?? ''));
    $report['class_name'] = trim((string) ($_POST['class_name'] ?? ''));
    $beneficiariesCount = trim((string) ($_POST['beneficiaries_count'] ?? ''));
    $report['beneficiaries_count'] = $beneficiariesCount;
    $report['body'] = trim((string) ($_POST['body'] ?? ''));
    $action = (string) ($_POST['save_action'] ?? 'draft');
    $isAutosave = $action === 'autosave';
    $validAction = in_array($action, ['draft', 'submit', 'autosave'], true);

    if (!$validAction) {
        $error = 'إجراء الحفظ غير صالح.';
    } elseif (mb_strlen($report['class_name']) > 100) {
        $error = 'يجب ألا يتجاوز اسم الصف 100 حرف.';
    } elseif ($beneficiariesCount !== '' && (!ctype_digit($beneficiariesCount) || (float) $beneficiariesCount > 4294967295)) {
        $error = 'أدخلي عددًا صحيحًا غير سالب للمستفيدات.';
    } elseif (!$isAutosave && ($report['title'] === '' || mb_strlen($report['title']) > 200 || $report['body'] === '')) {
        $error = 'أدخلي عنوان التقرير ونصه. يجب ألا يتجاوز العنوان 200 حرف.';
    } elseif (mb_strlen($report['title']) > 200) {
        $error = 'يجب ألا يتجاوز عنوان التقرير 200 حرف.';
    } else {
        try {
            $pdo->beginTransaction();
            if ($reportId) {
                $lock = $pdo->prepare('SELECT status FROM reports WHERE id = ? AND user_id = ? FOR UPDATE');
                $lock->execute([$reportId, current_user()['id']]);
                $currentStatus = $lock->fetchColumn();
                if (!in_array($currentStatus, ['draft', 'rejected'], true)) {
                    throw new RuntimeException('تغيّرت حالة التقرير؛ أعد تحميل الصفحة قبل التعديل.');
                }

                $newStatus = $currentStatus;
                if (!$isAutosave) {
                    $newStatus = $action === 'submit' ? 'submitted' : 'draft';
                }
                $update = $pdo->prepare(
                    'UPDATE reports
                     SET title = :title, class_name = :class_name, beneficiaries_count = :beneficiaries_count,
                         body = :body, status = :status,
                         submitted_at = CASE WHEN :submitted_at_flag = 1 THEN NOW() ELSE submitted_at END,
                         admin_note = CASE WHEN :clear_note_flag = 1 THEN NULL ELSE admin_note END,
                         reviewed_by = CASE WHEN :clear_reviewer_flag = 1 THEN NULL ELSE reviewed_by END,
                         reviewed_at = CASE WHEN :clear_reviewed_at_flag = 1 THEN NULL ELSE reviewed_at END
                     WHERE id = :id AND user_id = :user_id AND status IN ("draft", "rejected")'
                );
                $update->execute([
                    'title' => $report['title'],
                    'class_name' => $report['class_name'] !== '' ? $report['class_name'] : null,
                    'beneficiaries_count' => $beneficiariesCount !== '' ? (int) $beneficiariesCount : null,
                    'body' => $report['body'],
                    'status' => $newStatus,
                    'submitted_at_flag' => !$isAutosave && $action === 'submit' ? 1 : 0,
                    'clear_note_flag' => !$isAutosave ? 1 : 0,
                    'clear_reviewer_flag' => !$isAutosave ? 1 : 0,
                    'clear_reviewed_at_flag' => !$isAutosave ? 1 : 0,
                    'id' => $reportId,
                    'user_id' => current_user()['id'],
                ]);
            } else {
                $status = !$isAutosave && $action === 'submit' ? 'submitted' : 'draft';
                $insert = $pdo->prepare(
                    'INSERT INTO reports (user_id, title, class_name, beneficiaries_count, body, status, submitted_at)
                     VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? = "submitted" THEN NOW() ELSE NULL END)'
                );
                $insert->execute([
                    current_user()['id'],
                    $report['title'],
                    $report['class_name'] !== '' ? $report['class_name'] : null,
                    $beneficiariesCount !== '' ? (int) $beneficiariesCount : null,
                    $report['body'],
                    $status,
                    $status,
                ]);
                $reportId = (int) $pdo->lastInsertId();
                $allowedEdit = false;
            }

            if (!$isAutosave) {
                audit_log(
                    $pdo,
                    $allowedEdit ? 'report_updated' : 'report_created',
                    'report',
                    (int) $reportId,
                    $allowedEdit ? 'تعديل تقرير' : 'إنشاء تقرير',
                    [
                        'title' => $report['title'],
                        'previous_status' => $allowedEdit ? $report['status'] : null,
                        'new_status' => $newStatus ?? $status,
                        'attachment_count' => 0,
                    ]
                );
            }
            if (!$isAutosave && $action === 'submit') {
                notify_permission_holders(
                    $pdo,
                    'reports.review',
                    'report',
                    $allowedEdit ? 'تقرير أُعيد إرساله للمراجعة' : 'تقرير جديد بانتظار المراجعة',
                    'أرسل ' . current_user()['name'] . ' التقرير: ' . $report['title'],
                    '/admin/report_view.php?id=' . $reportId,
                    'report',
                    (int) $reportId
                );
            }
            $pdo->commit();

            $saveRedirect = has_permission('reports.view_own')
                ? '/user/report_view.php?id=' . $reportId
                : ($action === 'submit' ? role_home(current_user()['role']) : '/user/report_create.php?id=' . $reportId);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'report_id' => $reportId,
                    'redirect' => $isAutosave ? null : app_url($saveRedirect),
                    'submitted' => !$isAutosave && $action === 'submit',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash('success', $action === 'submit' ? 'تم إرسال التقرير للاعتماد.' : 'تم حفظ التقرير كمسودة.');
            redirect($saveRedirect);
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        }
    }
    if ($isAjax) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$attachedMedia = $reportId ? report_media($pdo, (int) $reportId) : [];
$title = $allowedEdit ? 'تعديل التقرير' : 'كتابة تقرير';
render_page_start($title, true);
?>
<div class="page-heading report-create-heading">
  <div><h1><?= $allowedEdit ? 'تعديل التقرير' : 'كتابة تقرير جديد' ?></h1><p>اكتبي التقرير يدويًا بالكامل، واحفظيه كمسودة أو أرسليه للاعتماد.</p></div>
  <?php if (has_permission('reports.view_own')): ?><a class="button button-quiet" href="<?= e(app_url('/user/reports.php')) ?>">تقاريري</a><?php endif; ?>
</div>
<?php if ($error !== ''): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($report['admin_note'])): ?><div class="notice-box report-rejection-note"><strong>سبب الرفض:</strong> <?= e($report['admin_note']) ?></div><?php endif; ?>
<form method="post" action="<?= e(app_url('/user/report_create.php' . ($reportId ? '?id=' . $reportId : ''))) ?>" data-report-form data-report-editor data-report-id="<?= $reportId ? (int) $reportId : '' ?>" data-upload-url="<?= e(app_url('/api/report_media.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="report_id" value="<?= $reportId ? (int) $reportId : '' ?>" data-report-id-input>
  <section class="card report-editor-card">
    <div class="report-writing-panel">
      <div class="report-section-heading">
        <span class="report-section-icon report-writing-icon" aria-hidden="true">✎</span>
        <div><h2>نص التقرير</h2><p>اكتبي تفاصيلك بنفسك، ويمكنك تعديل المسودة في أي وقت.</p></div>
        <span class="report-save-indicator" data-save-status role="status"><i></i>لم يبدأ الحفظ</span>
      </div>
      <label class="sr-only" for="title">عنوان التقرير</label>
      <input class="report-title-input" id="title" name="title" maxlength="200" required value="<?= e($report['title']) ?>" placeholder="عنوان التقرير...">
      <div class="report-extra-fields">
        <label>الصف
          <input type="text" name="class_name" maxlength="100" value="<?= e($report['class_name'] ?? '') ?>" placeholder="مثال: الصف الأول">
        </label>
        <label>عدد المستفيدات
          <input type="number" name="beneficiaries_count" min="0" step="1" value="<?= e((string) ($report['beneficiaries_count'] ?? '')) ?>" placeholder="مثال: 25">
        </label>
      </div>
      <div class="report-body-wrap">
        <div class="report-toolbar" role="toolbar" aria-label="تنسيق النص">
          <button type="button" data-editor-action="bold" aria-label="إحاطة النص المحدد بعلامتي إبراز"><strong>B</strong></button>
          <span></span>
          <button type="button" data-editor-action="list" aria-label="إضافة تعداد نقطي">• قائمة</button>
          <button type="button" data-editor-action="heading" aria-label="إضافة عنوان فرعي">عنوان فرعي</button>
        </div>
        <label class="sr-only" for="body">نص التقرير</label>
        <textarea class="report-body-input" id="body" name="body" required placeholder="اكتبي تفاصيل التقرير هنا..."><?= e($report['body']) ?></textarea>
        <div class="report-editor-footer"><span>المحتوى يُكتب يدويًا ولا يتم توليده تلقائيًا.</span><span><b data-character-count>0</b> حرف</span></div>
      </div>
    </div>
    <aside class="report-media-panel">
      <div class="report-section-heading">
        <span class="report-section-icon report-media-icon" aria-hidden="true">⌁</span>
        <div><h2>المرفقات</h2><p>أضيفي الصور أو الفيديو أو الملفات.</p></div>
      </div>
      <div class="report-media-count" data-media-count>0 من 10 ملفات</div>
      <?php
      $uploadZones = [
          ['image', 'الصور', 'jpg، png، webp · حتى 5MB', 'image/*', '▧', 'report-zone-images'],
          ['video', 'الفيديو', 'mp4، webm · حتى 50MB', 'video/mp4,video/webm', '▶', 'report-zone-videos'],
          ['file', 'الملفات', 'pdf، docx، xlsx · حتى 10MB', '.pdf,.docx,.xlsx', '⌕', 'report-zone-files'],
      ];
      foreach ($uploadZones as [$type, $label, $help, $accept, $icon, $zoneClass]):
      ?>
        <section class="report-upload-group <?= e($zoneClass) ?>" data-upload-group="<?= e($type) ?>">
          <div class="report-upload-heading"><span aria-hidden="true"><?= e($icon) ?></span><div><strong><?= e($label) ?></strong><small><?= e($help) ?></small></div></div>
          <div class="report-dropzone" data-dropzone="<?= e($type) ?>" tabindex="0" role="button" aria-label="اسحبي <?= e($label) ?> هنا أو اختاريها">
            <input type="file" accept="<?= e($accept) ?>" multiple data-file-input="<?= e($type) ?>" aria-label="اختيار <?= e($label) ?>">
            <span class="report-dropzone-icon" aria-hidden="true">＋</span>
            <span><strong>اسحبي الملفات هنا</strong><small>أو اضغطي للاختيار</small></span>
          </div>
          <div class="report-file-list" data-file-list="<?= e($type) ?>">
            <?php foreach ($attachedMedia as $media): if ($media['type'] !== $type) continue; ?>
              <article class="report-file-item" draggable="true" data-existing-media="<?= (int) $media['id'] ?>" data-media-type="<?= e($media['type']) ?>" data-media-path="<?= e(app_url('/' . $media['file_path'])) ?>">
                <?php if ($type === 'image'): ?><img class="report-file-thumbnail" src="<?= e(app_url('/' . $media['file_path'])) ?>" alt="">
                <?php elseif ($type === 'video'): ?><span class="report-file-thumbnail report-video-thumbnail" aria-hidden="true">▶</span>
                <?php else: ?><span class="report-file-thumbnail report-document-thumbnail" aria-hidden="true">PDF</span><?php endif; ?>
                <span class="report-file-info"><strong><?= e($media['original_name']) ?></strong><small><?= e(number_format((int) $media['size'] / 1048576, 2)) ?> MB · مرفق محفوظ</small></span>
                <button class="report-file-delete" type="button" data-delete-media aria-label="حذف <?= e($media['original_name']) ?>">×</button>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
      <p class="report-upload-hint" data-upload-message role="status">الحد الأقصى 10 ملفات إجمالًا. اسحبي المرفقات لإعادة ترتيبها.</p>
    </aside>
  </section>
  <div class="report-action-bar">
    <span class="report-save-indicator" data-save-status-bottom aria-live="polite"><i></i>لم يبدأ الحفظ</span>
    <div>
      <button class="button button-secondary" type="submit" name="save_action" value="draft">حفظ كمسودة</button>
      <?php if (has_permission('reports.submit')): ?><button class="button button-primary report-submit-button" type="submit" name="save_action" value="submit">إرسال للاعتماد <span aria-hidden="true">←</span></button><?php endif; ?>
    </div>
  </div>
</form>
<dialog class="report-confirm-dialog" data-submit-dialog>
  <div class="report-confirm-symbol" aria-hidden="true">!</div>
  <h2>تأكيد إرسال التقرير</h2>
  <p>سيُرسل التقرير للاعتماد، وبعد الإرسال سيُقفل التعديل عليه. هل تريدين المتابعة؟</p>
  <div><button class="button button-quiet" type="button" data-submit-cancel>مراجعة التقرير</button><button class="button button-primary" type="button" data-submit-confirm>تأكيد الإرسال</button></div>
</dialog>
<dialog class="report-submitted-dialog" data-submitted-dialog>
  <span class="report-submitted-check" aria-hidden="true">✓</span><h2>تم إرسال التقرير</h2><p>أُرسل للاعتماد بنجاح، وتم إقفال التعديل.</p>
</dialog>
<script src="<?= e(app_url('/assets/js/report-editor.js?v=20261010-report-fields')) ?>" defer></script>
<?php render_page_end(); ?>
