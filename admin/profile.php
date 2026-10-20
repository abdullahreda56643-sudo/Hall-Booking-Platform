<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role([ROLE_ADMIN]);

$pdo = db();
$user = current_user();
$name = $user['name'];
$username = $user['username'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ((string) ($_POST['action'] ?? '') !== 'update_profile') {
        http_response_code(400);
        exit('طلب غير صالح.');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        $error = 'أدخلي اسمًا صحيحًا لا يتجاوز 150 حرفًا.';
    } elseif (preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username) !== 1) {
        $error = 'اسم المستخدم يجب أن يتكون من 3 إلى 80 حرفًا إنجليزيًا أو رقمًا أو نقطة أو شرطة.';
    } else {
        $usernameCheck = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
        $usernameCheck->execute([$username, $user['id']]);
        if ($usernameCheck->fetchColumn()) {
            $error = 'اسم المستخدم مستخدم بالفعل. اختاري اسمًا آخر.';
        } else {
            $pdo->beginTransaction();
            try {
                $update = $pdo->prepare('UPDATE users SET name = ?, username = ? WHERE id = ? AND role = ?');
                $update->execute([$name, $username, $user['id'], ROLE_ADMIN]);
                if ($update->rowCount() !== 1 && ($name !== $user['name'] || $username !== $user['username'])) {
                    throw new RuntimeException('تعذر تحديث الملف الشخصي. حدّثي الصفحة وحاولي مرة أخرى.');
                }
                audit_log($pdo, 'profile_updated', 'user', $user['id'], 'تحديث الملف الشخصي للأدمن', [
                    'previous_name' => $user['name'],
                    'previous_username' => $user['username'],
                    'name' => $name,
                    'username' => $username,
                ]);
                $pdo->commit();
                $_SESSION['user_name'] = $name;
                $_SESSION['username'] = $username;
                flash('success', 'تم تحديث الملف الشخصي بنجاح.');
                redirect('/admin/profile.php');
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception->getCode() !== '23000' || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                    throw $exception;
                }
                $error = 'اسم المستخدم مستخدم بالفعل. اختاري اسمًا آخر.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    }
}

$title = 'الملف الشخصي';
render_page_start($title, true);
?>
<div class="page-heading">
  <div><h1>الملف الشخصي</h1><p>حدّثي اسمك واسم المستخدم الخاص بحساب الأدمن.</p></div>
</div>
<?php if ($error !== ''): ?><div class="users-error" role="alert"><strong>تعذر تحديث الملف الشخصي</strong><span><?= e($error) ?></span></div><?php endif; ?>
<section class="card card-pad" style="max-width:680px">
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_profile">
    <div class="field">
      <label for="profile-name">الاسم</label>
      <input id="profile-name" name="name" type="text" required maxlength="150" autocomplete="name" value="<?= e($name) ?>">
    </div>
    <div class="field">
      <label for="profile-username">اسم المستخدم</label>
      <input id="profile-username" name="username" type="text" required minlength="3" maxlength="80" pattern="[A-Za-z0-9_.-]{3,80}" autocomplete="username" value="<?= e($username) ?>">
      <small>استخدمي 3 إلى 80 حرفًا إنجليزيًا أو رقمًا أو نقطة أو شرطة.</small>
    </div>
    <div><button class="button button-primary" type="submit">حفظ التغييرات</button></div>
  </form>
</section>
<?php render_page_end(); ?>
