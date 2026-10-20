<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$columns = $pdo->query('SHOW FULL COLUMNS FROM bookings')->fetchAll(PDO::FETCH_ASSOC);
if ($columns === []) {
    throw new RuntimeException('جدول الحجوزات غير موجود؛ لم يتم إجراء أي تغيير.');
}

$requiresConversion = false;
foreach ($columns as $column) {
    $collation = $column['Collation'] ?? null;
    if (is_string($collation) && !str_starts_with(strtolower($collation), 'utf8mb4_')) {
        $requiresConversion = true;
        break;
    }
}

if (!$requiresConversion) {
    fwrite(STDOUT, 'أعمدة النصوص في جدول الحجوزات تستخدم utf8mb4 بالفعل.' . PHP_EOL);
    exit(0);
}

$pdo->exec('ALTER TABLE bookings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
fwrite(STDOUT, 'تم تحويل جدول الحجوزات إلى utf8mb4 بنجاح.' . PHP_EOL);
