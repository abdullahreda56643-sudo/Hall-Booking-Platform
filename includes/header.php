<?php
require_once __DIR__ . '/functions.php';
$pageTitle = isset($title) ? $title . ' | ' . APP_NAME : APP_NAME;
$activeUser = function_exists('current_user') ? current_user() : null;
$unreadNotifications = $activeUser ? unread_notification_count(db(), $activeUser['id']) : 0;
$requestPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$showPreloader = !empty($GLOBALS['home_page']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f8fafc">
  <title><?= e($pageTitle) ?></title>
  <?php if ($showPreloader): ?><script>document.documentElement.classList.add('preloader-enabled');</script><?php endif; ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <link rel="preload" href="<?= e(app_url('/assets/fonts/tajawal-arabic-400.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="<?= e(app_url('/assets/fonts/tajawal-arabic-700.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/style.css?v=20261010-hall-responsive')) ?>">
</head>
<body class="<?= !empty($GLOBALS['home_page']) ? 'home-public-body' : '' ?>">
<?php if ($showPreloader): ?>
<div class="platform-preloader" data-platform-preloader role="status" aria-live="polite" aria-label="جارٍ تجهيز المنصة">
  <div class="preloader-glow preloader-glow-one" aria-hidden="true"></div>
  <div class="preloader-glow preloader-glow-two" aria-hidden="true"></div>
  <div class="preloader-content">
    <div class="preloader-hall-scene" aria-hidden="true">
      <svg class="preloader-hall" viewBox="0 0 420 300" role="presentation">
        <defs>
          <linearGradient id="hall-wall" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#fff"/><stop offset="1" stop-color="#e9e6fb"/></linearGradient>
          <linearGradient id="hall-roof" x1="0" y1="0" x2="1" y2="0"><stop stop-color="#818cf8"/><stop offset=".5" stop-color="#4f46e5"/><stop offset="1" stop-color="#3730a3"/></linearGradient>
          <linearGradient id="hall-interior" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#312e81"/><stop offset="1" stop-color="#4f46e5"/></linearGradient>
          <linearGradient id="hall-light" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#fff4ce"/><stop offset="1" stop-color="#f6bf67"/></linearGradient>
          <linearGradient id="hall-door-left" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#818cf8"/><stop offset="1" stop-color="#4f46e5"/></linearGradient>
          <linearGradient id="hall-door-right" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#6366f1"/><stop offset="1" stop-color="#4338ca"/></linearGradient>
          <linearGradient id="hall-floor" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#f5d99e" stop-opacity=".72"/><stop offset="1" stop-color="#f5d99e" stop-opacity="0"/></linearGradient>
          <radialGradient id="hall-aura"><stop stop-color="#4f46e5" stop-opacity=".27"/><stop offset="1" stop-color="#4f46e5" stop-opacity="0"/></radialGradient>
          <filter id="hall-shadow" x="-.3" y="-.3" width="1.6" height="1.8"><feGaussianBlur stdDeviation="7"/></filter>
          <clipPath id="hall-opening"><path d="M124 106h172v130H124z"/></clipPath>
        </defs>
        <ellipse cx="210" cy="261" rx="155" ry="20" fill="#312e81" opacity=".13" filter="url(#hall-shadow)"/>
        <ellipse cx="210" cy="151" rx="175" ry="128" fill="url(#hall-aura)"/>
        <path d="m44 93 29-44h274l29 44v19H44z" fill="url(#hall-roof)"/>
        <path d="m59 88 22-30h258l22 30z" fill="#fff" opacity=".17"/>
        <path d="M56 94h308v147H56z" fill="url(#hall-wall)" stroke="#ddd7f2" stroke-width="2"/>
        <path d="M48 91h324v13H48z" fill="#3730a3"/>
        <path d="M64 105h292v7H64z" fill="#c7d2fe"/>
        <path d="M70 113h27v116H70zM323 113h27v116h-27z" fill="#e1dcf2"/>
        <path d="M76 118h15v106H76zM329 118h15v106h-15z" fill="#fff" opacity=".8"/>
        <path d="M103 121h13v101h-13zM304 121h13v101h-13z" fill="#d3caec"/>
        <path d="M99 112h23v8H99zM298 112h23v8h-23z" fill="#6366f1"/>
        <path d="M118 101h184v142H118z" fill="#3730a3"/>
        <path d="M124 106h172v130H124z" fill="url(#hall-interior)"/>
        <g clip-path="url(#hall-opening)">
          <path class="hall-interior-glow" d="m210 112-103 133h206z" fill="url(#hall-floor)"/>
          <path d="M164 126h92v43h-92z" fill="#312e81" stroke="#a5b4fc" stroke-opacity=".7" stroke-width="2"/>
          <path d="M170 132h80v31h-80z" fill="#93e3e3"/>
          <path d="m174 157 18-15 13 10 13-12 27 23h-71z" fill="#79b8c5" opacity=".75"/>
          <path d="M206 172h8v17h-8z" fill="#c7d2fe"/>
          <path d="M190 189h40l12 8h-64z" fill="#a5b4fc"/>
          <path d="M181 200h58l12 8h-82z" fill="#818cf8"/>
          <path d="M173 211h74l13 9h-100z" fill="#6366f1"/>
          <path d="M165 223h90l11 8H154z" fill="#4f46e5"/>
          <path d="M195 177h30" stroke="url(#hall-light)" stroke-width="3" stroke-linecap="round"/>
          <circle cx="210" cy="180" r="3" fill="#fff1cb"/>
        </g>
        <path d="M119 102h182v6H119z" fill="#c7d2fe"/>
        <path d="M111 237h198v8H111z" fill="#4338ca"/>
        <g class="hall-door hall-door-left">
          <path d="M124 107h86v128h-86z" fill="url(#hall-door-left)" stroke="#c7d2fe" stroke-width="2"/>
          <path d="M133 116h67v109h-67z" fill="none" stroke="#c7d2fe" stroke-opacity=".72" stroke-width="2"/>
          <path d="M141 124h51v92h-51z" fill="#ffffff" fill-opacity=".055" stroke="#c7d2fe" stroke-opacity=".28"/>
          <path d="M198 169v13" stroke="#f3d394" stroke-width="3" stroke-linecap="round"/>
          <circle cx="198" cy="175.5" r="2.5" fill="#fff0c9"/>
        </g>
        <g class="hall-door hall-door-right">
          <path d="M210 107h86v128h-86z" fill="url(#hall-door-right)" stroke="#c7d2fe" stroke-width="2"/>
          <path d="M219 116h67v109h-67z" fill="none" stroke="#a5b4fc" stroke-opacity=".72" stroke-width="2"/>
          <path d="M227 124h51v92h-51z" fill="#ffffff" fill-opacity=".045" stroke="#a5b4fc" stroke-opacity=".25"/>
          <path d="M222 169v13" stroke="#f3d394" stroke-width="3" stroke-linecap="round"/>
          <circle cx="222" cy="175.5" r="2.5" fill="#fff0c9"/>
        </g>
        <path d="M42 240h336v11H42z" fill="#a5b4fc"/>
        <path d="M54 251h312v8H54z" fill="#6366f1"/>
        <path d="M69 259h282v8H69z" fill="#c7d2fe"/>
        <path d="M60 109h5v115h-5zM355 109h5v115h-5z" fill="#a5b4fc"/>
        <path d="M179 73h62" stroke="#fff" stroke-opacity=".74" stroke-width="2" stroke-linecap="round"/>
        <circle cx="168" cy="73" r="2.5" fill="#f4d28d"/><circle cx="252" cy="73" r="2.5" fill="#f4d28d"/>
        <path d="M83 237v-9h11v9M326 237v-9h11v9" fill="#6366f1"/>
        <path d="M81 228h15M324 228h15" stroke="#f3d394" stroke-width="2"/>
        <path d="m77 113 15 0v2l-15 4zM328 113h15v6l-15-4z" fill="#fff" opacity=".55"/>
      </svg>
      <span class="preloader-scene-spark scene-spark-one"></span>
      <span class="preloader-scene-spark scene-spark-two"></span>
    </div>
    <span class="preloader-kicker">مساحة للتعلّم والإبداع</span>
    <strong class="preloader-title">قاعة التعلم الرقمية</strong>
    <span class="preloader-caption">نُهيّئ لكِ تجربة تعليمية مميّزة</span>
    <span class="preloader-progress" aria-hidden="true"><span></span></span>
    <span class="preloader-status">لحظات ونبدأ</span>
  </div>
  <span class="preloader-footer" aria-hidden="true"><?= e(APP_NAME) ?> <i></i> مساحة تعلم أفضل</span>
</div>
<?php endif; ?>
<div id="toast-root" aria-live="polite" class="toast-root">
  <?php foreach (take_flashes() as $notice): ?>
    <?php if ($notice['type'] === 'booking_success'): ?>
      <div class="booking-success-backdrop" data-booking-success-backdrop>
        <section class="booking-success-dialog" role="dialog" aria-modal="true" aria-labelledby="booking-success-title" aria-describedby="booking-success-message" tabindex="-1" data-booking-success-dialog>
          <button class="booking-success-close" type="button" aria-label="إغلاق رسالة التأكيد" data-booking-success-close>×</button>
          <span class="booking-success-icon" aria-hidden="true">
            <svg viewBox="0 0 48 48"><path d="M24 4.5 29.8 8l6.8.7 2.9 6.1 5.3 4.4-1.6 6.7 1.6 6.7-5.3 4.4-2.9 6.1-6.8.7L24 47l-5.8-3.5-6.8-.7-2.9-6.1-5.3-4.4 1.6-6.7-1.6-6.7 5.3-4.4 2.9-6.1 6.8-.7L24 4.5Z"/><path class="booking-success-check" d="m15.5 24.5 5.8 5.8 11.8-12"/></svg>
          </span>
          <span class="booking-success-kicker">قاعة التعلم الرقمية</span>
          <h2 id="booking-success-title">تم تأكيد حجزك بنجاح!</h2>
          <p id="booking-success-message"><?= e($notice['message']) ?></p>
          <a class="button button-primary booking-success-action" href="<?= e(app_url('/schedule.php')) ?>">رائع، عرض جدول الحجوزات</a>
          <span class="booking-success-decoration" aria-hidden="true">✦</span>
        </section>
      </div>
    <?php else: ?>
      <div class="toast toast-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<header class="topbar <?= !empty($GLOBALS['home_page']) ? 'home-topbar' : '' ?>">
  <div class="topbar-inner">
    <a href="<?= e(app_url($activeUser ? role_home($activeUser['role']) : '/index.php')) ?>" class="brand <?= !empty($GLOBALS['home_page']) ? 'home-brand' : '' ?>">
      <span class="brand-mark" aria-hidden="true">
        <?php if (!empty($GLOBALS['home_page'])): ?>
          <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2.5"/><path d="M8 21h8m-4-4v4"/><rect x="17" y="13" width="5" height="8" rx="1.4"/></svg>
        <?php else: ?>ق<?php endif; ?>
      </span>
      <span class="brand-copy">
        <span><?= !empty($GLOBALS['home_page']) ? e(APP_NAME) : 'القاعة الرقمية' ?></span>
        <?php if (!empty($GLOBALS['home_page'])): ?><small>قاعة التعلم الرقمية</small><?php endif; ?>
      </span>
    </a>
    <?php if (!empty($GLOBALS['home_page'])): ?>
      <nav class="home-nav" aria-label="التنقل الرئيسي">
        <a class="home-nav-link is-active" href="<?= e(app_url('/index.php')) ?>">الرئيسية</a>
        <a class="home-nav-link" href="<?= e(app_url('/booking.php')) ?>">حجز القاعة</a>
        <a class="home-nav-link" href="<?= e(app_url('/schedule.php')) ?>">جدول الحجوزات</a>
      </nav>
      <div class="topbar-actions">
        <?php if ($activeUser): ?>
          <a class="notification-bell <?= str_ends_with($requestPath, '/notifications.php') ? 'is-active' : '' ?>" href="<?= e(app_url('/notifications.php')) ?>" aria-label="<?= e($unreadNotifications > 0 ? 'الإشعارات، ' . $unreadNotifications . ' غير مقروء' : 'الإشعارات') ?>" title="الإشعارات">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            <?php if ($unreadNotifications > 0): ?><span class="notification-bell-count" aria-hidden="true"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span><?php endif; ?>
          </a>
          <a class="button button-primary home-login-button" href="<?= e(app_url(role_home($activeUser['role']))) ?>">
            لوحة التحكم
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5m5 5H3"/></svg>
          </a>
        <?php else: ?>
          <a class="button button-primary home-login-button" href="<?= e(app_url('/login.php')) ?>">
            دخول
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5m5 5H3"/></svg>
          </a>
        <?php endif; ?>
        <button type="button" class="home-menu-toggle" data-home-drawer-toggle aria-label="فتح القائمة" aria-expanded="false" aria-controls="home-mobile-drawer">
          <span></span><span></span><span></span>
        </button>
      </div>
      <div class="home-drawer-backdrop" data-home-drawer-backdrop></div>
      <nav id="home-mobile-drawer" class="home-mobile-drawer" data-home-drawer aria-label="قائمة الموبايل" inert>
        <a class="home-nav-link is-active" href="<?= e(app_url('/index.php')) ?>">الرئيسية</a>
        <a class="home-nav-link" href="<?= e(app_url('/booking.php')) ?>">حجز القاعة</a>
        <a class="home-nav-link" href="<?= e(app_url('/schedule.php')) ?>">جدول الحجوزات</a>
        <?php if ($activeUser): ?>
          <a class="button button-primary" href="<?= e(app_url(role_home($activeUser['role']))) ?>">لوحة التحكم</a>
        <?php else: ?>
          <a class="button button-primary" href="<?= e(app_url('/login.php')) ?>">دخول</a>
        <?php endif; ?>
      </nav>
    <?php elseif ($activeUser): ?>
      <div class="topbar-actions">
        <span class="topbar-user"><?= e($activeUser['name']) ?> <span>· <?= e(ROLE_LABELS[$activeUser['role']] ?? '') ?></span></span>
        <a class="notification-bell <?= str_ends_with($requestPath, '/notifications.php') ? 'is-active' : '' ?>" href="<?= e(app_url('/notifications.php')) ?>" aria-label="<?= e($unreadNotifications > 0 ? 'الإشعارات، ' . $unreadNotifications . ' غير مقروء' : 'الإشعارات') ?>" title="الإشعارات">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
          <?php if ($unreadNotifications > 0): ?><span class="notification-bell-count" aria-hidden="true"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span><?php endif; ?>
        </a>
        <form method="post" action="<?= e(app_url('/logout.php')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <button class="button button-quiet button-sm" type="submit">تسجيل الخروج</button>
        </form>
        <button type="button" class="menu-toggle" data-menu-toggle aria-label="فتح القائمة">☰</button>
      </div>
    <?php else: ?>
      <nav class="topbar-actions" aria-label="القائمة الرئيسية">
        <a class="button button-quiet button-sm" href="<?= e(app_url('/schedule.php')) ?>">جدول الحجوزات</a>
      </nav>
    <?php endif; ?>
  </div>
</header>
