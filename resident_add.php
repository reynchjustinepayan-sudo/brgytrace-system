<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$err = ''; $v = array_fill_keys(['last_name','first_name','middle_name','suffix','birthdate','sex','civil_status','purok','address','contact'], '');
$v['sex'] = 'Male'; $v['civil_status'] = 'Single';
$returnToIssue = ($_GET['return_to_issue'] ?? $_POST['return_to_issue'] ?? '') === '1';
$issuePurposeValue = $_GET['purpose'] ?? $_POST['issue_purpose'] ?? '';
$issuePurpose = is_string($issuePurposeValue) ? trim($issuePurposeValue) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($v as $k => $_) { $v[$k] = trim($_POST[$k] ?? ''); }
    if ($v['last_name'] === '' || $v['first_name'] === '' || $v['birthdate'] === '' || $v['purok'] === '') {
        $err = 'Last name, first name, birthdate and purok are required.';
    } elseif (!in_array($v['sex'], ['Male','Female'], true) || !in_array($v['civil_status'], ['Single','Married','Widowed','Separated'], true)) {
        $err = 'Please choose a valid sex and civil status.';
    } else {
        $dup = $pdo->prepare('SELECT id FROM residents WHERE last_name = ? AND first_name = ? AND birthdate = ?');
        $dup->execute([$v['last_name'], $v['first_name'], $v['birthdate']]);
        if ($dup->fetch() && empty($_POST['confirm_dup'])) {
            $err = 'A resident with the same name and birthdate already exists. Check that this is not the same person, then tick the box to save anyway.';
            $showDup = true;
        } else {
            $pdo->prepare('INSERT INTO residents (last_name,first_name,middle_name,suffix,birthdate,sex,civil_status,purok,address,contact,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$v['last_name'],$v['first_name'],$v['middle_name'] ?: null,$v['suffix'] ?: null,$v['birthdate'],$v['sex'],$v['civil_status'],$v['purok'],$v['address'] ?: null,$v['contact'] ?: null,now()]);
            $id = $pdo->lastInsertId();
            audit($pdo, 'Resident added', $v['last_name'] . ', ' . $v['first_name']);
            flash('Resident saved.');
            if ($returnToIssue) {
                redirect('issue.php?resident_id=' . (int)$id . '&purpose=' . rawurlencode($issuePurpose));
            }
            redirect('resident_view.php?id=' . $id);
        }
    }
}
$page_title = 'Add resident'; $page_sub = 'Records · Residents'; $active = 'residents';
include __DIR__ . '/includes/layout_top.php';
?>
<form method="post" class="card-box" style="max-width:860px">
  <?= csrf_field() ?>
  <?php if ($returnToIssue): ?>
    <input type="hidden" name="return_to_issue" value="1">
    <input type="hidden" name="issue_purpose" value="<?= e($issuePurpose) ?>">
  <?php endif; ?>
  <?php if ($err): ?><div class="alert alert-warning py-2 small"><?= e($err) ?></div><?php endif; ?>
  <h2>Personal details</h2>
  <div class="row g-3 mb-3">
    <div class="col-md-4"><label class="form-label">Last name</label><input class="form-control" name="last_name" value="<?= e($v['last_name']) ?>" required></div>
    <div class="col-md-4"><label class="form-label">First name</label><input class="form-control" name="first_name" value="<?= e($v['first_name']) ?>" required></div>
    <div class="col-md-3"><label class="form-label">Middle name</label><input class="form-control" name="middle_name" value="<?= e($v['middle_name']) ?>"></div>
    <div class="col-md-1"><label class="form-label">Suffix</label><input class="form-control" name="suffix" value="<?= e($v['suffix']) ?>" placeholder="Jr."></div>
    <div class="col-md-4"><label class="form-label">Birthdate</label><input class="form-control" type="date" name="birthdate" value="<?= e($v['birthdate']) ?>" required></div>
    <div class="col-md-4"><label class="form-label">Sex</label>
      <select class="form-select" name="sex"><?php foreach (['Male','Female'] as $o): ?><option <?= $v['sex'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Civil status</label>
      <select class="form-select" name="civil_status"><?php foreach (['Single','Married','Widowed','Separated'] as $o): ?><option <?= $v['civil_status'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></div>
  </div>
  <h2>Address and contact</h2>
  <div class="row g-3 mb-3">
    <div class="col-md-4"><label class="form-label">Purok</label><input class="form-control" name="purok" value="<?= e($v['purok']) ?>" placeholder="Purok 1" required></div>
    <div class="col-md-8"><label class="form-label">Address / Sitio</label><input class="form-control" name="address" value="<?= e($v['address']) ?>"></div>
    <div class="col-md-6"><label class="form-label">Contact number (optional)</label><input class="form-control" name="contact" value="<?= e($v['contact']) ?>"></div>
  </div>
  <?php if (!empty($showDup)): ?>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="confirm_dup" value="1" id="cd"><label class="form-check-label" for="cd">This is a different person. Save anyway.</label></div>
  <?php endif; ?>
  <div class="d-flex gap-2"><button class="btn btn-teal px-4">Save resident</button><a class="btn btn-outline-teal" href="<?= $returnToIssue ? 'issue.php' : 'residents.php' ?>">Cancel</a></div>
</form>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
