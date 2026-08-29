<?php
require __DIR__ . '/includes/init.php';

$logged = (bool) current_user();
$myRole = $logged ? role() : '';
$limit = money(approval_limit());
$hospital = setting('hospital_name', 'the hospital');

$rolesMeta = [
    'admin' => [
        'who' => 'Medical superintendent / IT owner of the desk',
        'can' => [
            'Everything every other role can do',
            'Create and deactivate users, assign roles',
            'Hospital name, city, expense categories, and the approval limit',
            'Delete events and cancel any expense or promise',
            'Approve large expenses and allow overspend',
        ],
        'cannot' => ['Nothing on this desk is hidden from an administrator'],
    ],
    'marketing' => [
        'who' => 'Head of marketing and industry liaison',
        'can' => [
            'Plan and edit events, including sponsorship amount and first sponsor',
            'Add and edit sponsor companies',
            'Link a sponsor to an event and post receipts when money arrives',
            'Edit a promised amount if the company revises the grant',
            'Add registrations for camps and programmes',
            'Book expenses (Purchase or ECM)',
            'Add event registrations and upload attendee lists',
            'Read the dashboard, reports, and activity log',
        ],
        'cannot' => [
            'Edit or cancel an expense after it is approved — finance does that',
            'Approve pending expenses or tick “Allow overspend”',
            'Open Users or Settings',
        ],
    ],
    'doctor' => [
        'who' => 'Faculty / consultant attached to programmes',
        'can' => [
            'Open every event, expense, sponsor, registration, and sponsorship',
            'See health, spend, promises, and receipts',
            'Read reports and the activity log',
        ],
        'cannot' => [
            'Create or edit events, expenses, sponsors, or promises',
            'Post receipts or approve spend',
            'Change users or settings',
        ],
    ],
    'pharmacy' => [
        'who' => 'Pharmacy head — often the industry contact for camps and stalls',
        'can' => [
            'Add and edit sponsor companies',
            'Link a sponsor to an event, capture the promised amount, and edit it if the company revises',
            'Post receipts when the company pays',
            'Add event registrations and upload attendee lists',
            'View events, expenses, dashboard, and reports',
        ],
        'cannot' => [
            'Create or edit events',
            'Book, edit, or approve expenses',
            'Open Users or Settings',
        ],
    ],
    'finance' => [
        'who' => 'Accounts — the money gate for this desk',
        'can' => [
            'Book, edit, and cancel expenses, including approved lines',
            'Approve or reject amounts above the approval limit (' . $limit . ')',
            'Allow spend above the sponsorship amount or hospital budget',
            'Post receipts against a promise',
            'Edit or delete a promised amount when the company revises or a row is wrong',
            'Read sponsorships, reports, and the dashboard',
            'See registration lists (read-only)',
        ],
        'cannot' => [
            'Create or edit events (ask marketing or a coordinator)',
            'Add sponsor companies or link a new promise',
            'Open Users or Settings',
        ],
    ],
    'coordinator' => [
        'who' => 'Event coordinator on the ground',
        'can' => [
            'Plan and edit events (dates, venue, team, sponsored vs hospital-funded)',
            'Edit a promised amount if the company revises the grant',
            'Book expenses as Purchase (PO/WO) or ECM',
            'Add registrations and upload the attendee list',
            'Edit or cancel expenses that are still pending or rejected',
            'Read sponsorships, reports, and the dashboard',
        ],
        'cannot' => [
            'Link a sponsor or post a receipt — marketing or pharmacy does that',
            'Approve pending expenses or allow overspend',
            'Change an expense after finance has approved it',
            'Open Users or Settings',
        ],
    ],
];

$matrix = [
    'Open dashboard & activity' => ['admin', 'marketing', 'doctor', 'pharmacy', 'finance', 'coordinator'],
    'Create / edit events' => ['admin', 'marketing', 'coordinator'],
    'Delete an event' => ['admin'],
    'Book an expense' => ['admin', 'marketing', 'finance', 'coordinator'],
    'Edit a pending expense' => ['admin', 'finance', 'coordinator'],
    'Edit an approved expense' => ['admin', 'finance'],
    'Approve / reject expenses' => ['admin', 'finance'],
    'Allow overspend' => ['admin', 'finance'],
    'Manage sponsor companies' => ['admin', 'marketing', 'pharmacy'],
    'Add / upload registrations' => ['admin', 'marketing', 'pharmacy', 'coordinator'],
    'Edit / remove a registration' => ['admin', 'marketing', 'pharmacy', 'coordinator'],
    'Link a sponsor & amount' => ['admin', 'marketing', 'pharmacy'],
    'Edit a promised amount' => ['admin', 'marketing', 'pharmacy', 'finance', 'coordinator'],
    'Delete a promise' => ['admin', 'marketing', 'pharmacy', 'finance', 'coordinator'],
    'Post a receipt' => ['admin', 'marketing', 'pharmacy', 'finance'],
    'View reports' => ['admin', 'marketing', 'doctor', 'pharmacy', 'finance', 'coordinator'],
    'Users & settings' => ['admin'],
];
$roleKeys = ['admin', 'marketing', 'doctor', 'pharmacy', 'finance', 'coordinator'];

