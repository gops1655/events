<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function notif_on(string $key): bool
{
    return setting($key, '0') === '1';
}

function app_base_url(): string
{
    $configured = trim(setting('app_base_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
    }
    return '';
}

/* ---------------------------------------------------------------- *
 * Queue + dispatch
 * ---------------------------------------------------------------- */

function queue_notification(?int $userId, string $channel, string $type, string $title, string $body, ?string $entityType, ?int $entityId, ?string $recipient): int
{
    $status = $channel === 'inapp' ? 'sent' : 'pending';
    db()->prepare('INSERT INTO notifications (user_id, channel, type, title, body, entity_type, entity_id, recipient, status, sent_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$userId, $channel, $type, $title, $body, $entityType, $entityId, $recipient, $status, $status === 'sent' ? date('Y-m-d H:i:s') : null]);
    return (int) db()->lastInsertId();
}

function dispatch_notification(int $id): bool
{
    $st = db()->prepare('SELECT * FROM notifications WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || $row['status'] === 'sent') {
        return true;
    }
    if ($row['channel'] === 'inapp') {
        return true;
    }
    if ($row['channel'] === 'email') {
        $res = send_email_raw((string) $row['recipient'], (string) $row['title'], (string) $row['body']);
    } elseif ($row['channel'] === 'whatsapp') {
        $res = send_whatsapp_raw((string) $row['recipient'], (string) $row['body']);
    } else {
        $res = ['ok' => false, 'error' => 'Unknown channel.'];
    }
    db()->prepare('UPDATE notifications SET status = ?, error = ?, attempts = attempts + 1, sent_at = ? WHERE id = ?')
        ->execute([$res['ok'] ? 'sent' : 'failed', $res['error'], $res['ok'] ? date('Y-m-d H:i:s') : null, $id]);
    return $res['ok'];
}

function notification_recently_sent(string $type, string $entityType, int $entityId, int $hours): bool
{
    $hours = max(1, min(720, $hours));
    $st = db()->prepare("SELECT 1 FROM notifications WHERE type = ? AND entity_type = ? AND entity_id = ? AND created_at > (NOW() - INTERVAL {$hours} HOUR) LIMIT 1");
    $st->execute([$type, $entityType, $entityId]);
    return (bool) $st->fetchColumn();
}

function retry_failed_notifications(int $maxAttempts = 5, int $limit = 50): int
{
    $st = db()->prepare("SELECT id FROM notifications WHERE status = 'failed' AND attempts < ? ORDER BY id LIMIT {$limit}");
    $st->execute([$maxAttempts]);
    $n = 0;
    foreach ($st->fetchAll() as $row) {
        if (dispatch_notification((int) $row['id'])) {
            $n++;
        }
    }
    return $n;
}

/* ---------------------------------------------------------------- *
 * Sending
 * ---------------------------------------------------------------- */

function send_email_raw(string $to, string $subject, string $html): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'No valid recipient email.'];
    }
    $host = trim(setting('smtp_host'));
    if ($host === '') {
        return ['ok' => false, 'error' => 'SMTP is not configured yet (Settings → Notifications).'];
    }
    $port = (int) setting('smtp_port', '587');
    $enc = setting('smtp_encryption', 'tls');
    $user = trim(setting('smtp_user'));
    $pass = setting('smtp_pass');
    $fromEmail = trim(setting('smtp_from_email')) ?: $user;
    $fromName = trim(setting('smtp_from_name')) ?: product_name();
    $mailer = new SmtpMailer($host, $port, $enc, $user, $pass);
    return $mailer->send($fromEmail, $fromName, $to, $to, $subject, $html);
}

function http_post(string $url, array $headers, string $body, ?string $basicAuth = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'body' => null, 'error' => 'The cURL PHP extension is not enabled on this server.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($basicAuth !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
    }
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $resp !== false && $code >= 200 && $code < 300, 'code' => $code, 'body' => $resp, 'error' => $err ?: null];
}

