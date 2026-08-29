<?php
declare(strict_types=1);

function product_name(): string
{
    return 'EventGrant';
}

function product_tagline(): string
{
    return 'Event sponsorships';
}

function app_name(): string
{
    return setting('hospital_name', 'City Care Hospital') . ' · ' . product_name();
}

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            return $default;
        }
    }
    return $cache[$key] ?? $default;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function indian_grouped(float|int|string|null $amount, int $decimals = 0): string
{
    $n = abs((float) $amount);
    $decimals = max(0, $decimals);
    $fixed = number_format($n, $decimals, '.', '');
    $parts = explode('.', $fixed);
    $int = $parts[0];
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
        $int = $rest . ',' . $last3;
    }
    if ($decimals > 0) {
        return $int . '.' . str_pad((string) ($parts[1] ?? '0'), $decimals, '0');
    }
    return $int;
}

function money(float|int|string|null $amount): string
{
    $n = (float) $amount;
    $formatted = '₹' . indian_grouped($n, 0);
    return $n < 0 ? '−' . $formatted : $formatted;
}

function svg_sparkline(array $values, string $color, int $w = 108, int $h = 30): string
{
    $values = array_map('floatval', $values);
    $n = count($values);
    if ($n < 2) {
        return '';
    }
    $min = min($values);
    $max = max($values);
    $range = $max - $min ?: 1.0;
    $step = $w / ($n - 1);
    $pts = [];
    foreach ($values as $i => $v) {
        $x = round($i * $step, 1);
        $y = round($h - (($v - $min) / $range) * ($h - 4) - 2, 1);
        $pts[] = $x . ',' . $y;
    }
    $last = $pts[count($pts) - 1];
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" width="' . $w . '" height="' . $h . '">'
        . '<polyline points="' . e(implode(' ', $pts)) . '" fill="none" stroke="' . e($color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<circle cx="' . e(explode(',', $last)[0]) . '" cy="' . e(explode(',', $last)[1]) . '" r="2.6" fill="' . e($color) . '"/>'
        . '</svg>';
}

function trend_delta(float $current, float $previous): ?array
{
    if (abs($previous) < 0.009 && abs($current) < 0.009) {
        return null;
    }
    if (abs($previous) < 0.009) {
        return ['pct' => null, 'up' => $current > 0, 'label' => $current > 0 ? 'new' : '—'];
    }
    $pct = ($current - $previous) / abs($previous) * 100;
    return ['pct' => $pct, 'up' => $pct >= 0, 'label' => ($pct >= 0 ? '+' : '') . number_format($pct, 0) . '%'];
}

function money_dec(float|int|string|null $amount): string
{
    $n = (float) $amount;
    $formatted = '₹' . indian_grouped($n, 2);
    return $n < 0 ? '−' . $formatted : $formatted;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) $token)) {
        http_response_code(419);
        die('Invalid security token. Please refresh and try again.');
    }
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $msg = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $msg;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function uid(): int
{
    return (int) (current_user()['id'] ?? 0);
}

function role(): string
{
    return current_user()['role'] ?? '';
}

function can(string $capability): bool
{
    $map = [
        'admin' => ['*'],
        'marketing' => ['events.view', 'events.create', 'events.edit', 'sponsors', 'sponsorships.create', 'receipts', 'expenses.view', 'expenses.create', 'registrations.view', 'registrations.create', 'registrations.edit', 'reports'],
        'doctor' => ['events.view', 'expenses.view', 'sponsorships.view', 'registrations.view', 'reports'],
        'pharmacy' => ['events.view', 'sponsors', 'sponsorships.create', 'sponsorships.view', 'receipts', 'expenses.view', 'registrations.view', 'registrations.create', 'registrations.edit', 'reports'],
        'finance' => ['events.view', 'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.approve', 'sponsorships.view', 'receipts', 'registrations.view', 'reports'],
        'coordinator' => ['events.view', 'events.create', 'events.edit', 'expenses.view', 'expenses.create', 'expenses.edit', 'sponsorships.view', 'registrations.view', 'registrations.create', 'registrations.edit', 'reports'],
    ];
    $role = role();
    $caps = $map[$role] ?? [];
    return in_array('*', $caps, true) || in_array($capability, $caps, true);
}

function require_login(): void
{
    if (!current_user()) {
        redirect('index.php');
    }
}

function require_can(string $capability): void
{
    require_login();
    if (!can($capability)) {
        http_response_code(403);
        die('You do not have permission to access this page.');
    }
}

