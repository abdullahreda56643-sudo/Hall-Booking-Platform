<?php
declare(strict_types=1);

const DIAGNOSTIC_ACCESS_KEY = 'REPLACE_WITH_A_RANDOM_SECRET';

$providedKey = $_GET['key'] ?? null;
if (
    DIAGNOSTIC_ACCESS_KEY === 'REPLACE_WITH_A_RANDOM_SECRET'
    || !is_string($providedKey)
    || !hash_equals(DIAGNOSTIC_ACCESS_KEY, $providedKey)
) {
    http_response_code(404);
    exit('Not Found');
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

$checks = [];
$addCheck = static function (string $name, bool $passed, string $details) use (&$checks): void {
    $checks[] = ['name' => $name, 'passed' => $passed, 'details' => $details];
};

$addCheck(
    'إصدار PHP',
    PHP_VERSION_ID >= 80000,
    PHP_VERSION
);

foreach (['pdo', 'pdo_mysql', 'fileinfo', 'mbstring', 'gd', 'zip'] as $extension) {
    $addCheck(
        "امتداد PHP: {$extension}",
        extension_loaded($extension),
        extension_loaded($extension) ? 'مثبّت' : 'غير مثبّت'
    );
}

foreach ([
    'index.php' => 'ملف الصفحة الرئيسية',
    'config.php' => 'ملف الإعدادات',
    'includes/db.php' => 'ملف اتصال قاعدة البيانات',
    'vendor/autoload.php' => 'ملفات Composer',
] as $path => $label) {
    $addCheck(
        $label,
        is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path)),
        is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))
            ? 'موجود'
            : 'غير موجود'
    );
}

foreach (['images', 'videos', 'files'] as $directory) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $directory;
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $addCheck(
        "مجلد الرفع: {$directory}",
        $writable,
        !$exists ? 'المجلد غير موجود' : ($writable ? 'موجود وقابل للكتابة' : 'غير قابل للكتابة')
    );
}

$connection = null;
try {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db.php';
    $connection = db();
    $connection->query('SELECT 1');
    $addCheck('اتصال قاعدة البيانات', true, 'تم الاتصال بنجاح');
} catch (Throwable $exception) {
    $errorCode = $exception instanceof PDOException ? (string) $exception->getCode() : 'غير متاح';
    $addCheck(
        'اتصال قاعدة البيانات',
        false,
        "فشل الاتصال (رمز الخطأ: {$errorCode}). راجع بيانات الاتصال وصلاحيات مستخدم قاعدة البيانات."
    );
}

if ($connection instanceof PDO) {
    try {
        $tables = $connection->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $requiredTables = ['users', 'bookings', 'reports', 'report_media'];
        $missingTables = array_values(array_diff($requiredTables, $tables));
        $addCheck(
            'جداول المنصة الأساسية',
            $missingTables === [],
            $missingTables === []
                ? 'الجداول الأساسية موجودة'
                : 'جداول غير موجودة: ' . implode(', ', $missingTables) . '. استورد database.sql أو أكمل ترحيل قاعدة البيانات.'
        );
    } catch (Throwable $exception) {
        $addCheck('جداول المنصة الأساسية', false, 'تعذر فحص الجداول. تحقق من صلاحيات مستخدم قاعدة البيانات.');
    }
}

$allPassed = count(array_filter($checks, static fn (array $check): bool => !$check['passed'])) === 0;
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>تشخيص منصة حجز القاعة</title>
  <style>
    body{font-family:Tahoma,Arial,sans-serif;background:#f4f6fb;color:#172033;margin:0;padding:24px}
    main{max-width:850px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;box-shadow:0 8px 28px #17203312}
    h1{font-size:1.5rem;margin-top:0}.summary{padding:12px;border-radius:8px;background:<?= $allPassed ? '#e9f8ef' : '#fff3e8' ?>}
    table{width:100%;border-collapse:collapse;margin-top:16px}th,td{text-align:right;padding:12px;border-bottom:1px solid #e7eaf0}
    .ok{color:#16743a;font-weight:bold}.failed{color:#b54708;font-weight:bold}
    .note{color:#5c667a;line-height:1.8;margin-bottom:0}
  </style>
</head>
<body>
<main>
  <h1>تشخيص منصة حجز القاعة</h1>
  <p class="summary"><?= $allPassed ? 'اجتازت الفحوصات المعروضة.' : 'توجد فحوصات تحتاج إلى معالجة.' ?></p>
  <table>
    <thead><tr><th>الفحص</th><th>النتيجة</th><th>التفاصيل</th></tr></thead>
    <tbody>
      <?php foreach ($checks as $check): ?>
        <tr>
          <td><?= htmlspecialchars($check['name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="<?= $check['passed'] ? 'ok' : 'failed' ?>"><?= $check['passed'] ? 'ناجح' : 'فشل' ?></td>
          <td><?= htmlspecialchars($check['details'], ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="note">هذا الفحص لا يعرض كلمات المرور أو محتوى قاعدة البيانات. احذف diagnose.php من الخادم فور الانتهاء.</p>
</main>
</body>
</html>
