<?php
declare(strict_types=1);

function role_permission_catalog(): array
{
    return [
        'admin.dashboard' => [
            'label' => 'دخول لوحة الإدارة',
            'description' => 'عرض لوحة المؤشرات والأنشطة الإدارية.',
            'editable' => true,
            'default' => false,
        ],
        'reports.review' => [
            'label' => 'مراجعة تقارير المستخدمين',
            'description' => 'عرض التقارير العامة ومراجعتها واعتمادها أو رفضها.',
            'editable' => true,
            'default' => false,
        ],
        'reports.export' => [
            'label' => 'تصدير التقارير',
            'description' => 'تصدير التقارير بصيغ PDF وExcel.',
            'editable' => true,
            'default' => false,
        ],
        'users.manage' => [
            'label' => 'إدارة حسابات المستخدمين',
            'description' => 'إنشاء حسابات المعلمات وتعديلها وتفعيلها.',
            'editable' => true,
            'default' => false,
        ],
        'statistics.view' => [
            'label' => 'عرض الإحصائيات والتحليلات',
            'description' => 'مشاهدة مؤشرات التقارير والحجوزات والرسوم التحليلية.',
            'editable' => true,
            'default' => false,
        ],
        'bookings.manage' => [
            'label' => 'إدارة الحجوزات',
            'description' => 'عرض سجل الحجوزات وإلغاء الحجوزات المؤكدة.',
            'editable' => true,
            'default' => false,
        ],
        'audit.view' => [
            'label' => 'عرض سجل التدقيق',
            'description' => 'متابعة عمليات الدخول والتغييرات والأنشطة المسجلة.',
            'editable' => true,
            'default' => false,
        ],
        'system.backup' => [
            'label' => 'تنزيل نسخة احتياطية',
            'description' => 'تنزيل نسخة من بيانات المنصة ومرفقاتها.',
            'editable' => true,
            'default' => false,
        ],
        'system.restore' => [
            'label' => 'استعادة نسخة احتياطية',
            'description' => 'استبدال بيانات المنصة ومرفقاتها بنسخة سابقة.',
            'editable' => true,
            'default' => false,
        ],
        'reports.view_own' => [
            'label' => 'عرض تقاريري',
            'description' => 'فتح قائمة تقارير الدور ومشاهدة تفاصيلها.',
            'editable' => true,
            'default' => true,
        ],
        'reports.create' => [
            'label' => 'إنشاء وتعديل التقارير',
            'description' => 'كتابة التقارير وتعديل المسودات والمرفوض منها وإدارة مرفقاتها.',
            'editable' => true,
            'default' => true,
        ],
        'reports.submit' => [
            'label' => 'إرسال التقارير للمراجعة',
            'description' => 'إرسال التقارير المحفوظة للاعتماد.',
            'editable' => true,
            'default' => true,
        ],
        'reports.delete' => [
            'label' => 'حذف التقارير',
            'description' => 'حذف التقارير التي لا تزال مسودة أو مرفوضة.',
            'editable' => true,
            'default' => true,
        ],
    ];
}

function ensure_role_permissions_table(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS role_permissions (
            role VARCHAR(20) NOT NULL PRIMARY KEY,
            permissions JSON NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $defaults = [];
    foreach (role_permission_catalog() as $permission => $definition) {
        $defaults[$permission] = $definition['default'];
    }
    $insert = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, permissions) VALUES (?, ?)');
    $encodedDefaults = json_encode($defaults, JSON_THROW_ON_ERROR);
    $insert->execute([ROLE_STAFF, $encodedDefaults]);

    $initialized = true;
}

function role_permissions_for(string $role): array
{
    static $permissionsByRole = [];
    if ($role !== ROLE_STAFF) {
        throw new InvalidArgumentException('لا يمكن تعديل صلاحيات هذا الدور.');
    }
    if (isset($permissionsByRole[$role])) {
        return $permissionsByRole[$role];
    }

    $pdo = db();
    ensure_role_permissions_table($pdo);
    $statement = $pdo->prepare('SELECT permissions FROM role_permissions WHERE role = ?');
    $statement->execute([$role]);
    $stored = $statement->fetchColumn();
    if (!is_string($stored)) {
        throw new RuntimeException('تعذر تحميل صلاحيات الدور.');
    }

    $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
    $catalog = role_permission_catalog();
    if (!is_array($decoded) || array_diff(array_keys($decoded), array_keys($catalog)) !== []) {
        throw new UnexpectedValueException('إعدادات صلاحيات الدور غير صالحة.');
    }

    $permissions = [];
    foreach ($catalog as $key => $definition) {
        if (!array_key_exists($key, $decoded)) {
            $permissions[$key] = $definition['default'];
            continue;
        }
        if (!is_bool($decoded[$key])) {
            throw new UnexpectedValueException('إعدادات صلاحيات الدور غير مكتملة أو غير صالحة.');
        }
        $permissions[$key] = $decoded[$key];
    }
    $permissionsByRole[$role] = $permissions;
    return $permissions;
}

function save_role_permissions(string $role, array $permissions, int $updatedBy): void
{
    if ($role !== ROLE_STAFF) {
        throw new RuntimeException('لا يمكن تعديل صلاحيات الأدمن.');
    }

    $catalog = role_permission_catalog();
    if (array_diff(array_keys($permissions), array_keys($catalog)) !== []) {
        throw new RuntimeException('تتضمن قائمة الصلاحيات خيارًا غير صالح.');
    }
    foreach ($catalog as $key => $definition) {
        if (!array_key_exists($key, $permissions) || !is_bool($permissions[$key])) {
            throw new RuntimeException('تعذر حفظ إعدادات الصلاحيات. حدّثي الصفحة وحاولي مرة أخرى.');
        }
    }
    $pdo = db();
    ensure_role_permissions_table($pdo);
    $statement = $pdo->prepare(
        'INSERT INTO role_permissions (role, permissions, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE permissions = VALUES(permissions), updated_by = VALUES(updated_by)'
    );
    $statement->execute([$role, json_encode($permissions, JSON_THROW_ON_ERROR), $updatedBy]);
}

function has_permission(string $permission): bool
{
    $catalog = role_permission_catalog();
    if (!array_key_exists($permission, $catalog)) {
        throw new InvalidArgumentException('الصلاحية المطلوبة غير معرّفة.');
    }

    $user = function_exists('current_user') ? current_user() : null;
    if ($user === null) {
        return false;
    }
    if ($user['role'] === ROLE_ADMIN) {
        return true;
    }
    if ($user['role'] !== ROLE_STAFF) {
        return false;
    }

    return role_permissions_for($user['role'])[$permission];
}

function require_permission(string $permission, bool $json = false): void
{
    if (has_permission($permission)) {
        return;
    }

    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => false, 'message' => 'لا تملكين صلاحية تنفيذ هذا الإجراء.'],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    require BASE_PATH . '/includes/403.php';
    exit;
}
