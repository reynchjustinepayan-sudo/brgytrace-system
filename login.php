<?php
require_once __DIR__ . '/includes/auth.php';
if (current_user()) { redirect('dashboard.php'); }

$error = ''; $info = '';
$barangayName = app_setting($pdo, 'barangay_name', BRGY_NAME);
$municipality = app_setting($pdo, 'barangay_municipality', BRGY_MUNI);
$province = app_setting($pdo, 'barangay_province', BRGY_PROVINCE);
$maxFailedLogins = (int)app_setting($pdo, 'max_failed_logins', (string)MAX_FAILED_LOGINS);
$lockMinutes = (int)app_setting($pdo, 'lock_minutes', (string)LOCK_MINUTES);
if (!in_array($maxFailedLogins, [3, 5, 7, 10], true)) { $maxFailedLogins = MAX_FAILED_LOGINS; }
if (!in_array($lockMinutes, [1, 5, 10, 15, 30, 60], true)) { $lockMinutes = LOCK_MINUTES; }
if (isset($_GET['out'])) { $info = 'You have been signed out.'; }
if (isset($_GET['expired'])) { $info = 'You were signed out after being inactive.'; }
$forgot = isset($_GET['forgot']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();

    if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
        audit($pdo, 'Failed sign-in');
        $error = 'Too many wrong tries. This account is locked for ' . $lockMinutes . ' minutes. If you cannot wait, ask the admin to unlock it.';
    } elseif ($u && password_verify($password, $u['password_hash'])) {
        $pdo->prepare('UPDATE users SET failed_attempts = 0, failed_attempt_at = NULL, locked_until = NULL, last_login = ? WHERE id = ?')->execute([now(), $u['id']]);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role']];
        $_SESSION['last_activity'] = time();
        audit($pdo, 'Signed in');
        redirect('dashboard.php');
    } else {
        if ($u) {
            $lastFailedAt = $u['failed_attempt_at'] ?? null;
            $lastFailedTimestamp = $lastFailedAt ? strtotime($lastFailedAt) : false;
            $withinAttemptWindow = $lastFailedTimestamp !== false && time() - $lastFailedTimestamp < $lockMinutes * 60;
            $fails = $withinAttemptWindow ? (int)$u['failed_attempts'] + 1 : 1;
            $lock = null;
            $failedAt = now();
            if ($fails >= $maxFailedLogins) {
                $lock = date('Y-m-d H:i:s', time() + $lockMinutes * 60);
                $fails = 0;
                $failedAt = null;
            }
            $pdo->prepare('UPDATE users SET failed_attempts = ?, failed_attempt_at = ?, locked_until = ? WHERE id = ?')->execute([$fails, $failedAt, $lock, $u['id']]);
            if ($lock) { $error = 'Too many wrong tries. This account is locked for ' . $lockMinutes . ' minutes.'; }
        }
        audit($pdo, 'Failed sign-in');
        if (!$error) { $error = 'Wrong username or password. Please check and try again.'; }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · BrgyTrace</title>
<link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <section class="login-left">
    <img src="assets/img/logo-white-teal-1-cropped.png" alt="BrgyTrace">
    <div class="tag">Secure. Track. Serve.</div>
    <div class="rule"></div>
    <p>A smarter barangay for a more organized community.</p>
    <div class="loc"><img src="assets/img/location-pin-new.png" alt=""><span>Barangay <?= e($barangayName) ?>, <?= e($municipality) ?>,<br><?= e($province) ?></span></div>
  </section>

  <section class="login-right">
    <div class="login-panel-mark" aria-hidden="true">
      <span>BrgyTrace</span>
      <i></i>
    </div>
    <div class="login-card">
    <?php if ($forgot): ?>
      <h1>Forgot <span>password?</span></h1>
      <p class="text-secondary mt-2">Ask the admin to reset it for you.</p>
      <ol class="small">
        <li>The admin gives you a temporary password.</li>
        <li>Sign in with it.</li>
        <li>Choose a new password.</li>
      </ol>
      <a href="login.php" class="btn btn-amber w-100 py-2">Back to sign in</a>
      <p class="text-center text-secondary small mt-3 mb-0">Password resets are saved in the audit log.</p>
    <?php else: ?>
      <h1>Welcome <span>Back!</span></h1>
      <p class="text-secondary mt-2 mb-3">Log in to your barangay account</p>
      <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>
      <?php if ($info): ?><div class="alert alert-success py-2 small"><?= e($info) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="u">Username</label>
          <input class="form-control" id="u" name="username" placeholder="Enter your username" required autofocus></div>
        <div class="mb-2"><label class="form-label" for="p">Password</label>
          <input class="form-control" id="p" name="password" type="password" placeholder="Enter your password" required></div>
        <a class="small fw-semibold" href="login.php?forgot=1">Forgot password?</a>
        <button class="btn btn-amber w-100 py-2 mt-3" type="submit">Sign In</button>
      </form>
      <p class="text-center text-secondary small mt-3 mb-0">Every sign-in is saved in the audit log.</p>
    <?php endif; ?>
    </div>
  </section>
</div>
</body>
</html>
