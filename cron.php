<?php
/**
 * Scheduled jobs: daily overdue-collection digests, event-start reminders,
 * and retrying any queued email/WhatsApp notifications that failed to send.
 *
 * Run this from the server's cron, once a day is enough:
 *   php /path/to/events/cron.php
 *
 * If your host only offers URL-based cron (common on shared cPanel plans),
 * schedule a request to this instead and it will use the secret token
 * generated in Settings → Notifications so nobody else can trigger it:
 *   https://your-domain/cron.php?token=xxxxxxxxxxxxxxxx
 */
require __DIR__ . '/includes/init.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain');
    $token = (string) ($_GET['token'] ?? '');
    if ($token === '' || !hash_equals(setting('cron_secret', ''), $token)) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
}

$log = [];

/** Overdue sponsorship collections — one digest a day, hospital-wide (no unit filter). */
function cron_overdue_collections(): array
{
    $grace = collection_grace_days();
    $sql = "SELECT s.id, s.event_id, sp.name sponsor_name,
                   (s.promised_amount - COALESCE((SELECT SUM(r.amount) FROM sponsorship_receipts r WHERE r.sponsorship_id = s.id),0)) outstanding,
                   DATEDIFF(CURDATE(), DATE_ADD(ev.end_date, INTERVAL {$grace} DAY)) days_late
            FROM sponsorships s
            JOIN events ev ON ev.id = s.event_id
            JOIN sponsors sp ON sp.id = s.sponsor_id
            WHERE s.status IN ('promised','partial')
              AND ev.status <> 'cancelled'
              AND ev.end_date < DATE_SUB(CURDATE(), INTERVAL {$grace} DAY)
              AND (s.promised_amount - COALESCE((SELECT SUM(r.amount) FROM sponsorship_receipts r WHERE r.sponsorship_id = s.id),0)) > 0.009";
    return db()->query($sql)->fetchAll();
}

/** Events starting in N days, for each configured reminder threshold. */
function cron_event_reminders(): array
{
    $days = array_filter(array_map('intval', explode(',', setting('event_reminder_days', '7,1'))), fn ($d) => $d >= 0);
    if (!$days) {
        return [];
    }
    $out = [];
    $st = db()->prepare("SELECT id, start_date FROM events WHERE status NOT IN ('cancelled','draft') AND start_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)");
    foreach (array_unique($days) as $d) {
        $st->execute([$d]);
        foreach ($st->fetchAll() as $row) {
            $out[] = ['event_id' => (int) $row['id'], 'days_before' => $d];
        }
    }
    return $out;
}

try {
    $n = 0;
    foreach (cron_overdue_collections() as $row) {
        $sid = (int) $row['id'];
        if (notification_recently_sent('collection.overdue', 'sponsorship', $sid, 20)) {
            continue;
        }
        notify('collection.overdue', [
            'event' => event_notify_context((int) $row['event_id']),
            'sponsor_name' => $row['sponsor_name'],
            'outstanding' => (float) $row['outstanding'],
            'days_late' => (int) $row['days_late'],
            'entity_type' => 'sponsorship',
            'entity_id' => $sid,
        ]);
        $n++;
    }
    $log[] = "Overdue-collection notices sent: {$n}";
} catch (Throwable $e) {
    $log[] = 'Overdue-collection job failed: ' . $e->getMessage();
}

try {
    $n = 0;
    foreach (cron_event_reminders() as $r) {
        $eventId = $r['event_id'];
        if (notification_recently_sent('event.reminder', 'event', $eventId, 20)) {
            continue;
        }
        notify('event.reminder', [
            'event' => event_notify_context($eventId),
            'days_before' => $r['days_before'],
            'entity_type' => 'event',
            'entity_id' => $eventId,
        ]);
        $n++;
    }
    $log[] = "Event reminders sent: {$n}";
} catch (Throwable $e) {
    $log[] = 'Event reminder job failed: ' . $e->getMessage();
}

try {
    $n = retry_failed_notifications();
    $log[] = "Retried queued notifications: {$n} sent";
} catch (Throwable $e) {
    $log[] = 'Retry job failed: ' . $e->getMessage();
}

echo implode("\n", $log) . "\n";
