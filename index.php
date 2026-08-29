<?php
require __DIR__ . '/includes/init.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    try {
        if (!attempt_login($email, $pass)) {
            $error = 'Those credentials do not match an active account.';
            try {
                $n = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
                if ($n === 0) {
                    $error = 'No users in the database yet. Open reset_admin.php once to create the admin login, then delete that file from the server.';
                }
            } catch (Throwable $e) {
            }
        } else {
            redirect('dashboard.php');
        }
    } catch (Throwable $e) {
        $error = 'Sign-in failed: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in · <?= e(product_name()) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css?v=7">
</head>
<body>
<div class="login-wrap">
  <section class="login-art">
    <div>
      <div class="brand" style="border:0;padding:0;margin:0">
        <div class="brand-mark">EG</div>
        <div><h1><?= e(product_name()) ?></h1><p><?= e(product_tagline()) ?></p></div>
      </div>
      <h2>Every event, every rupee — promised, spent, and received.</h2>
      <p>Marketing, doctors, pharmacy, and finance share one ledger for hospital events: expenses on the ground, sponsorship commitments, and money that actually lands.</p>
    </div>
    <p class="muted" style="color:#93a3ae">Built for <?= e(setting('hospital_name', 'your hospital')) ?></p>
  </section>
  <section class="login-panel">
    <form class="login-card" method="post">
      <h3>Welcome back</h3>
      <p class="muted" style="margin-bottom:18px">Sign in with your department account.</p>
      <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
      <div class="field" style="margin-bottom:12px">
        <label>Email</label>
        <input type="text" name="email" required autofocus autocomplete="username" placeholder="admin@hospital.local" inputmode="email">
      </div>
      <div class="field" style="margin-bottom:18px">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <button class="btn btn-brass" style="width:100%;justify-content:center" type="submit">Sign in</button>
      <p class="muted" style="text-align:center;margin:14px 0 0"><a href="help.php" style="color:var(--teal);font-weight:560">Read the desk manual</a> — roles, flow, and who can do what</p>
      <div class="hint-accounts">
        <strong>Demo accounts</strong><br>
        admin@hospital.local · Admin@123<br>
        marketing@hospital.local · HTC · Demo@123<br>
        marketing.sec / .smj / .mlk @hospital.local · Demo@123<br>
        doctor / pharmacy / finance / coordinator @hospital.local · Demo@123
      </div>
    </form>
  </section>
</div>
</body>
</html>
