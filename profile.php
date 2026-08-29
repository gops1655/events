<?php
require __DIR__ . '/includes/init.php';
require_login();

$pdo = db();
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if ($name === '') {
        flash('err', 'Name is required.');
        redirect('profile.php');
    }
    $pdo->prepare('UPDATE users SET name=?, phone=?, designation=? WHERE id=?')
        ->execute([$name, $phone, trim($_POST['designation'] ?? ''), uid()]);
    if (trim($_POST['password'] ?? '') !== '') {
        if (strlen($_POST['password']) < 6) {
            flash('err', 'New password must be at least 6 characters.');
            redirect('profile.php');
        }
        $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), uid()]);
    }
    refresh_session_user();
    flash('ok', 'Profile saved.');
    redirect('profile.php');
}

$pageTitle = 'Your profile';
$pageCrumb = roles()[$u['role']] ?? '';
$active = '';
require __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:560px">
  <div class="card-b">
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <div class="field"><label>Name</label><input name="name" required value="<?= e($u['name']) ?>"></div>
      <div class="field"><label>Email</label><input value="<?= e($u['email']) ?>" disabled></div>
      <div class="field"><label>Unit</label><input value="<?= e(is_central_role() ? 'Central · purchase & finance' : (user_unit() ?: 'Not assigned')) ?>" disabled></div>
      <div class="field"><label>Designation</label><input name="designation" value="<?= e($u['designation'] ?? '') ?>"></div>
      <div class="field"><label>Phone</label><input name="phone" value="<?= e($u['phone'] ?? '') ?>"></div>
      <div class="field"><label>New password (optional)</label><input type="password" name="password" minlength="6"></div>
      <button class="btn btn-teal" type="submit">Save profile</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
