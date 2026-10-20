<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect(role_home(current_user()['role']));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'أدخل اسم المستخدم وكلمة المرور.';
    } else {
        $pdo = db();
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)')->execute();
        $attempts = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username = :username AND ip_address = :ip AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $attempts->execute(['username' => $username, 'ip' => $ip]);
        if ((int) $attempts->fetchColumn() >= 5) {
            $error = 'تم تجاوز عدد المحاولات. انتظر 15 دقيقة ثم حاول مجددًا.';
        } else {
            $statement = $pdo->prepare('SELECT id, name, username, password_hash, role, is_active FROM users WHERE username = ? LIMIT 1');
            $statement->execute([$username]);
            $user = $statement->fetch();
            if ($user && (int) $user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
                $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND ip_address = ?')->execute([$username, $ip]);
                $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
                login_user($user);
                audit_log($pdo, 'login_success', 'user', (int) $user['id'], 'تسجيل دخول ناجح', ['username' => $user['username']]);
                redirect(role_home($user['role']));
            }
            $pdo->prepare('INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)')->execute([$username, $ip]);
            audit_log($pdo, 'login_failed', 'authentication', null, 'محاولة تسجيل دخول غير ناجحة', ['username' => $username]);
            $error = 'بيانات الدخول غير صحيحة أو أن الحساب غير نشط.';
        }
    }
}

$title = 'تسجيل الدخول';
render_page_start($title);
?>
<main class="login-shell">
  <section class="login-card card">
    <span class="brand-mark" aria-hidden="true">ق</span>
    <h1>مرحبًا بعودتك</h1>
    <p>سجّل الدخول للمتابعة إلى لوحة القاعة الرقمية.</p>
    <?php if ($error !== ''): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(app_url('/login.php')) ?>">
      <?= csrf_field() ?>
      <div class="field">
        <label for="username">اسم المستخدم</label>
        <input id="username" name="username" autocomplete="username" required maxlength="80" value="<?= e((string) ($_POST['username'] ?? '')) ?>">
      </div>
      <div class="field">
        <label for="password">كلمة المرور</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
      </div>
      <button class="button button-primary" type="submit">تسجيل الدخول</button>
    </form>
  </section>
</main>
<?php render_page_end(); ?>
