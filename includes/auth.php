<?php
declare(strict_types=1);

function attempt_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['is_active']) {
        return false;
    }
    if (!password_verify($password, $user['password'])) {
        return false;
    }
    @session_regenerate_id(true);
    unset($user['password']);
    $_SESSION['user'] = $user;
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    log_activity('login', 'user', (int) $user['id'], 'Signed in');
    return true;
}

function logout(): void
{
    log_activity('logout', 'user', uid(), 'Signed out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function refresh_session_user(): void
{
    if (!uid()) {
        return;
    }
    $stmt = db()->prepare('SELECT id, name, email, role, department, designation, phone, avatar, is_active, unit_code FROM users WHERE id = ?');
    $stmt->execute([uid()]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['is_active']) {
        logout();
        redirect('index.php');
    }
    $_SESSION['user'] = $user;
}
