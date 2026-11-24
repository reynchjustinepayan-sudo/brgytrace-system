<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$u = current_user(); $err = '';
$barangayName = app_setting($pdo, 'barangay_name', BRGY_NAME); $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cur = $_POST['current'] ?? ''; $new = $_POST['new'] ?? ''; $con = $_POST['confirm'] ?? '';
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?'); $st->execute([$u['id']]); $hash = $st->fetchColumn();
    if (!password_verify($cur, $hash)) { $err = 'Your current password is not correct.'; }
    elseif (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) { $err = 'The new password needs at least 8 characters, with letters and numbers.'; }
    elseif ($new !== $con) { $err = 'The two new passwords are not the same.'; }
    else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        audit($pdo, 'Password changed', $u['username']);
        flash('Password changed.'); redirect('account.php');
    }
}
$page_title = 'My Account'; $page_sub = 'Account'; $active = 'account';
include __DIR__ . '/includes/layout_top.php';
?>
<div class="account-layout">
  <section class="card-box account-profile-card">
    <div class="account-profile-summary">
      <div class="avatar avatar-teal account-profile-avatar"><?= e(strtoupper($u['full_name'][0])) ?></div>
      <div class="account-profile-heading">
        <span class="account-eyebrow">Account profile</span>
        <h2><?= e($u['full_name']) ?></h2>
        <span class="pill pill-green"><?= e(ucfirst($u['role'])) ?></span>
      </div>
    </div>
    <dl class="account-details">
      <div><dt>Username</dt><dd><?= e($u['username']) ?></dd></div>
      <div><dt>Barangay</dt><dd><?= e($barangayName) ?></dd></div>
    </dl>
  </section>
  <section class="card-box account-security-card">
    <div class="account-security-heading">
      <h2>Change password</h2>
      <p>Update your password to keep your account secure.</p>
    </div>
    <form method="post">
    <?= csrf_field() ?>
    <?php if ($err): ?><div class="alert alert-warning py-2 small"><?= e($err) ?></div><?php endif; ?>
    <div class="mb-3">
      <label class="form-label" for="account_current_password">Current password</label>
      <input class="form-control" type="password" id="account_current_password" name="current" autocomplete="current-password" required>
    </div>
    <div class="account-password-fields mb-3">
      <div>
        <label class="form-label" for="account_new_password">New password</label>
        <input class="form-control" type="password" id="account_new_password" name="new" autocomplete="new-password" required>
      </div>
      <div>
        <label class="form-label" for="account_confirm_password">Confirm new password</label>
        <input class="form-control" type="password" id="account_confirm_password" name="confirm" autocomplete="new-password" required>
      </div>
    </div>
    <p class="account-password-note">Use at least 8 characters, including letters and numbers. Both new password fields must match.</p>
    <button class="btn btn-teal px-4">Save password</button>
    </form>
  </section>
</div>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
