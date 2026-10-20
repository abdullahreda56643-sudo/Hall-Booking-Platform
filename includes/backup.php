<?php
declare(strict_types=1);

function backup_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function backup_table_names(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        if (($row[1] ?? '') === 'BASE TABLE') {
            $tables[] = (string) $row[0];
        }
    }
    sort($tables, SORT_STRING);
    if ($tables === []) {
        throw new RuntimeException('لم يتم العثور على جداول في قاعدة البيانات.');
    }
    return $tables;
}

function backup_table_schema(PDO $pdo, string $table): array
{
    $quotedTable = backup_quote_identifier($table);
    $columns = [];
    foreach ($pdo->query('SHOW FULL COLUMNS FROM ' . $quotedTable)->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[] = [
            'field' => (string) $column['Field'],
            'type' => (string) $column['Type'],
            'null' => (string) $column['Null'],
            'key' => (string) $column['Key'],
            'default' => $column['Default'],
            'extra' => (string) $column['Extra'],
        ];
    }
    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM ' . $quotedTable)->fetchAll(PDO::FETCH_ASSOC) as $index) {
        $indexes[] = [
            'name' => (string) $index['Key_name'],
            'unique' => (string) $index['Non_unique'],
            'sequence' => (string) $index['Seq_in_index'],
            'column' => $index['Column_name'] === null ? null : (string) $index['Column_name'],
            'collation' => $index['Collation'] === null ? null : (string) $index['Collation'],
            'prefix' => $index['Sub_part'] === null ? null : (string) $index['Sub_part'],
            'type' => (string) $index['Index_type'],
        ];
    }
    $create = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
    if (!isset($create[1])) {
        throw new RuntimeException('تعذر قراءة بنية أحد جداول قاعدة البيانات.');
    }
    return ['columns' => $columns, 'indexes' => $indexes, 'create_sql' => (string) $create[1]];
}

function backup_write($handle, string $contents): void
{
    $length = strlen($contents);
    $written = 0;
    while ($written < $length) {
        $result = fwrite($handle, substr($contents, $written));
        if ($result === false || $result === 0) {
            throw new RuntimeException('تعذرت كتابة ملف النسخة الاحتياطية.');
        }
        $written += $result;
    }
}

function backup_json_size_limit(): int
{
    $limit = trim((string) ini_get('memory_limit'));
    if ($limit === '-1' || $limit === '') {
        return 268435456;
    }
    $unit = strtolower(substr($limit, -1));
    $value = (int) $limit;
    $bytes = match ($unit) {
        'g' => $value * 1024 * 1024 * 1024,
        'm' => $value * 1024 * 1024,
        'k' => $value * 1024,
        default => $value,
    };
    return max(1048576, min(268435456, intdiv(max(0, $bytes - memory_get_usage(true)), 10)));
}

function backup_walk_uploads(): iterable
{
    foreach (['images', 'videos', 'files'] as $directory) {
        $root = UPLOAD_DIR . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('تحتوي مجلدات المرفقات على رابط غير مسموح به.');
            }
            if (!$file->isFile() || $file->getFilename() === '.gitkeep') {
                continue;
            }
            $relative = 'uploads/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(UPLOAD_DIR) + 1));
            if (preg_match('~^uploads/(images|videos|files)/[A-Za-z0-9][A-Za-z0-9._-]{0,250}$~D', $relative) !== 1) {
                throw new RuntimeException('يوجد اسم ملف غير صالح ضمن مجلدات المرفقات.');
            }
            yield [$relative, $file->getPathname()];
        }
    }
}

