<?php
require_once __DIR__ . '/includes/auth.php';
require_secretary();
$q = trim($_GET['q'] ?? ''); $type = $_GET['type'] ?? ''; $status = $_GET['status'] ?? '';
$sql = 'SELECT c.*, r.first_name, r.last_name FROM certificates c JOIN residents r ON r.id = c.resident_id WHERE 1=1';
$args = [];
if ($q !== '') { $sql .= ' AND (r.first_name LIKE ? OR r.last_name LIKE ? OR c.receipt_no LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if (isset(CERT_TYPES[$type])) { $sql .= ' AND c.cert_type = ?'; $args[] = $type; }
if (in_array($status, ['Waiting for receipt','Issued'], true)) { $sql .= ' AND c.status = ?'; $args[] = $status; }
$st = $pdo->prepare($sql . ' ORDER BY c.id DESC LIMIT 200'); $st->execute($args); $rows = $st->fetchAll();

$page_title = 'Certificates'; $page_sub = 'Records'; $active = 'certificates';
include __DIR__ . '/includes/layout_top.php';
?>
<div class="card-box">
  <form class="d-flex gap-2 mb-3 flex-wrap" method="get">
    <input class="form-control" style="flex:1;min-width:200px" name="q" value="<?= e($q) ?>" placeholder="Search by name or receipt number">
    <select class="form-select w-auto" name="type"><option value="">All types</option>
      <?php foreach (array_keys(CERT_TYPES) as $t): ?><option <?= $type === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <select class="form-select w-auto" name="status"><option value="">All status</option>
      <?php foreach (['Waiting for receipt','Issued'] as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <button class="btn btn-teal px-4">Search</button>
  </form>
  <table class="table-b">
    <thead><tr><th>Ref</th><th>Resident</th><th>Certificate</th><th>Fee</th><th>Date</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><?= e(ref_no($c['id'])) ?></td>
        <td class="fw-semibold" style="text-align:left"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></td>
        <td style="text-align:left"><?= $c['cert_type'] !== '' ? e($c['cert_type']) : 'Choose in Step 3' ?></td>
        <td style="text-align:left"><?= $c['fee'] > 0 ? peso($c['fee']) : 'Free' ?></td>
        <td style="text-align:left"><?= e(date('M j', strtotime($c['created_at']))) ?></td>
        <td style="text-align:left"><?= status_pill($c) ?></td>
        <td>
          <?php if ($c['status'] === 'Issued'): ?>
            <a class="btn btn-sm btn-outline-teal" href="print.php?id=<?= (int)$c['id'] ?>">Print</a>
          <?php elseif ($c['cert_type'] === '' && ($c['receipt_no'] || (float)$c['fee'] === 0.0)): ?>
            <a class="btn btn-sm btn-teal" href="template.php?id=<?= (int)$c['id'] ?>">Choose type and template</a>
          <?php else: ?>
            <a class="btn btn-sm btn-teal" href="receipt.php?id=<?= (int)$c['id'] ?>">Record receipt</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-secondary py-4">No certificates found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
