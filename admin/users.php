<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('users.manage');
$canManageAdminAccounts = current_user()['role'] === ROLE_ADMIN;
$canManageRolePermissions = $canManageAdminAccounts;

$pdo = db();
$rolePermissionCatalog = role_permission_catalog();
ensure_role_permissions_table($pdo);
$error = '';
$action = '';
$postedValues = [];
$userId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $postedValues = $_POST;
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?: 0;

    try {
        if ($action === 'save_role_permissions') {
            if (!$canManageRolePermissions) {
                throw new RuntimeException('إدارة صلاحيات الأدوار متاحة للأدمن فقط.');
            }
            $role = (string) ($_POST['role'] ?? '');
            if ($role !== ROLE_STAFF) {
                throw new RuntimeException('صلاحيات المعلمة هي الصلاحيات القابلة للتعديل.');
            }
            $submittedPermissions = $_POST['permissions'] ?? null;
            if (!is_array($submittedPermissions) || array_diff(array_keys($submittedPermissions), [ROLE_STAFF]) !== []) {
                throw new RuntimeException('بيانات صلاحيات المعلمة غير صالحة.');
            }

            $roleInput = $submittedPermissions[$role] ?? null;
            if (!is_array($roleInput) || ($roleInput['_submitted'] ?? null) !== '1') {
                throw new RuntimeException('تعذر قراءة صلاحيات الدور. حدّثي الصفحة وحاولي مرة أخرى.');
            }
            unset($roleInput['_submitted']);
            if (array_diff(array_keys($roleInput), array_keys($rolePermissionCatalog)) !== []) {
                throw new RuntimeException('تتضمن قائمة الصلاحيات خيارًا غير صالح.');
            }

            $permissions = [];
            foreach ($rolePermissionCatalog as $permission => $definition) {
                $value = $roleInput[$permission] ?? null;
                if (!$definition['editable']) {
                    if ($value !== null) {
                        throw new RuntimeException('لا يمكن منح صلاحيات الإدارة للمعلمة.');
                    }
                    $permissions[$permission] = false;
                    continue;
                }
                if ($value !== null && $value !== '1') {
                    throw new RuntimeException('بيانات إحدى الصلاحيات غير صالحة.');
                }
                $permissions[$permission] = $value === '1';
            }

            $pdo->beginTransaction();
            $previousPermissions = role_permissions_for($role);
            save_role_permissions($role, $permissions, current_user()['id']);
            audit_log($pdo, 'role_permissions_updated', 'role', null, 'تحديث صلاحيات دور: ' . ROLE_LABELS[$role], [
                'role' => $role,
                'permissions' => $permissions,
            ]);
            if ($previousPermissions !== $permissions) {
                $members = $pdo->prepare('SELECT id FROM users WHERE role = ? AND is_active = 1');
                $members->execute([$role]);
                foreach ($members->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
                    create_notification(
                        $pdo,
                        (int) $memberId,
                        'account',
                        'تم تحديث صلاحيات دورك',
                        'عدّلت إدارة المنصة الصلاحيات المتاحة لدورك. قد تتغير الوظائف التي يمكنك استخدامها.',
                        '/user/dashboard.php',
                        'role',
                        null
                    );
                }
            }
            $pdo->commit();
            flash('success', 'تم حفظ صلاحيات المعلمة. صلاحيات الأدمن ثابتة.');
            redirect('/admin/users.php#role-permissions');
        }

        if ($action === 'create') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $role = (string) ($_POST['role'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '' || mb_strlen($name) > 150 || preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username) !== 1 || !in_array($role, USER_ROLES, true) || (!$canManageAdminAccounts && $role === ROLE_ADMIN) || strlen($password) < 12) {
                throw new RuntimeException('تحققي من الاسم واسم المستخدم والدور وكلمة المرور (12 حرفًا على الأقل).');
            }
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO users (name, username, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role, $isActive]);
            $newUserId = (int) $pdo->lastInsertId();
            audit_log($pdo, 'user_created', 'user', (int) $pdo->lastInsertId(), 'إنشاء حساب مستخدم', [
                'name' => $name,
                'username' => $username,
                'role' => $role,
            ]);
            create_notification(
                $pdo,
                $newUserId,
                'account',
                'تم إنشاء حسابك في المنصة',
                'أهلًا ' . $name . '، أصبح بإمكانك تسجيل الدخول واستخدام المنصة.',
                $role === ROLE_ADMIN ? '/admin/dashboard.php' : '/user/dashboard.php',
                'user',
                $newUserId
            );
            $pdo->commit();
            flash('success', 'تم إنشاء الحساب بنجاح.');
            redirect('/admin/users.php');
        }

        if (!in_array($action, ['update', 'toggle', 'reset_password', 'delete'], true) || $userId < 1) {
            throw new RuntimeException('الإجراء أو معرّف المستخدم غير صالح.');
        }

        $lockedActiveAdmins = [];
        if (in_array($action, ['update', 'toggle', 'delete'], true)) {
            $pdo->beginTransaction();
            $lockedActiveAdmins = $pdo->query('SELECT id FROM users WHERE role = \'admin\' AND is_active = 1 FOR UPDATE')->fetchAll();
        }
        $find = $pdo->prepare('SELECT id, name, username, role, is_active FROM users WHERE id = ?' . (in_array($action, ['update', 'toggle', 'delete'], true) ? ' FOR UPDATE' : ''));
        $find->execute([$userId]);
        $target = $find->fetch();
        if (!$target) {
            throw new RuntimeException('المستخدم غير موجود.');
        }
        if (!$canManageAdminAccounts && $target['role'] === ROLE_ADMIN) {
            throw new RuntimeException('لا يمكن إدارة حسابات الأدمن إلا من خلال أدمن.');
        }
        if ($action === 'delete') {
            if ($userId === current_user()['id']) {
                throw new RuntimeException('لا يمكن حذف الحساب المستخدم حاليًا.');
            }
            if ($target['role'] === ROLE_ADMIN && (int) $target['is_active'] === 1 && count($lockedActiveAdmins) < 2) {
                throw new RuntimeException('لا يمكن حذف آخر أدمن نشط. فعّلي حساب أدمن آخر أولًا.');
            }

            $ownedReports = $pdo->prepare('SELECT id FROM reports WHERE user_id = ? FOR UPDATE');
            $ownedReports->execute([$userId]);
            $ownedReportIds = $ownedReports->fetchAll(PDO::FETCH_COLUMN);
            $reportMedia = $pdo->prepare(
                'SELECT m.file_path FROM report_media m JOIN reports r ON r.id = m.report_id WHERE r.user_id = ?'
            );
            $reportMedia->execute([$userId]);
            $mediaPaths = $reportMedia->fetchAll(PDO::FETCH_COLUMN);
            $deletedReportCount = count($ownedReportIds);

            audit_log($pdo, 'user_deleted', 'user', $userId, 'حذف حساب مستخدم وتقاريره', [
                'name' => $target['name'],
                'username' => $target['username'],
                'role' => $target['role'],
                'report_count' => $deletedReportCount,
                'attachment_count' => count($mediaPaths),
            ]);
            $pdo->prepare(
                'DELETE FROM notifications WHERE entity_type = \'report\' AND entity_id IN (SELECT id FROM reports WHERE user_id = ?)'
            )->execute([$userId]);
            $pdo->prepare('DELETE FROM reports WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            $pdo->commit();

            $uploadDirectory = realpath(UPLOAD_DIR);
            foreach ($mediaPaths as $mediaPath) {
                $path = realpath(BASE_PATH . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $mediaPath));
                if ($path === false || $uploadDirectory === false || !str_starts_with($path, $uploadDirectory . DIRECTORY_SEPARATOR)) {
                    if ($path !== false) {
                        error_log('تم تخطي مسار مرفق غير آمن بعد حذف المستخدم: ' . $path);
                    }
                    continue;
                }
                if (is_file($path) && !unlink($path)) {
                    error_log('تعذر حذف مرفق تقرير بعد حذف المستخدم: ' . $path);
                }
            }
            flash('success', 'تم حذف المستخدم وتقاريره ومرفقاتها.');
            redirect('/admin/users.php');
        }

        if ($action === 'update') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $role = (string) ($_POST['role'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '' || mb_strlen($name) > 150 || preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username) !== 1 || !in_array($role, USER_ROLES, true) || (!$canManageAdminAccounts && $role === ROLE_ADMIN)) {
                throw new RuntimeException('تحققي من الاسم واسم المستخدم والدور.');
            }
            if (!$canManageAdminAccounts && $userId === current_user()['id'] && $role !== $target['role']) {
                throw new RuntimeException('لا يمكن تغيير دور حسابك إلا بواسطة الأدمن.');
            }

            $demotesActiveAdmin = $target['role'] === ROLE_ADMIN
                && (int) $target['is_active'] === 1
                && ($role !== ROLE_ADMIN || $isActive === 0);
            if ($demotesActiveAdmin) {
                if (count($lockedActiveAdmins) < 2) {
                    throw new RuntimeException('لا يمكن تغيير دور آخر أدمن نشط أو تعطيله. يجب إبقاء أدمن نشط واحد على الأقل.');
                }
            }
            $password = (string) ($_POST['password'] ?? '');
            if ($password !== '' && strlen($password) < 12) {
                throw new RuntimeException('يجب ألا تقل كلمة المرور عن 12 حرفًا.');
            }
            $sql = 'UPDATE users SET name = ?, username = ?, role = ?, is_active = ?';
            $values = [$name, $username, $role, $isActive];
            if ($password !== '') {
                $sql .= ', password_hash = ?';
                $values[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = ?';
            $values[] = $userId;
            $pdo->prepare($sql)->execute($values);
            audit_log($pdo, 'user_updated', 'user', $userId, 'تعديل بيانات مستخدم', [
                'previous_name' => $target['name'],
                'previous_username' => $target['username'],
                'previous_role' => $target['role'],
                'name' => $name,
                'username' => $username,
                'role' => $role,
                'is_active' => $isActive,
                'password_changed' => $password !== '',
            ]);
            create_notification(
                $pdo,
                $userId,
                'account',
                'تم تحديث بيانات حسابك',
                'تم تحديث بيانات حسابك أو صلاحياته بواسطة إدارة المنصة.',
                $role === ROLE_ADMIN ? '/admin/dashboard.php' : '/user/dashboard.php',
                'user',
                $userId
            );
            $pdo->commit();

            if ($userId === current_user()['id'] && $isActive === 0) {
                $_SESSION = [];
                session_destroy();
                redirect('/login.php');
            }
            if ($userId === current_user()['id'] && $role !== ROLE_ADMIN) {
                $_SESSION['user_name'] = $name;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = $role;
                flash('success', 'تم تحديث الحساب.');
                redirect(role_home($role));
            }
            flash('success', 'تم تحديث بيانات المستخدم.');
            redirect('/admin/users.php');
        }

        if ($action === 'toggle') {
            $newActive = (int) $target['is_active'] === 1 ? 0 : 1;
            if ($newActive === 0 && $target['role'] === ROLE_ADMIN) {
                if (count($lockedActiveAdmins) < 2) {
                    throw new RuntimeException('لا يمكن تعطيل آخر أدمن نشط. فعّلي حساب أدمن آخر أولًا.');
                }
            }
            $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$newActive, $userId]);
            audit_log($pdo, $newActive ? 'user_activated' : 'user_deactivated', 'user', $userId, $newActive ? 'تفعيل حساب مستخدم' : 'تعطيل حساب مستخدم', [
                'name' => $target['name'],
                'username' => $target['username'],
            ]);
            create_notification(
                $pdo,
                $userId,
                'account',
                $newActive ? 'تم تفعيل حسابك' : 'تم تعطيل حسابك',
                $newActive
                    ? 'تم تفعيل حسابك ويمكنك الآن تسجيل الدخول إلى المنصة.'
                    : 'تم تعطيل حسابك بواسطة إدارة المنصة. تواصلي مع الإدارة إذا كنتِ تعتقدين أن ذلك حدث بالخطأ.',
                $target['role'] === ROLE_ADMIN ? '/admin/dashboard.php' : '/user/dashboard.php',
                'user',
                $userId
            );
            $pdo->commit();
            flash('success', $newActive ? 'تم تفعيل الحساب.' : 'تم تعطيل الحساب.');
            redirect('/admin/users.php');
        }

        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 12) {
            throw new RuntimeException('يجب ألا تقل كلمة المرور عن 12 حرفًا.');
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        audit_log($pdo, 'user_password_reset', 'user', $userId, 'إعادة تعيين كلمة مرور مستخدم', [
            'name' => $target['name'],
            'username' => $target['username'],
        ]);
        create_notification(
            $pdo,
            $userId,
            'account',
            'تم تغيير كلمة مرور حسابك',
            'أعاد مسؤول المنصة تعيين كلمة مرورك. إذا لم تطلبي ذلك، تواصلي مع إدارة المنصة.',
            $target['role'] === ROLE_ADMIN ? '/admin/dashboard.php' : '/user/dashboard.php',
            'user',
            $userId
        );
        $pdo->commit();
        flash('success', 'تمت إعادة تعيين كلمة المرور.');
        redirect('/admin/users.php');
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception->getCode() === '23000') {
            $error = 'اسم المستخدم مستخدم بالفعل.';
        } else {
            throw $exception;
        }
    }
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: ($action === 'update' ? $userId : 0);
$editUser = null;
if ($editId) {
    $statement = $pdo->prepare('SELECT id, name, username, role, is_active FROM users WHERE id = ?' . ($canManageAdminAccounts ? '' : ' AND role <> ?'));
    $statement->execute($canManageAdminAccounts ? [$editId] : [$editId, ROLE_ADMIN]);
    $editUser = $statement->fetch() ?: null;
}
$drawerOpen = $editUser !== null || in_array($action, ['create', 'update'], true);
$isEditing = $editUser !== null || $action === 'update';
$roleFilter = (string) ($_GET['role'] ?? '');
$activeFilter = (string) ($_GET['active'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$sortOptions = ['created_at', 'name', 'username', 'role', 'is_active', 'last_login'];
$sort = (string) ($_GET['sort'] ?? 'created_at');
$direction = strtolower((string) ($_GET['direction'] ?? 'desc'));
if (!in_array($sort, $sortOptions, true)) {
    $sort = 'created_at';
}
if (!in_array($direction, ['asc', 'desc'], true)) {
    $direction = 'desc';
}
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$perPage = 10;
$conditions = $canManageAdminAccounts ? [] : ['u.role <> :admin_role'];
$params = [];
if (!$canManageAdminAccounts) {
    $params['admin_role'] = ROLE_ADMIN;
}
if (in_array($roleFilter, USER_ROLES, true)) {
    $conditions[] = 'u.role = :role';
    $params['role'] = $roleFilter;
}
if (in_array($activeFilter, ['0', '1'], true)) {
    $conditions[] = 'u.is_active = :active';
    $params['active'] = (int) $activeFilter;
}
if ($search !== '') {
    $conditions[] = '(u.name LIKE :query_name OR u.username LIKE :query_username)';
    $params['query_name'] = '%' . $search . '%';
    $params['query_username'] = '%' . $search . '%';
}
$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
$countQuery = $pdo->prepare('SELECT COUNT(*) FROM users u' . $where);
$countQuery->execute($params);
$filteredCount = (int) $countQuery->fetchColumn();
$allCountQuery = $pdo->prepare('SELECT COUNT(*) FROM users' . ($canManageAdminAccounts ? '' : ' WHERE role <> ?'));
$allCountQuery->execute($canManageAdminAccounts ? [] : [ROLE_ADMIN]);
$allCount = (int) $allCountQuery->fetchColumn();
$activeAdminCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = \'admin\' AND is_active = 1')->fetchColumn();
$pageCount = max(1, (int) ceil($filteredCount / $perPage));
$page = min($page, $pageCount);
$offset = ($page - 1) * $perPage;
$orderColumns = [
    'created_at' => 'u.created_at',
    'name' => 'u.name',
    'username' => 'u.username',
    'role' => 'u.role',
    'is_active' => 'u.is_active',
    'last_login' => 'u.last_login',
];
$query = 'SELECT u.id, u.name, u.username, u.role, u.is_active, u.last_login, u.created_at,
                 (SELECT COUNT(*) FROM reports r WHERE r.user_id = u.id) AS report_count
          FROM users u' . $where . ' ORDER BY ' . $orderColumns[$sort] . ' ' . strtoupper($direction) . ', u.id DESC LIMIT :limit OFFSET :offset';
$usersQuery = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $usersQuery->bindValue(':' . $key, $value, $key === 'active' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$usersQuery->bindValue(':limit', $perPage, PDO::PARAM_INT);
$usersQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
$usersQuery->execute();
$users = $usersQuery->fetchAll();
$rolePermissionsByRole = [
    ROLE_STAFF => role_permissions_for(ROLE_STAFF),
];

$title = 'إدارة المستخدمين';
render_page_start($title, true);
$formName = (string) ($postedValues['name'] ?? $editUser['name'] ?? '');
$formUsername = (string) ($postedValues['username'] ?? $editUser['username'] ?? '');
$formRole = (string) ($postedValues['role'] ?? $editUser['role'] ?? '');
$formActive = in_array($action, ['create', 'update'], true)
    ? isset($postedValues['is_active'])
    : (int) ($editUser['is_active'] ?? 1) === 1;
$resetTargetId = filter_input(INPUT_GET, 'reset', FILTER_VALIDATE_INT) ?: 0;
$resetTarget = null;
if ($resetTargetId > 0) {
    $resetLookup = $pdo->prepare('SELECT id, name FROM users WHERE id = ?' . ($canManageAdminAccounts ? '' : ' AND role <> ?'));
    $resetLookup->execute($canManageAdminAccounts ? [$resetTargetId] : [$resetTargetId, ROLE_ADMIN]);
    $resetTarget = $resetLookup->fetch() ?: null;
}
?>
<section class="users-page" data-users-page>
  <div class="users-heading">
    <div>
      <span class="users-eyebrow">إدارة وصلاحيات</span>
      <h1>إدارة المستخدمين <span class="users-count"><?= number_format($allCount, 0, '٫', '٬') ?></span></h1>
      <p><?= $canManageAdminAccounts ? 'إدارة جميع الحسابات وصلاحيات المعلمات.' : 'إدارة حسابات المعلمات.' ?></p>
    </div>
    <button class="button users-add-button" type="button" data-user-drawer-open="create">
      <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6m3-3h-6"/></svg>
      إضافة مستخدم
    </button>
  </div>

  <?php if ($error !== ''): ?><div class="users-error" role="alert"><strong>تعذر تنفيذ الإجراء</strong><span><?= e($error) ?></span></div><?php endif; ?>

  <?php if ($canManageRolePermissions): ?><section class="role-permissions-card card" id="role-permissions">
    <div class="role-permissions-heading">
      <div><span class="users-eyebrow">صلاحيات الأدوار</span><h2>التحكم في الصلاحيات</h2><p>تُطبّق صلاحيات المعلمة على جميع حسابات المعلمات مباشرةً. صلاحيات الأدمن ثابتة.</p></div>
      <span class="role-permissions-lock">صلاحيات دور الأدمن ثابتة</span>
    </div>
    <div class="role-admin-fixed">
      <strong>الأدمن — جميع الصلاحيات مفعّلة وثابتة</strong>
      <span>يمكن تفعيل أو إيقاف صلاحيات المعلمة بشكل مستقل عن صلاحيات الأدمن.</span>
    </div>
    <form method="post" id="role-permissions-staff">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_role_permissions">
      <input type="hidden" name="role" value="<?= e(ROLE_STAFF) ?>">
      <input type="hidden" name="permissions[staff][_submitted]" value="1">
    </form>
    <div class="role-permissions-table-wrap">
      <table class="role-permissions-table" aria-label="صلاحيات الأدوار">
        <thead><tr><th scope="col">الصلاحية</th><th scope="col">الأدمن <small>ثابتة</small></th><th scope="col">المعلمة</th></tr></thead>
        <tbody>
        <?php foreach ($rolePermissionCatalog as $permission => $definition): ?>
          <tr>
            <th scope="row"><strong><?= e($definition['label']) ?></strong><small><?= e($definition['description']) ?></small></th>
            <td><label class="role-permission-control is-locked"><input type="checkbox" checked disabled aria-label="<?= e($definition['label']) ?> — الأدمن (ثابتة)"><span></span><small>دائمًا</small></label></td>
            <?php $permissionEnabled = $definition['editable'] && $rolePermissionsByRole[ROLE_STAFF][$permission]; ?>
            <td><label class="role-permission-control <?= $definition['editable'] ? '' : 'is-locked' ?>">
                <input type="checkbox" <?= $definition['editable'] ? 'form="role-permissions-staff" name="permissions[staff][' . e($permission) . ']" value="1"' : 'disabled' ?> <?= $permissionEnabled ? 'checked' : '' ?> aria-label="<?= e($definition['label'] . ' — ' . ROLE_LABELS[ROLE_STAFF]) ?>">
                <span></span><small><?= $permissionEnabled ? 'مسموحة' : 'موقوفة' ?></small>
              </label></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="role-permissions-footer">
      <small>تُطبّق هذه الصلاحيات على حسابات المعلمات فقط.</small>
      <div>
        <button class="button users-add-button" type="submit" form="role-permissions-staff">حفظ صلاحيات المعلمة</button>
      </div>
    </div>
  </section><?php endif; ?>

  <form method="get" class="users-toolbar" data-users-filter>
    <label class="users-search">
      <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="ابحثي بالاسم أو اسم المستخدم…" aria-label="بحث فوري" data-user-search>
      <input type="hidden" name="sort" value="<?= e($sort) ?>">
      <input type="hidden" name="direction" value="<?= e($direction) ?>">
    </label>
    <label class="users-select"><span>الدور</span><select name="role" data-auto-submit><option value="">كل الأدوار</option>    <?php foreach (USER_ROLES as $role): ?><?php if ($canManageAdminAccounts || $role !== ROLE_ADMIN): ?><option value="<?= e($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>><?= e(ROLE_LABELS[$role]) ?></option><?php endif; ?><?php endforeach; ?></select></label>
    <label class="users-select"><span>الحالة</span><select name="active" data-auto-submit><option value="">كل الحالات</option><option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>نشط</option><option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>معطل</option></select></label>
    <label class="users-select users-sort"><span>ترتيب حسب</span><select name="sort" data-auto-submit><option value="created_at" <?= $sort === 'created_at' ? 'selected' : '' ?>>تاريخ الإضافة</option><option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>الاسم</option><option value="username" <?= $sort === 'username' ? 'selected' : '' ?>>اسم المستخدم</option><option value="role" <?= $sort === 'role' ? 'selected' : '' ?>>الدور</option><option value="is_active" <?= $sort === 'is_active' ? 'selected' : '' ?>>الحالة</option><option value="last_login" <?= $sort === 'last_login' ? 'selected' : '' ?>>آخر دخول</option></select></label>
    <select name="direction" aria-label="اتجاه الترتيب" data-auto-submit><option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>تنازلي</option><option value="asc" <?= $direction === 'asc' ? 'selected' : '' ?>>تصاعدي</option></select>
  </form>

  <section class="users-table-card">
    <div class="users-table-caption">
      <div><h2>قائمة المستخدمين</h2><span><?= number_format($filteredCount, 0, '٫', '٬') ?> حساب</span></div>
      <?php if ($search !== '' || $roleFilter !== '' || $activeFilter !== ''): ?><a class="users-clear-filters" href="<?= e(app_url('/admin/users.php')) ?>">مسح عوامل التصفية</a><?php endif; ?>
    </div>
    <?php if ($users === []): ?>
      <div class="users-empty"><span>♙</span><h2>لا يوجد مستخدمون</h2><p>لم يتم العثور على حسابات تطابق عوامل التصفية.</p></div>
    <?php else: ?>
      <div class="users-table-wrap">
        <table class="users-table" aria-label="قائمة حسابات المستخدمين">
          <thead><tr><th scope="col">المستخدم</th><th scope="col">الدور</th><th scope="col">الحالة</th><th scope="col">آخر دخول</th><th scope="col">التقارير</th><th scope="col">الإجراءات</th></tr></thead>
          <tbody>
          <?php foreach ($users as $index => $user): ?>
            <?php $isLastAdmin = $user['role'] === ROLE_ADMIN && (int) $user['is_active'] === 1 && $activeAdminCount === 1; ?>
            <tr style="--row-index:<?= (int) $index ?>">
              <td data-label="المستخدم"><span class="users-identity"><span class="users-avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span><span><strong><?= e($user['name']) ?></strong><small>@<?= e($user['username']) ?></small></span></span></td>
              <td data-label="الدور"><span class="users-role users-role-<?= e($user['role']) ?>"><?= e(ROLE_LABELS[$user['role']]) ?></span></td>
              <td data-label="الحالة">
                <form method="post" class="users-switch-form" data-user-confirm="<?= e((int) $user['is_active'] ? 'هل أنتِ متأكدة من تعطيل حساب ' . $user['name'] . '؟' : 'هل تريدين تفعيل حساب ' . $user['name'] . '؟') ?>" data-confirm-kind="<?= (int) $user['is_active'] ? 'disable' : 'enable' ?>">
                  <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                  <button type="submit" class="users-switch <?= (int) $user['is_active'] ? 'is-on' : '' ?>" role="switch" aria-checked="<?= (int) $user['is_active'] ? 'true' : 'false' ?>" aria-label="<?= (int) $user['is_active'] ? 'تعطيل ' : 'تفعيل ' ?><?= e($user['name']) ?>" <?= $isLastAdmin ? 'disabled title="لا يمكن تعطيل آخر أدمن نشط"' : '' ?>><span></span></button>
                  <span class="users-status <?= (int) $user['is_active'] ? 'is-active' : '' ?>"><?= (int) $user['is_active'] ? 'نشط' : 'معطل' ?></span>
                </form>
              </td>
              <td data-label="آخر دخول"><?= $user['last_login'] ? e(date('Y/m/d · H:i', strtotime($user['last_login']))) : '<span class="users-muted">لم يسجل الدخول</span>' ?></td>
              <td data-label="التقارير"><span class="users-report-count"><?= number_format((int) $user['report_count'], 0, '٫', '٬') ?></span></td>
              <td data-label="الإجراءات">
                <details class="users-actions">
                  <summary aria-label="إجراءات <?= e($user['name']) ?>">⋯</summary>
                  <div class="users-actions-menu">
                    <a href="<?= e(app_url('/admin/users.php?edit=' . $user['id'])) ?>"><span>✎</span> تعديل البيانات</a>
                    <a href="<?= e(app_url('/admin/users.php?reset=' . $user['id'])) ?>"><span>⌑</span> إعادة تعيين كلمة المرور</a>
                    <?php if ((int) $user['is_active'] === 1): ?>
                      <form method="post" data-user-confirm="<?= e('هل أنتِ متأكدة من تعطيل حساب ' . $user['name'] . '؟') ?>" data-confirm-kind="disable">
                        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                        <button type="submit" <?= $isLastAdmin ? 'disabled title="لا يمكن تعطيل آخر أدمن نشط"' : '' ?>><span>⊘</span> تعطيل الحساب</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" data-user-confirm="<?= e('هل أنتِ متأكدة من حذف حساب ' . $user['name'] . ' نهائيًا؟') ?>" data-confirm-kind="delete">
                      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                      <button type="submit" <?= (int) $user['id'] === current_user()['id'] || $isLastAdmin ? 'disabled title="لا يمكن حذف الحساب الحالي أو آخر أدمن نشط"' : '' ?>><span>×</span> حذف الحساب</button>
                    </form>
                  </div>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <nav class="users-pagination" aria-label="ترقيم صفحات المستخدمين">
        <span>عرض <?= number_format($offset + 1, 0, '٫', '٬') ?>–<?= number_format(min($offset + $perPage, $filteredCount), 0, '٫', '٬') ?> من <?= number_format($filteredCount, 0, '٫', '٬') ?></span>
        <div>
          <?php $queryParams = $_GET; ?>
          <?php if ($page > 1): ?><?php $queryParams['page'] = $page - 1; ?><a href="<?= e(app_url('/admin/users.php?' . http_build_query($queryParams))) ?>" aria-label="الصفحة السابقة">السابق</a><?php else: ?><span class="is-disabled">السابق</span><?php endif; ?>
          <?php
          $pageStart = max(1, min($page - 2, $pageCount - 4));
          $pageEnd = min($pageCount, $pageStart + 4);
          for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++):
              $queryParams = $_GET;
              $queryParams['page'] = $pageNumber;
          ?>
            <a class="<?= $pageNumber === $page ? 'is-current' : '' ?>" href="<?= e(app_url('/admin/users.php?' . http_build_query($queryParams))) ?>" <?= $pageNumber === $page ? 'aria-current="page"' : '' ?>><?= number_format($pageNumber, 0, '٫', '٬') ?></a>
          <?php endfor; ?>
          <?php $queryParams = $_GET; $queryParams['page'] = min($page + 1, $pageCount); ?>
          <?php if ($page < $pageCount): ?><a href="<?= e(app_url('/admin/users.php?' . http_build_query($queryParams))) ?>" aria-label="الصفحة التالية">التالي</a><?php else: ?><span class="is-disabled">التالي</span><?php endif; ?>
        </div>
      </nav>
    <?php endif; ?>
  </section>

  <div class="users-drawer-backdrop <?= $drawerOpen ? 'is-open' : '' ?>" data-user-drawer-backdrop></div>
  <aside class="users-drawer <?= $drawerOpen ? 'is-open' : '' ?>" data-user-drawer aria-hidden="<?= $drawerOpen ? 'false' : 'true' ?>" aria-labelledby="users-drawer-title">
    <div class="users-drawer-head">
      <div><span class="users-eyebrow"><?= $isEditing ? 'تحديث بيانات الحساب' : 'حساب جديد' ?></span><h2 id="users-drawer-title"><?= $isEditing ? 'تعديل المستخدم' : 'إضافة مستخدم' ?></h2></div>
      <button type="button" class="users-drawer-close" data-user-drawer-close aria-label="إغلاق">×</button>
    </div>
    <form method="post" class="users-drawer-form" data-user-editor novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
      <?php if ($isEditing && $editUser): ?><input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>"><?php endif; ?>
      <div class="users-form-field"><label for="user-name">الاسم الكامل</label><input id="user-name" name="name" maxlength="150" required value="<?= e($formName) ?>" autocomplete="name"><small data-field-error="name"></small></div>
      <div class="users-form-field"><label for="user-username">اسم المستخدم</label><input id="user-username" name="username" maxlength="80" pattern="[A-Za-z0-9_.-]{3,80}" required value="<?= e($formUsername) ?>" autocomplete="username"><small>أحرف إنجليزية وأرقام وشرطة سفلية أو نقطة فقط.</small><small data-field-error="username"></small></div>
      <div class="users-form-field"><label for="user-role">الدور الوظيفي</label><select id="user-role" name="role" required><option value="">اختاري الدور</option><?php foreach (USER_ROLES as $role): ?><?php if ($canManageAdminAccounts || $role !== ROLE_ADMIN): ?><option value="<?= e($role) ?>" <?= $formRole === $role ? 'selected' : '' ?>><?= e(ROLE_LABELS[$role]) ?></option><?php endif; ?><?php endforeach; ?></select><small data-field-error="role"></small></div>
      <div class="users-form-field"><label for="user-password">كلمة المرور <?= $isEditing ? '(اختياري عند التعديل)' : '' ?></label><input id="user-password" name="password" type="password" <?= $isEditing ? '' : 'required' ?> minlength="12" autocomplete="new-password" placeholder="12 حرفًا على الأقل"><div class="users-password-meter" aria-live="polite"><span data-password-meter></span></div><small data-password-hint><?= $isEditing ? 'اتركي الحقل فارغًا للاحتفاظ بكلمة المرور الحالية.' : 'يجب أن تتكون من 12 حرفًا على الأقل.' ?></small><small data-field-error="password"></small></div>
      <label class="users-active-control"><input type="checkbox" name="is_active" value="1" <?= $formActive ? 'checked' : '' ?>><span class="users-checkbox" aria-hidden="true"></span><span><strong>تفعيل الحساب</strong><small>يمكن للمستخدم تسجيل الدخول عند تفعيل الحساب.</small></span></label>
      <div class="users-drawer-actions"><button type="submit" class="button users-add-button"><?= $isEditing ? 'حفظ التعديلات' : 'إنشاء الحساب' ?></button><button type="button" class="button button-quiet" data-user-drawer-close>إلغاء</button></div>
    </form>
  </aside>

  <?php if ($resetTarget): ?>
    <dialog class="users-dialog" data-reset-dialog>
      <form method="post" class="users-dialog-form" data-user-confirm="<?= e('سيتم استبدال كلمة مرور ' . $resetTarget['name'] . '. هل تريدين المتابعة؟') ?>" data-confirm-kind="reset">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int) $resetTarget['id'] ?>">
        <span class="users-warning-icon" aria-hidden="true">!</span><h2>إعادة تعيين كلمة المرور</h2><p>أدخلي كلمة مرور جديدة للحساب <strong><?= e($resetTarget['name']) ?></strong>.</p>
        <div class="users-form-field"><label for="reset-user-password">كلمة المرور الجديدة</label><input id="reset-user-password" name="password" type="password" minlength="12" required autocomplete="new-password"><div class="users-password-meter"><span data-password-meter></span></div><small data-password-hint>يجب أن تتكون من 12 حرفًا على الأقل.</small></div>
        <div class="users-dialog-actions"><button class="button users-add-button" type="submit">إعادة تعيين</button><a class="button button-quiet" href="<?= e(app_url('/admin/users.php')) ?>">إلغاء</a></div>
      </form>
    </dialog>
  <?php endif; ?>

  <dialog class="users-confirm-dialog" data-user-confirm-dialog>
    <div class="users-confirm-content"><span class="users-warning-icon" aria-hidden="true">!</span><h2>تأكيد الإجراء</h2><p data-confirm-text></p><div class="users-dialog-actions"><button type="button" class="button button-danger" data-confirm-continue>تأكيد</button><button type="button" class="button button-quiet" data-confirm-cancel>إلغاء</button></div></div>
  </dialog>
</section>
<?php render_page_end(); ?>