function backup_create_archive(PDO $pdo): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ميزة ZIP غير مفعّلة في إعدادات PHP.');
    }
    if (!is_dir(UPLOAD_DIR) || !is_readable(UPLOAD_DIR)) {
        throw new RuntimeException('مجلد المرفقات غير متاح للقراءة.');
    }

    $workDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'hall-backup-' . bin2hex(random_bytes(12));
    if (!mkdir($workDirectory, 0700)) {
        throw new RuntimeException('تعذر إنشاء مساحة مؤقتة للنسخة الاحتياطية.');
    }
    $databasePath = $workDirectory . DIRECTORY_SEPARATOR . 'database.json';
    $archivePath = $workDirectory . DIRECTORY_SEPARATOR . 'hall-platform-backup.zip';
    $snapshotStarted = false;

    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $snapshotStarted = true;
        $handle = fopen($databasePath, 'xb');
        if ($handle === false) {
            throw new RuntimeException('تعذر إنشاء بيانات النسخة الاحتياطية.');
        }
        $tableSummary = [];
        try {
            $tableNames = backup_table_names($pdo);
            backup_write($handle, '{"format":"hall-platform-backup","version":1,"created_at":');
            backup_write($handle, json_encode(date(DATE_ATOM), JSON_THROW_ON_ERROR));
            backup_write($handle, ',"tables":[');
            foreach ($tableNames as $tableIndex => $table) {
                if ($tableIndex > 0) {
                    backup_write($handle, ',');
                }
                $schema = backup_table_schema($pdo, $table);
                $tableHeader = json_encode([
                    'name' => $table,
                    'schema' => $schema,
                    'columns' => array_column($schema['columns'], 'field'),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                backup_write($handle, substr($tableHeader, 0, -1) . ',"rows":[');
                $rowCount = 0;
                $statement = $pdo->query('SELECT * FROM ' . backup_quote_identifier($table));
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    if ($rowCount > 0) {
                        backup_write($handle, ',');
                    }
                    backup_write($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $rowCount++;
                }
                backup_write($handle, ']}');
                $tableSummary[] = ['name' => $table, 'row_count' => $rowCount];
            }
            backup_write($handle, ']}');
        } finally {
            fclose($handle);
        }
        $pdo->commit();
        $snapshotStarted = false;

        $files = [
            'database.json' => [
                'size' => filesize($databasePath),
                'sha256' => hash_file('sha256', $databasePath),
            ],
        ];
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('تعذر إنشاء ملف ZIP للنسخة الاحتياطية.');
        }
        try {
            if (!$zip->addFile($databasePath, 'database.json')) {
                throw new RuntimeException('تعذر إضافة بيانات قاعدة البيانات إلى النسخة الاحتياطية.');
            }
            foreach (backup_walk_uploads() as [$relativePath, $absolutePath]) {
                $size = filesize($absolutePath);
                $hash = hash_file('sha256', $absolutePath);
                if ($size === false || $hash === false || !$zip->addFile($absolutePath, $relativePath)) {
                    throw new RuntimeException('تعذر إضافة أحد المرفقات إلى النسخة الاحتياطية.');
                }
                $files[$relativePath] = ['size' => $size, 'sha256' => $hash];
            }
            $manifest = [
                'format' => 'hall-platform-backup',
                'version' => 1,
                'created_at' => date(DATE_ATOM),
                'tables' => $tableSummary,
                'files' => $files,
            ];
            if (!$zip->addFromString(
                'manifest.json',
                json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            )) {
                throw new RuntimeException('تعذر إضافة فهرس النسخة الاحتياطية.');
            }
        } catch (Throwable $exception) {
            $zip->close();
            throw $exception;
        }
        if (!$zip->close()) {
            throw new RuntimeException('تعذر إكمال ضغط النسخة الاحتياطية.');
        }

        return [
            'path' => $archivePath,
            'work_directory' => $workDirectory,
            'filename' => 'hall-platform-backup-' . date('Y-m-d-His') . '.zip',
            'table_count' => count($tableSummary),
            'file_count' => count($files) - 1,
        ];
    } catch (Throwable $exception) {
        if ($snapshotStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        backup_remove_tree($workDirectory, sys_get_temp_dir());
        throw $exception;
    }
}

