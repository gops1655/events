<?php
declare(strict_types=1);

function default_units(): array
{
    return [
        'HTC' => [
            'code' => 'HTC',
            'name' => 'HTC',
            'notes' => 'Unit marketing, doctors and coordinators run local programmes. Purchase (PO/WO) and finance approval are hospital-wide.',
            'sort_order' => 1,
        ],
        'SEC' => [
            'code' => 'SEC',
            'name' => 'SEC',
            'notes' => 'Unit marketing, doctors and coordinators run local programmes. Purchase (PO/WO) and finance approval are hospital-wide.',
            'sort_order' => 2,
        ],
        'SMJ' => [
            'code' => 'SMJ',
            'name' => 'SMJ',
            'notes' => 'Unit marketing, doctors and coordinators run local programmes. Purchase (PO/WO) and finance approval are hospital-wide.',
            'sort_order' => 3,
        ],
        'MLK' => [
            'code' => 'MLK',
            'name' => 'MLK',
            'notes' => 'Unit marketing, doctors and coordinators run local programmes. Purchase (PO/WO) and finance approval are hospital-wide.',
            'sort_order' => 4,
        ],
    ];
}

function units(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $rows = db()->query('SELECT code, name, notes, sort_order FROM units ORDER BY sort_order, code')->fetchAll();
        foreach ($rows as $row) {
            $cache[$row['code']] = $row;
        }
    } catch (Throwable $e) {
        $cache = default_units();
    }
    if (!$cache) {
        $cache = default_units();
    }
    return $cache;
}

function unit_codes(): array
{
    return array_keys(units());
}

function unit_label(?string $code): string
{
    $code = strtoupper(trim((string) $code));
    if ($code === '') {
        return 'Central';
    }
    $u = units()[$code] ?? null;
    if (!$u) {
        return $code;
    }
    return $u['name'] === $code ? $code : $code . ' · ' . $u['name'];
}

function is_central_role(?string $role = null): bool
{
    $role ??= role();
    return in_array($role, ['admin', 'finance'], true);
}

function is_unit_role(?string $role = null): bool
{
    $role ??= role();
    return in_array($role, ['marketing', 'doctor', 'pharmacy', 'coordinator'], true);
}

function can_see_all_units(): bool
{
    return is_central_role();
}

function user_unit(): ?string
{
    $code = strtoupper(trim((string) (current_user()['unit_code'] ?? '')));
    return $code !== '' && isset(units()[$code]) ? $code : null;
}

function active_unit_filter(): ?string
{
    if (!can_see_all_units()) {
        return user_unit();
    }
    $posted = strtoupper(trim((string) query('unit')));
    return isset(units()[$posted]) ? $posted : null;
}

function unit_where(string $alias, array &$params): string
{
    $unit = active_unit_filter();
    if ($unit === null) {
        if (can_see_all_units()) {
            return '';
        }
        $params[] = '__none__';
        return " AND {$alias}.unit_code = ?";
    }
    $params[] = $unit;
    return " AND {$alias}.unit_code = ?";
}

function assert_unit_access(array $event): void
{
    if (can_see_all_units()) {
        return;
    }
    $mine = user_unit();
    if (!$mine || strtoupper((string) ($event['unit_code'] ?? '')) !== $mine) {
        throw new RuntimeException('This programme belongs to another unit.');
    }
}

function deny_other_unit(array $event): void
{
    try {
        assert_unit_access($event);
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        redirect('events.php');
    }
}

function resolve_event_unit(int $eventId, string $posted): string
{
    if (!can_see_all_units()) {
        $mine = user_unit();
        if (!$mine) {
            throw new RuntimeException('Your account is not assigned to a unit. Ask an administrator.');
        }
        if ($eventId > 0) {
            $st = db()->prepare('SELECT unit_code FROM events WHERE id = ?');
            $st->execute([$eventId]);
            $old = strtoupper((string) $st->fetchColumn());
            if ($old !== '' && $old !== $mine) {
                throw new RuntimeException('You cannot edit another unit’s event.');
            }
        }
        return $mine;
    }
    $posted = strtoupper(trim($posted));
    if (!isset(units()[$posted])) {
        throw new RuntimeException('Choose a unit: HTC, SEC, SMJ or MLK.');
    }
    return $posted;
}

function render_unit_pills(string $page): void
{
    if (!can_see_all_units()) {
        $mine = user_unit();
        if ($mine) {
            echo '<div class="unit-pills"><span class="badge unit">' . e($mine) . ' unit</span>';
            $notes = units()[$mine]['notes'] ?? '';
            if ($notes) {
                echo '<span class="muted" style="font-size:13px">' . e($notes) . '</span>';
            }
            echo '</div>';
        }
        return;
    }
    $cur = strtoupper(trim((string) query('unit')));
    $q = $_GET;
    echo '<div class="unit-pills">';
    unset($q['unit']);
    $allQs = http_build_query($q);
    echo '<a class="' . ($cur === '' ? 'active' : '') . '" href="' . e($page) . ($allQs !== '' ? '?' . $allQs : '') . '">All units</a>';
    foreach (unit_codes() as $code) {
        $q['unit'] = $code;
        echo '<a class="' . ($cur === $code ? 'active' : '') . '" href="' . e($page) . '?' . e(http_build_query($q)) . '">' . e($code) . '</a>';
    }
    echo '</div>';
}
