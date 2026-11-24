<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$user = current_user();
$isAdmin = $user['role'] === 'admin';
if ($isAdmin) {
    $activeUserCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE locked_until IS NULL OR locked_until <= NOW()')->fetchColumn();
    $auditCount = (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

    $todayStart = date('Y-m-d 00:00:00');
    $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $signInsTodayStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log
         WHERE action = 'Signed in' AND created_at >= ? AND created_at < ?"
    );
    $signInsTodayStmt->execute([$todayStart, $tomorrowStart]);
    $signInsToday = (int)$signInsTodayStmt->fetchColumn();

    $failedSignInsTodayStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log
         WHERE action = 'Failed sign-in' AND created_at >= ? AND created_at < ?"
    );
    $failedSignInsTodayStmt->execute([$todayStart, $tomorrowStart]);
    $failedSignInsToday = (int)$failedSignInsTodayStmt->fetchColumn();

    $recentSignInsStmt = $pdo->query(
        "SELECT a.created_at,
                COALESCE(NULLIF(u.full_name, ''), u.username, 'Unknown') AS actor
         FROM audit_log a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.action = 'Signed in'
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 5"
    );
    $recentSignIns = $recentSignInsStmt->fetchAll();

    $weekStart = new DateTimeImmutable('monday this week');
    $weekEnd = $weekStart->modify('+7 days');
    $signInWeekStmt = $pdo->prepare(
        "SELECT DATE(created_at) AS activity_day, COUNT(*) AS total
         FROM audit_log
         WHERE action = 'Signed in' AND created_at >= ? AND created_at < ?
         GROUP BY DATE(created_at)"
    );
    $signInWeekStmt->execute([$weekStart->format('Y-m-d H:i:s'), $weekEnd->format('Y-m-d H:i:s')]);
    $signInCounts = [];
    foreach ($signInWeekStmt->fetchAll() as $row) {
        $signInCounts[$row['activity_day']] = (int)$row['total'];
    }

    $weekDays = [];
    $maxSignIns = 0;
    for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
        $day = $weekStart->modify("+{$dayOffset} days");
        $dayKey = $day->format('Y-m-d');
        $signIns = $signInCounts[$dayKey] ?? 0;
        $weekDays[] = [
            'date' => $dayKey,
            'label' => $day->format('D'),
            'sign_ins' => $signIns,
        ];
        $maxSignIns = max($maxSignIns, $signIns);
    }
    $maxSignIns = max(1, $maxSignIns);

    $maxAttempts = max(3, min(10, (int)app_setting($pdo, 'max_failed_logins', (string)MAX_FAILED_LOGINS)));
    $lockMinutes = max(1, min(60, (int)app_setting($pdo, 'lock_minutes', (string)LOCK_MINUTES)));
    $systemChecks = [
        ['label' => 'Database connection', 'ok' => true, 'status' => 'Connected'],
        ['label' => 'Audit log', 'ok' => true, 'status' => $auditCount > 0 ? 'Recording' : 'Ready'],
        ['label' => 'Active administrator', 'ok' => $activeAdmins > 0, 'status' => $activeAdmins > 0 ? 'Enabled' : 'Missing'],
        ['label' => 'Login protection', 'ok' => $maxAttempts >= 3 && $lockMinutes >= 1, 'status' => 'Enabled'],
    ];
    $checksPassed = count(array_filter($systemChecks, static fn(array $check): bool => $check['ok']));
    $checksPercent = (int)round($checksPassed / count($systemChecks) * 100);
} else {
    $today = date('Y-m-d');
    $st = $pdo->prepare('SELECT c.*, r.first_name, r.middle_name, r.last_name, r.suffix
                         FROM certificates c JOIN residents r ON r.id = c.resident_id
                         WHERE DATE(c.created_at) = ? OR DATE(c.issued_at) = ? ORDER BY c.id DESC LIMIT 5');
    $st->execute([$today, $today]);
    $queue = $st->fetchAll();

    $st = $pdo->prepare('SELECT status, has_pending_case, COUNT(*) n FROM certificates WHERE DATE(created_at) = ? GROUP BY status, has_pending_case');
    $st->execute([$today]);
    $cnt = ['waiting' => 0, 'pending' => 0, 'issued' => 0];
    foreach ($st->fetchAll() as $row) {
        if ($row['status'] === 'Issued') { $cnt['issued'] += $row['n']; }
        elseif ($row['has_pending_case']) { $cnt['pending'] += $row['n']; }
        else { $cnt['waiting'] += $row['n']; }
    }
    $total = array_sum($cnt);

    $monday = date('Y-m-d', strtotime('monday this week'));
    $week = array_fill(0, 7, 0);
    $st = $pdo->prepare('SELECT DATE(issued_at) d, COUNT(*) n FROM certificates WHERE status = "Issued" AND DATE(issued_at) BETWEEN ? AND ? GROUP BY DATE(issued_at)');
    $st->execute([$monday, date('Y-m-d', strtotime($monday . ' +6 days'))]);
    foreach ($st->fetchAll() as $row) { $week[(int)((strtotime($row['d']) - strtotime($monday)) / 86400)] = (int)$row['n']; }
    $max = max(1, max($week));
    $todayIdx = (int)date('N') - 1;
    $days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

    $circ = 2 * M_PI * 58;
    $dash = $total ? $circ * $cnt['issued'] / $total : 0;
}

$page_title = $isAdmin ? 'System overview' : 'Certificate desk';
$page_sub = $isAdmin ? 'Barangay ' . app_setting($pdo, 'barangay_name', BRGY_NAME) . '  ›  ' . date('l, F j, Y') : null;
$active = 'dashboard';
include __DIR__ . '/includes/layout_top.php';
?>
<section class="tiles">
  <?php if ($isAdmin): ?>
    <a class="tile" href="settings.php#backups"><span class="b"><?= icon('backup') ?></span>Backup settings</a>
    <a class="tile" href="users.php"><span class="b"><?= icon('users') ?></span>Users and roles</a>
    <a class="tile" href="audit.php"><span class="b"><?= icon('audit') ?></span>Audit log</a>
    <a class="tile" href="settings.php"><span class="b"><?= icon('settings') ?></span>Settings</a>
  <?php else: ?>
  <a class="tile" href="issue.php"><span class="b"><?= icon('plus') ?></span>New certificate request</a>
  <a class="tile" href="blotter_add.php"><span class="b"><?= icon('addbox') ?></span>New blotter entry</a>
  <a class="tile" href="residents.php"><span class="b"><?= icon('search') ?></span>Search resident</a>
  <a class="tile" href="certificates.php"><span class="b"><?= icon('shield') ?></span>Certificates</a>
  <a class="tile" href="blotter.php"><span class="b"><?= icon('doc') ?></span>Blotter list</a>
  <?php endif; ?>
</section>

<?php if ($isAdmin): ?>
<div class="admin-overview">
  <section class="admin-overview-stats" aria-label="System statistics">
    <?php
      $lastBackup = app_setting($pdo, 'backup_last_run', '');
      $lastBackupTimestamp = $lastBackup !== '' ? strtotime($lastBackup) : false;
      $lastBackupValue = $lastBackupTimestamp !== false ? date('M j, Y', $lastBackupTimestamp) : 'Not yet run';
      $lastBackupNote = $lastBackupTimestamp !== false ? 'Completed at ' . date('g:i A', $lastBackupTimestamp) : 'Not yet run';
    ?>
    <article class="admin-overview-card">
      <span>Last backup</span>
      <strong><?= e($lastBackupValue) ?></strong>
      <small class="<?= $lastBackupTimestamp !== false ? 'is-good' : 'is-warning' ?>"><?= e($lastBackupNote) ?></small>
    </article>
    <article class="admin-overview-card">
      <span>Active users</span>
      <strong><?= number_format($activeUserCount) ?></strong>
      <small>Across all roles</small>
    </article>
    <article class="admin-overview-card">
      <span>Sign-ins today</span>
      <strong><?= number_format($signInsToday) ?></strong>
      <small>From the audit log</small>
    </article>
    <article class="admin-overview-card">
      <span>Failed sign-ins today</span>
      <strong><?= number_format($failedSignInsToday) ?></strong>
      <small class="<?= $failedSignInsToday > 0 ? 'is-warning' : 'is-good' ?>"><?= $failedSignInsToday > 0 ? 'Review activity' : 'No failed sign-ins' ?></small>
    </article>
  </section>

  <div class="admin-overview-columns">
    <div class="admin-overview-primary">
      <section class="admin-overview-panel" aria-labelledby="admin-signins-title">
        <div class="admin-overview-panel-heading">
          <h2 id="admin-signins-title">Recent sign-ins</h2>
          <a href="audit.php">Open the audit log</a>
        </div>
        <div class="table-responsive">
          <table class="table-b admin-overview-table">
            <thead><tr><th>Time</th><th>User</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($recentSignIns as $signIn): ?>
              <tr>
                <td><?= e(date('g:i A', strtotime($signIn['created_at']))) ?></td>
                <td><?= e($signIn['actor']) ?></td>
                <td><span class="admin-overview-status is-good">Success</span></td>
              </tr>
            <?php endforeach; if (!$recentSignIns): ?>
              <tr><td colspan="3" class="text-center text-secondary py-4">No sign-in activity recorded.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="admin-overview-panel" aria-labelledby="admin-weekly-title">
        <div class="admin-overview-panel-heading">
          <h2 id="admin-weekly-title">Sign-ins this week</h2>
          <span>Successful sign-ins per day</span>
        </div>
        <div class="admin-overview-chart" role="img" aria-label="Successful sign-ins per day this week">
          <?php foreach ($weekDays as $day): ?>
            <?php $barHeight = $day['sign_ins'] > 0 ? ($day['sign_ins'] / $maxSignIns * 100) : 0; ?>
            <div class="admin-overview-chart-day <?= $day['date'] === date('Y-m-d') ? 'is-today' : '' ?>">
              <span><?= $day['sign_ins'] ?></span>
              <div><i style="height: <?= $barHeight ?>%"></i></div>
              <small><?= e($day['label']) ?></small>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <aside class="admin-overview-panel admin-overview-checks" aria-labelledby="admin-checks-title">
      <div class="admin-overview-panel-heading">
        <h2 id="admin-checks-title">System checks</h2>
      </div>
      <div class="admin-overview-ring" style="--checks-progress: <?= $checksPercent ?>%">
        <div><strong><?= $checksPassed ?> of <?= count($systemChecks) ?></strong><span>checks OK</span></div>
      </div>
      <ul>
        <?php foreach ($systemChecks as $check): ?>
          <li>
            <span><?= e($check['label']) ?></span>
            <span class="admin-overview-status <?= $check['ok'] ? 'is-good' : 'is-warning' ?>"><?= e($check['status']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>
  </div>
</div>
<?php else: ?>
<section class="dash-grid">
  <div class="d-flex flex-column gap-3">
    <div class="card-box">
      <div class="d-flex justify-content-between"><h2>Today's queue</h2><a href="certificates.php" class="small fw-semibold">See all</a></div>
      <table class="table-b">
        <thead><tr><th>Ref</th><th>Resident</th><th>Certificate</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($queue as $c): ?>
          <tr>
            <td><a href="<?= $c['status'] === 'Issued' ? 'print.php' : 'receipt.php' ?>?id=<?= (int)$c['id'] ?>"><?= e(ref_no($c['id'])) ?></a></td>
            <td class="fw-semibold"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></td>
            <td><?= e($c['cert_type']) ?></td>
            <td><?= status_pill($c) ?></td>
          </tr>
        <?php endforeach; if (!$queue): ?>
          <tr><td colspan="4" class="text-center text-secondary py-4">Nothing in the queue today.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card-box">
      <div class="d-flex justify-content-between"><h2>Certificates this week</h2><span class="small text-secondary">Issued per day</span></div>
      <div class="chart">
        <?php foreach ($week as $i => $n): ?>
          <div class="<?= $i === $todayIdx ? 'today' : '' ?>"><?= $n ?><i style="height:<?= max(3, round($n / $max * 100)) ?>px"></i><span><?= $days[$i] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card-box">
    <h2>Today's progress</h2>
    <div class="ring" role="img" aria-label="<?= $cnt['issued'] ?> of <?= $total ?> issued today">
      <svg viewBox="0 0 140 140" width="140" height="140">
        <circle cx="70" cy="70" r="58" fill="none" stroke="#ece6d8" stroke-width="10"/>
        <circle cx="70" cy="70" r="58" fill="none" stroke="#0f5a54" stroke-width="10" stroke-linecap="round"
                stroke-dasharray="<?= round($dash, 1) ?> <?= round($circ, 1) ?>"/>
      </svg>
      <div class="mid"><b><?= $cnt['issued'] ?> of <?= $total ?></b><span>Issued today</span></div>
    </div>
    <?php foreach ([['Waiting for receipt','waiting','#b8741a'],['Pending case','pending','#b23a2c'],['Issued','issued','#15803d']] as [$label,$k,$color]): ?>
      <div class="bar-row"><span><?= $label ?></span><b><?= $cnt[$k] ?></b></div>
      <div class="bar-track"><i style="width:<?= $total ? round($cnt[$k] / $total * 100) : 0 ?>%;background:<?= $color ?>"></i></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