function send_whatsapp_raw(string $to, string $message): array
{
    $digits = preg_replace('/[^0-9]/', '', $to) ?? '';
    if ($digits === '') {
        return ['ok' => false, 'error' => 'No valid recipient phone number.'];
    }
    $provider = setting('whatsapp_provider', 'generic');

    if ($provider === 'meta') {
        $endpoint = trim(setting('whatsapp_endpoint'));
        $token = trim(setting('whatsapp_token'));
        if ($endpoint === '' || $token === '') {
            return ['ok' => false, 'error' => 'WhatsApp Cloud API endpoint/token not configured.'];
        }
        $payload = json_encode(['messaging_product' => 'whatsapp', 'to' => $digits, 'type' => 'text', 'text' => ['body' => $message]]);
        $res = http_post($endpoint, ['Content-Type: application/json', 'Authorization: Bearer ' . $token], (string) $payload);
    } elseif ($provider === 'twilio') {
        $sid = trim(setting('whatsapp_sid'));
        $token = trim(setting('whatsapp_token'));
        $from = preg_replace('/[^0-9]/', '', setting('whatsapp_from')) ?? '';
        if ($sid === '' || $token === '' || $from === '') {
            return ['ok' => false, 'error' => 'Twilio Account SID, Auth Token, or From number not configured.'];
        }
        $endpoint = trim(setting('whatsapp_endpoint')) ?: ('https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json');
        $body = http_build_query(['From' => 'whatsapp:+' . $from, 'To' => 'whatsapp:+' . $digits, 'Body' => $message]);
        $res = http_post($endpoint, ['Content-Type: application/x-www-form-urlencoded'], $body, $sid . ':' . $token);
    } else {
        $endpoint = trim(setting('whatsapp_endpoint'));
        if ($endpoint === '') {
            return ['ok' => false, 'error' => 'WhatsApp endpoint not configured.'];
        }
        $token = trim(setting('whatsapp_token'));
        $headers = ['Content-Type: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $payload = json_encode(['to' => $digits, 'message' => $message]);
        $res = http_post($endpoint, $headers, (string) $payload);
    }

    if (!$res['ok']) {
        $detail = $res['error'] ?: ('HTTP ' . $res['code'] . ' ' . substr((string) $res['body'], 0, 200));
        return ['ok' => false, 'error' => $detail];
    }
    return ['ok' => true, 'error' => null];
}

function send_test_email(string $to): array
{
    return send_email_raw($to, 'Test email · ' . product_name(), notification_email_body('Test email', 'This confirms your SMTP settings are working. Notifications from ' . product_name() . ' will look like this.', null));
}

function send_test_whatsapp(string $to): array
{
    return send_whatsapp_raw($to, 'Test message from ' . product_name() . ': your WhatsApp notification settings are working.');
}

/* ---------------------------------------------------------------- *
 * Templates
 * ---------------------------------------------------------------- */

function notification_email_body(string $title, string $text, ?string $path): string
{
    $base = app_base_url();
    $link = $path ? ($base !== '' ? $base . '/' . ltrim($path, '/') : $path) : null;
    $btn = $link
        ? '<p style="margin:20px 0 0"><a href="' . e($link) . '" style="background:#1b6e64;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block">Open in ' . e(product_name()) . '</a></p>'
        : '';
    return '<div style="font-family:Arial,Helvetica,sans-serif;background:#f3efe6;padding:28px">'
        . '<div style="max-width:520px;margin:0 auto;background:#fffcf7;border-radius:16px;padding:24px 28px;border:1px solid #eee2cd">'
        . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6d7a86;margin-bottom:10px">' . e(product_name()) . ' · ' . e(setting('hospital_name', 'Hospital')) . '</div>'
        . '<h2 style="margin:0 0 12px;font-size:19px;color:#14202b">' . e($title) . '</h2>'
        . '<p style="margin:0;font-size:14.5px;line-height:1.55;color:#3d4a57">' . nl2br(e($text)) . '</p>'
        . $btn
        . '</div></div>';
}

function notification_entity_path(array $context): ?string
{
    $eventId = (int) ($context['event']['id'] ?? 0);
    if ($eventId > 0) {
        return 'event.php?id=' . $eventId;
    }
    return null;
}

function notification_template(string $type, array $context): ?array
{
    $event = $context['event'] ?? [];
    $evLabel = trim((string) ($event['code'] ?? '') . ' · ' . (string) ($event['title'] ?? ''), ' ·') ?: 'an event';

    switch ($type) {
        case 'sponsorship.promised':
            $title = 'New sponsorship promised · ' . $evLabel;
            $text = ($context['sponsor_name'] ?? 'A sponsor') . ' promised ' . money((float) ($context['amount'] ?? 0)) . ' for ' . $evLabel . '.';
            break;
        case 'sponsorship.received':
            $title = 'Sponsorship collected in full · ' . $evLabel;
            $text = money((float) ($context['amount'] ?? 0)) . ' received from ' . ($context['sponsor_name'] ?? 'the sponsor') . ' for ' . $evLabel . '. The promise is now fully collected.';
            break;
        case 'sponsorship.partial':
            $title = 'Sponsorship payment received · ' . $evLabel;
            $text = money((float) ($context['amount'] ?? 0)) . ' received from ' . ($context['sponsor_name'] ?? 'the sponsor') . ' for ' . $evLabel . '. ' . money((float) ($context['balance'] ?? 0)) . ' is still outstanding.';
            break;
        case 'collection.overdue':
            $title = 'Collection overdue · ' . $evLabel;
            $days = (int) ($context['days_late'] ?? 0);
            $text = money((float) ($context['outstanding'] ?? 0)) . ' is still due from ' . ($context['sponsor_name'] ?? 'the sponsor') . ', ' . $days . ' day' . ($days === 1 ? '' : 's') . ' past the collection window for ' . $evLabel . '.';
            break;
        case 'expense.pending':
            $title = 'Expense awaiting approval · ' . $evLabel;
            $text = ($context['title'] ?? 'An expense') . ' of ' . money((float) ($context['amount'] ?? 0)) . ' on ' . $evLabel . ' needs finance approval.';
            break;
        case 'expense.approved':
            $title = 'Expense approved · ' . $evLabel;
            $text = ($context['title'] ?? 'Your expense') . ' of ' . money((float) ($context['amount'] ?? 0)) . ' on ' . $evLabel . ' was approved.';
            break;
        case 'expense.rejected':
            $title = 'Expense rejected · ' . $evLabel;
            $text = ($context['title'] ?? 'Your expense') . ' of ' . money((float) ($context['amount'] ?? 0)) . ' on ' . $evLabel . ' was rejected.';
            break;
        case 'event.reminder':
            $days = (int) ($context['days_before'] ?? 0);
            $when = $days <= 0 ? 'is today' : ('starts in ' . $days . ' day' . ($days === 1 ? '' : 's'));
            $title = ($days <= 0 ? 'Event today' : 'Event in ' . $days . ' day' . ($days === 1 ? '' : 's')) . ' · ' . $evLabel;
            $text = $evLabel . ' ' . $when . '. Check budget, sponsorship and registrations before go-live.';
            break;
        default:
            return null;
    }

    $path = notification_entity_path($context);
    return ['title' => $title, 'text' => $text, 'html' => notification_email_body($title, $text, $path)];
}

function notification_trigger_enabled(string $type): bool
{
    $map = [
        'sponsorship.' => 'notify_on_sponsorship',
        'collection.overdue' => 'notify_on_overdue',
        'expense.' => 'notify_on_expense_approval',
        'event.reminder' => 'notify_on_event_reminder',
    ];
    foreach ($map as $prefix => $key) {
        if (str_starts_with($type, $prefix)) {
            return notif_on($key);
        }
    }
    return true;
}

/* ---------------------------------------------------------------- *
 * Recipients
 * ---------------------------------------------------------------- */

function event_notify_context(int $eventId): array
{
    $st = db()->prepare('SELECT id, code, title, unit_code, marketing_lead_id, doctor_id, pharmacy_head_id, coordinator_id FROM events WHERE id = ?');
    $st->execute([$eventId]);
    return $st->fetch() ?: ['id' => $eventId];
}

function sponsor_name_by_id(int $sponsorId): string
{
    $st = db()->prepare('SELECT name FROM sponsors WHERE id = ?');
    $st->execute([$sponsorId]);
    return (string) ($st->fetchColumn() ?: 'A sponsor');
}

function users_by_ids(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id, name, email, phone FROM users WHERE id IN ({$place}) AND is_active = 1");
    $st->execute($ids);
    return $st->fetchAll();
}

function notification_recipients(string $type, array $context): array
{
    $ids = [];
    $event = $context['event'] ?? [];
    if (str_starts_with($type, 'sponsorship.') || $type === 'collection.overdue') {
        if (!empty($event['marketing_lead_id'])) {
            $ids[] = (int) $event['marketing_lead_id'];
        }
        if (!empty($context['liaison_user_id'])) {
            $ids[] = (int) $context['liaison_user_id'];
        }
        foreach (users_by_role('finance') as $u) {
            $ids[] = (int) $u['id'];
        }
    } elseif ($type === 'expense.pending') {
        foreach (users_by_role('finance') as $u) {
            $ids[] = (int) $u['id'];
        }
        foreach (users_by_role('admin') as $u) {
            $ids[] = (int) $u['id'];
        }
    } elseif (in_array($type, ['expense.approved', 'expense.rejected'], true)) {
        if (!empty($context['requester_id'])) {
            $ids[] = (int) $context['requester_id'];
        }
    } elseif ($type === 'event.reminder') {
        foreach (['marketing_lead_id', 'doctor_id', 'pharmacy_head_id', 'coordinator_id'] as $f) {
            if (!empty($event[$f])) {
                $ids[] = (int) $event[$f];
            }
        }
    }
    return users_by_ids($ids);
}

/* ---------------------------------------------------------------- *
 * Entry point
 * ---------------------------------------------------------------- */

function notify(string $type, array $context): void
{
    try {
        if (!notification_trigger_enabled($type)) {
            return;
        }
        $tpl = notification_template($type, $context);
        if (!$tpl) {
            return;
        }
        $recipients = notification_recipients($type, $context);
        if (!$recipients) {
            return;
        }
        $entityType = $context['entity_type'] ?? null;
        $entityId = isset($context['entity_id']) ? (int) $context['entity_id'] : null;
        foreach ($recipients as $u) {
            $userId = (int) $u['id'];
            if (notif_on('notify_inapp_enabled')) {
                queue_notification($userId, 'inapp', $type, $tpl['title'], $tpl['text'], $entityType, $entityId, null);
            }
            if (notif_on('notify_email_enabled') && !empty($u['email'])) {
                $id = queue_notification($userId, 'email', $type, $tpl['title'], $tpl['html'], $entityType, $entityId, $u['email']);
                dispatch_notification($id);
            }
            if (notif_on('notify_whatsapp_enabled') && !empty($u['phone'])) {
                $id = queue_notification($userId, 'whatsapp', $type, $tpl['title'], $tpl['text'], $entityType, $entityId, $u['phone']);
                dispatch_notification($id);
            }
        }
    } catch (Throwable $e) {
        // A notification failure must never break the underlying business action.
        try {
            log_activity('notify.error', $context['entity_type'] ?? null, isset($context['entity_id']) ? (int) $context['entity_id'] : null, $type . ': ' . $e->getMessage());
        } catch (Throwable $e2) {
        }
    }
}

/* ---------------------------------------------------------------- *
 * In-app inbox
 * ---------------------------------------------------------------- */

function unread_notification_count(int $userId): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND channel = 'inapp' AND read_at IS NULL");
    $st->execute([$userId]);
    return (int) $st->fetchColumn();
}

