<?php
require_once __DIR__ . '/includes/auth.php';
require_secretary();
$q = trim($_GET['q'] ?? ''); $status = $_GET['status'] ?? '';
$sql = 'SELECT b.*, r.first_name, r.last_name FROM blotters b JOIN residents r ON r.id = b.respondent_id WHERE 1=1';
$args = [];
if ($q !== '') { $sql .= ' AND (b.complainant LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if (in_array($status, ['Pending','Settled','Dismissed'], true)) { $sql .= ' AND b.status = ?'; $args[] = $status; }
$st = $pdo->prepare($sql . ' ORDER BY b.id DESC LIMIT 200'); $st->execute($args); $rows = $st->fetchAll();

$page_title = 'Blotter'; $page_sub = 'Records'; $active = 'blotter';
include __DIR__ . '/includes/layout_top.php';
?>
<div class="card-box">
  <form class="d-flex gap-2 mb-3" method="get">
    <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search by name">
    <select class="form-select w-auto" name="status"><option value="">All status</option>
      <?php foreach (['Pending','Settled','Dismissed'] as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <button class="btn btn-teal px-4">Search</button>
    <a class="btn btn-outline-teal text-nowrap" href="blotter_add.php">New blotter entry</a>
  </form>
  <table class="table-b">
    <thead><tr><th>Case</th><th>Complainant</th><th>Respondent</th><th>Date</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $b): ?>
      <tr>
        <td><a href="blotter_case.php?id=<?= (int)$b['id'] ?>">BL-<?= str_pad($b['id'], 4, '0', STR_PAD_LEFT) ?></a></td>
        <td style="text-align:left"><?= e($b['complainant']) ?></td>
        <td style="text-align:left"><?= e($b['first_name'] . ' ' . $b['last_name']) ?></td>
        <td style="text-align:left"><?= e(date('M j, Y', strtotime($b['incident_date']))) ?></td>
        <td style="text-align:left"><span class="pill <?= $b['status'] === 'Pending' ? 'pill-red' : 'pill-grey' ?>"><?= e($b['status']) ?></span></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="text-center text-secondary py-4">No cases found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
