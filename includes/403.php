<?php
$title = 'غير مصرح';
require __DIR__ . '/header.php';
?>
<main class="public-container">
  <section class="empty-state card">
    <span class="empty-icon">403</span>
    <h1>ليس لديك صلاحية لعرض هذه الصفحة</h1>
    <p>يمكنك العودة إلى لوحة التحكم الخاصة بحسابك.</p>
    <a class="button button-primary" href="<?= e(app_url(role_home(current_user()['role']))) ?>">العودة إلى لوحة التحكم</a>
  </section>
</main>
<?php require __DIR__ . '/footer.php'; ?>
