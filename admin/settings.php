<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role([ROLE_ADMIN, ROLE_STAFF]);
require_once dirname(__DIR__) . '/includes/backup.php';

$pdo = db();
$canDownloadBackup = has_permission('system.backup');
$canRestoreBackup = has_permission('system.restore');
$canAccessSystemSettings = current_user()['role'] === ROLE_ADMIN || $canDownloadBackup || $canRestoreBackup;
if (!$canAccessSystemSettings) {
    http_response_code(403);
    require dirname(__DIR__) . '/includes/403.php';
    exit;
}
$zipAvailable = class_exists(ZipArchive::class);
$action = (string) ($_GET['action'] ?? '');
if ($action === 'download') {
    require_permission('system.backup');
    try {
        $backup = backup_create_archive($pdo);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
        header('Content-Length: ' . (string) filesize($backup['path']));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($backup['path']);
        backup_remove_tree($backup['work_directory'], sys_get_temp_dir());
        exit;
    } catch (Throwable $exception) {
        error_log('Database backup download failed: ' . $exception->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit($exception instanceof RuntimeException ? $exception->getMessage() : 'تعذر إنشاء النسخة الاحتياطية. راجعي سجل الخادم.');
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error = 'حجم الملف يتجاوز post_max_size المحدد في إعدادات PHP.';
    } else {
        verify_csrf();
    }
    $postAction = (string) ($_POST['action'] ?? '');
    if ($error === '' && $postAction === 'restore') {
        require_permission('system.restore');
    } elseif ($error === '') {
        http_response_code(400);
        exit('طلب غير صالح.');
    }
    if ($error === '' && $postAction === 'restore') {
        $confirmation = $_POST['restore_confirmation'] ?? null;
        if (($_POST['restore_acknowledged'] ?? '') !== '1' || !is_string($confirmation) || trim($confirmation) !== 'استعادة') {
            $error = 'للمتابعة، أكّدي فهمك للاستبدال واكتبي كلمة «استعادة».';
        }
    }
    if ($error === '' && $postAction === 'restore') {
        try {
            $result = backup_restore_archive($pdo, $_FILES['backup_file'] ?? []);
            flash('success', 'تمت استعادة النسخة بنجاح: ' . $result['tables'] . ' جداول و' . $result['rows'] . ' سجلًا، مع المرفقات.');
            redirect('/admin/settings.php');
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Database backup restore failed: ' . $exception->getMessage());
            $error = 'تعذرت استعادة النسخة. لم تُستبدل البيانات؛ تحققي من توافقها وسجل الخادم.';
        }
    }
}

$tableCount = 0;
$rowCount = 0;
$attachmentCount = 0;
try {
    $tables = backup_table_names($pdo);
    $tableCount = count($tables);
    foreach ($tables as $table) {
        $rowCount += (int) $pdo->query('SELECT COUNT(*) FROM ' . backup_quote_identifier($table))->fetchColumn();
    }
    $attachmentCount = iterator_count(backup_walk_uploads());
} catch (Throwable $exception) {
    error_log('Backup settings summary failed: ' . $exception->getMessage());
}

$title = 'إعدادات النظام والنسخ الاحتياطي';
render_page_start($title, true);
?>
<div class="backup-page">
  <div class="page-heading backup-heading">
    <div>
      <span class="backup-eyebrow">إدارة النظام</span>
      <h1>الإعدادات والنسخ الاحتياطي</h1>
      <p>احفظي بيانات المنصة ومرفقاتها، أو استعيديها بأمان عند الحاجة.</p>
    </div>
    <span class="backup-heading-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 16v4h14v-4"/></svg>
    </span>
  </div>

  <?php if ($error !== ''): ?><div class="alert backup-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$zipAvailable): ?>
    <div class="backup-extension-notice" role="status">
      تعذّر تفعيل النسخ الاحتياطي لأن امتداد ZIP غير مفعّل في PHP. فعّلي <code>extension=zip</code> في ملف <code>php.ini</code> ثم أعيدي تشغيل Apache.
    </div>
  <?php endif; ?>

  <section class="backup-summary" aria-label="محتويات النسخة الاحتياطية">
    <article class="card backup-stat">
      <span class="backup-stat-icon backup-stat-violet" aria-hidden="true">
        <svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
      </span>
      <span><small>جداول قاعدة البيانات</small><strong><?= $tableCount ?></strong></span>
    </article>
    <article class="card backup-stat">
      <span class="backup-stat-icon backup-stat-cyan" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M4 7h16v13H4zM8 7l2-3h4l2 3"/><circle cx="12" cy="13" r="3"/></svg>
      </span>
      <span><small>المرفقات المحفوظة</small><strong><?= $attachmentCount ?></strong></span>
    </article>
    <article class="card backup-stat">
      <span class="backup-stat-icon backup-stat-green" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM9 4v5h6V4m-6 9h6m-6 3h6"/></svg>
      </span>
      <span><small>السجلات المسجلة</small><strong><?= $rowCount ?></strong></span>
    </article>
  </section>

  <div class="backup-cards">
    <?php if ($canDownloadBackup): ?><section class="card backup-card">
      <div class="backup-card-top">
        <span class="backup-card-icon backup-card-download" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 16v4h14v-4"/></svg>
        </span>
        <span class="backup-card-tag">نسخة كاملة</span>
      </div>
      <h2>تنزيل نسخة احتياطية</h2>
      <p>ينشئ ملفًا واحدًا يحتوي على بنية الجداول وبياناتها، وسجلات المستخدمين والحجوزات والتقارير، وجميع مرفقات التقارير.</p>
      <ul class="backup-includes">
        <li><span aria-hidden="true">✓</span> قاعدة البيانات وجميع الجداول</li>
        <li><span aria-hidden="true">✓</span> الصور والفيديو والملفات المرفقة</li>
        <li><span aria-hidden="true">✓</span> فحص سلامة الملفات عند الاستعادة</li>
      </ul>
      <?php if ($zipAvailable): ?>
        <a class="button button-primary backup-action" href="<?= e(app_url('/admin/settings.php?action=download')) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 16v4h14v-4"/></svg>
          إنشاء وتنزيل النسخة
        </a>
        <span class="backup-footnote">تُنشأ النسخة عند الطلب ولا تُحفظ على خادم الموقع.</span>
      <?php else: ?>
        <button class="button button-primary backup-action" type="button" disabled>يلزم تفعيل امتداد ZIP</button>
      <?php endif; ?>
    </section><?php endif; ?>

    <?php if ($canRestoreBackup): ?><section class="card backup-card backup-restore-card">
      <div class="backup-card-top">
        <span class="backup-card-icon backup-card-restore" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M4 7v5h5"/><path d="M5.6 16a8 8 0 1 0-.8-7L4 12"/></svg>
        </span>
        <span class="backup-card-tag backup-restore-tag">استعادة آمنة</span>
      </div>
      <h2>استعادة نسخة سابقة</h2>
      <p>ارفعي نسخة أنشأتها المنصة. يتحقق النظام من سلامة الملف وتوافق بنية قاعدة البيانات قبل بدء الاستعادة.</p>
      <form class="backup-restore-form" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="restore">
        <label class="backup-file-label" for="backup-file">ملف النسخة الاحتياطية <span>ZIP</span></label>
        <input class="backup-file-input" id="backup-file" type="file" name="backup_file" accept=".zip,application/zip" required <?= $zipAvailable ? '' : 'disabled' ?>>
        <label class="backup-acknowledge">
          <input type="checkbox" name="restore_acknowledged" value="1" required <?= $zipAvailable ? '' : 'disabled' ?>>
          <span>أفهم أن الاستعادة ستستبدل بيانات المنصة ومرفقاتها الحالية.</span>
        </label>
        <label class="backup-file-label" for="restore-confirmation">اكتبي «استعادة» للتأكيد</label>
        <input class="backup-confirm-input" id="restore-confirmation" name="restore_confirmation" type="text" autocomplete="off" required <?= $zipAvailable ? '' : 'disabled' ?>>
        <button class="button button-danger backup-action" type="submit" <?= $zipAvailable ? '' : 'disabled' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7v5h5"/><path d="M5.6 16a8 8 0 1 0-.8-7L4 12"/></svg>
          استعادة النسخة المحددة
        </button>
      </form>
      <div class="backup-warning" role="note">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5m0 3h.01"/></svg>
        <span>هذه العملية تستبدل البيانات الحالية بالكامل. لا تغلقي الصفحة حتى تظهر رسالة اكتمال الاستعادة.</span>
      </div>
      <span class="backup-footnote">يخضع حجم الرفع لإعدادات <code>upload_max_filesize</code> و<code>post_max_size</code> وذاكرة PHP.</span>
    </section><?php endif; ?>
  </div>
</div>
<?php render_page_end(); ?>
