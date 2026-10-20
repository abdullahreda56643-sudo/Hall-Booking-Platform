<?php
$sidebarUser = current_user();
$isAdmin = $sidebarUser['role'] === ROLE_ADMIN;
$unreadNotifications = unread_notification_count(db(), $sidebarUser['id']);
$workspaceItems = [];
$adminItems = [];

if ($isAdmin) {
    $workspaceItems = [
        ['/admin/dashboard.php', 'نظرة عامة', '⌂'],
        ['/admin/profile.php', 'الملف الشخصي', '♙'],
        ['/admin/reports.php', 'إدارة التقارير', '▤'],
        ['/admin/users.php', 'المستخدمون', '♙'],
        ['/admin/statistics.php', 'الإحصائيات', '▥'],
        ['/admin/hall.php', 'تعريف القاعة', '✦'],
        ['/schedule.php', 'جدول القاعة', '▦'],
        ['/admin/bookings.php', 'الحجوزات', '▦'],
        ['/admin/audit_logs.php', 'سجل التدقيق', '◷'],
        ['/admin/settings.php', ' النسخ الاحتياطي ', '⚙'],
    ];
} else {
    if (has_permission('reports.view_own')) {
        $workspaceItems[] = ['/user/dashboard.php', 'لوحة التحكم', '⌂'];
        $workspaceItems[] = ['/user/reports.php', 'تقاريري', '▤'];
    }
    if (has_permission('reports.create')) {
        $workspaceItems[] = ['/user/report_create.php', 'كتابة تقرير', '＋'];
    }
    $workspaceItems[] = ['/schedule.php', 'جدول القاعة', '▦'];

    foreach ([
        ['admin.dashboard', '/admin/dashboard.php', 'لوحة الإدارة', '⌂'],
        ['reports.review', '/admin/reports.php', 'مراجعة التقارير', '▤'],
        ['users.manage', '/admin/users.php', 'إدارة المستخدمين', '♙'],
        ['statistics.view', '/admin/statistics.php', 'الإحصائيات', '▥'],
        ['bookings.manage', '/admin/bookings.php', 'إدارة الحجوزات', '▦'],
        ['audit.view', '/admin/audit_logs.php', 'سجل التدقيق', '◷'],
        ['system.backup', '/admin/settings.php', 'تنزيل نسخة احتياطية', '⚙'],
        ['system.restore', '/admin/settings.php', 'استعادة نسخة احتياطية', '↺'],
    ] as [$permission, $path, $label, $icon]) {
        if (has_permission($permission)) {
            $adminItems[] = [$path, $label, $icon];
        }
    }
    if (has_permission('reports.export') && !has_permission('reports.review')) {
        $adminItems[] = ['/admin/reports.php', 'تصدير التقارير', '⇩'];
    }
}
$requestPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
?>
<div class="dashboard-layout">
  <aside class="sidebar" data-sidebar>
    <div class="sidebar-heading"><?= $isAdmin ? 'إدارة المنصة' : 'مساحة العمل' ?></div>
    <nav aria-label="<?= $isAdmin ? 'قائمة الإدارة' : 'قائمة مساحة العمل' ?>">
      <?php foreach ($workspaceItems as [$path, $label, $icon]): ?>
        <a href="<?= e(app_url($path)) ?>" class="sidebar-link <?= str_ends_with($requestPath, $path) ? 'is-active' : '' ?>">
          <span class="sidebar-icon" aria-hidden="true"><?= e($icon) ?></span><span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php if ($adminItems !== []): ?>
      <div class="sidebar-heading sidebar-heading-secondary">صلاحيات إدارية ممنوحة</div>
      <nav aria-label="الصلاحيات الإدارية الممنوحة">
        <?php foreach ($adminItems as [$path, $label, $icon]): ?>
          <a href="<?= e(app_url($path)) ?>" class="sidebar-link <?= str_ends_with($requestPath, $path) ? 'is-active' : '' ?>">
            <span class="sidebar-icon" aria-hidden="true"><?= e($icon) ?></span><span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <nav aria-label="الإشعارات">
      <a href="<?= e(app_url('/notifications.php')) ?>" class="sidebar-link <?= str_ends_with($requestPath, '/notifications.php') ? 'is-active' : '' ?>">
        <span class="sidebar-icon" aria-hidden="true">♧</span><span>الإشعارات</span>
        <?php if ($unreadNotifications > 0): ?><span class="sidebar-notification-count"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span><?php endif; ?>
      </a>
    </nav>
    <div class="sidebar-note">
      <span class="sidebar-note-icon">✦</span>
      <strong>قاعة التعلم الرقمية</strong>
      <span>مساحة منظمة للتعلّم والتعاون.</span>
    </div>
  </aside>
  <main class="dashboard-main">