function backup_remove_tree(string $path, string $parent): void
{
    $normalizedParent = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($path, $normalizedParent) || !file_exists($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            error_log('تعذر حذف ملف مؤقت للنسخ الاحتياطي: ' . $path);
        }
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            if (!unlink($item->getPathname())) {
                error_log('تعذر حذف ملف مؤقت للنسخ الاحتياطي: ' . $item->getPathname());
            }
        } elseif (!rmdir($item->getPathname())) {
            error_log('تعذر حذف مجلد مؤقت للنسخ الاحتياطي: ' . $item->getPathname());
        }
    }
    if (!rmdir($path)) {
        error_log('تعذر حذف مساحة مؤقتة للنسخ الاحتياطي: ' . $path);
    }
}

function backup_hash_archive_entry(ZipArchive $zip, string $name, string $destination, array $expected): void
{
    $source = $zip->getStream($name);
    $target = fopen($destination, 'xb');
    if ($source === false || $target === false) {
        if (is_resource($source)) {
            fclose($source);
        }
        throw new RuntimeException('تعذر قراءة أحد ملفات النسخة الاحتياطية.');
    }
    $hash = hash_init('sha256');
    $size = 0;
    try {
        while (!feof($source)) {
            $chunk = fread($source, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('تعذرت قراءة بيانات أحد ملفات النسخة الاحتياطية.');
            }
            if ($chunk === '') {
                continue;
            }
            $size += strlen($chunk);
            if ($size > $expected['size']) {
                throw new RuntimeException('حجم أحد ملفات النسخة الاحتياطية لا يطابق الفهرس.');
            }
            hash_update($hash, $chunk);
            backup_write($target, $chunk);
        }
    } finally {
        fclose($source);
        fclose($target);
    }
    if ($size !== $expected['size'] || !hash_equals($expected['sha256'], hash_final($hash))) {
        throw new RuntimeException('فشل التحقق من سلامة أحد ملفات النسخة الاحتياطية.');
    }
}