function log_activity(string $action, ?string $entity = null, ?int $id = null, ?string $details = null): void
{
    try {
        $stmt = db()->prepare('INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            uid() ?: null,
            $action,
            $entity,
            $id,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // logging must never break the request
    }
}

function next_event_code(?string $unit = null): string
{
    $year = date('Y');
    $unit = strtoupper(trim((string) ($unit ?: active_unit_filter() ?: 'HTC')));
    if (!isset(units()[$unit])) {
        $unit = 'HTC';
    }
    $like = $unit . '-' . $year . '-%';
    $stmt = db()->prepare('SELECT code FROM events WHERE code LIKE ? ORDER BY code DESC LIMIT 1');
    $stmt->execute([$like]);
    $last = $stmt->fetchColumn();
    $n = 1;
    if ($last && preg_match('/-(\d+)$/', (string) $last, $m)) {
        $n = (int) $m[1] + 1;
    }
    return sprintf('%s-%s-%03d', $unit, $year, $n);
}

function sponsorship_captured(float $target, float $promised): float
{
    return $promised > 0.009 ? $promised : max(0.0, $target);
}

function event_totals(int $eventId): array
{
    $pdo = db();
    $ev = $pdo->prepare('SELECT COALESCE(sponsorship_target,0) FROM events WHERE id = ?');
    $ev->execute([$eventId]);
    $target = (float) $ev->fetchColumn();
    $exp = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE event_id = ? AND deleted_at IS NULL AND approval_status = 'approved'");
    $exp->execute([$eventId]);
    $purchase = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE event_id = ? AND booking_type = 'purchase' AND deleted_at IS NULL AND approval_status = 'approved'");
    $purchase->execute([$eventId]);
    $ecm = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE event_id = ? AND booking_type = 'ecm' AND deleted_at IS NULL AND approval_status = 'approved'");
    $ecm->execute([$eventId]);
    $promised = $pdo->prepare('SELECT COALESCE(SUM(promised_amount),0) FROM sponsorships WHERE event_id = ? AND status <> "cancelled"');
    $promised->execute([$eventId]);
    $received = $pdo->prepare(
        'SELECT COALESCE(SUM(r.amount),0)
         FROM sponsorship_receipts r
         JOIN sponsorships s ON s.id = r.sponsorship_id
         WHERE s.event_id = ? AND s.status <> "cancelled"'
    );
    $received->execute([$eventId]);
    $expenses = (float) $exp->fetchColumn();
    $prom = (float) $promised->fetchColumn();
    $recv = (float) $received->fetchColumn();
    $captured = sponsorship_captured($target, $prom);
    $pend = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount),0) FROM expenses WHERE event_id = ? AND deleted_at IS NULL AND approval_status = 'pending'");
    $pend->execute([$eventId]);
    $pendRow = $pend->fetch(PDO::FETCH_NUM);
    return [
        'target' => $target,
        'captured' => $captured,
        'expenses' => $expenses,
        'purchase_expenses' => (float) $purchase->fetchColumn(),
        'ecm_expenses' => (float) $ecm->fetchColumn(),
        'promised' => $prom,
        'received' => $recv,
        'outstanding' => max(0, $captured - $recv),
        'uncovered' => max(0, $expenses - $recv),
        'net' => $recv - $expenses,
        'collect_pct' => $captured > 0 ? min(100, (int) round($recv / $captured * 100)) : 0,
        'cover_pct' => $expenses > 0 ? min(100, (int) round($recv / $expenses * 100)) : ($recv > 0 ? 100 : 0),
        'pending_count' => (int) ($pendRow[0] ?? 0),
        'pending_amount' => (float) ($pendRow[1] ?? 0),
    ];
}

function apply_event_sponsorship_amount(int $eventId, float $amount): void
{
    if ($amount <= 0) {
        throw new RuntimeException('Sponsorship amount must be greater than zero.');
    }
    $st = db()->prepare('SELECT * FROM sponsorships WHERE event_id = ? AND status <> "cancelled" ORDER BY id');
    $st->execute([$eventId]);
    $links = $st->fetchAll();
    if (count($links) === 1) {
        $sid = (int) $links[0]['id'];
        $sum = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM sponsorship_receipts WHERE sponsorship_id = ?');
        $sum->execute([$sid]);
        $got = (float) $sum->fetchColumn();
        if ($got > $amount + 0.009) {
            throw new RuntimeException('Cannot set sponsorship below receipts already posted (' . money_dec($got) . ').');
        }
        db()->prepare('UPDATE sponsorships SET promised_amount = ? WHERE id = ?')->execute([$amount, $sid]);
        refresh_sponsorship_status($sid);
        log_activity('sponsorship.update', 'sponsorship', $sid, 'Promise updated to ' . $amount);
    } elseif (count($links) > 1) {
        $promised = 0.0;
        foreach ($links as $row) {
            $promised += (float) $row['promised_amount'];
        }
        db()->prepare('UPDATE events SET sponsorship_target = ? WHERE id = ?')->execute([$promised, $eventId]);
        return;
    }
    db()->prepare('UPDATE events SET sponsorship_target = ? WHERE id = ?')->execute([$amount, $eventId]);
}

function can_edit_sponsorship_amount(): bool
{
    return can('sponsorships.create') || can('events.edit') || can('expenses.approve') || role() === 'admin';
}

