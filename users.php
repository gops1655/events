<?php
require __DIR__ . '/includes/init.php';
require_login();
if (role() !== 'admin') {
    http_response_code(403);
    die('Administrators only.');
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['role'] ?? 'coordinator';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(roles()[$role])) {
            flash('err', 'Name, valid email and role are required.');
            redirect('users.php');
        }
        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $dup->execute([$email, $id]);
        if ($dup->fetch()) {
            flash('err', 'That email is already in use.');
            redirect('users.php');
        }
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id === uid() && !$active) {
            flash('err', 'You cannot deactivate your own account.');
            redirect('users.php');
        }
        $unitCode = strtoupper(trim((string) ($_POST['unit_code'] ?? '')));
        if (is_central_role($role)) {
            $unitCode = null;
        } elseif (!isset(units()[$unitCode])) {
            flash('err', 'Marketing, doctors, pharmacy and coordinators must belong to a unit (HTC, SEC, SMJ or MLK).');
            redirect($id ? 'users.php?edit=' . $id : 'users.php');
        }
        if ($id) {
            $pdo->prepare('UPDATE users SET name=?, email=?, role=?, department=?, unit_code=?, designation=?, phone=?, is_active=? WHERE id=?')
                ->execute([$name, $email, $role, trim($_POST['department'] ?? ''), $unitCode, trim($_POST['designation'] ?? ''), trim($_POST['phone'] ?? ''), $active, $id]);
            if (trim($_POST['password'] ?? '') !== '') {
                $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
            }
            flash('ok', 'User updated.');
        } else {
            $pass = trim($_POST['password'] ?? '');
            if (strlen($pass) < 6) {
                flash('err', 'Set a password of at least 6 characters.');
                redirect('users.php');
            }
            $pdo->prepare('INSERT INTO users (name, email, password, role, department, unit_code, designation, phone, is_active) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, trim($_POST['department'] ?? ''), $unitCode, trim($_POST['designation'] ?? ''), trim($_POST['phone'] ?? ''), $active]);
            log_activity('user.create', 'user', (int)$pdo->lastInsertId(), $email);
            flash('ok', 'User created.');
        }
        redirect('users.php');
    }
}

$rows = $pdo->query('SELECT id, name, email, role, department, unit_code, designation, phone, is_active, last_login FROM users ORDER BY name')->fetchAll();
$editId = (int) query('edit');
$edit = null;
if ($editId) {
    $st = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $st->execute([$editId]);
    $edit = $st->fetch();
}

$pageTitle = 'Users';
$pageCrumb = 'Department accounts';
$active = 'users';
require __DIR__ . '/includes/header.php';
?>
<div class="filters">
  <div></div>
  <button class="btn btn-brass" type="button" onclick="openModal('uModal')">Invite user</button>
</div>
<div class="card">
  <div class="card-b table-wrap">
    <table class="data">
      <thead><tr><th>Name</th><th>Role</th><th>Unit</th><th>Department</th><th>Last login</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong><div class="muted"><?= e($r['email']) ?></div></td>
          <td><?= e(roles()[$r['role']] ?? $r['role']) ?></td>
          <td><?= is_central_role($r['role']) ? 'Central' : e($r['unit_code'] ?: '—') ?></td>
          <td><?= e($r['department'] ?: '—') ?><div class="muted"><?= e($r['designation'] ?: '') ?></div></td>
          <td><?= $r['last_login'] ? e(dmy($r['last_login'])) : 'Never' ?></td>
          <td><span class="badge <?= $r['is_active'] ? 'ok' : 'muted' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td><a class="btn btn-ghost btn-sm" href="users.php?edit=<?= (int)$r['id'] ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal-bg <?= $edit ? 'open' : '' ?>" id="uModal">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <h3><?= $edit ? 'Edit user' : 'New user' ?></h3>
    <div class="form-grid">
      <div class="field"><label>Name</label><input name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
      <div class="field"><label>Email</label><input type="email" name="email" required value="<?= e($edit['email'] ?? '') ?>"></div>
      <div class="field"><label>Role</label>
        <select name="role" id="userRole"><?php foreach (roles() as $k=>$v): ?>
          <option value="<?= e($k) ?>" <?= ($edit['role'] ?? 'coordinator') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?></select>
      </div>
      <div class="field"><label>Unit</label>
        <select name="unit_code">
          <option value="">Central (finance / admin)</option>
          <?php foreach (units() as $code => $u): ?>
            <option value="<?= e($code) ?>" <?= ($edit['unit_code'] ?? '') === $code ? 'selected' : '' ?>><?= e($code) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Department</label><input name="department" value="<?= e($edit['department'] ?? '') ?>"></div>
      <div class="field"><label>Designation</label><input name="designation" value="<?= e($edit['designation'] ?? '') ?>"></div>
      <div class="field"><label>Phone</label><input name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
      <div class="field full"><label>Password <?= $edit ? '(leave blank to keep)' : '' ?></label><input type="password" name="password" <?= $edit ? '' : 'required minlength="6"' ?>></div>
      <div class="field"><label><input type="checkbox" name="is_active" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>> Active</label></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('uModal')">Cancel</button>
      <button class="btn btn-teal" type="submit">Save user</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