function backup_table_restore_order(PDO $pdo, array $tables): array
{
    $known = array_fill_keys($tables, true);
    $dependencies = array_fill_keys($tables, []);
    $query = $pdo->prepare(
        'SELECT TABLE_NAME, REFERENCED_TABLE_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $query->execute([DB_NAME]);
    foreach ($query->fetchAll(PDO::FETCH_NUM) as [$child, $parent]) {
        if (isset($known[$child], $known[$parent]) && $child !== $parent) {
            $dependencies[$child][$parent] = true;
        }
    }
    $ordered = [];
    while (count($ordered) < count($tables)) {
        $progress = false;
        foreach ($tables as $table) {
            if (in_array($table, $ordered, true)) {
                continue;
            }
            if (array_diff(array_keys($dependencies[$table]), $ordered) === []) {
                $ordered[] = $table;
                $progress = true;
            }
        }
        if (!$progress) {
            throw new RuntimeException('تحتوي بنية الجداول على علاقات دائرية تمنع الاستعادة الآمنة.');
        }
    }
    return $ordered;
}

function backup_validate_rows(array $tables, PDO $pdo): array
{
    if (($tables['format'] ?? null) !== 'hall-platform-backup' || ($tables['version'] ?? null) !== 1
        || !is_array($tables['tables'] ?? null) || !is_string($tables['created_at'] ?? null)) {
        throw new RuntimeException('تنسيق بيانات النسخة الاحتياطية غير مدعوم أو غير صالح.');
    }
    $currentNames = backup_table_names($pdo);
    $backupNames = [];
    $rowsByTable = [];
    $summary = [];
    foreach ($tables['tables'] as $tableData) {
        if (!is_array($tableData) || !is_string($tableData['name'] ?? null)
            || !is_array($tableData['schema'] ?? null) || !is_array($tableData['columns'] ?? null)
            || !is_array($tableData['rows'] ?? null)) {
            throw new RuntimeException('تحتوي النسخة الاحتياطية على تعريف جدول غير صالح.');
        }
        $table = $tableData['name'];
        if (in_array($table, $backupNames, true) || !in_array($table, $currentNames, true)) {
            throw new RuntimeException('الجداول الموجودة في النسخة الاحتياطية لا تطابق قاعدة البيانات الحالية.');
        }
        $currentSchema = backup_table_schema($pdo, $table);
        if ($currentSchema !== $tableData['schema']
            || array_column($currentSchema['columns'], 'field') !== $tableData['columns']) {
            throw new RuntimeException('هيكل جدول ' . $table . ' غير متوافق. لا يمكن استعادة هذه النسخة على إصدار قاعدة البيانات الحالي.');
        }
        $columnNames = $tableData['columns'];
        $rowCount = 0;
        foreach ($tableData['rows'] as $row) {
            if (!is_array($row) || array_diff(array_keys($row), $columnNames) !== []
                || array_diff($columnNames, array_keys($row)) !== []) {
                throw new RuntimeException('تحتوي بيانات جدول ' . $table . ' على أعمدة غير متوافقة.');
            }
            foreach ($row as $value) {
                if (!is_scalar($value) && $value !== null) {
                    throw new RuntimeException('تحتوي بيانات جدول ' . $table . ' على قيمة غير صالحة.');
                }
            }
            $rowCount++;
        }
        $backupNames[] = $table;
        $rowsByTable[$table] = $tableData['rows'];
        $summary[$table] = $rowCount;
    }
    sort($backupNames, SORT_STRING);
    if ($backupNames !== $currentNames) {
        throw new RuntimeException('النسخة الاحتياطية لا تتضمن جميع جداول قاعدة البيانات.');
    }
    $userColumns = [];
    foreach ($tables['tables'] as $tableData) {
        if ($tableData['name'] === 'users') {
            $userColumns = $tableData['columns'];
            break;
        }
    }
    if (!in_array('role', $userColumns, true) || !in_array('is_active', $userColumns, true)) {
        throw new RuntimeException('تعذر التحقق من حسابات الإدارة في النسخة الاحتياطية.');
    }
    $hasActiveAdmin = false;
    foreach ($rowsByTable['users'] as $user) {
        if (($user['role'] ?? null) === ROLE_ADMIN && (string) ($user['is_active'] ?? '') === '1') {
            $hasActiveAdmin = true;
            break;
        }
    }
    if (!$hasActiveAdmin) {
        throw new RuntimeException('يجب أن تتضمن النسخة الاحتياطية حساب أدمن نشطًا واحدًا على الأقل لتجنب فقدان الوصول.');
    }
    if (isset($rowsByTable['report_media'])) {
        $archiveFiles = $tables['_archive_files'] ?? [];
        foreach ($rowsByTable['report_media'] as $media) {
            $path = $media['file_path'] ?? null;
            if (!is_string($path) || preg_match('~^uploads/(images|videos|files)/[A-Za-z0-9][A-Za-z0-9._-]{0,250}$~D', $path) !== 1
                || !isset($archiveFiles[$path])) {
                throw new RuntimeException('النسخة الاحتياطية لا تحتوي على مرفق مرتبط بأحد التقارير.');
            }
        }
    }
    return [$rowsByTable, $summary];
}

function backup_stage_uploads(ZipArchive $zip, array $files): string
{
    if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
        throw new RuntimeException('مجلد المرفقات غير متاح للاستعادة.');
    }
    $stageRoot = UPLOAD_DIR . DIRECTORY_SEPARATOR . '.restore-' . bin2hex(random_bytes(12));
    if (!mkdir($stageRoot, 0700)) {
        throw new RuntimeException('تعذر إنشاء مساحة مؤقتة لاستعادة المرفقات.');
    }
    try {
        foreach (['images', 'videos', 'files'] as $directory) {
            if (!mkdir($stageRoot . DIRECTORY_SEPARATOR . 'new' . DIRECTORY_SEPARATOR . $directory, 0700, true)) {
                throw new RuntimeException('تعذر تجهيز مجلدات المرفقات للاستعادة.');
            }
        }
        foreach ($files as $archivePath => $metadata) {
            if (!str_starts_with($archivePath, 'uploads/')) {
                continue;
            }
            $relative = substr($archivePath, strlen('uploads/'));
            $destination = $stageRoot . DIRECTORY_SEPARATOR . 'new' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            backup_hash_archive_entry($zip, $archivePath, $destination, $metadata);
        }
        return $stageRoot;
    } catch (Throwable $exception) {
        backup_remove_tree($stageRoot, UPLOAD_DIR);
        throw $exception;
    }
}

