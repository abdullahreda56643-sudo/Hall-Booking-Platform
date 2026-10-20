<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('bookings.manage');
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
    if (!$bookingId) {
        http_response_code(400);
        exit('معرّف الحجز غير صالح.');
    }
    try {
        $pdo->beginTransaction();
        $bookingQuery = $pdo->prepare('SELECT user_id, teacher_name, booking_date, day, period, class_name FROM bookings WHERE id = ? AND status = "booked" FOR UPDATE');
        $bookingQuery->execute([$bookingId]);
        $booking = $bookingQuery->fetch();
        $update = $pdo->prepare('UPDATE bookings SET status = "cancelled" WHERE id = ? AND status = "booked"');
        $update->execute([$bookingId]);
        $cancelled = $booking && $update->rowCount() > 0;
        if ($cancelled) {
            audit_log($pdo, 'booking_cancelled', 'booking', $bookingId, 'إلغاء حجز القاعة', $booking);
            if ($booking['user_id'] !== null) {
                create_notification(
                    $pdo,
                    (int) $booking['user_id'],
                    'booking',
                    'تم إلغاء حجز القاعة',
                    'تم إلغاء حجزك يوم ' . $booking['day'] . ' الموافق ' . $booking['booking_date'] . '، الحصة ' . (int) $booking['period'] . '.',
                    '/schedule.php?week=' . rawurlencode($booking['booking_date']),
                    'booking',
                    (int) $bookingId,
                    false
                );
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    flash('success', $cancelled ? 'تم إلغاء الحجز.' : 'الحجز غير موجود أو سبق إلغاؤه.');
    redirect('/admin/bookings.php');
}
$conditions = [];
$params = [];
$status = (string) ($_GET['status'] ?? '');
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
if (in_array($status, ['booked', 'cancelled'], true)) {
    $conditions[] = 'status = :status';
    $params['status'] = $status;
}
if (valid_date($from)) {
    $conditions[] = 'booking_date >= :from_date';
    $params['from_date'] = $from;
}
if (valid_date($to)) {
    $conditions[] = 'booking_date <= :to_date';
    $params['to_date'] = $to;
}
if ($search !== '') {
    $conditions[] = '(teacher_name LIKE :query_teacher OR class_name LIKE :query_class)';
    $params['query_teacher'] = '%' . $search . '%';
    $params['query_class'] = '%' . $search . '%';
}
$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
$count = $pdo->prepare('SELECT COUNT(*) FROM bookings' . $where);
$count->execute($params);
$total = (int) $count->fetchColumn();
$perPage = 20;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$query = $pdo->prepare('SELECT * FROM bookings' . $where . ' ORDER BY booking_date DESC, period ASC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$query->execute($params);
$bookings = $query->fetchAll();
$title = 'إدارة الحجوزات';
render_page_start($title, true);
?>
<div class="page-heading"><div><h1>إدارة الحجوزات</h1><p>عرض الحجوزات المؤكدة والملغاة وإلغاء الحجز عند الحاجة.</p></div><a class="button button-quiet" href="<?= e(app_url('/schedule.php')) ?>">عرض الجدول العام</a></div>
<form method="get" class="filters card">
  <label class="filter-field">بحث<input type="search" name="q" value="<?= e($search) ?>" placeholder="اسم المعلمة أو الصف"></label>
  <label class="filter-field">الحالة<select name="status"><option value="">كل الحالات</option><option value="booked" <?= $status === 'booked' ? 'selected' : '' ?>>محجوز</option><option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>ملغى</option></select></label>
  <label class="filter-field">من تاريخ<input type="date" name="from" value="<?= e($from) ?>"></label>
  <label class="filter-field">إلى تاريخ<input type="date" name="to" value="<?= e($to) ?>"></label>
  <button class="button button-secondary" type="submit">تطبيق</button>
</form>
<section class="card card-pad">
  <?php if ($bookings === []): ?><div class="empty-state"><span class="empty-icon">▦</span><h2>لا توجد حجوزات</h2><p>لا توجد حجوزات تطابق عوامل التصفية.</p></div>
  <?php else: ?><div class="table-wrap mobile-cards"><table class="data-table">
    <thead><tr><th>المعلمة والصف</th><th>اليوم والتاريخ</th><th>الحصة</th><th>الحالة</th><th></th></tr></thead>
    <tbody><?php foreach ($bookings as $booking): ?><tr>
      <td data-label="المعلمة والصف"><?= e($booking['teacher_name']) ?><br><small><?= e($booking['class_name']) ?></small></td>
      <td data-label="التاريخ"><?= e($booking['day']) ?> · <?= e($booking['booking_date']) ?></td>
      <td data-label="الحصة"><?= (int) $booking['period'] ?></td>
      <td data-label="الحالة"><span class="badge <?= $booking['status'] === 'booked' ? 'badge-violet' : 'badge-rose' ?>"><?= $booking['status'] === 'booked' ? 'محجوز' : 'ملغى' ?></span></td>
      <td data-label="الإجراء"><?php if ($booking['status'] === 'booked'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>"><button class="link-button link-danger" type="submit" data-confirm="إلغاء هذا الحجز؟">إلغاء الحجز</button></form><?php else: ?>—<?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php if ($pages > 1): ?><nav class="pagination"><?php for ($i=1;$i<=$pages;$i++): ?><a class="<?= $page===$i?'current':'' ?>" href="<?= e(app_url('/admin/bookings.php?' . http_build_query(array_merge($_GET,['page'=>$i])))) ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?>
  <?php endif; ?>
</section>
<?php render_page_end(); ?>
