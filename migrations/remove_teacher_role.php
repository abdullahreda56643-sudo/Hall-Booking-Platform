<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$mediaQuery = $pdo->prepare(
    'SELECT DISTINCT m.file_path
     FROM report_media AS m
     JOIN reports AS r ON r.id = m.report_id
     JOIN users AS u ON u.id = r.user_id
     WHERE u.role = ?
       AND NOT EXISTS (
           SELECT 1
           FROM report_media AS retained_media
           JOIN reports AS retained_report ON retained_report.id = retained_media.report_id
           JOIN users AS retained_user ON retained_user.id = retained_report.user_id
           WHERE retained_media.file_path = m.file_path
             AND retained_user.role <> ?
       )'
);
$mediaQuery->execute(['teacher', 'teacher']);
$mediaPaths = array_values(array_unique($mediaQuery->fetchAll(PDO::FETCH_COLUMN)));

$uploadRoot = realpath(UPLOAD_DIR);
if ($uploadRoot === false) {
    throw new RuntimeException('مجلد المرفقات غير موجود؛ لم تُحذف بيانات المدرسين.');
}

$filesToDelete = [];
foreach ($mediaPaths as $relativePath) {
    if (!is_string($relativePath)) {
        throw new UnexpectedValueException('يوجد مسار مرفق غير صالح؛ لم تُحذف بيانات المدرسين.');
    }
    $normalizedPath = str_replace('\\', '/', $relativePath);
    if (!preg_match('~^uploads/(images|videos|files)/[^/]+$~', $normalizedPath)) {
        throw new UnexpectedValueException('يوجد مرفق خارج مجلدات الرفع المعتمدة؛ لم تُحذف بيانات المدرسين.');
    }

    $absolutePath = realpath(BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath));
    if ($absolutePath === false) {
        continue;
    }
    if (!str_starts_with($absolutePath, $uploadRoot . DIRECTORY_SEPARATOR)) {
        throw new UnexpectedValueException('مسار مرفق غير آمن؛ لم تُحذف بيانات المدرسين.');
    }
    if (is_file($absolutePath)) {
        $filesToDelete[] = $absolutePath;
    }
}

$accountCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher'")->fetchColumn();
$reportCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM reports AS r
     JOIN users AS u ON u.id = r.user_id
     WHERE u.role = 'teacher'"
)->fetchColumn();

$pdo->beginTransaction();
try {
    $deleteReports = $pdo->prepare(
        'DELETE r FROM reports AS r
         JOIN users AS u ON u.id = r.user_id
         WHERE u.role = ?'
    );
    $deleteReports->execute(['teacher']);
    $pdo->prepare('DELETE FROM users WHERE role = ?')->execute(['teacher']);
    $pdo->prepare('DELETE FROM role_permissions WHERE role = ?')->execute(['teacher']);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

$roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
if (!is_array($roleColumn) || !isset($roleColumn['Type']) || !is_string($roleColumn['Type'])) {
    throw new RuntimeException('تعذر التحقق من مخطط أدوار المستخدمين.');
}
if (str_contains($roleColumn['Type'], "'teacher'")) {
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','staff') NOT NULL");
}

foreach ($filesToDelete as $path) {
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('حُذفت سجلات المدرسين، لكن تعذر حذف أحد ملفاتهم المرفقة.');
    }
}

printf(
    "تم حذف %d حساب مدرس و%d تقرير و%d مرفق، وتحديث الأدوار إلى أدمن ومعلمة فقط.%s",
    $accountCount,
    $reportCount,
    count($filesToDelete),
    PHP_EOL
);