function backup_swap_uploads(string $stageRoot): array
{
    $changes = [];
    $oldRoot = $stageRoot . DIRECTORY_SEPARATOR . 'old';
    if (!mkdir($oldRoot, 0700)) {
        throw new RuntimeException('تعذر حفظ المرفقات الحالية مؤقتًا قبل الاستعادة.');
    }
    try {
        foreach (['images', 'videos', 'files'] as $directory) {
            $current = UPLOAD_DIR . DIRECTORY_SEPARATOR . $directory;
            $replacement = $stageRoot . DIRECTORY_SEPARATOR . 'new' . DIRECTORY_SEPARATOR . $directory;
            $previous = $oldRoot . DIRECTORY_SEPARATOR . $directory;
            $hadPrevious = file_exists($current);
            if ($hadPrevious && (is_link($current) || !is_dir($current))) {
                throw new RuntimeException('تعذر استبدال مجلد مرفقات غير صالح.');
            }
            if ($hadPrevious && !rename($current, $previous)) {
                throw new RuntimeException('تعذر نقل المرفقات الحالية مؤقتًا.');
            }
            $changes[] = [$current, $previous, $hadPrevious, false];
            if (!rename($replacement, $current)) {
                throw new RuntimeException('تعذر تثبيت مجلد المرفقات المستعاد.');
            }
            $changes[count($changes) - 1][3] = true;
        }
    } catch (Throwable $exception) {
        backup_revert_uploads($changes);
        throw $exception;
    }
    return $changes;
}

function backup_revert_uploads(array $changes): void
{
    foreach (array_reverse($changes) as [$current, $previous, $hadPrevious, $installed]) {
        if ($installed && file_exists($current)) {
            backup_remove_tree($current, UPLOAD_DIR);
        }
        if ($hadPrevious && file_exists($previous) && !rename($previous, $current)) {
            error_log('تعذر إرجاع مجلد المرفقات بعد فشل الاستعادة: ' . $previous);
        }
    }
}

