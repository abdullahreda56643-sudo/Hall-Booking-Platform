<?php
require_once __DIR__ . '/includes/auth.php';
require_role([ROLE_ADMIN, ROLE_STAFF]);

$pdo = db();
$userId = current_user()['id'];
$action = (string) ($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ($action === 'mark_all_read') {
        $statement = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE recipient_id = ? AND read_at IS NULL');
        $statement->execute([$userId]);
        flash('success', 'تم تحديد جميع الإشعارات كمقروءة.');
        redirect('/notifications.php');
    }
    if ($action === 'open') {
        $notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
        if (!$notificationId) {
            http_response_code(400);
            exit('معرّف الإشعار غير صالح.');
        }
        $statement = $pdo->prepare('SELECT link FROM notifications WHERE id = ? AND recipient_id = ?');
        $statement->execute([$notificationId, $userId]);
        $link = $statement->fetchColumn();
        if (!is_string($link)) {
            http_response_code(404);
            exit('الإشعار غير موجود.');
        }
        if (!str_starts_with($link, '/') || str_starts_with($link, '//') || str_contains($link, '\\')) {
            throw new UnexpectedValueException('رابط الإشعار المخزن غير صالح.');
        }
        $markRead = $pdo->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ? AND recipient_id = ?');
        $markRead->execute([$notificationId, $userId]);
        redirect($link);
    }
    http_response_code(400);
    exit('الإجراء المطلوب غير صالح.');
}

$unreadOnly = ($_GET['filter'] ?? '') === 'unread';
$conditions = ['recipient_id = :recipient_id'];
if ($unreadOnly) {
    $conditions[] = 'read_at IS NULL';
}
$where = ' WHERE ' . implode(' AND ', $conditions);
$count = $pdo->prepare('SELECT COUNT(*) FROM notifications' . $where);
$count->execute(['recipient_id' => $userId]);
$total = (int) $count->fetchColumn();
$perPage = 20;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$statement = $pdo->prepare(
    'SELECT id, type, title, message, link, read_at, created_at FROM notifications'
    . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
);
$statement->execute(['recipient_id' => $userId]);
$notifications = $statement->fetchAll();
$unreadCount = unread_notification_count($pdo, $userId);
$title = 'الإشعارات';
render_page_start($title, true);
?>
<div class="page-heading">
  <div><h1>الإشعارات</h1><p>تابعي مستجدات التقارير والحجوزات وإجراءات الحساب.</p></div>
  <?php if ($unreadCount > 0): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_all_read"><button class="button button-quiet" type="submit">تحديد الكل كمقروء</button></form>
  <?php endif; ?>
</div>
<div class="notification-filters" aria-label="تصفية الإشعارات">
  <a class="<?= !$unreadOnly ? 'is-active' : '' ?>" href="<?= e(app_url('/notifications.php')) ?>">الكل</a>
  <a class="<?= $unreadOnly ? 'is-active' : '' ?>" href="<?= e(app_url('/notifications.php?filter=unread')) ?>">غير المقروءة <span><?= $unreadCount ?></span></a>
</div>
<section class="notification-list" aria-label="قائمة الإشعارات">
  <?php if ($notifications === []): ?>
    <div class="card empty-state"><span class="empty-icon">♧</span><h2>لا توجد إشعارات</h2><p><?= $unreadOnly ? 'كل إشعاراتك مقروءة.' : 'ستظهر هنا تحديثات التقارير والحجوزات والإجراءات المهمة.' ?></p></div>
  <?php else: ?>
    <?php foreach ($notifications as $notification): ?>
      <article class="card notification-card <?= $notification['read_at'] === null ? 'is-unread' : '' ?>">
        <div class="notification-mark" aria-hidden="true"><?= $notification['type'] === 'booking' ? '▦' : ($notification['type'] === 'account' ? '♙' : '▤') ?></div>
        <div class="notification-content">
          <div class="notification-heading"><h2><?= e($notification['title']) ?></h2><?php if ($notification['read_at'] === null): ?><span class="notification-unread-label">جديد</span><?php endif; ?></div>
          <p><?= e($notification['message']) ?></p>
          <time datetime="<?= e($notification['created_at']) ?>"><?= e(date('Y-m-d H:i', strtotime($notification['created_at']))) ?></time>
        </div>
        <form method="post" class="notification-open-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
          <button class="button button-secondary button-sm" type="submit">عرض التفاصيل</button>
        </form>
      </article>
    <?php endforeach; ?>
    <?php if ($pages > 1): ?><nav class="pagination" aria-label="صفحات الإشعارات"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $page === $i ? 'current' : '' ?>" href="<?= e(app_url('/notifications.php?' . http_build_query(array_filter(['filter' => $unreadOnly ? 'unread' : null, 'page' => $i])))) ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?>
  <?php endif; ?>
</section>
<?php render_page_end(); ?>
