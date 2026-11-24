<?php
// Expects: $page_title, $page_sub (optional), $active (nav key)
$user = current_user();
$isAdminDashboard = $active === 'dashboard' && $user['role'] === 'admin';
$barangayName = app_setting($pdo, 'barangay_name', BRGY_NAME);
if ($user['role'] === 'admin') {
  try {
    run_due_local_backup($pdo);
  } catch (RuntimeException $ex) {
    flash('Scheduled local backup failed: ' . $ex->getMessage(), 'danger');
  }
}
$nav = [
  ['section', 'Daily Work'],
  ['dashboard',   'Dashboard',         'home',    'dashboard.php'],
];
if ($user['role'] === 'admin') {
  array_push($nav, ['section', 'Records'],
    ['residents', 'Residents', 'user', 'residents.php'],
    ['section', 'Management'],
    ['audit', 'Audit log', 'audit', 'audit.php'],
    ['users', 'Users', 'users', 'users.php'],
    ['settings', 'Settings', 'settings', 'settings.php']);
} else {
  array_push($nav, ['issue', 'Issue certificate', 'doc', 'issue.php'],
    ['section', 'Records'],
    ['residents', 'Residents', 'user', 'residents.php'],
    ['blotter', 'Blotter', 'book', 'blotter.php'],
    ['certificates', 'Certificates', 'shield', 'certificates.php']);
}
array_push($nav, ['section', 'Support'],
  ['help', 'Help', 'help', 'help.php'],
  ['account', 'My account', 'account', 'account.php']);
$flash = flash();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> · BrgyTrace</title>
<link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="assets/css/style.css?v=certificate-workflow-32">
</head>
<body<?= $isAdminDashboard ? ' class="admin-dashboard-view"' : '' ?>>
<div class="app">
  <aside class="sidebar">
    <a href="dashboard.php" class="brand" aria-label="BrgyTrace">
      <img src="assets/img/logo_icon.png" alt="" class="brand-icon">
      <img src="assets/img/logo_text.png" alt="" class="brand-text">
    </a>
    <nav class="side-nav">
      <?php foreach ($nav as $n): ?>
        <?php if ($n[0] === 'section'): ?><div class="nav-section-label"><?= e($n[1]) ?></div><?php continue; endif; ?>
        <a href="<?= e($n[3]) ?>" class="<?= $active === $n[0] ? 'on' : '' ?>"><?= icon($n[2]) ?><?= e($n[1]) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-user">
      <div class="side-user-profile d-flex align-items-center gap-2">
        <div class="avatar avatar-amber"><?= e(strtoupper($user['full_name'][0])) ?></div>
        <div><b><?= e($user['full_name']) ?></b><small>Brgy. <?= e($barangayName) ?></small></div>
      </div>
      <a href="logout.php" class="btn-signout" aria-label="Sign out" title="Sign out"><?= icon('logout') ?></a>
    </div>
  </aside>

  <main class="content<?= $active === 'help' ? ' help-page' : '' ?><?= $active === 'account' ? ' account-page' : '' ?>">
    <header class="page-head<?= $isAdminDashboard ? ' admin-dashboard-head' : '' ?>">
      <div>
        <?php if ($active === 'help'): ?>
          <div class="crumb">System guide</div>
          <h1><?= e($page_title) ?></h1>
          <p class="help-page-subtitle"><?= $user['role'] === 'admin' ? 'Quick instructions for system setup and daily administration.' : 'Quick instructions for common barangay office tasks.' ?></p>
        <?php elseif ($isAdminDashboard): ?>
          <h1><?= e($page_title) ?></h1>
          <div class="crumb"><?= e($page_sub) ?></div>
        <?php else: ?>
          <div class="crumb"><?= e($page_sub ?? 'Barangay ' . $barangayName) ?></div>
          <h1><?= e($page_title) ?></h1>
        <?php endif; ?>
      </div>
      <?php if ($active !== 'account'): ?>
        <div class="d-flex align-items-center gap-2">
          <?php if ($isAdminDashboard): ?>
            <form class="admin-resident-search" method="get" action="residents.php" role="search">
              <button type="submit" aria-label="Search residents"><?= icon('search') ?></button>
              <input type="search" name="q" placeholder="Search residents..." aria-label="Search residents">
            </form>
          <?php else: ?>
            <?php if ($active === 'help'): ?>
              <span class="help-role-badge"><?= $user['role'] === 'admin' ? 'Admin guide' : 'Staff guide' ?></span>
            <?php else: ?>
              <span class="tag-sample">Sample data</span>
            <?php endif; ?>
          <?php endif; ?>
          <div class="avatar avatar-teal"><?= e(strtoupper($user['full_name'][0])) ?></div>
        </div>
      <?php endif; ?>
    </header>
    <?php if ($flash): ?>
      <div class="alert alert-<?= e($flash[1]) ?> py-2"><?= e($flash[0]) ?></div>
    <?php endif; ?>
