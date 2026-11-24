<?php
require_once __DIR__ . '/includes/auth.php';
require_secretary();
$residents = $pdo->query('SELECT id, first_name, last_name, purok FROM residents ORDER BY last_name, first_name')->fetchAll();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $comp = trim($_POST['complainant'] ?? ''); $rid = (int)($_POST['respondent_id'] ?? 0);
    $date = $_POST['incident_date'] ?? ''; $sum = trim($_POST['summary'] ?? '');
    if ($comp === '' || !$rid || $date === '' || $sum === '') { $err = 'Please fill in every field.'; }
    else {
        $pdo->prepare('INSERT INTO blotters (complainant, respondent_id, incident_date, summary, status, created_by, created_at) VALUES (?,?,?,?,"Pending",?,?)')
            ->execute([$comp, $rid, $date, $sum, current_user()['id'], now()]);
        $id = $pdo->lastInsertId();
        audit($pdo, 'Blotter entry added', 'BL-' . str_pad($id, 4, '0', STR_PAD_LEFT));
        flash('Blotter entry saved. The respondent now shows a pending case.');
        redirect('blotter_case.php?id=' . $id);
    }
}
$page_title = 'New blotter entry'; $page_sub = 'Records · Blotter'; $active = 'blotter';
include __DIR__ . '/includes/layout_top.php';
?>
<form method="post" class="card-box" style="max-width:760px">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="alert alert-warning py-2 small"><?= e($err) ?></div><?php endif; ?>
  <h2>Case details</h2>
  <div class="row g-3 mb-3">
    <div class="col-md-6"><label class="form-label">Complainant (person who reported)</label><input class="form-control" name="complainant" required></div>
    <div class="col-md-6"><label class="form-label">Respondent (resident)</label>
      <select class="form-select" name="respondent_id" required><option value="">Choose a resident</option>
        <?php foreach ($residents as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['last_name'] . ', ' . $r['first_name'] . ' · ' . $r['purok']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">Date of incident</label><input class="form-control" type="date" name="incident_date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="col-12"><label class="form-label">Short summary</label><textarea class="form-control" rows="4" name="summary" required></textarea></div>
  </div>
  <div class="note mb-3">A new case starts as <b>Pending</b>. While it is pending, paid certificates for the respondent cost <?= peso(PENDING_CASE_CERTIFICATE_FEE) ?>; Certificate of Indigency remains free.</div>
  <div class="d-flex gap-2"><button class="btn btn-teal px-4">Save entry</button><a class="btn btn-outline-teal" href="blotter.php">Cancel</a></div>
</form>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
