<?php
require_once __DIR__ . '/includes/auth.php';
$pdo = db();
$hallProfileStatement = $pdo->prepare('SELECT * FROM hall_profile WHERE id = 1');
$hallProfileStatement->execute();
$hallProfile = $hallProfileStatement->fetch();
$hallEvidence = $pdo->query('SELECT title, description, image_path FROM hall_evidence ORDER BY sort_order, id')->fetchAll();
$hallProfile = $hallProfile ?: [
    'section_title' => 'عن قاعة التعلم الرقمية',
    'section_intro' => 'تعرفي على تاريخ القاعة وأهدافها والتقنيات المستخدمة فيها.',
    'opened_on' => null,
    'purpose' => '',
    'technologies' => '',
    'contributors' => '',
    'is_visible' => 1,
];
$hallTechnologies = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $hallProfile['technologies']) ?: [])));
$hallContributors = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $hallProfile['contributors']) ?: [])));
$today = new DateTimeImmutable('today');
$weekStart = $today->modify('-' . (int) $today->format('w') . ' days');
$weekEnd = $weekStart->modify('+4 days');
$firstBookableDate = $today > $weekStart ? $today : $weekStart;
$bookableDays = 0;
for ($date = $firstBookableDate; $date <= $weekEnd; $date = $date->modify('+1 day')) {
    if (in_array(SCHEDULE_DAYS[(int) $date->format('w')] ?? null, SCHEDULE_DAYS, true)) {
        $bookableDays++;
    }
}
$availableSlots = $bookableDays * PERIODS_PER_DAY;
$statement = $pdo->prepare(
    'SELECT
       COALESCE(SUM(latest.status = "booked" AND latest.booking_date >= ?), 0) AS booked,
       COALESCE(SUM(latest.status = "cancelled" AND latest.booking_date >= ?), 0) AS upcoming_cancelled
     FROM bookings AS latest
     INNER JOIN (
       SELECT MAX(id) AS id
       FROM bookings
       WHERE booking_date BETWEEN ? AND ?
       GROUP BY booking_date, period
     ) AS current_slots ON current_slots.id = latest.id'
);
$statement->execute([
    $firstBookableDate->format('Y-m-d'),
    $firstBookableDate->format('Y-m-d'),
    $weekStart->format('Y-m-d'),
    $weekEnd->format('Y-m-d'),
]);
$bookingSummary = $statement->fetch() ?: ['booked' => 0, 'upcoming_cancelled' => 0];
$bookingSummary['booked'] = (int) $bookingSummary['booked'];
$bookingSummary['upcoming_cancelled'] = (int) $bookingSummary['upcoming_cancelled'];
$bookingSummary['available'] = max(0, $availableSlots - $bookingSummary['booked'] - $bookingSummary['upcoming_cancelled']);
$bookingSummary['booked_percent'] = $availableSlots === 0 ? 0 : min(100, (int) round($bookingSummary['booked'] / $availableSlots * 100));
$bookingSummary['cancelled_percent'] = $availableSlots === 0 ? 0 : min(100 - $bookingSummary['booked_percent'], (int) round($bookingSummary['upcoming_cancelled'] / $availableSlots * 100));
$bookingSummary['available_percent'] = max(0, 100 - $bookingSummary['booked_percent'] - $bookingSummary['cancelled_percent']);
$GLOBALS['home_page'] = true;
$title = 'الرئيسية';
render_page_start($title);
?>
<main class="public-container">
  <section class="hero home-hero">
    <span class="hero-orb hero-orb-one" aria-hidden="true"></span>
    <span class="hero-orb hero-orb-two" aria-hidden="true"></span>
    <span class="hero-orb hero-orb-three" aria-hidden="true"></span>
    <div class="hero-copy">
      <span class="hero-eyebrow home-reveal">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3Z"/><path d="m19 15 .9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15ZM5 3l.7 1.3L7 5l-1.3.7L5 7l-.7-1.3L3 5l1.3-.7L5 3Z"/></svg>
        قاعة تعليمية مصممة للإلهام
      </span>
      <h1 class="home-hero-title">
        <svg class="hero-laptop" aria-hidden="true" viewBox="0 0 48 48"><rect x="8" y="8" width="32" height="24" rx="3"/><path d="M4 38h40l-3 4H7l-3-4Z"/><path d="M19 36h10"/></svg>
        <span>تعلّم يتجاوز حدود</span><span class="hero-title-accent">القاعة التقليدية</span>
      </h1>
      <h2 class="home-hero-subtitle home-reveal">قاعة التعلم الرقمية</h2>
      <p class="home-reveal">احجزي قاعتك بسهولة وابدئي تجربة تعليمية رقمية مميزة.</p>
      <div class="hero-actions">
        <a class="button button-primary home-reveal" href="<?= e(app_url('/booking.php')) ?>">
          احجزي القاعة
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <a class="button button-quiet home-reveal" href="<?= e(app_url('/schedule.php')) ?>">جدول الحجوزات</a>
      </div>
    </div>
    <div class="hero-art home-hero-art home-reveal" aria-hidden="true">
      <div class="home-visual-orbit home-visual-orbit-one"></div>
      <div class="home-visual-orbit home-visual-orbit-two"></div>
      <div class="home-visual-card">
        <div class="home-visual-topline"><span></span><span></span><span></span><i></i></div>
        <div class="home-visual-scene">
          <div class="home-visual-sun"></div>
          <div class="home-visual-window"><span></span><i></i></div>
          <div class="home-visual-board"><span></span><i></i><b></b></div>
          <div class="home-visual-shelf"><span></span><i></i><b></b></div>
          <div class="home-visual-desk"><span></span><i></i></div>
          <div class="home-visual-chair"><span></span><i></i></div>
          <div class="home-visual-plant"><span></span><i></i><b></b></div>
          <div class="home-visual-floor"></div>
        </div>
        <div class="home-visual-caption"><span class="home-visual-caption-icon">✦</span><span><strong>مساحة تصنع الفرق</strong><small>تعلّم تفاعلي في بيئة ملهمة</small></span><span class="home-visual-caption-dot"></span></div>
      </div>
      <div class="home-visual-float home-visual-float-calendar"><span>✓</span><span><strong>حجز سهل</strong><small>خطوات بسيطة</small></span></div>
      <div class="home-visual-float home-visual-float-live"><i></i><span>جدول محدّث</span></div>
      <span class="home-visual-spark home-visual-spark-one">✦</span>
      <span class="home-visual-spark home-visual-spark-two">✧</span>
    </div>
  </section>

  <section class="home-highlights home-reveal" aria-label="مميزات منصة حجز القاعة">
    <article class="home-highlight">
      <span class="home-highlight-icon home-highlight-icon-violet" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="3"/><path d="M16 2v4M8 2v4M3 9h18"/><path d="m9 15 2 2 4-4"/></svg>
      </span>
      <span><strong>جدول واضح</strong><small>تصفحي مواعيد القاعة بسهولة</small></span>
    </article>
    <span class="home-highlight-divider" aria-hidden="true"></span>
    <article class="home-highlight">
      <span class="home-highlight-icon home-highlight-icon-cyan" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 3v3m0 12v3M3 12h3m12 0h3"/><circle cx="12" cy="12" r="7"/><path d="m9 12 2 2 4-4"/></svg>
      </span>
      <span><strong>حجز بخطوات بسيطة</strong><small>اختاري الموعد وأكملي البيانات</small></span>
    </article>
    <span class="home-highlight-divider" aria-hidden="true"></span>
    <article class="home-highlight">
      <span class="home-highlight-icon home-highlight-icon-green" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg>
      </span>
      <span><strong>حالة محدثة</strong><small>تحققي من توفر الموعد قبل الحجز</small></span>
    </article>
  </section>

  <section class="public-section home-overview home-reveal">
    <div class="public-section-heading">
      <div><h2>قاعة رقمية، وتجربة أكثر ثراءً</h2><p>مكان تلتقي فيه التقنية بالتعلّم التفاعلي</p></div>
    </div>
    <div class="home-overview-grid">
      <article class="home-room-card card home-hover-card">
        <div class="home-card-icon tablet-icon">
          <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="5" y="2.5" width="14" height="19" rx="2.5"/><path d="M10 18.5h4"/></svg>
        </div>
        <div class="home-room-copy">
          <span class="home-card-kicker">تعلّم بلا حدود</span>
          <h3>القاعة الرقمية</h3>
          <p>تابعي جدول القاعة واختاري الموعد المتاح المناسب لاحتياجك. تظهر حالة الحجز وتفاصيله مباشرةً من بيانات المنصة.</p>
        </div>
        <div class="home-room-decoration" aria-hidden="true">✧</div>
      </article>
      <article class="home-status-card card home-hover-card">
        <div class="home-status-heading">
          <div class="home-card-icon status-icon">
            <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="3"/><path d="M16 2v4M8 2v4M3 9h18"/><path d="m9 15 2 2 4-4"/></svg>
          </div>
          <div><span class="home-card-kicker">الفترة المتبقية من الأسبوع</span><h3>حالة الجدول</h3></div>
          <a class="home-status-link" href="<?= e(app_url('/schedule.php')) ?>" aria-label="عرض جدول الحجوزات">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </a>
        </div>
        <div class="home-status-total"><strong data-count="<?= (int) $bookingSummary['available'] ?>"><?= (int) $bookingSummary['available'] ?></strong><span>موعد متاح متبقٍ هذا الأسبوع</span></div>
        <p class="home-status-note">المتاح لم يُحجز، والمحجوز حجز قائم، والملغى أُلغي وأصبح متاحًا للحجز.</p>
        <div class="home-progress" role="img" aria-label="ملخص المواعيد المتبقية: <?= (int) $bookingSummary['available'] ?> متاحة، <?= (int) $bookingSummary['booked'] ?> محجوزة، <?= (int) $bookingSummary['upcoming_cancelled'] ?> ملغاة">
          <span class="progress-available" style="width:<?= $bookingSummary['available_percent'] ?>%"></span>
          <span class="progress-booked" style="width:<?= $bookingSummary['booked_percent'] ?>%"></span>
          <span class="progress-cancelled" style="width:<?= $bookingSummary['cancelled_percent'] ?>%"></span>
        </div>
        <div class="home-status-legend">
          <span><i class="legend-dot legend-available"></i>متاحة <strong data-count="<?= (int) $bookingSummary['available'] ?>"><?= (int) $bookingSummary['available'] ?></strong></span>
          <span><i class="legend-dot legend-booked"></i>محجوزة <strong data-count="<?= (int) $bookingSummary['booked'] ?>"><?= (int) $bookingSummary['booked'] ?></strong></span>
          <span><i class="legend-dot legend-cancelled"></i>ملغاة ومتاحة <strong data-count="<?= (int) $bookingSummary['upcoming_cancelled'] ?>"><?= (int) $bookingSummary['upcoming_cancelled'] ?></strong></span>
        </div>
        <span class="home-week-dates">الفترة: <?= e($firstBookableDate->format('Y/m/d')) ?> — <?= e($weekEnd->format('Y/m/d')) ?></span>
      </article>
    </div>
  </section>

  <?php if ((int) $hallProfile['is_visible'] === 1): ?>
  <section class="public-section hall-profile-section home-reveal" aria-labelledby="hall-profile-title">
    <div class="public-section-heading">
      <div>
        <span class="home-card-kicker">مساحة للتعلّم والإبداع</span>
        <h2 id="hall-profile-title"><?= e((string) $hallProfile['section_title']) ?></h2>
        <?php if ((string) $hallProfile['section_intro'] !== ''): ?><p><?= e((string) $hallProfile['section_intro']) ?></p><?php endif; ?>
      </div>
    </div>
    <div class="hall-profile-grid">
      <?php if (!empty($hallProfile['opened_on'])): ?>
      <article class="card hall-profile-card hall-profile-date">
        <span class="hall-profile-icon" aria-hidden="true">◷</span>
        <div><span class="hall-profile-label">تاريخ الافتتاح</span><strong><?= e((new DateTimeImmutable((string) $hallProfile['opened_on']))->format('Y/m/d')) ?></strong></div>
      </article>
      <?php endif; ?>
      <?php if (trim((string) $hallProfile['purpose']) !== ''): ?>
      <article class="card hall-profile-card hall-profile-purpose">
        <span class="hall-profile-icon" aria-hidden="true">✦</span>
        <div><span class="hall-profile-label">الهدف من القاعة</span><p><?= nl2br(e((string) $hallProfile['purpose'])) ?></p></div>
      </article>
      <?php endif; ?>
      <?php if ($hallTechnologies !== []): ?>
      <article class="card hall-profile-card">
        <span class="hall-profile-icon" aria-hidden="true">⌘</span>
        <div><span class="hall-profile-label">التقنيات الموجودة</span><ul><?php foreach ($hallTechnologies as $technology): ?><li><?= e($technology) ?></li><?php endforeach; ?></ul></div>
      </article>
      <?php endif; ?>
      <?php if ($hallContributors !== []): ?>
      <article class="card hall-profile-card">
        <span class="hall-profile-icon" aria-hidden="true">♙</span>
        <div><span class="hall-profile-label">المساهمون في الإنشاء</span><ul><?php foreach ($hallContributors as $contributor): ?><li><?= e($contributor) ?></li><?php endforeach; ?></ul></div>
      </article>
      <?php endif; ?>
    </div>
    <?php if ($hallEvidence !== []): ?>
    <div class="hall-evidence">
      <div class="hall-evidence-heading"><span class="home-card-kicker">من داخل القاعة</span><h3>الصور والشواهد</h3></div>
      <div class="hall-evidence-grid">
        <?php foreach ($hallEvidence as $evidence): ?>
        <article class="card hall-evidence-card">
          <a class="hall-evidence-image-link" href="<?= e(app_url('/' . ltrim((string) $evidence['image_path'], '/'))) ?>" target="_blank" rel="noopener noreferrer" aria-label="عرض الصورة: <?= e((string) $evidence['title']) ?>">
            <img src="<?= e(app_url('/' . ltrim((string) $evidence['image_path'], '/'))) ?>" alt="<?= e((string) $evidence['title']) ?>" loading="lazy">
          </a>
          <div class="hall-evidence-copy">
            <h4><?= e((string) $evidence['title']) ?></h4>
            <?php if (trim((string) $evidence['description']) !== ''): ?><p><?= nl2br(e((string) $evidence['description'])) ?></p><?php endif; ?>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="public-section home-steps-section">
    <div class="public-section-heading home-reveal">
      <div><span class="home-card-kicker">بخطوات بسيطة</span><h2>احجزي القاعة في ثلاث خطوات</h2><p>رحلتك إلى تجربة تعليمية مميزة تبدأ من هنا</p></div>
    </div>
    <div class="steps-grid home-steps" data-home-steps>
      <span class="steps-connector" aria-hidden="true"></span>
      <article class="step-card card home-hover-card home-reveal">
        <span class="step-icon step-icon-violet"><svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span>
        <span class="step-number">01</span><h3>اختاري الموعد</h3><p>تصفحي الجدول واختاري الوقت والحصة الأنسب لك.</p>
      </article>
      <article class="step-card card home-hover-card home-reveal">
        <span class="step-icon step-icon-cyan"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="m9 10 2 2 4-4"/></svg></span>
        <span class="step-number">02</span><h3>أدخلي البيانات</h3><p>أضيفي اسم المعلمة والصف لإكمال تفاصيل الحجز.</p>
      </article>
      <article class="step-card card home-hover-card home-reveal">
        <span class="step-icon step-icon-green"><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m8 12 2.5 2.5L16 9"/></svg></span>
        <span class="step-number">03</span><h3>أكدي الحجز</h3><p>أكدي طلبك وتابعي موعدك مباشرة في جدول القاعة.</p>
      </article>
    </div>
  </section>

  <section class="home-final-cta home-reveal" aria-labelledby="home-final-title">
    <span class="home-final-decoration home-final-decoration-one" aria-hidden="true"></span>
    <span class="home-final-decoration home-final-decoration-two" aria-hidden="true"></span>
    <div class="home-final-copy">
      <span class="home-final-kicker"><i aria-hidden="true"></i> ابدئي من هنا</span>
      <h2 id="home-final-title">مساحتك التعليمية القادمة بانتظارك</h2>
      <p>اختاري الموعد المناسب واحجزي قاعة التعلم الرقمية بخطوات واضحة وسريعة.</p>
      <span class="home-final-availability">
        <strong><?= (int) $bookingSummary['available'] ?></strong>
        موعد متاح هذا الأسبوع
        <i aria-hidden="true"></i>
        حتى <?= e($weekEnd->format('Y/m/d')) ?>
      </span>
    </div>
    <a class="button home-final-button" href="<?= e(app_url('/booking.php')) ?>">
      احجزي موعدك الآن
      <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
    </a>
  </section>
</main>
<?php render_page_end(); ?>
