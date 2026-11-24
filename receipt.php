<?php
require_once __DIR__ . '/includes/auth.php';
require_secretary();
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$id = $id === false ? 0 : (int)$id;
$st = $pdo->prepare(
    'SELECT c.*, r.first_name, r.middle_name, r.last_name, r.suffix, r.purok
     FROM certificates c JOIN residents r ON r.id = c.resident_id WHERE c.id = ?'
);
$st->execute([$id]);
$c = $st->fetch();
if (!$c) { http_response_code(404); exit('Request not found.'); }
if ($c['status'] === 'Issued') { redirect('template.php?id=' . $id); }

$isTypeDraft = $c['cert_type'] === '';
$allowedFees = $isTypeDraft
    ? ($c['has_pending_case'] ? [0, 100, PENDING_CASE_CERTIFICATE_FEE] : [0, 100])
    : [(int)$c['fee']];
$defaultFee = $isTypeDraft
    ? ($c['has_pending_case'] ? PENDING_CASE_CERTIFICATE_FEE : 100)
    : (int)$c['fee'];
$feeValue = $defaultFee;
$receiptTypeValue = (string)($c['receipt_type'] ?? 'OR');
$receiptNumberValue = (string)($c['receipt_no'] ?? '');
$receiptDateValue = !empty($c['receipt_date']) ? (string)$c['receipt_date'] : date('Y-m-d');
$err = '';
$pendingCase = null;
if ($c['has_pending_case']) {
    $pendingCaseStmt = $pdo->prepare(
        'SELECT id, incident_date, summary FROM blotters
         WHERE respondent_id = ? AND status = "Pending"
         ORDER BY incident_date DESC, id DESC LIMIT 1'
    );
    $pendingCaseStmt->execute([(int)$c['resident_id']]);
    $pendingCase = $pendingCaseStmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $feeInput = filter_var($_POST['fee'] ?? '', FILTER_VALIDATE_INT);
    $feeValue = $feeInput === false ? -1 : (int)$feeInput;
    $typeInput = $_POST['receipt_type'] ?? '';
    $receiptTypeValue = is_string($typeInput) ? $typeInput : '';
    $numberInput = $_POST['receipt_no'] ?? '';
    $receiptNumberValue = is_string($numberInput) ? trim($numberInput) : '';
    $dateInput = $_POST['receipt_date'] ?? '';
    $receiptDateValue = is_string($dateInput) ? trim($dateInput) : '';

    if (!in_array($feeValue, $allowedFees, true)) {
        $err = 'Choose a fee amount available for this request.';
    } elseif ($feeValue === 0) {
        if ($isTypeDraft) {
            $pdo->prepare(
                'UPDATE certificates SET fee = 0, receipt_type = NULL, receipt_no = NULL, receipt_date = NULL WHERE id = ?'
            )->execute([$id]);
            audit($pdo, 'Free certificate selected', ref_no($id) . ' · no receipt');
        } else {
            $pdo->prepare(
                'UPDATE certificates
                 SET fee = 0, receipt_type = NULL, receipt_no = NULL, receipt_date = NULL, status = "Issued", issued_at = ?
                 WHERE id = ?'
            )->execute([now(), $id]);
            audit($pdo, 'Certificate issued', ref_no($id) . ' · ' . $c['cert_type'] . ' · free');
        }
        redirect('template.php?id=' . $id);
    } elseif (!in_array($receiptTypeValue, ['OR', 'Cash ticket'], true) || $receiptNumberValue === '') {
        $err = 'Choose a receipt type and enter its number.';
    } elseif (strlen($receiptNumberValue) > 40) {
        $err = 'Receipt number must be 40 characters or fewer.';
    } elseif (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $receiptDateValue)
        || !checkdate((int)substr($receiptDateValue, 5, 2), (int)substr($receiptDateValue, 8, 2), (int)substr($receiptDateValue, 0, 4))) {
        $err = 'Choose a valid date paid.';
    } else {
        $duplicate = $pdo->prepare('SELECT id FROM certificates WHERE receipt_no = ? AND id <> ?');
        $duplicate->execute([$receiptNumberValue, $id]);
        if ($duplicate->fetch()) {
            $err = 'This receipt number was already used. Check the OR or cash-ticket number.';
        } else {
            if ($isTypeDraft) {
                $pdo->prepare(
                    'UPDATE certificates
                     SET fee = ?, receipt_type = ?, receipt_no = ?, receipt_date = ?
                     WHERE id = ?'
                )->execute([$feeValue, $receiptTypeValue, $receiptNumberValue, $receiptDateValue, $id]);
                audit($pdo, 'Receipt recorded', ref_no($id) . ' · ' . $receiptTypeValue . ' ' . $receiptNumberValue . ' · ' . peso($feeValue));
                redirect('template.php?id=' . $id);
            }

            $pdo->prepare(
                'UPDATE certificates
                 SET fee = ?, receipt_type = ?, receipt_no = ?, receipt_date = ?, status = "Issued", issued_at = ?
                 WHERE id = ?'
            )->execute([$feeValue, $receiptTypeValue, $receiptNumberValue, $receiptDateValue, now(), $id]);
            audit($pdo, 'Receipt recorded', ref_no($id) . ' · ' . $receiptTypeValue . ' ' . $receiptNumberValue . ' · ' . peso($feeValue));
            flash('Receipt recorded. The certificate is ready for template selection.');
            redirect('template.php?id=' . $id);
        }
    }
}

