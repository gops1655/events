<?php
declare(strict_types=1);
require __DIR__ . '/includes/init.php';

if (!is_file(__DIR__ . '/includes/config.php')) {
    header('Location: install.php');
    exit;
}

$error = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hash = password_hash('Admin@123', PASSWORD_BCRYPT);
        $find = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $find->execute(['admin@hospital.local']);
        $id = $find->fetchColumn();
        if ($id) {
            db()->prepare('UPDATE users SET password = ?, is_active = 1, role = ? WHERE id = ?')
                ->execute([$hash, 'admin', (int) $id]);
        } else {
            db()->prepare(
                'INSERT INTO users (name, email, password, role, department, designation, phone, is_active) VALUES (?,?,?,?,?,?,?,1)'
            )->execute([
                'Administrator',
                'admin@hospital.local',
                $hash,
                'admin',
                'Administration',
                'Administrator',
                '',
            ]);
        }
        $ok = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset admin · <?= e(product_name()) ?></title>
  <link rel="stylesheet" href="assets/css/app.css?v=6">
</head>
<body class="install-body">
  <div class="install-card">
    <p class="muted" style="letter-spacing:.16em;text-transform:uppercase;font-size:11px"><?= e(product_name()) ?> · Recovery</p>
    <h2 style="font-family:Fraunces,serif;margin:6px 0 8px">Reset admin login</h2>
    <?php if ($ok): ?>
      <div class="alert ok">Admin is ready. Sign in, then delete <strong>reset_admin.php</strong> from the server.</div>
      <p><strong>Email:</strong> admin@hospital.local<br><strong>Password:</strong> Admin@123</p>
      <p><a class="btn btn-brass" href="index.php">Open sign in</a></p>
    <?php else: ?>
      <p class="muted">Use this once on cPanel if the sign-in page loads but admin@hospital.local is rejected. It creates that account or resets its password.</p>
      <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
      <form method="post" style="margin-top:16px">
        <button class="btn btn-brass" type="submit">Create / reset admin</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
