<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM residents WHERE id = ?'); $st->execute([$id]);
$r = $st->fetch();
if (!$r) { http_response_code(404); exit('Resident not found.'); }
$certs = $pdo->prepare('SELECT * FROM certificates WHERE resident_id = ? ORDER BY id DESC'); $certs->execute([$id]); $certs = $certs->fetchAll();
$cases = $pdo->prepare('SELECT * FROM blotters WHERE respondent_id = ? ORDER BY id DESC'); $cases->execute([$id]); $cases = $cases->fetchAll();
$pending = count(array_filter($cases, fn($c) => $c['status'] === 'Pending'));

$page_title = 'Resident profile'; $page_sub = 'Records · Residents'; $active = 'residents';
include __DIR__ . '/includes/layout_top.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card-box">
      <div class="d-flex justify-content-between align-items-start">
        <h2 class="mb-3"><?= e(full_name($r)) ?></h2>
        <?= $pending ? '<span class="pill pill-red">Pending case</span>' : '' ?>
      </div>
      <dl class="kv mb-3">
        <dt>Birthdate</dt><dd><?= e(date('F j, Y', strtotime($r['birthdate']))) ?></dd>
        <dt>Sex</dt><dd><?= e($r['sex']) ?></dd>
        <dt>Civil status</dt><dd><?= e($r['civil_status']) ?></dd>
        <dt>Purok</dt><dd><?= e($r['purok']) ?></dd>
        <dt>Address</dt><dd><?= e($r['address'] ?: '—') ?></dd>
        <dt>Contact</dt><dd><?= e($r['contact'] ?: '—') ?></dd>
      </dl>
      <div class="d-flex gap-2">
        <?php if (current_user()['role'] === 'secretary'): ?>
          <a class="btn btn-teal" href="issue.php?resident_id=<?= $id ?>">Issue certificate</a>
        <?php endif; ?>
        <a class="btn btn-outline-teal" href="residents.php">Back</a>
      </div>
    </div>
  </div>
  <div class="col-lg-7 d-flex flex-column gap-3">
    <div class="card-box"><h2>Blotter history</h2>
      <table class="table-b"><thead><tr><th>Case</th><th>Date</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($cases as $c): ?>
        <tr><td><?php if (current_user()['role'] === 'secretary'): ?><a href="blotter_case.php?id=<?= (int)$c['id'] ?>">BL-<?= str_pad($c['id'], 4, '0', STR_PAD_LEFT) ?></a><?php else: ?>BL-<?= str_pad($c['id'], 4, '0', STR_PAD_LEFT) ?><?php endif; ?></td>
            <td><?= e(date('M j, Y', strtotime($c['incident_date']))) ?></td>
            <td><span class="pill <?= $c['status'] === 'Pending' ? 'pill-red' : 'pill-grey' ?>"><?= e($c['status']) ?></span></td></tr>
      <?php endforeach; if (!$cases): ?><tr><td colspan="3" class="text-secondary text-center py-3">No cases on record.</td></tr><?php endif; ?>
      </tbody></table></div>
    <div class="card-box"><h2>Certificates issued</h2>
      <table class="table-b"><thead><tr><th>Ref</th><th>Type</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($certs as $c): ?>
        <tr><td><?= e(ref_no($c['id'])) ?></td><td><?= e($c['cert_type']) ?></td><td><?= status_pill($c) ?></td></tr>
      <?php endforeach; if (!$certs): ?><tr><td colspan="3" class="text-secondary text-center py-3">No certificates yet.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>
</div>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
