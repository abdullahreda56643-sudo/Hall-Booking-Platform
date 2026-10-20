<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role([ROLE_ADMIN]);

$pdo = db();
$defaults = [
    'section_title' => 'عن قاعة التعلم الرقمية',
    'section_intro' => 'تعرفي على تاريخ القاعة وأهدافها والتقنيات المستخدمة فيها.',
    'opened_on' => '',
    'purpose' => '',
    'technologies' => '',
    'contributors' => '',
    'is_visible' => 1,
];
$profileStatement = $pdo->prepare('SELECT * FROM hall_profile WHERE id = 1');
$profileStatement->execute();
$profile = $profileStatement->fetch() ?: $defaults;
$evidenceStatement = $pdo->query('SELECT * FROM hall_evidence ORDER BY sort_order, id');
$hallEvidence = $evidenceStatement->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'save_profile');
    if ($action === 'save_profile') {
        $profile = $defaults;
        foreach (['section_title', 'section_intro', 'opened_on', 'purpose', 'technologies', 'contributors'] as $field) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value)) {
                $error = 'تحققي من صحة البيانات المدخلة.';
                continue;
            }
            $profile[$field] = $value;
        }
        $profile['section_title'] = trim((string) $profile['section_title']);
        $profile['section_intro'] = trim((string) $profile['section_intro']);
        $profile['is_visible'] = ($_POST['is_visible'] ?? '') === '1' ? 1 : 0;

        if ($error === '' && $profile['section_title'] === '') {
            $error = 'عنوان القسم مطلوب.';
        } elseif ($error === '' && mb_strlen($profile['section_title']) > 150) {
            $error = 'يجب ألا يتجاوز عنوان القسم 150 حرفًا.';
        } elseif ($error === '' && mb_strlen($profile['section_intro']) > 500) {
            $error = 'يجب ألا يتجاوز وصف القسم 500 حرف.';
        } elseif ($error === '' && $profile['opened_on'] !== '' && !valid_date($profile['opened_on'])) {
            $error = 'تاريخ افتتاح القاعة غير صالح.';
        } elseif ($error === '' && mb_strlen($profile['purpose']) > 5000) {
            $error = 'يجب ألا يتجاوز الهدف من القاعة 5000 حرف.';
        } elseif ($error === '' && mb_strlen($profile['technologies']) > 5000) {
            $error = 'يجب ألا يتجاوز محتوى التقنيات 5000 حرف.';
        } elseif ($error === '' && mb_strlen($profile['contributors']) > 5000) {
            $error = 'يجب ألا يتجاوز محتوى المساهمين 5000 حرف.';
        }

        if ($error === '') {
            $save = $pdo->prepare(
                'INSERT INTO hall_profile (id, section_title, section_intro, opened_on, purpose, technologies, contributors, is_visible)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   section_title = VALUES(section_title),
                   section_intro = VALUES(section_intro),
                   opened_on = VALUES(opened_on),
                   purpose = VALUES(purpose),
                   technologies = VALUES(technologies),
                   contributors = VALUES(contributors),
                   is_visible = VALUES(is_visible)'
            );
            $save->execute([
                $profile['section_title'],
                $profile['section_intro'],
                $profile['opened_on'] === '' ? null : $profile['opened_on'],
                $profile['purpose'],
                $profile['technologies'],
                $profile['contributors'],
                $profile['is_visible'],
            ]);
            flash('success', 'تم حفظ تعريف القاعة.');
            redirect('/admin/hall.php');
        }
    } elseif ($action === 'upload_evidence') {
        $evidenceTitle = $_POST['evidence_title'] ?? '';
        $evidenceDescription = $_POST['evidence_description'] ?? '';
        $evidenceTitle = is_string($evidenceTitle) ? trim($evidenceTitle) : '';
        $evidenceDescription = is_string($evidenceDescription) ? trim($evidenceDescription) : '';
        $image = $_FILES['evidence_image'] ?? [];
        if ($evidenceTitle === '' || mb_strlen($evidenceTitle) > 180) {
            $error = 'عنوان الصورة مطلوب، ويجب ألا يتجاوز 180 حرفًا.';
        } elseif (mb_strlen($evidenceDescription) > 3000) {
            $error = 'يجب ألا يتجاوز وصف الصورة 3000 حرف.';
        } elseif (count($hallEvidence) >= 20) {
            $error = 'تم بلوغ الحد الأقصى لمعرض الشواهد (20 صورة).';
        } elseif (!isset($image['error']) || (int) $image['error'] !== UPLOAD_ERR_OK) {
            $uploadError = (int) ($image['error'] ?? UPLOAD_ERR_NO_FILE);
            $error = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'حجم الصورة يتجاوز الحد المسموح به.'
                : 'اختاري صورة صالحة ثم أعيدي المحاولة.';
        } else {
            $temporaryPath = (string) ($image['tmp_name'] ?? '');
            $size = (int) ($image['size'] ?? 0);
            $extensionByMime = [
                'image/jpeg' => ['extension' => 'jpg', 'source_extensions' => ['jpg', 'jpeg']],
                'image/png' => ['extension' => 'png', 'source_extensions' => ['png']],
                'image/webp' => ['extension' => 'webp', 'source_extensions' => ['webp']],
            ];
            if ($size <= 0 || $size > MAX_IMAGE_SIZE || !is_uploaded_file($temporaryPath)) {
                $error = 'الصورة غير صالحة أو تجاوزت الحد الأقصى للحجم (5 ميجابايت).';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
                $sourceExtension = strtolower(pathinfo((string) ($image['name'] ?? ''), PATHINFO_EXTENSION));
                $fileType = $extensionByMime[$mime] ?? null;
                if ($fileType === null || !in_array($sourceExtension, $fileType['source_extensions'], true)) {
                    $error = 'يسمح برفع الصور بصيغ JPG وPNG وWEBP فقط.';
                } else {
                    $imageDirectory = UPLOAD_DIR . DIRECTORY_SEPARATOR . 'images';
                    if (!is_dir($imageDirectory) && !mkdir($imageDirectory, 0750, true) && !is_dir($imageDirectory)) {
                        throw new RuntimeException('تعذر تجهيز مجلد حفظ الصور.');
                    }
                    $filename = bin2hex(random_bytes(20)) . '.' . $fileType['extension'];
                    $absolutePath = $imageDirectory . DIRECTORY_SEPARATOR . $filename;
                    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
                        throw new RuntimeException('تعذر حفظ الصورة المرفوعة.');
                    }
                    try {
                        $pdo->beginTransaction();
                        $nextPosition = $pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM hall_evidence');
                        $saveEvidence = $pdo->prepare('INSERT INTO hall_evidence (title, description, image_path, sort_order) VALUES (?, ?, ?, ?)');
                        $saveEvidence->execute([$evidenceTitle, $evidenceDescription, 'uploads/images/' . $filename, (int) $nextPosition->fetchColumn()]);
                        audit_log($pdo, 'hall_evidence_uploaded', 'hall_evidence', (int) $pdo->lastInsertId(), 'إضافة صورة إلى شواهد القاعة', ['title' => $evidenceTitle]);
                        $pdo->commit();
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        if (is_file($absolutePath) && !unlink($absolutePath)) {
                            error_log('تعذر تنظيف صورة الشواهد بعد فشل حفظها: ' . $absolutePath);
                        }
                        throw $exception;
                    }
                    flash('success', 'تمت إضافة الصورة إلى شواهد القاعة.');
                    redirect('/admin/hall.php#hall-evidence-list');
                }
            }
        }
    } elseif ($action === 'update_evidence') {
        $evidenceId = filter_input(INPUT_POST, 'evidence_id', FILTER_VALIDATE_INT);
        $evidenceTitle = $_POST['evidence_title'] ?? '';
        $evidenceDescription = $_POST['evidence_description'] ?? '';
        $evidenceTitle = is_string($evidenceTitle) ? trim($evidenceTitle) : '';
        $evidenceDescription = is_string($evidenceDescription) ? trim($evidenceDescription) : '';
        if (!$evidenceId || $evidenceTitle === '' || mb_strlen($evidenceTitle) > 180 || mb_strlen($evidenceDescription) > 3000) {
            $error = 'تحققي من العنوان والوصف قبل حفظ بيانات الصورة.';
        } else {
            $pdo->beginTransaction();
            try {
                $updateEvidence = $pdo->prepare('UPDATE hall_evidence SET title = ?, description = ? WHERE id = ?');
                $updateEvidence->execute([$evidenceTitle, $evidenceDescription, $evidenceId]);
                if ($updateEvidence->rowCount() > 0) {
                    audit_log($pdo, 'hall_evidence_updated', 'hall_evidence', (int) $evidenceId, 'تعديل بيانات صورة من شواهد القاعة');
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            flash('success', 'تم حفظ بيانات الصورة.');
            redirect('/admin/hall.php#hall-evidence-list');
        }
    } elseif ($action === 'delete_evidence') {
        $evidenceId = filter_input(INPUT_POST, 'evidence_id', FILTER_VALIDATE_INT);
        $findEvidence = $pdo->prepare('SELECT image_path FROM hall_evidence WHERE id = ?');
        $findEvidence->execute([$evidenceId ?: 0]);
        $evidence = $findEvidence->fetch();
        if (!$evidenceId || !$evidence) {
            $error = 'الصورة المحددة غير موجودة.';
        } else {
            $pdo->beginTransaction();
            try {
                $deleteEvidence = $pdo->prepare('DELETE FROM hall_evidence WHERE id = ?');
                $deleteEvidence->execute([$evidenceId]);
                audit_log($pdo, 'hall_evidence_deleted', 'hall_evidence', (int) $evidenceId, 'حذف صورة من شواهد القاعة');
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            $relativePath = (string) $evidence['image_path'];
            $imageDirectory = realpath(UPLOAD_DIR . DIRECTORY_SEPARATOR . 'images');
            $absolutePath = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $resolvedPath = realpath($absolutePath);
            $isManagedImage = preg_match('~^uploads/images/[a-f0-9]{40}\.(?:jpg|png|webp)$~D', $relativePath) === 1
                && $imageDirectory !== false
                && $resolvedPath !== false
                && dirname($resolvedPath) === $imageDirectory;
            if ($isManagedImage && is_file($resolvedPath) && !unlink($resolvedPath)) {
                error_log('تعذر حذف صورة الشواهد من القرص: ' . $absolutePath);
                flash('error', 'حُذفت الصورة من الموقع، لكن تعذر حذف الملف من الخادم. راجعي سجل الخادم.');
            } else {
                flash('success', 'تم حذف الصورة من شواهد القاعة.');
            }
            redirect('/admin/hall.php#hall-evidence-list');
        }
    } elseif ($action === 'move_evidence') {
        $evidenceId = filter_input(INPUT_POST, 'evidence_id', FILTER_VALIDATE_INT);
        $direction = (string) ($_POST['direction'] ?? '');
        if (!$evidenceId || !in_array($direction, ['up', 'down'], true)) {
            $error = 'طلب تغيير ترتيب الصورة غير صالح.';
        } else {
            $ids = array_map(static fn (array $item): int => (int) $item['id'], $hallEvidence);
            $position = array_search((int) $evidenceId, $ids, true);
            $neighborPosition = $position === false ? false : $position + ($direction === 'up' ? -1 : 1);
            if ($position === false || !isset($ids[$neighborPosition])) {
                $error = 'لا يمكن نقل الصورة في هذا الاتجاه.';
            } else {
                [$ids[$position], $ids[$neighborPosition]] = [$ids[$neighborPosition], $ids[$position]];
                $pdo->beginTransaction();
                try {
                    $reorder = $pdo->prepare('UPDATE hall_evidence SET sort_order = ? WHERE id = ?');
                    foreach ($ids as $order => $id) {
                        $reorder->execute([$order, $id]);
                    }
                    audit_log($pdo, 'hall_evidence_reordered', 'hall_evidence', (int) $evidenceId, 'تغيير ترتيب صور شواهد القاعة');
                    $pdo->commit();
                    flash('success', 'تم تحديث ترتيب الصور.');
                    redirect('/admin/hall.php#hall-evidence-list');
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }
            }
        }
    } else {
        http_response_code(400);
        exit('طلب غير صالح.');
    }
}

$title = 'تعريف القاعة';
render_page_start($title, true);
?>
<div class="page-heading">
  <div><h1>تعريف القاعة</h1><p>تحكمي في المعلومات التي تظهر عن القاعة في الصفحة الرئيسية.</p></div>
  <a class="button button-quiet" href="<?= e(app_url('/index.php#hall-profile-title')) ?>">معاينة الصفحة الرئيسية</a>
</div>

<?php if ($error !== ''): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>

<form class="card form-card hall-admin-form" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_profile">
  <div class="form-grid">
    <div class="field">
      <label for="hall-section-title">عنوان القسم</label>
      <input id="hall-section-title" name="section_title" type="text" maxlength="150" required value="<?= e((string) $profile['section_title']) ?>">
    </div>
    <div class="field">
      <label for="hall-opening-date">تاريخ افتتاح القاعة</label>
      <input id="hall-opening-date" name="opened_on" type="date" value="<?= e((string) ($profile['opened_on'] ?? '')) ?>">
    </div>
    <div class="field field-full">
      <label for="hall-section-intro">وصف مختصر</label>
      <input id="hall-section-intro" name="section_intro" type="text" maxlength="500" value="<?= e((string) $profile['section_intro']) ?>">
    </div>
    <div class="field field-full">
      <label for="hall-purpose">الهدف من القاعة</label>
      <textarea id="hall-purpose" name="purpose" maxlength="5000" rows="5"><?= e((string) $profile['purpose']) ?></textarea>
    </div>
    <div class="field">
      <label for="hall-technologies">التقنيات الموجودة</label>
      <textarea id="hall-technologies" name="technologies" maxlength="5000" rows="7" aria-describedby="hall-technologies-hint"><?= e((string) $profile['technologies']) ?></textarea>
      <small class="field-hint" id="hall-technologies-hint">اكتبي كل تقنية في سطر مستقل.</small>
    </div>
    <div class="field">
      <label for="hall-contributors">المساهمون في إنشاء القاعة</label>
      <textarea id="hall-contributors" name="contributors" maxlength="5000" rows="7" aria-describedby="hall-contributors-hint"><?= e((string) $profile['contributors']) ?></textarea>
      <small class="field-hint" id="hall-contributors-hint">اكتبي كل اسم أو جهة مساهمة في سطر مستقل.</small>
    </div>
    <div class="field field-full hall-visibility-field">
      <label><input type="hidden" name="is_visible" value="0"><input type="checkbox" name="is_visible" value="1" <?= (int) $profile['is_visible'] === 1 ? 'checked' : '' ?>> إظهار قسم تعريف القاعة في الصفحة الرئيسية</label>
    </div>
  </div>
  <div class="form-actions"><button class="button button-primary" type="submit">حفظ تعريف القاعة</button></div>
</form>

<section class="hall-evidence-admin" aria-labelledby="hall-evidence-title">
  <div class="public-section-heading">
    <div><h2 id="hall-evidence-title">صور وشواهد القاعة</h2><p>أضيفي صورًا مع عناوينها وأوصافها، وعدّليها أو رتّبيها واحذفيها. الحد الأقصى 20 صورة، وبحجم 5 ميجابايت للصورة.</p></div>
  </div>
  <form class="card form-card hall-evidence-upload" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_evidence">
    <div class="form-grid">
      <div class="field">
        <label for="evidence-image">الصورة <span class="field-hint">JPG أو PNG أو WEBP</span></label>
        <input id="evidence-image" name="evidence_image" type="file" accept="image/jpeg,image/png,image/webp" required <?= count($hallEvidence) >= 20 ? 'disabled' : '' ?>>
      </div>
      <div class="field">
        <label for="evidence-title">عنوان الصورة</label>
        <input id="evidence-title" name="evidence_title" type="text" maxlength="180" required <?= count($hallEvidence) >= 20 ? 'disabled' : '' ?>>
      </div>
      <div class="field field-full">
        <label for="evidence-description">وصف أو توضيح الشاهد</label>
        <textarea id="evidence-description" name="evidence_description" maxlength="3000" rows="3" <?= count($hallEvidence) >= 20 ? 'disabled' : '' ?>></textarea>
      </div>
    </div>
    <div class="form-actions"><button class="button button-primary" type="submit" <?= count($hallEvidence) >= 20 ? 'disabled' : '' ?>>إضافة الصورة</button></div>
  </form>

  <div class="hall-evidence-admin-grid" id="hall-evidence-list">
    <?php if ($hallEvidence === []): ?>
      <div class="card empty-state"><span class="empty-icon">▧</span><h2>لا توجد صور أو شواهد بعد</h2><p>ارفعي أول صورة لتظهر في الصفحة الرئيسية.</p></div>
    <?php else: ?>
      <?php foreach ($hallEvidence as $position => $evidence): ?>
      <article class="card hall-evidence-admin-card">
        <img class="hall-evidence-admin-image" src="<?= e(app_url('/' . ltrim((string) $evidence['image_path'], '/'))) ?>" alt="<?= e((string) $evidence['title']) ?>">
        <form class="hall-evidence-edit" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_evidence">
          <input type="hidden" name="evidence_id" value="<?= (int) $evidence['id'] ?>">
          <div class="field"><label for="evidence-title-<?= (int) $evidence['id'] ?>">عنوان الصورة</label><input id="evidence-title-<?= (int) $evidence['id'] ?>" name="evidence_title" type="text" maxlength="180" required value="<?= e((string) $evidence['title']) ?>"></div>
          <div class="field"><label for="evidence-description-<?= (int) $evidence['id'] ?>">الوصف</label><textarea id="evidence-description-<?= (int) $evidence['id'] ?>" name="evidence_description" maxlength="3000" rows="3"><?= e((string) $evidence['description']) ?></textarea></div>
          <button class="button button-secondary button-sm" type="submit">حفظ العنوان والوصف</button>
        </form>
        <div class="hall-evidence-admin-actions">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="move_evidence"><input type="hidden" name="evidence_id" value="<?= (int) $evidence['id'] ?>"><input type="hidden" name="direction" value="up">
            <button class="button button-quiet button-sm" type="submit" aria-label="تحريك الصورة إلى الأعلى" <?= $position === 0 ? 'disabled' : '' ?>>↑ لأعلى</button>
          </form>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="move_evidence"><input type="hidden" name="evidence_id" value="<?= (int) $evidence['id'] ?>"><input type="hidden" name="direction" value="down">
            <button class="button button-quiet button-sm" type="submit" aria-label="تحريك الصورة إلى الأسفل" <?= $position === count($hallEvidence) - 1 ? 'disabled' : '' ?>>↓ لأسفل</button>
          </form>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_evidence"><input type="hidden" name="evidence_id" value="<?= (int) $evidence['id'] ?>">
            <button class="button button-danger button-sm" type="submit" data-confirm="هل تريدين حذف هذه الصورة نهائيًا؟">حذف الصورة</button>
          </form>
        </div>
      </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
<?php render_page_end(); ?>
