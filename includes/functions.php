<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    static $base;
    if ($base === null) {
        $configured = getenv('APP_BASE_URL');
        if ($configured !== false) {
            $base = rtrim($configured, '/');
        } else {
            $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
            $projectRoot = realpath(BASE_PATH) ?: BASE_PATH;
            $base = $documentRoot !== '' && str_starts_with($projectRoot, $documentRoot)
                ? str_replace(DIRECTORY_SEPARATOR, '/', substr($projectRoot, strlen($documentRoot)))
                : '';
            $base = rtrim($base, '/');
        }
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function audit_log(PDO $pdo, string $action, string $entityType, ?int $entityId, string $description, array $details = []): void
{
    $user = function_exists('current_user') ? current_user() : null;
    $actorName = $user['name'] ?? (string) ($details['username'] ?? 'زائر');
    $statement = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, actor_name, actor_role, action, entity_type, entity_id, description, details, ip_address, user_agent)
         VALUES (:user_id, :actor_name, :actor_role, :action, :entity_type, :entity_id, :description, :details, :ip_address, :user_agent)'
    );
    $statement->execute([
        'user_id' => $user['id'] ?? null,
        'actor_name' => substr($actorName, 0, 150),
        'actor_role' => $user['role'] ?? null,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'description' => $description,
        'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
}

function ensure_notifications_table(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $table = $pdo->query("SHOW TABLES LIKE 'notifications'");
    if (!$table->fetchColumn()) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            recipient_id BIGINT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(180) NOT NULL,
            message VARCHAR(500) NOT NULL,
            link VARCHAR(500) NOT NULL,
            entity_type VARCHAR(50) NULL,
            entity_id BIGINT UNSIGNED NULL,
            read_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_notifications_recipient_read_created (recipient_id, read_at, created_at),
            INDEX idx_notifications_entity (entity_type, entity_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    $initialized = true;
}

function create_notification(
    PDO $pdo,
    int $recipientId,
    string $type,
    string $title,
    string $message,
    string $link,
    ?string $entityType = null,
    ?int $entityId = null,
    bool $excludeActor = true
): void {
    $user = function_exists('current_user') ? current_user() : null;
    if ($recipientId < 1 || ($excludeActor && $user !== null && $recipientId === $user['id'])) {
        return;
    }
    if (!str_starts_with($link, '/') || str_starts_with($link, '//') || str_contains($link, '\\')) {
        throw new InvalidArgumentException('رابط الإشعار غير صالح.');
    }

    ensure_notifications_table($pdo);
    $statement = $pdo->prepare(
        'INSERT INTO notifications (recipient_id, actor_id, type, title, message, link, entity_type, entity_id)
         SELECT users.id, ?, ?, ?, ?, ?, ?, ?
         FROM users WHERE users.id = ?'
    );
    $statement->execute([
        $user['id'] ?? null,
        $type,
        mb_substr($title, 0, 180),
        mb_substr($message, 0, 500),
        $link,
        $entityType,
        $entityId,
        $recipientId,
    ]);
}

function notify_permission_holders(
    PDO $pdo,
    string $permission,
    string $type,
    string $title,
    string $message,
    string $link,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    ensure_notifications_table($pdo);
    $users = $pdo->query('SELECT id, role FROM users WHERE is_active = 1')->fetchAll();
    $permissionsByRole = [];
    foreach ($users as $recipient) {
        $role = (string) $recipient['role'];
        $canReceive = $role === ROLE_ADMIN;
        if (!$canReceive && $role === ROLE_STAFF) {
            $permissionsByRole[$role] ??= role_permissions_for($role);
            $canReceive = $permissionsByRole[$role][$permission] ?? false;
        }
        if ($canReceive) {
            create_notification($pdo, (int) $recipient['id'], $type, $title, $message, $link, $entityType, $entityId);
        }
    }
}

function unread_notification_count(PDO $pdo, int $userId): int
{
    ensure_notifications_table($pdo);
    $statement = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND read_at IS NULL');
    $statement->execute([$userId]);
    return (int) $statement->fetchColumn();
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function role_home(string $role): string
{
    if ($role === ROLE_ADMIN) {
        return '/admin/dashboard.php';
    }
    if (function_exists('has_permission')) {
        foreach ([
            'admin.dashboard' => '/admin/dashboard.php',
            'reports.review' => '/admin/reports.php',
            'reports.export' => '/admin/reports.php',
            'users.manage' => '/admin/users.php',
            'statistics.view' => '/admin/statistics.php',
            'bookings.manage' => '/admin/bookings.php',
            'audit.view' => '/admin/audit_logs.php',
            'system.backup' => '/admin/settings.php',
            'system.restore' => '/admin/settings.php',
            'reports.view_own' => '/user/dashboard.php',
            'reports.create' => '/user/report_create.php',
        ] as $permission => $path) {
            if (has_permission($permission)) {
                return $path;
            }
        }
        return '/index.php';
    }
    return '/user/dashboard.php';
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'draft' => 'badge-slate',
        'submitted' => 'badge-amber',
        'approved' => 'badge-green',
        'rejected' => 'badge-rose',
        default => 'badge-slate',
    };
}

function report_filters(array $input, bool $admin): array
{
    $conditions = $admin ? ['r.status <> "draft"'] : [];
    $params = [];
    if (!empty($input['status']) && in_array($input['status'], REPORT_STATUSES, true)) {
        if ($admin && $input['status'] === 'draft') {
            $conditions[] = '1 = 0';
        } else {
            $conditions[] = 'r.status = :status';
            $params['status'] = $input['status'];
        }
    }
    if ($admin && !empty($input['user_id']) && ctype_digit((string) $input['user_id'])) {
        $conditions[] = 'r.user_id = :user_id';
        $params['user_id'] = (int) $input['user_id'];
    }
    if ($admin && !empty($input['role']) && $input['role'] === ROLE_STAFF) {
        $conditions[] = 'u.role = :role';
        $params['role'] = $input['role'];
    }
    foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
        $value = $input[$key] ?? '';
        if (is_string($value) && valid_date($value)) {
            $conditions[] = 'DATE(r.created_at) ' . $operator . ' :' . $key;
            $params[$key] = $value;
        }
    }
    $search = trim((string) ($input['q'] ?? ''));
    if ($search !== '') {
        $conditions[] = '(r.title LIKE :query_title OR r.body LIKE :query_body OR u.name LIKE :query_name)';
        $params['query_title'] = '%' . $search . '%';
        $params['query_body'] = '%' . $search . '%';
        $params['query_name'] = '%' . $search . '%';
    }
    return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
}

function selected_report_ids(array $input): ?array
{
    if (!array_key_exists('selected', $input)) {
        return null;
    }
    if (!is_array($input['selected']) || count($input['selected']) > 500) {
        throw new InvalidArgumentException('قائمة التقارير المحددة غير صالحة.');
    }

    $ids = [];
    foreach ($input['selected'] as $value) {
        if ((!is_string($value) && !is_int($value)) || !ctype_digit((string) $value) || (int) $value < 1) {
            throw new InvalidArgumentException('معرّف تقرير محدد غير صالح.');
        }
        $ids[] = (int) $value;
    }
    return array_values(array_unique($ids));
}

function ensure_report_media_order_column(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $column = $pdo->query("SHOW COLUMNS FROM report_media LIKE 'sort_order'");
    if (!$column->fetch()) {
        $pdo->exec('ALTER TABLE report_media ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0');
    }
    $checked = true;
}

function report_media(PDO $pdo, int $reportId): array
{
    ensure_report_media_order_column($pdo);
    $statement = $pdo->prepare('SELECT * FROM report_media WHERE report_id = ? ORDER BY sort_order, id');
    $statement->execute([$reportId]);
    return $statement->fetchAll();
}

function store_report_uploads(PDO $pdo, int $reportId, array $input): void
{
    ensure_report_media_order_column($pdo);
    if (!isset($input['name']) || !is_array($input['name'])) {
        return;
    }
    if (count($input['name']) > 10) {
        throw new RuntimeException('يمكن رفع 10 ملفات كحد أقصى في كل مرة.');
    }

    $mimeTypes = [
        'image' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
        'video' => [
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
        ],
        'file' => [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ],
    ];
    $maxSizes = ['image' => MAX_IMAGE_SIZE, 'video' => MAX_VIDEO_SIZE, 'file' => MAX_FILE_SIZE];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $validated = [];
    $count = count($input['name']);
    for ($i = 0; $i < $count; $i++) {
        $error = (int) ($input['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('تعذر رفع أحد الملفات. تحقق من حجمه ثم أعد المحاولة.');
        }
        $temporaryPath = (string) ($input['tmp_name'][$i] ?? '');
        $size = (int) ($input['size'][$i] ?? 0);
        if ($size <= 0 || !is_uploaded_file($temporaryPath)) {
            throw new RuntimeException('ملف مرفوع غير صالح.');
        }
        $mime = $finfo->file($temporaryPath);
        if (in_array($mime, ['application/zip', 'application/x-zip-compressed'], true) && class_exists(ZipArchive::class)) {
            $archive = new ZipArchive();
            if ($archive->open($temporaryPath) === true) {
                if ($archive->locateName('word/document.xml') !== false) {
                    $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                } elseif ($archive->locateName('xl/workbook.xml') !== false) {
                    $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                }
                $archive->close();
            }
        }
        $type = null;
        $extension = null;
        foreach ($mimeTypes as $candidateType => $allowedTypes) {
            if (isset($allowedTypes[$mime])) {
                $type = $candidateType;
                $extension = $allowedTypes[$mime];
                break;
            }
        }
        $originalExtension = strtolower(pathinfo((string) ($input['name'][$i] ?? ''), PATHINFO_EXTENSION));
        $matchingExtensions = $mime === 'image/jpeg' ? ['jpg', 'jpeg'] : [$extension];
        if ($type === null || !in_array($originalExtension, $matchingExtensions, true) || $size > $maxSizes[$type]) {
            throw new RuntimeException('نوع الملف غير مسموح أو تجاوز الحد الأقصى للحجم.');
        }
        $validated[] = [
            'index' => $i,
            'temporary_path' => $temporaryPath,
            'size' => $size,
            'mime' => $mime,
            'type' => $type,
            'extension' => $extension,
        ];
    }

    $savedPaths = [];
    try {
        foreach ($validated as $file) {
            $i = $file['index'];
            $type = $file['type'];
            $extension = $file['extension'];
            $directory = UPLOAD_DIR . DIRECTORY_SEPARATOR . ($type === 'file' ? 'files' : $type . 's');
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('تعذر تجهيز مجلد رفع الملفات.');
            }
            $filename = bin2hex(random_bytes(20)) . '.' . $extension;
            $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file($file['temporary_path'], $absolutePath)) {
                throw new RuntimeException('تعذر حفظ الملف المرفوع.');
            }
            $savedPaths[] = $absolutePath;

            $relativePath = 'uploads/' . ($type === 'file' ? 'files' : $type . 's') . '/' . $filename;
            $nextPosition = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM report_media WHERE report_id = ?');
            $nextPosition->execute([$reportId]);
            $sortOrder = (int) $nextPosition->fetchColumn();
            $statement = $pdo->prepare(
                'INSERT INTO report_media (report_id, type, file_path, original_name, mime_type, size, sort_order)
                 VALUES (:report_id, :type, :file_path, :original_name, :mime_type, :size, :sort_order)'
            );
            $statement->execute([
                'report_id' => $reportId,
                'type' => $type,
                'file_path' => $relativePath,
                'original_name' => basename((string) ($input['name'][$i] ?? 'file')),
                'mime_type' => $file['mime'],
                'size' => $file['size'],
                'sort_order' => $sortOrder,
            ]);
        }
    } catch (Throwable $exception) {
        foreach ($savedPaths as $savedPath) {
            if (is_file($savedPath) && !unlink($savedPath)) {
                error_log('تعذر تنظيف ملف مرفوع بعد فشل العملية: ' . $savedPath);
            }
        }
        throw $exception;
    }
}

function valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function render_page_start(string $title, bool $dashboard = false): void
{
    $GLOBALS['dashboard_layout'] = $dashboard;
    require BASE_PATH . '/includes/header.php';
    if ($dashboard) {
        require BASE_PATH . '/includes/sidebar.php';
    }
}

function render_page_end(): void
{
    require BASE_PATH . '/includes/footer.php';
}