$pageTitle = 'Help & manual';
$pageCrumb = 'How ' . product_name() . ' works · who can do what';
$active = 'help';

if ($logged) {
    require __DIR__ . '/includes/header.php';
} else {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Help &amp; manual · <?= e(product_name()) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Outfit:wght@400;500;560;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css?v=6">
</head>
<body>
<div class="manual-shell">
  <div class="manual-bar">
    <div class="brand" style="border:0;padding:0;margin:0">
      <div class="brand-mark">EG</div>
      <div>
        <h1 style="color:var(--ink)"><?= e(product_name()) ?></h1>
        <p style="color:var(--muted)"><?= e(product_tagline()) ?></p>
      </div>
    </div>
    <a class="btn btn-brass" href="index.php">Sign in</a>
  </div>
    <?php
}
?>

<div class="help-hero">
  <div>
    <p class="help-kicker">Desk manual</p>
    <h1>One ledger for events, spend, and sponsorship</h1>
    <p><?= e(product_name()) ?> is <?= e($hospital) ?>’s desk for programmes that marketing, doctors, pharmacy, coordinators, and finance all touch. Every rupee is either <strong>promised</strong> by a company, <strong>spent</strong> on the event, or <strong>received</strong> in the bank — and those three stay on the same event.</p>
  </div>
  <?php if ($logged): ?>
  <div class="help-you">
    <span>You are signed in as</span>
    <strong><?= e(current_user()['name']) ?></strong>
    <span class="badge info"><?= e(roles()[$myRole] ?? $myRole) ?></span>
    <a href="#role-<?= e($myRole) ?>">Jump to your role</a>
  </div>
  <?php endif; ?>
</div>

<nav class="help-toc" aria-label="Manual sections">
  <a href="#flow">How work flows</a>
  <a href="#units">Four units</a>
  <a href="#roles">Roles</a>
  <a href="#matrix">Access chart</a>
  <a href="#money">Money rules</a>
  <a href="#screens">Screens</a>
  <a href="#accounts">Demo logins</a>
</nav>

<section class="card" id="flow" style="margin-bottom:16px">
  <div class="card-h"><h3>How work flows</h3><span>Start with the event. Money always hangs off that event.</span></div>
  <div class="card-b">
    <div class="help-flow">
      <div class="help-step">
        <span class="n">1</span>
        <h4>Plan the event</h4>
        <p>Coordinator or marketing creates the programme: dates, venue, team (marketing lead, doctor, pharmacy head, coordinator), and whether it will be sponsored.</p>
      </div>
      <div class="help-step">
        <span class="n">2</span>
        <h4>Capture funding</h4>
        <p><strong>Sponsored:</strong> sponsorship amount and the company are both compulsory. If the company revises the grant, use <strong>Edit amount</strong> on the event hub or the Sponsorships list. <strong>Not sponsored:</strong> hospital-funded — expenses only, no sponsor ledger. Set a <strong>registration amount</strong> on the event (delegate fees planned) the same way as sponsorship; it is shown on the event hub.</p>
      </div>
      <div class="help-step">
        <span class="n">3</span>
        <h4>Book expenses</h4>
        <p>On the event page, add spend as <strong>Purchase (PO / WO)</strong> or <strong>ECM</strong>, or use <strong>Upload expenses</strong> with the CSV / Excel template. One PO or WO can have several line items — repeat the same number on each row. Both roll into the same tracker. Paid amount cannot exceed the line amount.</p>
      </div>
      <div class="help-step">
        <span class="n">4</span>
        <h4>Register attendees</h4>
        <p>On the event page (or <strong>Registrations</strong> in the menu), add people one by one or <strong>Upload list</strong> from Excel / CSV. Every row is linked to that programme. Duplicate email, phone, or registration number on the same event is skipped.</p>
      </div>
      <div class="help-step">
        <span class="n">5</span>
        <h4>Finance gate</h4>
        <p>Amounts over <?= e($limit) ?> wait as <strong>pending</strong> until finance approves. Pending lines do not count in the tracker. Overspend needs finance to tick “Allow overspend”.</p>
      </div>
      <div class="help-step">
        <span class="n">6</span>
        <h4>Collect money</h4>
        <p>When the company pays, marketing, pharmacy, or finance posts a <strong>receipt</strong> against that promise. You cannot receive more than the balance left, or date it before the promise.</p>
      </div>
      <div class="help-step">
        <span class="n">7</span>
        <h4>Watch health</h4>
        <p>Dashboard and the event page show On track, Collecting, a red flag when collection is more than 30 days late, or Overspent. The event page has a collection clock. Reports export the same numbers (approved spend only).</p>
      </div>
    </div>
    <p class="muted" style="margin:16px 0 0">The people named on an event (Marketing, Doctor, Pharmacy, Coordinator) are the <strong>team for that programme</strong>. That is separate from the <strong>login role</strong> that controls buttons. A doctor login is read-only even if they are listed as faculty on the event.</p>
  </div>
