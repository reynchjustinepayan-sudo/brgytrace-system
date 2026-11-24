<?php
require_once __DIR__ . '/includes/auth.php';
require_secretary();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT b.*, r.first_name, r.last_name, r.id AS rid FROM blotters b JOIN residents r ON r.id = b.respondent_id WHERE b.id = ?');
$st->execute([$id]); $b = $st->fetch();
if (!$b) { http_response_code(404); exit('Case not found.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = $_POST['status'] ?? '';
    if (in_array($new, ['Pending','Settled','Dismissed'], true)) {
        $pdo->prepare('UPDATE blotters SET status = ? WHERE id = ?')->execute([$new, $id]);
        audit($pdo, 'Blotter status changed', 'BL-' . str_pad($id, 4, '0', STR_PAD_LEFT) . ' → ' . $new);
        flash('Case updated.'); redirect('blotter_case.php?id=' . $id);
    }
}
$page_title = 'Blotter case'; $page_sub = 'Records · Blotter'; $active = 'blotter';
include __DIR__ . '/includes/layout_top.php';
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card-box">
    <h2>BL-<?= str_pad($b['id'], 4, '0', STR_PAD_LEFT) ?> <span class="pill <?= $b['status'] === 'Pending' ? 'pill-red' : 'pill-grey' ?> ms-2"><?= e($b['status']) ?></span></h2>
    <dl class="kv mb-3">
      <dt>Complainant</dt><dd><?= e($b['complainant']) ?></dd>
      <dt>Respondent</dt><dd><a href="resident_view.php?id=<?= (int)$b['rid'] ?>"><?= e($b['first_name'] . ' ' . $b['last_name']) ?></a></dd>
      <dt>Date of incident</dt><dd><?= e(date('F j, Y', strtotime($b['incident_date']))) ?></dd>
      <dt>Summary</dt><dd class="fw-normal"><?= nl2br(e($b['summary'])) ?></dd>
    </dl>
    <a class="btn btn-outline-teal" href="blotter.php">Back to blotter list</a>
  </div></div>
  <div class="col-lg-5"><form method="post" class="card-box">
    <?= csrf_field() ?><h2>Update status</h2>
    <select class="form-select mb-3" name="status"><?php foreach (['Pending','Settled','Dismissed'] as $s): ?><option <?= $b['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <button class="btn btn-teal">Save</button>
  </form></div>
</div>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
