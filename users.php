<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();

$error = '';
$editingId = (int)($_GET['edit'] ?? 0);
$isEditing = $editingId > 0;
$form = ['id' => '', 'username' => '', 'full_name' => '', 'role' => 'secretary'];

if ($isEditing) {
    $st = $pdo->prepare('SELECT id, username, full_name, role FROM users WHERE id = ?');
    $st->execute([$editingId]);
    $editingUser = $st->fetch();
    if (!$editingUser) {
        http_response_code(404);
        exit('User not found.');
    }
    $form = $editingUser;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $isEditing = $action === 'update';
    $editingId = $isEditing ? (int)($_POST['id'] ?? 0) : 0;
    $usernameInput = $_POST['username'] ?? '';
    $fullNameInput = $_POST['full_name'] ?? '';
    $roleInput = $_POST['role'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $form = [
        'id' => $editingId,
        'username' => is_string($usernameInput) ? trim($usernameInput) : '',
        'full_name' => is_string($fullNameInput) ? trim($fullNameInput) : '',
        'role' => is_string($roleInput) ? $roleInput : '',
    ];
    $newPassword = is_string($passwordInput) ? $passwordInput : '';
    $targetUser = null;

    if (!in_array($action, ['create', 'update'], true)) {
        $error = 'Invalid user action.';
    } elseif ($isEditing) {
        $st = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ?');
        $st->execute([$editingId]);
        $targetUser = $st->fetch();
        if (!$targetUser) {
            http_response_code(404);
            exit('User not found.');
        }
    }

    if (!$error && !preg_match('/\A[A-Za-z0-9._-]{3,50}\z/', $form['username'])) {
        $error = 'Username must be 3–50 characters and use only letters, numbers, dots, dashes, or underscores.';
    } elseif (!$error && ($form['full_name'] === '' || strlen($form['full_name']) > 100)) {
        $error = 'Full name is required and must be 100 characters or fewer.';
    } elseif (!$error && !in_array($form['role'], ['admin', 'secretary'], true)) {
        $error = 'Choose a valid user role.';
    } elseif (!$error && (!$isEditing || $newPassword !== '')
        && (strlen($newPassword) < 8 || !preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword))) {
        $error = 'Password must be at least 8 characters and contain letters and numbers.';
    } elseif (!$error && $isEditing && (int)$targetUser['id'] === (int)current_user()['id']
        && $form['role'] !== 'admin') {
        $error = 'You cannot change your own administrator role.';
    } elseif (!$error && $isEditing && $targetUser['role'] === 'admin' && $form['role'] !== 'admin'
        && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
        $error = 'At least one administrator account must remain.';
    }

    if (!$error) {
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $duplicate->execute([$form['username'], $editingId]);
        if ($duplicate->fetchColumn()) {
            $error = 'That username is already in use.';
        }
    }

    if (!$error) {
        try {
            if ($isEditing) {
                if ($newPassword !== '') {
                    $st = $pdo->prepare('UPDATE users SET username = ?, full_name = ?, role = ?, password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?');
                    $st->execute([$form['username'], $form['full_name'], $form['role'], password_hash($newPassword, PASSWORD_DEFAULT), $editingId]);
                } else {
                    $st = $pdo->prepare('UPDATE users SET username = ?, full_name = ?, role = ? WHERE id = ?');
                    $st->execute([$form['username'], $form['full_name'], $form['role'], $editingId]);
                }
                audit($pdo, 'User updated', $form['username'] . ' (' . $form['role'] . ')');
                if ($editingId === (int)current_user()['id']) {
                    $_SESSION['user']['username'] = $form['username'];
                    $_SESSION['user']['full_name'] = $form['full_name'];
                }
                flash('User account updated.');
            } else {
                $st = $pdo->prepare('INSERT INTO users (username, full_name, role, password_hash) VALUES (?, ?, ?, ?)');
                $st->execute([$form['username'], $form['full_name'], $form['role'], password_hash($newPassword, PASSWORD_DEFAULT)]);
                audit($pdo, 'User created', $form['username'] . ' (' . $form['role'] . ')');
                flash('User account created.');
            }
            redirect('users.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() !== '23000') {
                throw $ex;
            }
            $error = 'That username is already in use.';
        }
    }
}

$users = $pdo->query('SELECT id, username, full_name, role, failed_attempts, locked_until, last_login FROM users ORDER BY full_name, username')->fetchAll();
$page_title = 'Users'; $page_sub = 'Admin'; $active = 'users';
include __DIR__ . '/includes/layout_top.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" class="card-box">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
      <?php if ($isEditing): ?><input type="hidden" name="id" value="<?= (int)$editingId ?>"><?php endif; ?>
      <h2><?= $isEditing ? 'Edit user' : 'Add user' ?></h2>
      <?php if ($error): ?><div class="alert alert-warning py-2 small"><?= e($error) ?></div><?php endif; ?>
      <div class="mb-3">
        <label class="form-label" for="full_name">Full name</label>
        <input class="form-control" id="full_name" name="full_name" maxlength="100" value="<?= e($form['full_name']) ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <input class="form-control" id="username" name="username" maxlength="50" pattern="[A-Za-z0-9._-]{3,50}" value="<?= e($form['username']) ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="role">Role</label>
        <select class="form-select" id="role" name="role" required>
          <?php foreach (['secretary' => 'Secretary', 'admin' => 'Administrator'] as $roleValue => $roleLabel): ?>
            <option value="<?= e($roleValue) ?>" <?= $form['role'] === $roleValue ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password"><?= $isEditing ? 'New password (optional)' : 'Initial password' ?></label>
        <input class="form-control" type="password" id="password" name="password" minlength="8" <?= $isEditing ? '' : 'required' ?> autocomplete="new-password">
        <div class="form-text">At least 8 characters, including letters and numbers. Leave blank when keeping the current password.</div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-teal px-4"><?= $isEditing ? 'Save changes' : 'Create user' ?></button>
        <?php if ($isEditing): ?><a class="btn btn-outline-teal" href="users.php">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="col-lg-7">
    <div class="card-box">
      <h2>User accounts</h2>
      <div class="table-responsive">
        <table class="table-b">
          <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($users as $account): ?>
            <?php $isLocked = $account['locked_until'] && strtotime($account['locked_until']) > time(); ?>
            <tr>
              <td class="fw-semibold"><?= e($account['full_name']) ?></td>
              <td><?= e($account['username']) ?></td>
              <td><?= e(ucfirst($account['role'])) ?></td>
              <td><?= $account['last_login'] ? e(date('M j, Y g:i A', strtotime($account['last_login']))) : 'Never' ?></td>
              <td><?= $isLocked ? '<span class="pill pill-red">Locked</span>' : '<span class="pill pill-green">Active</span>' ?></td>
              <td><a class="btn btn-sm btn-outline-teal" href="users.php?edit=<?= (int)$account['id'] ?>">Edit</a></td>
            </tr>
          <?php endforeach; if (!$users): ?>
            <tr><td colspan="6" class="text-center text-secondary py-4">No user accounts found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
