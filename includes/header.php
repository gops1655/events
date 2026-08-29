<?php
$pageTitle = $pageTitle ?? 'Dashboard';
$pageCrumb = $pageCrumb ?? setting('hospital_name', 'Hospital');
$active = $active ?? '';
$u = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> · <?= e(product_name()) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Outfit:wght@400;500;560;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css?v=17">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark">EG</div>
      <div>
        <h1><?= e(product_name()) ?></h1>
        <p><?= e(product_tagline()) ?></p>
      </div>
    </div>
    <nav class="nav">
      <div class="nav-label">Overview</div>
      <a class="<?= $active === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
        Dashboard
      </a>
      <a class="<?= $active === 'help' ? 'active' : '' ?>" href="help.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.4 2.3c-.7.4-1.4 1-1.4 2.2V15"/><circle cx="12" cy="17.2" r=".8" fill="currentColor" stroke="none"/></svg>
        Help &amp; manual
      </a>
      <div class="nav-label">Operations</div>
      <a class="<?= $active === 'events' ? 'active' : '' ?>" href="events.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
        Events
      </a>
      <a class="<?= $active === 'expenses' ? 'active' : '' ?>" href="expenses.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16v12H4z"/><path d="M8 7V5h8v2"/><path d="M8 12h8M8 16h5"/></svg>
        Expenses
      </a>
      <a class="<?= $active === 'accounting' ? 'active' : '' ?>" href="accounting.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5M8 19V9M12 19V7M16 19v-6M20 19V11"/><path d="M3 19h18"/></svg>
        Purchase &amp; ECM
      </a>
      <a class="<?= $active === 'sponsors' ? 'active' : '' ?>" href="sponsors.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-8h6v8"/></svg>
        Sponsors
      </a>
      <a class="<?= $active === 'registrations' ? 'active' : '' ?>" href="registrations.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><path d="M3 20c.6-3.2 3-5 6-5s5.4 1.8 6 5"/><path d="M16 11l2 2 4-4"/></svg>
        Registrations
      </a>
      <a class="<?= $active === 'sponsorships' ? 'active' : '' ?>" href="sponsorships.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5c.6-1 1.5-1.5 2.5-1.5 1.4 0 2.5.8 2.5 2s-1 2-2.5 2h-1C9.5 12 8.5 13 8.5 14.2c0 1.2 1.1 2.3 2.7 2.3 1.1 0 2-.5 2.5-1.3"/></svg>
        Sponsorships
      </a>
      <div class="nav-label">Insights</div>
      <a class="<?= $active === 'reports' ? 'active' : '' ?>" href="reports.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5M4 19h16"/><path d="M8 15l4-5 3 3 5-7"/></svg>
        Reports
      </a>
      <a class="<?= $active === 'activity' ? 'active' : '' ?>" href="activity.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 8v5l3 2"/><circle cx="12" cy="12" r="9"/></svg>
        Activity
      </a>
      <?php if (role() === 'admin'): ?>
      <div class="nav-label">Admin</div>
      <a class="<?= $active === 'users' ? 'active' : '' ?>" href="users.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><path d="M3 20c.6-3.2 3-5 6-5s5.4 1.8 6 5"/><circle cx="17" cy="8" r="2.4"/><path d="M17 13c2.4.3 4.2 1.8 4.8 4.4"/></svg>
        Users
      </a>
      <a class="<?= $active === 'settings' ? 'active' : '' ?>" href="settings.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.3.7.9 1.2 1.6 1.4H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
        Settings
      </a>
      <?php endif; ?>
    </nav>
    <div class="sidebar-user">
      <div class="avatar"><?= e(initials($u['name'] ?? 'U')) ?></div>
      <div>
        <strong><?= e($u['name'] ?? '') ?></strong>
        <span><?= e(roles()[$u['role'] ?? ''] ?? '') ?><?php if (!empty($u['unit_code'])): ?> · <?= e($u['unit_code']) ?><?php elseif (is_central_role($u['role'] ?? '')): ?> · Central<?php endif; ?></span>
      </div>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <div>
        <h2><?= e($pageTitle) ?></h2>
        <div class="crumb"><?= e($pageCrumb) ?></div>
      </div>
      <div class="top-actions">
        <a class="btn btn-ghost btn-sm" href="help.php">Help</a>
        <a class="btn btn-ghost btn-sm" href="profile.php">Profile</a>
        <a class="btn btn-sm" href="logout.php">Sign out</a>
      </div>
    </header>
    <div class="content">
      <?php if ($m = flash('ok')): ?><div class="alert ok"><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('err')): ?><div class="alert err"><?= e($m) ?></div><?php endif; ?>