$page_title = 'Record receipt number'; $page_sub = 'Daily work'; $active = 'issue';
include __DIR__ . '/includes/layout_top.php';
?>
<ol class="certificate-progress list-unstyled d-flex gap-2 mb-0 small fw-semibold">
  <li class="is-done">1 Request and blotter check</li>
  <li class="is-current">2 Receipt</li>
  <li>3 Template and edit</li>
  <li>4 Print</li>
</ol>
<div class="certificate-request-summary">
  <span>Request for</span>
  <strong><?= e(full_name($c)) ?></strong>
  <span>·</span><span><?= e($c['purok']) ?></span>
  <span>·</span><span>Purpose: <?= e($c['purpose']) ?></span>
  <?php if ($c['has_pending_case']): ?><span class="pill pill-amber">Pending case</span><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" class="card-box receipt-entry-card" id="receipt-entry-form">
      <?= csrf_field() ?>
      <h2>Record receipt</h2>
      <?php if ($err): ?><div class="alert alert-warning py-2 small"><?= e($err) ?></div><?php endif; ?>
      <?php if ($isTypeDraft): ?>
        <p class="small text-secondary">Choose the amount collected. Paid certificates are normally <?= peso(100) ?>; a pending case requires <?= peso(PENDING_CASE_CERTIFICATE_FEE) ?>. Indigency is free and does not need a receipt.</p>
        <div class="mb-3">
          <label class="form-label" for="receipt-fee">Fee paid (PHP)</label>
          <select class="form-select" name="fee" id="receipt-fee" required>
            <?php foreach ($allowedFees as $amount): ?>
              <option value="<?= $amount ?>" <?= $feeValue === $amount ? 'selected' : '' ?>><?= $amount === 0 ? 'Free — no receipt' : peso($amount) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" name="fee" value="<?= (int)$feeValue ?>">
        <p class="small text-secondary">Record the OR or cash ticket for <?= peso($feeValue) ?>.</p>
      <?php endif; ?>
      <div id="paid-receipt-fields" <?= $feeValue === 0 ? 'hidden' : '' ?>>
        <div class="mb-3">
          <label class="form-label">Receipt type</label>
          <div class="d-flex flex-wrap gap-3">
            <div class="form-check"><input class="form-check-input" type="radio" name="receipt_type" value="OR" id="receipt-or" <?= $receiptTypeValue === 'OR' ? 'checked' : '' ?>><label class="form-check-label" for="receipt-or">Official receipt (OR)</label></div>
            <div class="form-check"><input class="form-check-input" type="radio" name="receipt_type" value="Cash ticket" id="receipt-cash-ticket" <?= $receiptTypeValue === 'Cash ticket' ? 'checked' : '' ?>><label class="form-check-label" for="receipt-cash-ticket">Cash ticket</label></div>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="receipt-number">Receipt number</label>
          <input class="form-control" id="receipt-number" name="receipt_no" maxlength="40" value="<?= e($receiptNumberValue) ?>" placeholder="0001246">
        </div>
        <div class="mb-3">
          <label class="form-label" for="receipt-date">Date paid</label>
          <input class="form-control" type="date" id="receipt-date" name="receipt_date" value="<?= e($receiptDateValue) ?>">
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-teal px-4" type="submit"><?= $feeValue === 0 ? 'Continue to template' : 'Save receipt and continue' ?></button>
        <a class="btn btn-outline-teal" href="dashboard.php">Cancel</a>
      </div>
    </form>
  </div>
  <div class="col-lg-5">
    <section class="card-box receipt-reference-card">
      <span class="small text-secondary">Reference no.</span>
      <h2><?= e(ref_no($c['id'])) ?></h2>
      <p class="mb-1"><?= e(full_name($c)) ?> · <?= e($c['purok']) ?></p>
      <p class="small text-secondary mb-0"><?= $c['has_pending_case'] ? 'Pending case found; confirm the correct fee.' : 'No pending case found.' ?></p>
      <?php if ($pendingCase): ?>
        <div class="receipt-pending-case">
          <strong>Pending case details</strong>
          <span>Case #<?= (int)$pendingCase['id'] ?> · Filed <?= e(date('M j, Y', strtotime($pendingCase['incident_date']))) ?></span>
          <span><?= e($pendingCase['summary']) ?></span>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<script>
(() => {
  const feeSelect = document.getElementById('receipt-fee');
  const fields = document.getElementById('paid-receipt-fields');
  const number = document.getElementById('receipt-number');
  const date = document.getElementById('receipt-date');
  if (!feeSelect || !fields) return;
  const updateReceiptFields = () => {
    const isFree = feeSelect.value === '0';
    fields.hidden = isFree;
    number.required = !isFree;
    date.required = !isFree;
    fields.querySelectorAll('input[type="radio"]').forEach(input => input.disabled = isFree);
    document.querySelector('#receipt-entry-form button[type="submit"]').textContent = isFree
      ? 'Continue to template'
      : 'Save receipt and continue';
  };
  feeSelect.addEventListener('change', updateReceiptFields);
  updateReceiptFields();
})();
</script>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