function backup_restore_archive(PDO $pdo, array $upload): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ميزة ZIP غير مفعّلة في إعدادات PHP.');
    }
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    $temporaryPath = $upload['tmp_name'] ?? null;
    if (!is_int($error) || $error !== UPLOAD_ERR_OK || !is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
        $error = is_int($error) ? $error : UPLOAD_ERR_NO_FILE;
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم ملف النسخة يتجاوز الحد المسموح به في إعدادات الخادم.',
            UPLOAD_ERR_PARTIAL => 'لم يكتمل رفع الملف. حاولي مرة أخرى.',
            UPLOAD_ERR_NO_FILE => 'اختاري ملف النسخة الاحتياطية أولاً.',
            default => 'تعذر رفع ملف النسخة الاحتياطية. تحققي من إعدادات وحجم الرفع.',
        };
        throw new RuntimeException($message);
    }

    $zip = new ZipArchive();
    if ($zip->open($temporaryPath) !== true) {
        throw new RuntimeException('الملف المحدد ليس أرشيف ZIP صالحًا.');
    }
    $stageRoot = null;
    $databasePath = null;
    $swaps = [];
    $transactionStarted = false;
    $zipOpen = true;
    try {
        if ($zip->numFiles < 2 || $zip->numFiles > 10002) {
            throw new RuntimeException('عدد الملفات داخل الأرشيف غير صالح.');
        }
        $manifestStat = $zip->statName('manifest.json');
        if (!is_array($manifestStat) || ($manifestStat['size'] ?? PHP_INT_MAX) > 1048576) {
            throw new RuntimeException('حجم فهرس النسخة الاحتياطية غير صالح.');
        }
        $archiveNames = [];
        $archiveSize = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = $stat['name'] ?? '';
            if (!is_string($name) || isset($archiveNames[$name]) || str_contains($name, '\\')
                || preg_match('~^(?:manifest\.json|database\.json|uploads/(?:images|videos|files)/[A-Za-z0-9][A-Za-z0-9._-]{0,250})$~D', $name) !== 1) {
                throw new RuntimeException('يحتوي الأرشيف على مسارات أو أسماء ملفات غير مسموح بها.');
            }
            $entrySize = $stat['size'] ?? null;
            if (!is_int($entrySize) || $entrySize < 0 || ($archiveSize += $entrySize) > 1073741824) {
                throw new RuntimeException('الحجم الإجمالي لمحتويات الأرشيف يتجاوز الحد الآمن.');
            }
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)
                && $opsys === ZipArchive::OPSYS_UNIX
                && (($attributes >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('لا يمكن استعادة أرشيف يحتوي على روابط ملفات.');
            }
            $archiveNames[$name] = true;
        }
        if (!isset($archiveNames['manifest.json'], $archiveNames['database.json'])) {
            throw new RuntimeException('الأرشيف لا يحتوي على ملفات النسخة الاحتياطية المطلوبة.');
        }
        $manifestContent = $zip->getFromName('manifest.json');
        if (!is_string($manifestContent)) {
            throw new RuntimeException('تعذر قراءة فهرس النسخة الاحتياطية.');
        }
        $manifest = json_decode($manifestContent, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'hall-platform-backup'
            || ($manifest['version'] ?? null) !== 1 || !is_array($manifest['files'] ?? null)
            || !is_array($manifest['tables'] ?? null)) {
            throw new RuntimeException('فهرس النسخة الاحتياطية غير صالح أو غير مدعوم.');
        }
        $expectedFiles = $manifest['files'];
        $expectedArchiveNames = array_merge(['manifest.json'], array_keys($expectedFiles));
        if (array_diff(array_keys($archiveNames), $expectedArchiveNames) !== []
            || array_diff($expectedArchiveNames, array_keys($archiveNames)) !== []) {
            throw new RuntimeException('قائمة ملفات الأرشيف لا تطابق فهرس النسخة الاحتياطية.');
        }
        $totalSize = 0;
        foreach ($expectedFiles as $name => $metadata) {
            if (!is_string($name) || !isset($archiveNames[$name]) || !is_array($metadata)
                || !is_int($metadata['size'] ?? null) || $metadata['size'] < 0
                || !is_string($metadata['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256']) !== 1) {
                throw new RuntimeException('تحتوي قائمة ملفات النسخة الاحتياطية على بيانات غير صالحة.');
            }
            $totalSize += $metadata['size'];
            if ($totalSize > 1073741824) {
                throw new RuntimeException('حجم بيانات النسخة الاحتياطية يتجاوز الحد الآمن للاستعادة.');
            }
        }
        if (!isset($expectedFiles['database.json'])) {
            throw new RuntimeException('بيانات قاعدة البيانات غير موجودة في فهرس النسخة الاحتياطية.');
        }
        if ($expectedFiles['database.json']['size'] > backup_json_size_limit()) {
            throw new RuntimeException('حجم قاعدة البيانات أكبر من الذاكرة المتاحة للاستعادة. ارفعي memory_limit في PHP أو استخدمي نسخة أصغر.');
        }
        $expectedTableCounts = [];
        foreach ($manifest['tables'] as $tableSummary) {
            if (!is_array($tableSummary) || !is_string($tableSummary['name'] ?? null)
                || !is_int($tableSummary['row_count'] ?? null) || $tableSummary['row_count'] < 0
                || isset($expectedTableCounts[$tableSummary['name']])) {
                throw new RuntimeException('قائمة جداول النسخة الاحتياطية غير صالحة.');
            }
            $expectedTableCounts[$tableSummary['name']] = $tableSummary['row_count'];
        }

        $stageRoot = backup_stage_uploads($zip, $expectedFiles);
        $databasePath = $stageRoot . DIRECTORY_SEPARATOR . 'database.json';
        backup_hash_archive_entry($zip, 'database.json', $databasePath, $expectedFiles['database.json']);
        $zip->close();
        $zipOpen = false;

        $databaseJson = file_get_contents($databasePath);
        if (!is_string($databaseJson)) {
            throw new RuntimeException('تعذرت قراءة بيانات قاعدة البيانات من النسخة الاحتياطية.');
        }
        $backupData = json_decode($databaseJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($backupData)) {
            throw new RuntimeException('بيانات قاعدة البيانات في النسخة الاحتياطية غير صالحة.');
        }
        $backupData['_archive_files'] = $expectedFiles;
        [$rowsByTable, $summary] = backup_validate_rows($backupData, $pdo);
        ksort($summary, SORT_STRING);
        ksort($expectedTableCounts, SORT_STRING);
        if ($summary !== $expectedTableCounts) {
            throw new RuntimeException('عدد السجلات لا يطابق فهرس النسخة الاحتياطية.');
        }
        $tableNames = array_keys($rowsByTable);
        $restoreOrder = backup_table_restore_order($pdo, $tableNames);

        $pdo->beginTransaction();
        $transactionStarted = true;
        foreach (array_reverse($restoreOrder) as $table) {
            $pdo->exec('DELETE FROM ' . backup_quote_identifier($table));
        }
        foreach ($restoreOrder as $table) {
            $quotedTable = backup_quote_identifier($table);
            $schema = backup_table_schema($pdo, $table);
            $insertColumns = array_values(array_filter(
                array_column($schema['columns'], 'field'),
                static function (string $column) use ($schema): bool {
                    foreach ($schema['columns'] as $definition) {
                        if ($definition['field'] === $column) {
                            return stripos($definition['extra'], 'generated') === false;
                        }
                    }
                    return false;
                }
            ));
            if ($insertColumns === []) {
                continue;
            }
            $quotedColumns = implode(', ', array_map('backup_quote_identifier', $insertColumns));
            foreach (array_chunk($rowsByTable[$table], 100) as $batch) {
                $rowPlaceholder = '(' . implode(', ', array_fill(0, count($insertColumns), '?')) . ')';
                $sql = 'INSERT INTO ' . $quotedTable . ' (' . $quotedColumns . ') VALUES '
                    . implode(', ', array_fill(0, count($batch), $rowPlaceholder));
                $values = [];
                foreach ($batch as $row) {
                    foreach ($insertColumns as $column) {
                        $values[] = $row[$column];
                    }
                }
                $insert = $pdo->prepare($sql);
                $insert->execute($values);
            }
        }

        $swaps = backup_swap_uploads($stageRoot);
        $actor = current_user();
        $actorId = (int) $actor['id'];
        $actorExists = $pdo->prepare('SELECT 1 FROM users WHERE id = ? LIMIT 1');
        $actorExists->execute([$actorId]);
        if (!$actorExists->fetchColumn()) {
            $actorId = 0;
        }
        $audit = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, actor_name, actor_role, action, entity_type, description, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $audit->execute([
            $actorId ?: null,
            $actor['name'],
            $actor['role'],
            'database_backup_restored',
            'system',
            'استعادة نسخة احتياطية كاملة لقاعدة البيانات والمرفقات',
            json_encode(['table_count' => count($summary), 'row_count' => array_sum($summary)], JSON_THROW_ON_ERROR),
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
        $pdo->commit();
        $transactionStarted = false;

        backup_remove_tree($stageRoot, UPLOAD_DIR);
        return ['tables' => count($summary), 'rows' => array_sum($summary)];
    } catch (Throwable $exception) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($swaps !== []) {
            backup_revert_uploads($swaps);
        }
        if ($stageRoot !== null) {
            backup_remove_tree($stageRoot, UPLOAD_DIR);
        }
        if ($exception instanceof JsonException) {
            throw new RuntimeException('ملف النسخة الاحتياطية تالف أو يحتوي على بيانات غير صالحة.');
        }
        throw $exception;
    } finally {
        if ($zipOpen) {
            $zip->close();
        }
    }
}
