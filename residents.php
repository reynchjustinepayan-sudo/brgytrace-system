<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$q = trim($_GET['q'] ?? '');
$sql = 'SELECT r.*, (SELECT COUNT(*) FROM blotters b WHERE b.respondent_id = r.id AND b.status = "Pending") AS pending
        FROM residents r';
$args = [];
if ($q !== '') { $sql .= ' WHERE r.first_name LIKE ? OR r.last_name LIKE ? OR r.purok LIKE ?'; $args = ["%$q%", "%$q%", "%$q%"]; }
$st = $pdo->prepare($sql . ' ORDER BY r.last_name, r.first_name LIMIT 200');
$st->execute($args);
$rows = $st->fetchAll();

$page_title = 'Residents'; $page_sub = 'Records'; $active = 'residents';
include __DIR__ . '/includes/layout_top.php';
?>
<div class="card-box">
  <form class="d-flex gap-2 mb-3" method="get">
    <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search by name or purok">
    <button class="btn btn-teal px-4">Search</button>
    <a class="btn btn-outline-teal text-nowrap" href="resident_add.php">Add resident</a>
  </form>
  <div class="small text-secondary mb-2"><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?></div>
  <table class="table-b">
    <thead><tr><th>Name</th><th>Birthdate</th><th>Purok</th><th>Case</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="fw-semibold"><?= e($r['last_name'] . ', ' . $r['first_name']) ?></td>
        <td><?= e(date('M j, Y', strtotime($r['birthdate']))) ?></td>
        <td><?= e($r['purok']) ?></td>
        <td style="text-align:left"><?= $r['pending'] ? '<span class="pill pill-red">Pending case</span>' : '<span class="pill pill-grey">None</span>' ?></td>
        <td><a class="btn btn-sm btn-outline-teal" href="resident_view.php?id=<?= (int)$r['id'] ?>">View</a></td>
      </tr>
    <?php endforeach; if (!$rows): ?>
      <tr><td colspan="5" class="text-center text-secondary py-4">No residents found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