function update_sponsorship_amount(int $eventId, int $sponsorshipId, float $amount): void
{
    if (!can_edit_sponsorship_amount()) {
        throw new RuntimeException('You cannot change this promise.');
    }
    if ($amount <= 0) {
        throw new RuntimeException('Sponsorship amount must be greater than zero.');
    }
    $event = event_row($eventId);
    assert_unit_access($event);
    if (($event['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('This event is cancelled.');
    }
    $st = db()->prepare('SELECT * FROM sponsorships WHERE id = ? AND event_id = ?');
    $st->execute([$sponsorshipId, $eventId]);
    $row = $st->fetch();
    if (!$row || $row['status'] === 'cancelled') {
        throw new RuntimeException('Sponsorship not found.');
    }
    $sum = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM sponsorship_receipts WHERE sponsorship_id = ?');
    $sum->execute([$sponsorshipId]);
    $got = (float) $sum->fetchColumn();
    if ($got > $amount + 0.009) {
        throw new RuntimeException('Cannot set this promise below receipts already posted (' . money_dec($got) . ').');
    }
    db()->prepare('UPDATE sponsorships SET promised_amount = ? WHERE id = ?')->execute([$amount, $sponsorshipId]);
    refresh_sponsorship_status($sponsorshipId);
    sync_sponsorship_target($eventId);
    log_activity('sponsorship.update', 'sponsorship', $sponsorshipId, 'Promise updated to ' . $amount);
}

function can_remove_sponsorship(): bool
{
    return can_edit_sponsorship_amount();
}

function remove_sponsorship(int $eventId, int $sponsorshipId): string
{
    if (!can_remove_sponsorship()) {
        throw new RuntimeException('You cannot remove this promise.');
    }
    $event = event_row($eventId);
    assert_unit_access($event);
    if (($event['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('This event is cancelled.');
    }
    $st = db()->prepare('SELECT * FROM sponsorships WHERE id = ? AND event_id = ?');
    $st->execute([$sponsorshipId, $eventId]);
    $row = $st->fetch();
    if (!$row || $row['status'] === 'cancelled') {
        throw new RuntimeException('Sponsorship not found.');
    }
    $sum = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM sponsorship_receipts WHERE sponsorship_id = ?');
    $sum->execute([$sponsorshipId]);
    $got = (float) $sum->fetchColumn();
    if ($got > 0.009) {
        db()->prepare('UPDATE sponsorships SET status = "cancelled" WHERE id = ? AND event_id = ?')->execute([$sponsorshipId, $eventId]);
        sync_sponsorship_target($eventId);
        log_activity('sponsorship.cancel', 'sponsorship', $sponsorshipId, 'Removed — receipts already posted (' . $got . ')');
        return 'cancelled';
    }
    db()->prepare('DELETE FROM sponsorships WHERE id = ? AND event_id = ?')->execute([$sponsorshipId, $eventId]);
    sync_sponsorship_target($eventId);
    log_activity('sponsorship.delete', 'sponsorship', $sponsorshipId, 'Deleted wrong entry on ' . ($event['title'] ?? 'event'));
    return 'deleted';
}

function event_registration_fees(int $eventId): array
{
    $empty = ['count' => 0, 'billed' => 0.0, 'collected' => 0.0];
    $queries = [
        "SELECT COUNT(*) n,
                COALESCE(SUM(fee_amount),0) billed,
                COALESCE(SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN fee_amount ELSE 0 END),0) collected
         FROM registrations WHERE event_id = ? AND deleted_at IS NULL",
        "SELECT COUNT(*) n,
                COALESCE(SUM(fee_amount),0) billed,
                COALESCE(SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN fee_amount ELSE 0 END),0) collected
         FROM registrations WHERE event_id = ?",
        "SELECT COUNT(*) n, COALESCE(SUM(fee_amount),0) billed, 0 collected
         FROM registrations WHERE event_id = ? AND deleted_at IS NULL",
        "SELECT COUNT(*) n, COALESCE(SUM(fee_amount),0) billed, 0 collected
         FROM registrations WHERE event_id = ?",
    ];
    foreach ($queries as $sql) {
        try {
            $st = db()->prepare($sql);
            $st->execute([$eventId]);
            $row = $st->fetch(PDO::FETCH_NUM);
            if ($row) {
                return [
                    'count' => (int) ($row[0] ?? 0),
                    'billed' => (float) ($row[1] ?? 0),
                    'collected' => (float) ($row[2] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return $empty;
}

function registration_fees_from_rows(array $rows): array
{
    $count = 0;
    $billed = 0.0;
    $collected = 0.0;
    foreach ($rows as $rg) {
        $count++;
        $fee = (float) ($rg['fee_amount'] ?? $rg['amount'] ?? 0);
        $billed += $fee;
        if (strtolower((string) ($rg['payment_status'] ?? '')) === 'paid') {
            $collected += $fee;
        }
    }
    return ['count' => $count, 'billed' => $billed, 'collected' => $collected];
}

function merge_registration_fees(array $a, array $b): array
{
    return [
        'count' => max((int) ($a['count'] ?? 0), (int) ($b['count'] ?? 0)),
        'billed' => max((float) ($a['billed'] ?? 0), (float) ($b['billed'] ?? 0)),
        'collected' => max((float) ($a['collected'] ?? 0), (float) ($b['collected'] ?? 0)),
    ];
}

function registration_amount_shown(float $planned, array $fees): float
{
    $billed = (float) ($fees['billed'] ?? 0);
    return $billed > 0.009 ? $billed : max(0.0, $planned);
}

function sync_registration_target(int $eventId): float
{
    $fees = event_registration_fees($eventId);
    $billed = (float) ($fees['billed'] ?? 0);
    if ($billed <= 0.009) {
        try {
            $planned = db()->prepare('SELECT COALESCE(registration_target,0) FROM events WHERE id = ?');
            $planned->execute([$eventId]);
            return (float) $planned->fetchColumn();
        } catch (Throwable $e) {
            return 0.0;
        }
    }
    try {
        db()->prepare('UPDATE events SET registration_target = ? WHERE id = ?')->execute([$billed, $eventId]);
    } catch (Throwable $e) {
    }
    return $billed;
}

function sync_sponsorship_target(int $eventId, ?float $entered = null): float
{
    $st = db()->prepare('SELECT funding_mode, sponsorship_target FROM events WHERE id = ?');
    $st->execute([$eventId]);
    $row = $st->fetch();
    if (!$row) {
        return 0;
    }
    if (($row['funding_mode'] ?? 'sponsored') === 'unsponsored') {
        db()->prepare('UPDATE events SET sponsorship_target = 0 WHERE id = ?')->execute([$eventId]);
        return 0;
    }
    $sum = db()->prepare('SELECT COALESCE(SUM(promised_amount),0) FROM sponsorships WHERE event_id = ? AND status <> "cancelled"');
    $sum->execute([$eventId]);
    $promised = (float) $sum->fetchColumn();
    $final = $promised > 0.009 ? $promised : (float) ($entered ?? $row['sponsorship_target']);
    db()->prepare('UPDATE events SET sponsorship_target = ? WHERE id = ?')->execute([$final, $eventId]);
    return $final;
}

function refresh_sponsorship_status(int $sponsorshipId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT promised_amount, status FROM sponsorships WHERE id = ?');
    $stmt->execute([$sponsorshipId]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] === 'cancelled') {
        return;
    }
    $sum = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM sponsorship_receipts WHERE sponsorship_id = ?');
    $sum->execute([$sponsorshipId]);
    $got = (float) $sum->fetchColumn();
    $promised = (float) $row['promised_amount'];
    $status = 'promised';
    if ($got <= 0) {
        $status = 'promised';
    } elseif ($got + 0.009 >= $promised) {
        $status = 'received';
    } else {
        $status = 'partial';
    }
    $upd = $pdo->prepare('UPDATE sponsorships SET status = ? WHERE id = ?');
    $upd->execute([$status, $sponsorshipId]);
}

function roles(): array
{
    return [
        'admin' => 'Administrator',
        'marketing' => 'Marketing',
        'doctor' => 'Doctor',
        'pharmacy' => 'Pharmacy Head',
        'finance' => 'Finance',
        'coordinator' => 'Event Coordinator',
    ];
}

function event_types(): array
{
    return ['CME', 'Conference', 'Workshop', 'Health Camp', 'Product Launch', 'Advisory Board', 'Dinner Meeting', 'Webinar', 'Other'];
}

function event_statuses(): array
{
    return ['draft' => 'Draft', 'planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
}

function ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $col = db()->query("SHOW COLUMNS FROM events LIKE 'funding_mode'")->fetch();
    if (!$col) {
        db()->exec("ALTER TABLE events ADD COLUMN funding_mode ENUM('sponsored','unsponsored') NOT NULL DEFAULT 'sponsored' AFTER status");
    }
    $amt = db()->query("SHOW COLUMNS FROM events LIKE 'sponsorship_target'")->fetch();
    if (!$amt) {
        db()->exec("ALTER TABLE events ADD COLUMN sponsorship_target DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER funding_mode");
        db()->exec(
            "UPDATE events e
             SET sponsorship_target = (
               SELECT COALESCE(SUM(s.promised_amount),0)
               FROM sponsorships s
               WHERE s.event_id = e.id AND s.status <> 'cancelled'
             )
             WHERE e.funding_mode = 'sponsored'"
        );
    }
    $regAmt = db()->query("SHOW COLUMNS FROM events LIKE 'registration_target'")->fetch();
    if (!$regAmt) {
        db()->exec("ALTER TABLE events ADD COLUMN registration_target DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER sponsorship_target");
    }
    $book = db()->query("SHOW COLUMNS FROM expenses LIKE 'booking_type'")->fetch();
    if (!$book) {
        db()->exec("ALTER TABLE expenses
            ADD COLUMN booking_type ENUM('purchase','ecm') NOT NULL DEFAULT 'purchase' AFTER category_id,
            ADD COLUMN po_no VARCHAR(80) DEFAULT NULL AFTER invoice_no,
            ADD COLUMN wo_no VARCHAR(80) DEFAULT NULL AFTER po_no,
            ADD COLUMN order_date DATE DEFAULT NULL AFTER wo_no,
            ADD COLUMN vendor_gstin VARCHAR(20) DEFAULT NULL AFTER order_date,
            ADD COLUMN ecm_no VARCHAR(80) DEFAULT NULL AFTER vendor_gstin,
            ADD COLUMN ecm_date DATE DEFAULT NULL AFTER ecm_no,
            ADD COLUMN claimant VARCHAR(120) DEFAULT NULL AFTER ecm_date,
            ADD COLUMN ecm_approved_by VARCHAR(120) DEFAULT NULL AFTER claimant");
        db()->exec("UPDATE expenses SET po_no = CONCAT('PO-', LPAD(id,4,'0')), order_date = expense_date WHERE booking_type = 'purchase'");
        db()->exec("UPDATE expenses SET booking_type = 'ecm', po_no = NULL, order_date = NULL,
            ecm_no = CONCAT('ECM-', LPAD(id,4,'0')), ecm_date = expense_date, claimant = 'Neha Joshi', ecm_approved_by = 'Sanjay Kulkarni'
            WHERE title LIKE '%ticket%' OR title LIKE '%memento%' OR title LIKE '%Tea and snacks%' OR title LIKE '%refreshment%' OR title LIKE '%Workbooks%' OR title LIKE '%air tickets%' OR title LIKE '%Delegate kit%'");
    }
    $appr = db()->query("SHOW COLUMNS FROM expenses LIKE 'approval_status'")->fetch();
    if (!$appr) {
        db()->exec("ALTER TABLE expenses
            ADD COLUMN approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER recorded_by,
            ADD COLUMN approved_by INT UNSIGNED DEFAULT NULL AFTER approval_status,
            ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by,
            ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER approved_at");
        db()->exec("UPDATE expenses SET approval_status = 'approved' WHERE approval_status = 'approved' OR approval_status IS NULL OR approval_status = ''");
        db()->exec("UPDATE expenses SET po_no = NULL WHERE po_no = ''");
        db()->exec("UPDATE expenses SET wo_no = NULL WHERE wo_no = ''");
        db()->exec("UPDATE expenses SET ecm_no = NULL WHERE ecm_no = ''");
        try {
            db()->exec("CREATE UNIQUE INDEX uq_exp_ecm ON expenses (ecm_no)");
        } catch (Throwable $e) {
        }
    } else {
        try {
            db()->exec("CREATE UNIQUE INDEX uq_exp_ecm ON expenses (ecm_no)");
        } catch (Throwable $e) {
        }
    }
    foreach (['uq_exp_po', 'uq_exp_wo'] as $idx) {
        try {
            db()->exec("ALTER TABLE expenses DROP INDEX {$idx}");
        } catch (Throwable $e) {
        }
    }
    foreach (['idx_exp_po' => 'po_no', 'idx_exp_wo' => 'wo_no'] as $idx => $col) {
        try {
            db()->exec("CREATE INDEX {$idx} ON expenses ({$col})");
        } catch (Throwable $e) {
        }
    }
    db()->exec("CREATE TABLE IF NOT EXISTS expense_history (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        expense_id INT UNSIGNED NOT NULL,
        event_id INT UNSIGNED DEFAULT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        action VARCHAR(40) NOT NULL,
        details TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_eh_exp (expense_id),
        INDEX idx_eh_event (event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        db()->exec("UPDATE expenses SET po_no = NULL WHERE po_no = ''");
        db()->exec("UPDATE expenses SET wo_no = NULL WHERE wo_no = ''");
        db()->exec("UPDATE expenses SET ecm_no = NULL WHERE ecm_no = ''");
    } catch (Throwable $e) {
    }
    $lim = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = 'expense_approval_limit'");
    $lim->execute();
    if (!$lim->fetch()) {
        db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('expense_approval_limit','50000')")->execute();
    }
    $grace = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = 'collection_grace_days'");
    $grace->execute();
    if (!$grace->fetch()) {
        db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('collection_grace_days','30')")->execute();
    }
    $demo = db()->query("SELECT id FROM events WHERE code = 'EVT-2026-001'")->fetch();
    $hasUnsponsored = db()->query("SELECT id FROM events WHERE code = 'EVT-2026-005'")->fetch();
    if ($demo && !$hasUnsponsored) {
        $ins = db()->prepare(
            'INSERT INTO events (code, title, event_type, description, venue, city, start_date, end_date, expected_attendees, budget_estimate, status, funding_mode, sponsorship_target, marketing_lead_id, doctor_id, pharmacy_head_id, coordinator_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            'EVT-2026-005', 'Nursing Skills Workshop', 'Workshop',
            'In-house skills drill. No industry support — hospital funded.',
            'Skill Lab, 3rd Floor', 'Pune', '2026-08-28', '2026-08-28', 40, 45000, 'planned', 'unsponsored', 0,
            2, 3, 4, 6, 6,
        ]);
        $eid = (int) db()->lastInsertId();
        $ex = db()->prepare(
            'INSERT INTO expenses (event_id, category_id, booking_type, title, vendor, amount, expense_date, payment_status, paid_amount, payment_mode, invoice_no, po_no, order_date, ecm_no, ecm_date, claimant, recorded_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,6)'
        );
        $ex->execute([$eid, 9, 'purchase', 'Workbooks and badges', 'PrintMint', 8000, '2026-08-20', 'paid', 8000, 'upi', 'PM-410', 'PO-0410', '2026-08-20', null, null, null]);
        $ex->execute([$eid, 2, 'ecm', 'Tea and snacks', 'Hospital Kitchen', 6000, '2026-08-28', 'unpaid', 0, null, '', null, null, 'ECM-0411', '2026-08-28', 'Neha Joshi']);
    }
    ensure_units_schema();
    ensure_bootstrap_admin();
    ensure_default_categories();
    ensure_registrations_schema();
    ensure_notifications_schema();
}

function ensure_notifications_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED DEFAULT NULL,
        channel ENUM('inapp','email','whatsapp') NOT NULL DEFAULT 'inapp',
        type VARCHAR(60) NOT NULL,
        title VARCHAR(200) NOT NULL,
        body TEXT,
        entity_type VARCHAR(40) DEFAULT NULL,
        entity_id INT UNSIGNED DEFAULT NULL,
        recipient VARCHAR(190) DEFAULT NULL,
        status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        error TEXT,
        read_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME DEFAULT NULL,
        INDEX idx_notif_user (user_id, read_at),
        INDEX idx_notif_status (channel, status),
        INDEX idx_notif_dedupe (type, entity_type, entity_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        db()->exec('ALTER TABLE notifications ADD CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    } catch (Throwable $e) {
    }
    $defaults = [
        'notify_inapp_enabled' => '1',
        'notify_email_enabled' => '0',
        'notify_whatsapp_enabled' => '0',
        'notify_on_sponsorship' => '1',
        'notify_on_overdue' => '1',
        'notify_on_expense_approval' => '1',
        'notify_on_event_reminder' => '1',
        'event_reminder_days' => '7,1',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => '',
        'whatsapp_provider' => 'generic',
        'whatsapp_endpoint' => '',
        'whatsapp_token' => '',
        'whatsapp_sid' => '',
        'whatsapp_from' => '',
        'app_base_url' => '',
        'cron_secret' => bin2hex(random_bytes(12)),
    ];
    $chk = db()->prepare('SELECT 1 FROM settings WHERE setting_key = ?');
    $ins = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)');
    foreach ($defaults as $key => $value) {
        $chk->execute([$key]);
        if (!$chk->fetch()) {
            $ins->execute([$key, $value]);
        }
    }
}

function default_expense_categories(): array
{
    return [
        ['Accommodation', 'accommodation', 'bed', '#3a6ea5', 1],
        ['Food & Beverage', 'food-beverage', 'utensils', '#1b6e64', 2],
        ['Liquor', 'liquor', 'glass', '#8a3a6e', 3],
        ['AV Setup', 'av-setup', 'monitor', '#c4892a', 4],
        ['Music', 'music', 'music', '#6b4c9a', 4],
        ['Stalls', 'stalls', 'store', '#3d6b9a', 5],
        ['Gifts', 'gifts', 'gift', '#c4a35a', 6],
        ['Travel Tickets', 'travel', 'plane', '#2f7d5b', 7],
        ['Venue Hire', 'venue', 'building', '#5a6d80', 8],
        ['Printing & Collaterals', 'printing', 'printer', '#6d7a86', 9],
        ['Honorarium', 'honorarium', 'award', '#c45c4a', 10],
        ['Miscellaneous', 'misc', 'more', '#6d7a86', 11],
    ];
}

function ensure_default_categories(): void
{
    try {
        db()->query('SELECT 1 FROM expense_categories LIMIT 1');
    } catch (Throwable $e) {
        return;
    }
    $ins = db()->prepare('INSERT INTO expense_categories (name, slug, icon, color, sort_order) VALUES (?,?,?,?,?)');
    $find = db()->prepare('SELECT id FROM expense_categories WHERE slug = ? OR name = ? LIMIT 1');
    foreach (default_expense_categories() as $row) {
        try {
            $find->execute([$row[1], $row[0]]);
            if ($find->fetch()) {
                continue;
            }
            $ins->execute($row);
        } catch (Throwable $e) {
        }
    }
}

function expense_category_list(): array
{
    ensure_default_categories();
    try {
        return db()->query('SELECT id, name FROM expense_categories WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
    } catch (Throwable $e) {
        try {
            return db()->query('SELECT id, name FROM expense_categories ORDER BY name')->fetchAll();
        } catch (Throwable $e2) {
            return [];
        }
    }
}

function ensure_bootstrap_admin(): void
{
    try {
        $count = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Throwable $e) {
        return;
    }
    if ($count > 0) {
        return;
    }
    $hash = password_hash('Admin@123', PASSWORD_BCRYPT);
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

function ensure_units_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS units (
        code VARCHAR(8) NOT NULL PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        notes TEXT,
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $insU = db()->prepare('INSERT IGNORE INTO units (code, name, notes, sort_order) VALUES (?,?,?,?)');
    foreach (default_units() as $u) {
        $insU->execute([$u['code'], $u['name'], $u['notes'], $u['sort_order']]);
    }
    $uc = db()->query("SHOW COLUMNS FROM users LIKE 'unit_code'")->fetch();
    if (!$uc) {
        db()->exec("ALTER TABLE users ADD COLUMN unit_code VARCHAR(8) DEFAULT NULL AFTER department");
    }
    $ec = db()->query("SHOW COLUMNS FROM events LIKE 'unit_code'")->fetch();
    if (!$ec) {
        db()->exec("ALTER TABLE events ADD COLUMN unit_code VARCHAR(8) NOT NULL DEFAULT 'HTC' AFTER status");
    }
    db()->exec("UPDATE users SET unit_code = 'HTC' WHERE role IN ('marketing','doctor','pharmacy','coordinator') AND (unit_code IS NULL OR unit_code = '')");
    db()->exec("UPDATE users SET unit_code = NULL WHERE role IN ('admin','finance')");
    db()->exec("UPDATE events SET unit_code = 'HTC' WHERE unit_code IS NULL OR unit_code = ''");

    $demo = null;
    $extras = [
        ['Kavya Patil', 'marketing.sec@hospital.local', 'marketing', 'Marketing · SEC', 'Unit Marketing Head', 'SEC'],
        ['Sameer Khan', 'marketing.smj@hospital.local', 'marketing', 'Marketing · SMJ', 'Unit Marketing Head', 'SMJ'],
        ['Anita Lopes', 'marketing.mlk@hospital.local', 'marketing', 'Marketing · MLK', 'Unit Marketing Head', 'MLK'],
        ['Ravi More', 'coordinator.sec@hospital.local', 'coordinator', 'Events · SEC', 'Event Coordinator', 'SEC'],
        ['Sneha Kadam', 'coordinator.smj@hospital.local', 'coordinator', 'Events · SMJ', 'Event Coordinator', 'SMJ'],
        ['Imran Shaikh', 'coordinator.mlk@hospital.local', 'coordinator', 'Events · MLK', 'Event Coordinator', 'MLK'],
    ];
    $find = db()->prepare('SELECT id FROM users WHERE email = ?');
    $add = db()->prepare('INSERT INTO users (name, email, password, role, department, designation, phone, unit_code) VALUES (?,?,?,?,?,?,?,?)');
    $ids = [];
    foreach ($extras as $row) {
        $find->execute([$row[1]]);
        $id = $find->fetchColumn();
        if (!$id) {
            $demo ??= password_hash('Demo@123', PASSWORD_BCRYPT);
            $add->execute([$row[0], $row[1], $demo, $row[2], $row[3], $row[4], '', $row[5]]);
            $id = (int) db()->lastInsertId();
        }
        $ids[$row[5] . '.' . $row[2]] = (int) $id;
    }
    $flag = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = 'units_demo_seeded'");
    $flag->execute();
    if ($flag->fetchColumn()) {
        return;
    }
    db()->exec("UPDATE events SET unit_code = 'HTC' WHERE code IN ('EVT-2026-001','EVT-2026-005')");
    db()->exec("UPDATE events SET unit_code = 'SEC' WHERE code = 'EVT-2026-002'");
    db()->exec("UPDATE events SET unit_code = 'SMJ' WHERE code = 'EVT-2026-003'");
    db()->exec("UPDATE events SET unit_code = 'MLK' WHERE code = 'EVT-2026-004'");
    $mkt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $mkt->execute(['marketing@hospital.local']);
    $htcM = (int) $mkt->fetchColumn();
    $mkt->execute(['coordinator@hospital.local']);
    $htcC = (int) $mkt->fetchColumn();
    $secM = $ids['SEC.marketing'] ?? 0;
    $smjM = $ids['SMJ.marketing'] ?? 0;
    $mlkM = $ids['MLK.marketing'] ?? 0;
    $secC = $ids['SEC.coordinator'] ?? 0;
    $smjC = $ids['SMJ.coordinator'] ?? 0;
    $mlkC = $ids['MLK.coordinator'] ?? 0;
    if ($secM) {
        db()->prepare('UPDATE events SET marketing_lead_id = ?, coordinator_id = ? WHERE code = ?')->execute([$secM, $secC ?: null, 'EVT-2026-002']);
    }
    if ($smjM) {
        db()->prepare('UPDATE events SET marketing_lead_id = ?, coordinator_id = ? WHERE code = ?')->execute([$smjM, $smjC ?: null, 'EVT-2026-003']);
    }
    if ($mlkM) {
        db()->prepare('UPDATE events SET marketing_lead_id = ?, coordinator_id = ? WHERE code = ?')->execute([$mlkM, $mlkC ?: null, 'EVT-2026-004']);
    }
    if ($htcM) {
        db()->prepare('UPDATE events SET marketing_lead_id = ?, coordinator_id = ? WHERE code IN (?,?)')->execute([$htcM, $htcC ?: null, 'EVT-2026-001', 'EVT-2026-005']);
    }
    db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('units_demo_seeded','1')")->execute();
}

function funding_modes(): array
{
    return [
        'sponsored' => 'Will be sponsored',
        'unsponsored' => 'Not sponsored — hospital funded',
    ];
}

function event_is_unsponsored(array $event): bool
{
    return ($event['funding_mode'] ?? 'sponsored') === 'unsponsored';
}

function funding_label(array $event, int $sponsorCount = 0): array
{
    if (event_is_unsponsored($event)) {
        return ['key' => 'unsponsored', 'label' => 'Not sponsored', 'class' => 'muted'];
    }
    if ($sponsorCount > 0) {
        return ['key' => 'sponsored', 'label' => $sponsorCount === 1 ? '1 sponsor' : $sponsorCount . ' sponsors', 'class' => 'ok'];
    }
    return ['key' => 'seeking', 'label' => 'Seeking sponsors', 'class' => 'warn'];
}

function active_sponsor_count(int $eventId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM sponsorships WHERE event_id = ? AND status <> "cancelled"');
    $stmt->execute([$eventId]);
    return (int) $stmt->fetchColumn();
}

function link_sponsorship(array $data): int
{
    $eventId = (int) $data['event_id'];
    $st = db()->prepare('SELECT id, title, funding_mode, status, start_date, end_date, unit_code FROM events WHERE id = ?');
    $st->execute([$eventId]);
    $event = $st->fetch();
    if (!$event) {
        throw new RuntimeException('Choose an event. Every sponsorship must belong to one programme.');
    }
    assert_unit_access($event);
    if (($event['funding_mode'] ?? 'sponsored') === 'unsponsored') {
        throw new RuntimeException('“' . $event['title'] . '” is marked not sponsored. Edit the event and switch it to “Will be sponsored” before linking a company.');
    }
    if (($event['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('Cannot link a sponsor to a cancelled event.');
    }
    $promiseDate = $data['promised_date'] ?: date('Y-m-d');
    if (!empty($event['start_date'])) {
        $min = date('Y-m-d', strtotime($event['start_date'] . ' -365 days'));
        $max = date('Y-m-d', strtotime(($event['end_date'] ?? $event['start_date']) . ' +90 days'));
        if ($promiseDate < $min || $promiseDate > $max) {
            throw new RuntimeException('Promise date must sit near the event dates.');
        }
    }
    $sponsorId = (int) ($data['sponsor_id'] ?? 0);
    if ($sponsorId < 1) {
        throw new RuntimeException('Select a sponsor.');
    }
    $amount = (float) ($data['promised_amount'] ?? 0);
    if ($amount <= 0) {
        throw new RuntimeException('Sponsorship amount is required and must be greater than zero.');
    }
    db()->prepare(
        'INSERT INTO sponsorships (event_id, sponsor_id, promised_amount, promised_date, status, liaison_user_id, notes, created_by)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $eventId,
        $sponsorId,
        $amount,
        $data['promised_date'] ?: date('Y-m-d'),
        'promised',
        !empty($data['liaison_user_id']) ? (int) $data['liaison_user_id'] : uid(),
        trim((string) ($data['notes'] ?? '')),
        uid(),
    ]);
    $id = (int) db()->lastInsertId();
    sync_sponsorship_target($eventId);
    log_activity('sponsorship.create', 'event', $eventId, 'Linked sponsor to ' . $event['title'] . ' for ' . $amount);
    notify('sponsorship.promised', [
        'event' => event_notify_context($eventId),
        'sponsor_name' => sponsor_name_by_id($sponsorId),
        'amount' => $amount,
        'liaison_user_id' => !empty($data['liaison_user_id']) ? (int) $data['liaison_user_id'] : uid(),
        'entity_type' => 'sponsorship',
        'entity_id' => $id,
    ]);
    return $id;
}

function sponsor_types(): array
{
    return ['pharma' => 'Pharma', 'device' => 'Device / Equipment', 'corporate' => 'Corporate', 'individual' => 'Individual', 'ngo' => 'NGO', 'other' => 'Other'];
}

function payment_modes(): array
{
    return ['cash' => 'Cash', 'bank' => 'Bank Transfer', 'upi' => 'UPI', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'];
}

function booking_types(): array
{
    return [
        'purchase' => 'Purchase (PO / WO)',
        'ecm' => 'ECM',
    ];
}

function expense_ref(array $ex): string
{
    if (($ex['booking_type'] ?? 'purchase') === 'ecm') {
        return trim('ECM ' . ($ex['ecm_no'] ?? '')) ?: 'ECM';
    }
    $parts = [];
    if (!empty($ex['po_no'])) {
        $parts[] = 'PO ' . $ex['po_no'];
    }
    if (!empty($ex['wo_no'])) {
        $parts[] = 'WO ' . $ex['wo_no'];
    }
    return $parts ? implode(' · ', $parts) : 'Purchase';
}

function users_by_role(string $role, ?string $unit = null): array
{
    $sql = 'SELECT id, name, designation, unit_code FROM users WHERE role = ? AND is_active = 1';
    $params = [$role];
    if ($unit) {
        $sql .= ' AND unit_code = ?';
        $params[] = $unit;
    }
    $sql .= ' ORDER BY name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function all_active_users(): array
{
    return db()->query('SELECT id, name, role, designation FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
}

function status_class(string $status): string
{
    return match ($status) {
        'completed', 'received', 'paid', 'active', 'approved', 'on_track' => 'ok',
        'ongoing', 'partial', 'planned', 'pending', 'watch', 'undercollected' => 'warn',
        'cancelled', 'unpaid', 'draft', 'rejected' => 'muted',
        'overspent' => 'coral',
        default => 'info',
    };
}

function dmy(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $t = strtotime($date);
    return $t ? date('d M Y', $t) : '—';
}

function posted(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function query(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function upload_bill(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Bill upload failed.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Bill must be under 5 MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Bills may be PDF or image files only.');
    }
    $name = 'bill_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir = __DIR__ . '/../uploads/bills';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the uploads/bills folder. In cPanel File Manager, create it and set permissions to 755.');
    }
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save the uploaded bill.');
    }
    return $name;
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $a = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $b = strtoupper(substr($parts[1] ?? '', 0, 1));
    return $a . $b;
}
