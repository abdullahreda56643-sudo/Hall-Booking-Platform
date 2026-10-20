<?php
header('Content-Type: application/json; charset=utf-8');
$chartStage = 'authorization';

set_exception_handler(static function (Throwable $exception) use (&$chartStage): void {
    error_log(sprintf(
        'تعذر تحميل إحصائيات لوحة التحكم (المرحلة: %s): %s',
        $chartStage,
        $exception->getMessage()
    ));
    http_response_code(500);
    echo json_encode([
        'error' => 'تعذر تحميل الإحصائيات حاليًا.',
        'diagnostic' => $chartStage,
    ], JSON_UNESCAPED_UNICODE);
});

require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('statistics.view', true);

$range = (string) ($_GET['range'] ?? '30d');
$today = new DateTimeImmutable('today');
if ($range === 'custom') {
    $fromInput = (string) ($_GET['from'] ?? '');
    $toInput = (string) ($_GET['to'] ?? '');
    if (!valid_date($fromInput) || !valid_date($toInput) || $fromInput > $toInput) {
        http_response_code(422);
        echo json_encode(['error' => 'حددي فترة زمنية صحيحة.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $from = new DateTimeImmutable($fromInput);
    $to = new DateTimeImmutable($toInput);
} else {
    $days = match ($range) {
        '7d' => 7,
        '90d' => 90,
        default => 30,
    };
    $from = $today->modify('-' . ($days - 1) . ' days');
    $to = $today;
}
$span = $from->diff($to)->days + 1;
if ($span > 366) {
    http_response_code(422);
    echo json_encode(['error' => 'الفترة المخصصة لا يمكن أن تتجاوز سنة واحدة.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$fromDate = $from->format('Y-m-d');
$toExclusive = $to->modify('+1 day')->format('Y-m-d');
$previousFrom = $from->modify('-' . $span . ' days')->format('Y-m-d');
$previousTo = $fromDate;
$chartStage = 'database_connection';
$pdo = db();

$chartStage = 'report_summary';
$summaryQuery = $pdo->prepare(
    'SELECT
       COUNT(*) AS total,
       SUM(status = "submitted") AS submitted,
       SUM(status = "approved") AS approved,
       SUM(status = "rejected") AS rejected,
       SUM(status = "draft") AS draft
     FROM reports WHERE created_at >= ? AND created_at < ?'
);
$summaryQuery->execute([$fromDate, $toExclusive]);
$summary = $summaryQuery->fetch();
$previousQuery = $pdo->prepare(
    'SELECT COUNT(*) AS total, SUM(status = "submitted") AS submitted,
       SUM(status = "approved") AS approved, SUM(status = "rejected") AS rejected
     FROM reports WHERE created_at >= ? AND created_at < ?'
);
$previousQuery->execute([$previousFrom, $previousTo]);
$previous = $previousQuery->fetch();
$changes = [];
foreach (['total', 'submitted', 'approved', 'rejected'] as $key) {
    $currentValue = (int) ($summary[$key] ?? 0);
    $previousValue = (int) ($previous[$key] ?? 0);
    $changes[$key] = $previousValue === 0 ? ($currentValue > 0 ? 100 : 0) : round((($currentValue - $previousValue) / $previousValue) * 100, 1);
}
$chartStage = 'active_users';
$activeUsersQuery = $pdo->prepare(
    'SELECT COUNT(DISTINCT logs.user_id) FROM audit_logs logs
     JOIN users u ON u.id = logs.user_id
     WHERE u.is_active = 1 AND logs.action = "login_success" AND logs.created_at >= ? AND logs.created_at < ?'
);
$activeUsersQuery->execute([$fromDate, $toExclusive]);
$summary['active_users'] = (int) $activeUsersQuery->fetchColumn();
$previousActiveUsersQuery = $pdo->prepare(
    'SELECT COUNT(DISTINCT logs.user_id) FROM audit_logs logs
     JOIN users u ON u.id = logs.user_id
     WHERE u.is_active = 1 AND logs.action = "login_success" AND logs.created_at >= ? AND logs.created_at < ?'
);
$previousActiveUsersQuery->execute([$previousFrom, $previousTo]);
$previousActiveUsers = (int) $previousActiveUsersQuery->fetchColumn();
$chartStage = 'weekly_bookings';
$weekStart = $today->modify('-' . (int) $today->format('w') . ' days')->format('Y-m-d');
$weekEnd = $today->modify('+' . (4 - (int) $today->format('w')) . ' days')->format('Y-m-d');
$weekBookings = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE status = "booked" AND booking_date BETWEEN ? AND ?');
$weekBookings->execute([$weekStart, $weekEnd]);
$summary['week_bookings'] = (int) $weekBookings->fetchColumn();
$previousWeekBookings = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE status = "booked" AND booking_date BETWEEN ? AND ?');
$previousWeekBookings->execute([
    (new DateTimeImmutable($weekStart))->modify('-7 days')->format('Y-m-d'),
    (new DateTimeImmutable($weekEnd))->modify('-7 days')->format('Y-m-d'),
]);
$previousWeekBookingCount = (int) $previousWeekBookings->fetchColumn();
$changes['active_users'] = $previousActiveUsers === 0
    ? ($summary['active_users'] > 0 ? 100 : 0)
    : round((($summary['active_users'] - $previousActiveUsers) / $previousActiveUsers) * 100, 1);
$changes['week_bookings'] = $previousWeekBookingCount === 0
    ? ($summary['week_bookings'] > 0 ? 100 : 0)
    : round((($summary['week_bookings'] - $previousWeekBookingCount) / $previousWeekBookingCount) * 100, 1);

$chartStage = 'submitted_reports_trend';
$dateBuckets = [];
$dateCursor = $from;
while ($dateCursor <= $to) {
    $key = $dateCursor->format('Y-m-d');
    $dateBuckets[$key] = 0;
    $dateCursor = $dateCursor->modify('+1 day');
}
$line = $pdo->prepare(
    'SELECT DATE(submitted_at) AS report_day, COUNT(*) AS total FROM reports
     WHERE submitted_at >= ? AND submitted_at < ? GROUP BY DATE(submitted_at) ORDER BY report_day'
);
$line->execute([$fromDate, $toExclusive]);
foreach ($line->fetchAll() as $row) {
    if (array_key_exists($row['report_day'], $dateBuckets)) {
        $dateBuckets[$row['report_day']] = (int) $row['total'];
    }
}
$sparklines = [
    'total' => array_fill_keys(array_keys($dateBuckets), 0),
    'submitted' => array_fill_keys(array_keys($dateBuckets), 0),
    'approved' => array_fill_keys(array_keys($dateBuckets), 0),
    'rejected' => array_fill_keys(array_keys($dateBuckets), 0),
    'active_users' => array_fill_keys(array_keys($dateBuckets), 0),
];
$chartStage = 'report_sparklines';
$reportTrendQuery = $pdo->prepare(
    'SELECT DATE(created_at) AS report_day, status, COUNT(*) AS total FROM reports
     WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at), status ORDER BY report_day'
);
$reportTrendQuery->execute([$fromDate, $toExclusive]);
foreach ($reportTrendQuery->fetchAll() as $row) {
    $day = $row['report_day'];
    if (!array_key_exists($day, $sparklines['total'])) {
        continue;
    }
    $count = (int) $row['total'];
    $sparklines['total'][$day] += $count;
    if (array_key_exists($row['status'], $sparklines)) {
        $sparklines[$row['status']][$day] += $count;
    }
}
$chartStage = 'active_users_sparkline';
$activeUsersTrendQuery = $pdo->prepare(
    'SELECT DATE(logs.created_at) AS login_day, COUNT(DISTINCT logs.user_id) AS total
     FROM audit_logs logs JOIN users u ON u.id = logs.user_id
     WHERE u.is_active = 1 AND logs.action = "login_success" AND logs.created_at >= ? AND logs.created_at < ?
     GROUP BY DATE(logs.created_at) ORDER BY login_day'
);
$activeUsersTrendQuery->execute([$fromDate, $toExclusive]);
foreach ($activeUsersTrendQuery->fetchAll() as $row) {
    if (array_key_exists($row['login_day'], $sparklines['active_users'])) {
        $sparklines['active_users'][$row['login_day']] = (int) $row['total'];
    }
}
$chartStage = 'booking_sparkline';
$bookingSparkline = [];
$bookingDayQuery = $pdo->prepare(
    'SELECT booking_date, COUNT(*) AS total FROM bookings
     WHERE status = "booked" AND booking_date BETWEEN ? AND ? GROUP BY booking_date ORDER BY booking_date'
);
$bookingDayQuery->execute([$weekStart, $weekEnd]);
foreach ($bookingDayQuery->fetchAll() as $row) {
    $bookingSparkline[$row['booking_date']] = (int) $row['total'];
}
$sparklines['week_bookings'] = [];
for ($day = new DateTimeImmutable($weekStart); $day <= new DateTimeImmutable($weekEnd); $day = $day->modify('+1 day')) {
    $key = $day->format('Y-m-d');
    $sparklines['week_bookings'][] = $bookingSparkline[$key] ?? 0;
}
$chartStage = 'report_status_distribution';
$statusQuery = $pdo->prepare('SELECT status, COUNT(*) AS total FROM reports WHERE created_at >= ? AND created_at < ? GROUP BY status');
$statusQuery->execute([$fromDate, $toExclusive]);
$statusCounts = array_fill_keys(REPORT_STATUSES, 0);
foreach ($statusQuery->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}
$chartStage = 'reports_by_user';
$topUsersQuery = $pdo->prepare(
    'SELECT u.name, COUNT(r.id) AS total FROM reports r JOIN users u ON u.id = r.user_id
     WHERE r.created_at >= ? AND r.created_at < ? GROUP BY u.id, u.name ORDER BY total DESC, u.name LIMIT 10'
);
$topUsersQuery->execute([$fromDate, $toExclusive]);
$topUsers = $topUsersQuery->fetchAll();
$chartStage = 'report_role_comparison';
$roleQuery = $pdo->prepare(
    'SELECT u.role, r.status, COUNT(*) AS total FROM reports r JOIN users u ON u.id = r.user_id
     WHERE r.created_at >= ? AND r.created_at < ? GROUP BY u.role, r.status'
);
$roleQuery->execute([$fromDate, $toExclusive]);
$roleCounts = [ROLE_STAFF => array_fill_keys(REPORT_STATUSES, 0)];
foreach ($roleQuery->fetchAll() as $row) {
    if (isset($roleCounts[$row['role']])) {
        $roleCounts[$row['role']][$row['status']] = (int) $row['total'];
    }
}
$chartStage = 'booking_heatmap_prepare';
$bookingQuery = $pdo->prepare(
    'SELECT DAYOFWEEK(b.booking_date) AS weekday_number, b.`period`, COUNT(*) AS total FROM bookings AS b
     WHERE b.status = ? AND b.booking_date >= ? AND b.booking_date < ?
     GROUP BY DAYOFWEEK(b.booking_date), b.`period`
     ORDER BY weekday_number, b.`period`'
);
$chartStage = 'booking_heatmap_execute';
$bookingQuery->execute(['booked', $fromDate, $toExclusive]);
$heat = array_fill_keys(['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'], array_fill(1, 8, 0));
$weekdays = [1 => 'الأحد', 2 => 'الإثنين', 3 => 'الثلاثاء', 4 => 'الأربعاء', 5 => 'الخميس'];
$chartStage = 'booking_heatmap_fetch';
foreach ($bookingQuery->fetchAll() as $row) {
    $weekday = $weekdays[(int) $row['weekday_number']] ?? null;
    $period = (int) $row['period'];
    if ($weekday !== null && isset($heat[$weekday][$period])) {
        $heat[$weekday][$period] = (int) $row['total'];
    }
}
$chartStage = 'monthly_approval';
$monthStart = $from->modify('first day of this month');
$monthEnd = $to->modify('first day of this month');
$months = [];
for ($month = $monthStart; $month <= $monthEnd; $month = $month->modify('+1 month')) {
    $months[$month->format('Y-m')] = ['approved' => 0, 'reviewed' => 0];
}
$approvalQuery = $pdo->prepare(
    'SELECT DATE_FORMAT(reviewed_at, "%Y-%m") AS month_key,
       SUM(status = "approved") AS approved, COUNT(*) AS reviewed
     FROM reports WHERE reviewed_at >= ? AND reviewed_at < ? AND status IN ("approved", "rejected")
     GROUP BY DATE_FORMAT(reviewed_at, "%Y-%m")'
);
$approvalQuery->execute([$fromDate, $toExclusive]);
foreach ($approvalQuery->fetchAll() as $row) {
    if (isset($months[$row['month_key']])) {
        $months[$row['month_key']] = ['approved' => (int) $row['approved'], 'reviewed' => (int) $row['reviewed']];
    }
}
$monthNames = ['01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'أبريل', '05' => 'مايو', '06' => 'يونيو', '07' => 'يوليو', '08' => 'أغسطس', '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر'];
$approvalLabels = [];
$approvalValues = [];
foreach ($months as $key => $values) {
    [$year, $month] = explode('-', $key);
    $approvalLabels[] = $monthNames[$month] . ' ' . $year;
    $approvalValues[] = $values['reviewed'] === 0 ? 0 : round(($values['approved'] / $values['reviewed']) * 100, 1);
}

$chartStage = 'json_encoding';
echo json_encode([
    'period' => ['from' => $fromDate, 'to' => $to->format('Y-m-d')],
    'summary' => array_map('intval', $summary),
    'changes' => $changes,
    'sparklines' => array_map('array_values', $sparklines),
    'sent_over_time' => ['labels' => array_keys($dateBuckets), 'values' => array_values($dateBuckets)],
    'status_distribution' => [
        'labels' => array_map(static fn(string $key): string => REPORT_STATUS_LABELS[$key], REPORT_STATUSES),
        'values' => array_values($statusCounts),
    ],
    'reports_by_user' => ['labels' => array_column($topUsers, 'name'), 'values' => array_map('intval', array_column($topUsers, 'total'))],
    'role_comparison' => [
        'labels' => [ROLE_LABELS[ROLE_STAFF]],
        'statuses' => array_map(static fn(string $key): string => REPORT_STATUS_LABELS[$key], REPORT_STATUSES),
        'values' => [$roleCounts[ROLE_STAFF]],
    ],
    'booking_heatmap' => [
        'labels' => array_keys($heat),
        'periods' => array_map(static fn(int $period): string => 'الحصة ' . $period, range(1, 8)),
        'values' => array_map('array_values', array_values($heat)),
    ],
    'monthly_approval' => ['labels' => $approvalLabels, 'values' => $approvalValues],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