</section>

<section class="card" id="units" style="margin-bottom:16px">
  <div class="card-h"><h3>Four units</h3><span>HTC · SEC · SMJ · MLK</span></div>
  <div class="card-b">
    <p>The hospital runs four branches. Each has its own marketing desk and local control of programmes. <strong>Purchase (PO / WO) and finance approval are central</strong> — one accounts team. A PO or WO may have several expense lines.</p>
    <div class="help-screens" style="margin-top:12px">
      <?php foreach (units() as $code => $u): ?>
      <div>
        <strong><?= e($code) ?></strong>
        <span><?= e($u['notes'] ?: 'Unit marketing books events. Finance sees every unit.') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <ul class="help-list" style="margin-top:14px">
      <li><strong>Unit roles</strong> (marketing, coordinator, doctor, pharmacy) only see their own unit’s events, expenses, and promises.</li>
      <li><strong>Central roles</strong> (administrator, finance) see all four units, with HTC / SEC / SMJ / MLK filters on the dashboard and lists.</li>
      <li>A new event is stamped with a unit. Marketing of HTC cannot open or edit an SEC programme.</li>
      <li>Sponsor companies are hospital-wide. Linking a promise still happens on a unit’s event.</li>
    </ul>
  </div>
</section>

<section class="card" id="roles" style="margin-bottom:16px">
  <div class="card-h"><h3>What each role can do</h3><span>Six logins. One desk.</span></div>
  <div class="card-b">
    <div class="help-roles">
      <?php foreach ($rolesMeta as $key => $meta): ?>
      <article class="help-role<?= $myRole === $key ? ' you' : '' ?>" id="role-<?= e($key) ?>">
        <h3>
          <?= e(roles()[$key]) ?>
          <?php if ($myRole === $key): ?><span class="badge ok">Your role</span><?php endif; ?>
        </h3>
        <p class="muted"><?= e($meta['who']) ?></p>
        <p class="help-sub">Can</p>
        <ul class="help-list"><?php foreach ($meta['can'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
        <p class="help-sub">Cannot</p>
        <ul class="help-list dim"><?php foreach ($meta['cannot'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="card" id="matrix" style="margin-bottom:16px">
  <div class="card-h"><h3>Access chart</h3><span>Tick = this role can do it</span></div>
  <div class="card-b table-wrap">
    <table class="data matrix">
      <thead>
        <tr>
          <th>Action</th>
          <?php foreach ($roleKeys as $rk): ?>
            <th class="ctr<?= $myRole === $rk ? ' you-col' : '' ?>"><?= e(roles()[$rk]) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($matrix as $action => $who): ?>
        <tr>
          <td><?= e($action) ?></td>
          <?php foreach ($roleKeys as $rk):
              $yes = in_array($rk, $who, true); ?>
            <td class="ctr<?= $myRole === $rk ? ' you-col' : '' ?> <?= $yes ? 'yes' : 'no' ?>"><?= $yes ? 'Yes' : '—' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="grid-2" id="money" style="margin-bottom:16px">
  <div class="card">
    <div class="card-h"><h3>Money rules</h3></div>
    <div class="card-b">
      <ul class="help-list">
        <li>The event <strong>sponsorship amount</strong> is the sum of live promised amounts from linked companies. Edit or delete a wrong row on Sponsorships or the event hub.</li>
        <li>A hospital-funded event has no sponsor ledger. If a company comes in later, switch it on the event page and capture amount + company together.</li>
        <li>Receipts cannot exceed what is still promised, and cannot be dated before the promise.</li>
        <li>One PO or WO may have several live expense lines. Each live ECM number must be unique. GSTIN, if entered, must be 15 characters.</li>
        <li>PO / ECM dates must fall from 180 days before the event to 90 days after it.</li>
        <li>Cancelled events are frozen. Completed events: only finance can change expenses; receipts can still be posted.</li>
        <li>Cancelling an expense keeps the history. It drops out of the tracker.</li>
      </ul>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><h3>Health badges</h3></div>
    <div class="card-b">
      <p class="muted" style="margin-top:0">These appear on the event, the events list, and upcoming items on the dashboard.</p>
      <p><span class="badge ok">On track</span> Spend and collections sit inside the sponsorship (or budget).</p>
      <p><span class="badge info">Collecting</span> Money is still due. After the event ends, companies have 30 days (changeable in Settings) to pay.</p>
      <p><span class="badge coral">Collection late</span> Red flag: the event ended more than 30 days ago and sponsorship is still outstanding. The collection clock on the event page shows how many days late.</p>
      <p><span class="badge coral">Overspent</span> Red flag: approved spend is above the sponsorship amount (or the hospital budget on an unsponsored event).</p>
      <p class="muted">Only <strong>approved</strong> expenses count in these numbers. Pending lines wait for finance. Both flags can show on the same event.</p>
    </div>
  </div>
</section>

<section class="card" id="screens" style="margin-bottom:16px">
  <div class="card-h"><h3>Screens</h3><span>Where to click</span></div>
  <div class="card-b">
    <div class="help-screens">
      <div><strong>Dashboard</strong><span>Hospital totals, then one card per event with registrations, sponsorship, spend, receipts, and health. Charts and late collections sit below.</span></div>
      <div><strong>Events</strong><span>List, filters, new event. Open a row to reach the event hub.</span></div>
      <div><strong>Event hub</strong><span>The working page: tracker, expenses (add, upload CSV/Excel, approve / edit / cancel), registrations (add or upload the attendee list), linked sponsors, receipts, history.</span></div>
      <div><strong>Expenses</strong><span>All live lines across events. Filter by Purchase / ECM and pending / approved. Upload a sheet with an event_code column.</span></div>
      <div><strong>Purchase &amp; ECM</strong><span>Books dashboard: split totals, charts, and event-wise Purchase vs ECM accounting.</span></div>
      <div><strong>Sponsors</strong><span>Company master (name, type, GSTIN). Link them to events from Sponsorships or the event page.</span></div>
      <div><strong>Registrations</strong><span>Attendees across events. Add one, or upload Excel / CSV with an event_code column. Each person stays on one programme.</span></div>
      <div><strong>Sponsorships</strong><span>Every promise tied to one event. Edit amount or Delete a wrong row. Outstanding = promised − received.</span></div>
      <div><strong>Reports</strong><span>Filter by event, Purchase only, ECM only, or outstanding sponsorship. Download Excel (letterhead, prepared-by, document ID) or CSV. Spend here is approved only.</span></div>
      <div><strong>Activity</strong><span>Who changed what. Visible to every signed-in role.</span></div>
      <div><strong>Users / Settings</strong><span>Administrators only. Approval limit lives under Settings.</span></div>
    </div>
  </div>
</section>

<section class="card" id="accounts">
  <div class="card-h"><h3>Demo logins</h3><span>Same hospital, six hats</span></div>
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>Role</th><th>Email</th><th>Password</th><th>Try this</th></tr></thead>
      <tbody>
        <tr><td>Administrator</td><td><code>admin@hospital.local</code></td><td><code>Admin@123</code></td><td>Users, Settings, any event</td></tr>
        <tr><td>Marketing · HTC</td><td><code>marketing@hospital.local</code></td><td><code>Demo@123</code></td><td>HTC events only</td></tr>
        <tr><td>Marketing · SEC</td><td><code>marketing.sec@hospital.local</code></td><td><code>Demo@123</code></td><td>SEC events only</td></tr>
        <tr><td>Marketing · SMJ</td><td><code>marketing.smj@hospital.local</code></td><td><code>Demo@123</code></td><td>SMJ events only</td></tr>
        <tr><td>Marketing · MLK</td><td><code>marketing.mlk@hospital.local</code></td><td><code>Demo@123</code></td><td>MLK events only</td></tr>
        <tr><td>Doctor</td><td><code>doctor@hospital.local</code></td><td><code>Demo@123</code></td><td>Read an event hub — no save buttons for money</td></tr>
        <tr><td>Pharmacy Head</td><td><code>pharmacy@hospital.local</code></td><td><code>Demo@123</code></td><td>Add a sponsor company and a promise</td></tr>
        <tr><td>Finance</td><td><code>finance@hospital.local</code></td><td><code>Demo@123</code></td><td>Approve a pending expense, allow overspend</td></tr>
        <tr><td>Event Coordinator</td><td><code>coordinator@hospital.local</code></td><td><code>Demo@123</code></td><td>Book an expense over <?= e($limit) ?> and see it wait for finance</td></tr>
      </tbody>
    </table>
    <p class="muted" style="margin:14px 0 0">A typical day: coordinator books the hotel on a PO → if it is over <?= e($limit) ?> it sits pending → finance approves → marketing posts the company’s receipt → dashboard health moves from Collecting toward On track.</p>
  </div>
</section>

<?php
if ($logged) {
    require __DIR__ . '/includes/footer.php';
} else {
    echo "</div></body></html>";
}
