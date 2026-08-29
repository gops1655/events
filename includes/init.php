<?php
declare(strict_types=1);

$sessDir = dirname(__DIR__) . '/storage/sessions';
if (!is_dir($sessDir)) {
    @mkdir($sessDir, 0755, true);
}
if (is_dir($sessDir) && is_writable($sessDir)) {
    session_save_path($sessDir);
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$installed = is_file(__DIR__ . '/config.php');
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$publicScripts = ['index.php', 'install.php', 'logout.php', 'help.php', 'reset_admin.php', 'cron.php'];

if (!$installed && $script !== 'install.php') {
    header('Location: install.php');
    exit;
}

if ($installed) {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/functions.php';
    require_once __DIR__ . '/units.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/ledger.php';
    require_once __DIR__ . '/registrations.php';
    require_once __DIR__ . '/notifications.php';
    if ($script !== 'install.php') {
        try {
            ensure_schema();
        } catch (Throwable $e) {
            // Schema patches must not block sign-in on a fresh cPanel import.
        }
        try {
            ensure_bootstrap_admin();
        } catch (Throwable $e) {
        }
    }
    if (!in_array($script, $publicScripts, true)) {
        require_login();
        refresh_session_user();
    } elseif ($script === 'help.php' && current_user()) {
        refresh_session_user();
    }
}
