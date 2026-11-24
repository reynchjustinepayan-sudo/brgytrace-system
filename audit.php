<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();
$rows = $pdo->query('SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
$page_title = 'Audit log'; $page_sub = 'Admin'; $active = 'audit';
include __DIR__ . '/includes/layout_top.php';
?>
<div class="card-box">
  <div class="table-responsive">
    <table class="table-b audit-log-table">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
        <?php
          $formattedTime = date('M j, Y, g:i A', strtotime($a['created_at']));
          $username = $a['username'] ?? '—';
          $detail = trim((string)($a['detail'] ?? ''));
        ?>
        <tr>
          <td><?= e($formattedTime) ?></td>
          <td><?= e($username) ?></td>
          <td class="fw-semibold"><?= e($a['action']) ?></td>
          <td>
            <details class="audit-log-detail">
              <summary>View details</summary>
              <div class="audit-log-detail-panel">
                <dl>
                  <div><dt>When</dt><dd><?= e($formattedTime) ?></dd></div>
                  <div><dt>User</dt><dd><?= e($username) ?></dd></div>
                  <div><dt>Action</dt><dd><?= e($a['action']) ?></dd></div>
                  <div><dt>Details</dt><dd><?= $detail !== '' ? e($detail) : 'No additional details recorded.' ?></dd></div>
                </dl>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="4" class="text-center text-secondary py-4">No entries yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
