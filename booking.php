<?php
require_once __DIR__ . '/includes/auth.php';

$days = SCHEDULE_DAYS;
$date = trim((string) ($_GET['date'] ?? $_POST['booking_date'] ?? ''));
$day = trim((string) ($_GET['day'] ?? $_POST['day'] ?? ''));
$period = trim((string) ($_GET['period'] ?? $_POST['period'] ?? ''));
$teacherName = current_user()['name'] ?? '';
$className = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $teacherName = trim((string) ($_POST['teacher_name'] ?? ''));
    $className = trim((string) ($_POST['class_name'] ?? ''));
    $date = trim((string) ($_POST['booking_date'] ?? ''));
    $day = trim((string) ($_POST['day'] ?? ''));
    $period = trim((string) ($_POST['period'] ?? ''));
    $dayIndex = valid_date($date) ? (int) (new DateTimeImmutable($date))->format('w') : -1;

    if ($teacherName === '' || mb_strlen($teacherName) > 150 || $className === '' || mb_strlen($className) > 100) {
        $error = 'يرجى إدخال اسم المعلمة والصف بشكل صحيح.';
    } elseif (!valid_date($date) || $date < date('Y-m-d') || $dayIndex < 0 || $dayIndex > 4 || ($days[$dayIndex] ?? '') !== $day) {
        $error = 'اختاري تاريخًا صحيحًا من الأحد إلى الخميس.';
    } elseif (!ctype_digit($period) || (int) $period < 1 || (int) $period > PERIODS_PER_DAY) {
        $error = 'اختاري رقم حصة صحيحًا.';
    } else {
        $pdo = db();
        ensure_role_permissions_table($pdo);
        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare('SELECT id FROM bookings WHERE booking_date = ? AND period = ? AND status = ? FOR UPDATE');
            $check->execute([$date, (int) $period, 'booked']);
            if ($check->fetch()) {
                $pdo->rollBack();
                $error = 'عذرًا، تم حجز هذه الحصة للتو. اختاري موعدًا آخر.';
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO bookings (teacher_name, user_id, booking_date, day, period, class_name)
                     VALUES (:teacher_name, :user_id, :booking_date, :day, :period, :class_name)'
                );
                $user = current_user();
                $insert->execute([
                    'teacher_name' => $teacherName,
                    'user_id' => $user['id'] ?? null,
                    'booking_date' => $date,
                    'day' => $day,
                    'period' => (int) $period,
                    'class_name' => $className,
                ]);
                $bookingId = (int) $pdo->lastInsertId();
                audit_log($pdo, 'booking_created', 'booking', $bookingId, 'إنشاء حجز للقاعة', [
                    'booking_date' => $date,
                    'day' => $day,
                    'period' => (int) $period,
                    'teacher_name' => $teacherName,
                    'class_name' => $className,
                ]);
                $bookingMessage = 'حجز ' . $teacherName . ' للصف ' . $className . ' يوم ' . $day . '، الحصة ' . (int) $period . '.';
                notify_permission_holders(
                    $pdo,
                    'bookings.manage',
                    'booking',
                    'حجز جديد للقاعة',
                    $bookingMessage,
                    '/admin/bookings.php',
                    'booking',
                    $bookingId
                );
                if ($user !== null) {
                    create_notification(
                        $pdo,
                        (int) $user['id'],
                        'booking',
                        'تم تأكيد حجز القاعة',
                        'تم تأكيد حجزك يوم ' . $day . ' الموافق ' . $date . '، الحصة ' . (int) $period . '.',
                        '/schedule.php?week=' . rawurlencode($date),
                        'booking',
                        $bookingId,
                        false
                    );
                }
                $pdo->commit();
                flash('booking_success', 'تم تأكيد الحجز بنجاح، استمتعي بتجربة رقمية ممتازة.');
                redirect('/schedule.php?week=' . rawurlencode($date));
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $error = 'عذرًا، تم حجز هذه الحصة للتو. اختاري موعدًا آخر.';
            } else {
                throw $exception;
            }
        }
    }
}

if (valid_date($date)) {
    $dateIndex = (int) (new DateTimeImmutable($date))->format('w');
    $day = $days[$dateIndex] ?? $day;
}
$title = 'حجز القاعة';
render_page_start($title);
?>
<main class="public-container">
  <div class="page-heading">
    <div><h1>احجزي القاعة</h1><p>أكملي بيانات الموعد لتأكيد الحجز.</p></div>
    <a class="button button-quiet" href="<?= e(app_url('/schedule.php')) ?>">العودة إلى الجدول</a>
  </div>
  <section class="card form-card">
    <?php if ($error !== ''): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(app_url('/booking.php')) ?>">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field">
          <label for="teacher_name">اسم المعلمة</label>
          <input id="teacher_name" name="teacher_name" required maxlength="150" value="<?= e($teacherName) ?>">
        </div>
        <div class="field">
          <label for="class_name">الصف</label>
          <input id="class_name" name="class_name" required maxlength="100" placeholder="مثال: ثاني ثانوي ب" value="<?= e($className) ?>">
        </div>
        <div class="field">
          <label for="booking_date">التاريخ</label>
          <input id="booking_date" name="booking_date" type="date" min="<?= e(date('Y-m-d')) ?>" required value="<?= e($date) ?>" data-booking-date>
          <span class="field-hint">الحجز متاح من الأحد إلى الخميس.</span>
        </div>
        <div class="field">
          <label for="day">اليوم</label>
          <select id="day" name="day" required data-booking-day>
            <option value="">اختاري اليوم</option>
            <?php foreach ($days as $weekday): ?><option value="<?= e($weekday) ?>" <?= $day === $weekday ? 'selected' : '' ?>><?= e($weekday) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="period">الحصة</label>
          <select id="period" name="period" required>
            <option value="">اختاري الحصة</option>
            <?php for ($i = 1; $i <= PERIODS_PER_DAY; $i++): ?><option value="<?= $i ?>" <?= (string) $i === $period ? 'selected' : '' ?>>الحصة <?= $i ?></option><?php endfor; ?>
          </select>
        </div>
      </div>
      <div class="form-actions">
        <button class="button button-primary" type="submit">تأكيد الحجز</button>
        <a class="button button-quiet" href="<?= e(app_url('/schedule.php')) ?>">إلغاء</a>
      </div>
    </form>
  </section>
</main>
<?php render_page_end(); ?>
