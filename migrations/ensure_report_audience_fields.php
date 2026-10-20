<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$columns = $pdo->query('SHOW COLUMNS FROM reports')->fetchAll(PDO::FETCH_COLUMN);
if ($columns === []) {
    throw new RuntimeException('جدول التقارير غير موجود؛ لم يتم إجراء أي تغيير.');
}

$existingColumns = array_flip($columns);
$added = [];
if (!isset($existingColumns['class_name'])) {
    $pdo->exec('ALTER TABLE reports ADD COLUMN class_name VARCHAR(100) NULL AFTER title');
    $added[] = 'الصف';
}
if (!isset($existingColumns['beneficiaries_count'])) {
    $pdo->exec('ALTER TABLE reports ADD COLUMN beneficiaries_count INT UNSIGNED NULL AFTER class_name');
    $added[] = 'عدد المستفيدات';
}

if ($added === []) {
    fwrite(STDOUT, 'حقول الصف وعدد المستفيدات موجودة بالفعل.' . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, 'تمت إضافة الحقول: ' . implode('، ', $added) . '.' . PHP_EOL);
