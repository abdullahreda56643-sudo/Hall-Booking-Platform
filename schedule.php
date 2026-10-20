<?php
require_once __DIR__ . '/includes/auth.php';

$days = SCHEDULE_DAYS;
$requestedWeek = (string) ($_GET['week'] ?? date('Y-m-d'));
if (!valid_date($requestedWeek)) {
    $requestedWeek = date('Y-m-d');
}
$selectedDate = new DateTimeImmutable($requestedWeek);
$weekStart = $selectedDate->modify('-' . (int) $selectedDate->format('w') . ' days');
$weekEnd = $weekStart->modify('+4 days');
$today = new DateTimeImmutable('today');
$todayString = $today->format('Y-m-d');
$activeDayOffset = $today >= $weekStart && $today <= $weekEnd ? (int) $today->format('w') : 0;
$weekTransition = in_array((string) ($_GET['transition'] ?? ''), ['next', 'previous'], true)
    ? (string) $_GET['transition']
    : '';

$statement = db()->prepare(
    'SELECT b.id, b.teacher_name, b.booking_date, b.day, b.period, b.class_name, b.status
     FROM bookings AS b
     INNER JOIN (
       SELECT MAX(id) AS id
       FROM bookings
       WHERE booking_date BETWEEN ? AND ?
       GROUP BY booking_date, period
     ) AS latest ON latest.id = b.id
     ORDER BY b.booking_date, b.period'
);
$statement->execute([$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')]);
$slots = [];
foreach ($statement->fetchAll() as $booking) {
    $slots[$booking['booking_date'] . '_' . $booking['period']] = $booking;
}

$weekUrl = static fn (DateTimeImmutable $date): string => app_url('/schedule.php?week=' . $date->format('Y-m-d'));
$bookingUrl = static fn (string $date, string $day, int $period): string => app_url(
    '/booking.php?date=' . rawurlencode($date) . '&day=' . rawurlencode($day) . '&period=' . $period
);

function schedule_cell(string $state, ?array $booking, string $date, string $day, int $period, string $url, string $tooltip, string $searchText, bool $bookable): string
{
    $attributes = ' data-schedule-slot data-state="' . e($state) . '" data-search-text="' . e($searchText)
        . '" data-teacher="' . e($booking['teacher_name'] ?? '') . '" data-class="' . e($booking['class_name'] ?? '') . '"';
    $title = ' data-tooltip="' . e($tooltip) . '"';

    if ($state === 'booked') {
        return '<div class="schedule-cell schedule-booked"' . $attributes . $title . '>'
            . '<span class="schedule-cell-status"><svg aria-hidden="true" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><strong>موعد محجوز</strong></span>'
            . '<strong class="schedule-teacher">' . e($booking['teacher_name'] ?? '') . '</strong>'
            . '<small>' . e($booking['class_name'] ?? '') . '</small></div>';
    }

    if ($state === 'available' || ($state === 'cancelled' && $bookable)) {
        $class = $state === 'cancelled' ? 'schedule-cancelled' : 'schedule-free';
        $label = $state === 'cancelled' ? 'ملغاة · متاحة الآن' : 'احجزي الآن';
        return '<a class="schedule-cell ' . $class . '" href="' . e($url) . '" aria-label="'
            . e('احجزي الحصة ' . $period . ' يوم ' . $day . ' بتاريخ ' . $date) . '"' . $attributes . $title . '>'
            . '<span class="schedule-cell-status"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg><strong>' . e($label) . '</strong></span>'
            . '<span class="schedule-cell-action">احجزي الآن <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></span></a>';
    }

    return '<div class="schedule-cell schedule-past"' . $attributes . $title . '>'
        . '<span class="schedule-cell-status"><strong>' . ($state === 'cancelled' ? 'موعد ملغى' : 'انتهى الموعد') . '</strong></span>'
        . '<small>غير متاح للحجز</small></div>';
}

$title = 'جدول حجوزات القاعة';
render_page_start($title);
?>
<main class="public-container schedule-page" data-schedule-page>
  <div class="page-heading schedule-heading">
    <div>
      <div class="schedule-title-line"><h1>جدول حجوزات القاعة الرقمية</h1><span class="schedule-kicker">نظرة أسبوعية</span></div>
      <p>اختاري أي خانة متاحة لبدء الحجز مباشرة</p>
    </div>
    <a class="button button-primary schedule-current-week" href="<?= e($weekUrl($today)) ?>">عرض الأسبوع الحالي</a>
  </div>

  <section class="card schedule-card" aria-label="الجدول الأسبوعي للحجوزات">
    <div class="schedule-toolbar">
      <div class="schedule-week-heading">
        <div class="schedule-calendar-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
        </div>
        <div>
          <h2><?= e($weekStart->format('Y/m/d')) ?> <span>—</span> <?= e($weekEnd->format('Y/m/d')) ?></h2>
          <p>الحصص من الأولى إلى <?= (int) PERIODS_PER_DAY ?></p>
        </div>
      </div>
      <nav class="schedule-week-nav" aria-label="التنقل بين الأسابيع">
        <a href="<?= e($weekUrl($weekStart->modify('-7 days'))) ?>" data-week-direction="previous" aria-label="الأسبوع السابق">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg><span>الأسبوع السابق</span>
        </a>
        <a href="<?= e($weekUrl($weekStart->modify('+7 days'))) ?>" data-week-direction="next" aria-label="الأسبوع التالي">
          <span>الأسبوع التالي</span><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
        </a>
      </nav>
    </div>

    <div class="schedule-legend" aria-label="مفتاح ألوان حالات الحجز">
      <span class="schedule-legend-title">مفتاح الألوان</span>
      <span><i class="legend-dot legend-available"></i>متاحة للحجز</span>
      <span><i class="legend-dot legend-booked"></i>موعد محجوز</span>
      <span><i class="legend-dot legend-cancelled"></i>ملغاة وقابلة للحجز</span>
    </div>

    <div class="schedule-filters">
      <label class="schedule-search">
        <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
        <input type="search" placeholder="ابحثي باسم المعلمة أو الصف..." aria-label="البحث باسم المعلمة أو الصف" data-schedule-search>
      </label>
      <label class="schedule-filter-select">
        <span>البحث حسب</span>
        <select aria-label="تصفية البحث حسب" data-schedule-filter>
          <option value="all">المعلمة أو الصف</option>
          <option value="teacher">المعلمة</option>
          <option value="class">الصف</option>
        </select>
      </label>
      <span class="schedule-search-hint">تُبرز النتائج المطابقة</span>
    </div>

    <div class="schedule-day-tabs" role="tablist" aria-label="أيام الأسبوع" data-schedule-tabs>
      <?php foreach ($days as $offset => $weekday):
          $date = $weekStart->modify('+' . $offset . ' days');
          $isToday = $date->format('Y-m-d') === $todayString;
      ?>
        <button class="schedule-day-tab<?= $isToday ? ' is-today' : '' ?>" type="button" role="tab" id="schedule-tab-<?= $offset ?>" aria-controls="schedule-day-<?= $offset ?>" aria-selected="<?= $offset === $activeDayOffset ? 'true' : 'false' ?>" data-schedule-tab="<?= $offset ?>">
          <span><?= e($weekday) ?></span><small><?= e($date->format('d/m')) ?></small>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="schedule-loading-skeleton" aria-hidden="true" data-schedule-skeleton>
      <div class="schedule-skeleton-toolbar"></div>
      <?php for ($skeletonRow = 0; $skeletonRow < 5; $skeletonRow++): ?>
        <div class="schedule-skeleton-row"><i></i><i></i><i></i><i></i><i></i><i></i></div>
      <?php endfor; ?>
    </div>

    <div class="schedule-content<?= $weekTransition !== '' ? ' schedule-enter-' . e($weekTransition) : '' ?>" data-schedule-content>
      <div class="schedule-table-wrap">
        <table class="schedule-table">
          <thead>
            <tr>
              <th class="schedule-period-heading" scope="col">الحصة / اليوم</th>
              <?php foreach ($days as $offset => $weekday):
                  $date = $weekStart->modify('+' . $offset . ' days');
                  $isToday = $date->format('Y-m-d') === $todayString;
              ?>
                <th scope="col" class="<?= $isToday ? 'is-current-day' : '' ?>">
                  <span><?= e($weekday) ?></span>
                  <small><?= e($date->format('d/m')) ?></small>
                  <?php if ($isToday): ?><i>اليوم</i><?php endif; ?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php for ($period = 1; $period <= PERIODS_PER_DAY; $period++): ?>
              <tr>
                <th class="schedule-period" scope="row">الحصة <strong><?= $period ?></strong></th>
                <?php foreach ($days as $offset => $weekday):
                    $cellDate = $weekStart->modify('+' . $offset . ' days')->format('Y-m-d');
                    $booking = $slots[$cellDate . '_' . $period] ?? null;
                    $isToday = $cellDate === $todayString;
                    $bookable = $cellDate >= $todayString;
                    $state = $booking && $booking['status'] === 'booked' ? 'booked' : ($booking ? 'cancelled' : ($bookable ? 'available' : 'past'));
                    $searchText = $booking ? trim($booking['teacher_name'] . ' ' . $booking['class_name']) : '';
                    $tooltip = $booking
                        ? ($booking['status'] === 'booked' ? 'المعلمة: ' . $booking['teacher_name'] . ' · الصف: ' . $booking['class_name'] : 'الحجز السابق ملغى · الموعد متاح للحجز')
                        : ($bookable ? 'موعد متاح للحجز' : 'انتهى موعد هذه الحصة');
                    $url = $bookingUrl($cellDate, $weekday, $period);
                ?>
                  <td class="<?= $isToday ? 'is-current-day' : '' ?>" style="--cell-delay: <?= ($period + $offset) * 35 ?>ms">
                    <?= schedule_cell($state, $booking, $cellDate, $weekday, $period, $url, $tooltip, $searchText, $bookable) ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>

      <div class="schedule-mobile-days">
        <?php foreach ($days as $offset => $weekday):
            $date = $weekStart->modify('+' . $offset . ' days');
            $isToday = $date->format('Y-m-d') === $todayString;
        ?>
          <section class="schedule-mobile-day" id="schedule-day-<?= $offset ?>" role="tabpanel" aria-labelledby="schedule-tab-<?= $offset ?>" data-schedule-panel="<?= $offset ?>" <?= $offset === $activeDayOffset ? '' : 'hidden' ?>>
            <?php for ($period = 1; $period <= PERIODS_PER_DAY; $period++):
                $cellDate = $date->format('Y-m-d');
                $booking = $slots[$cellDate . '_' . $period] ?? null;
                $bookable = $cellDate >= $todayString;
                $state = $booking && $booking['status'] === 'booked' ? 'booked' : ($booking ? 'cancelled' : ($bookable ? 'available' : 'past'));
                $searchText = $booking ? trim($booking['teacher_name'] . ' ' . $booking['class_name']) : '';
                $tooltip = $booking
                    ? ($booking['status'] === 'booked' ? 'المعلمة: ' . $booking['teacher_name'] . ' · الصف: ' . $booking['class_name'] : 'الحجز السابق ملغى · الموعد متاح للحجز')
                    : ($bookable ? 'موعد متاح للحجز' : 'انتهى موعد هذه الحصة');
                $url = $bookingUrl($cellDate, $weekday, $period);
            ?>
              <div class="schedule-mobile-slot" data-schedule-slot data-state="<?= e($state) ?>" data-search-text="<?= e($searchText) ?>" data-teacher="<?= e($booking['teacher_name'] ?? '') ?>" data-class="<?= e($booking['class_name'] ?? '') ?>">
                <span class="schedule-mobile-period">الحصة <?= $period ?></span>
                <?= schedule_cell($state, $booking, $cellDate, $weekday, $period, $url, $tooltip, $searchText, $bookable) ?>
              </div>
            <?php endfor; ?>
            <p class="schedule-no-results" data-schedule-empty hidden>لا توجد حجوزات مطابقة لهذا البحث.</p>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</main>
<?php render_page_end(); ?>