function recent_notifications(int $userId, int $limit = 12): array
{
    $limit = max(1, min(50, $limit));
    $st = db()->prepare("SELECT * FROM notifications WHERE user_id = ? AND channel = 'inapp' ORDER BY id DESC LIMIT {$limit}");
    $st->execute([$userId]);
    return $st->fetchAll();
}

function mark_notification_read(int $userId, int $id): void
{
    db()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL')->execute([$id, $userId]);
}

function mark_all_notifications_read(int $userId): void
{
    db()->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND channel = 'inapp' AND read_at IS NULL")->execute([$userId]);
}

function notification_link(array $row): ?string
{
    $type = (string) ($row['entity_type'] ?? '');
    $id = (int) ($row['entity_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if ($type === 'event') {
        return 'event.php?id=' . $id;
    }
    if ($type === 'sponsorship') {
        $st = db()->prepare('SELECT event_id FROM sponsorships WHERE id = ?');
        $st->execute([$id]);
        $eid = (int) $st->fetchColumn();
        return $eid ? 'event.php?id=' . $eid . '#sponsorship' : null;
    }
    if ($type === 'expense') {
        $st = db()->prepare('SELECT event_id FROM expenses WHERE id = ?');
        $st->execute([$id]);
        $eid = (int) $st->fetchColumn();
        return $eid ? 'event.php?id=' . $eid . '#expenses' : null;
    }
    return null;
}

function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) {
        return 'just now';
    }
    $steps = [31536000 => 'y', 2592000 => 'mo', 86400 => 'd', 3600 => 'h', 60 => 'm'];
    foreach ($steps as $secs => $label) {
        if ($diff >= $secs) {
            return floor($diff / $secs) . $label . ' ago';
        }
    }
    return 'just now';
}
