<?php
require_once __DIR__ . '/includes/auth.php';
require_secretary();

$residents = $pdo->query(
    'SELECT id, first_name, middle_name, last_name, suffix, purok, birthdate
     FROM residents ORDER BY last_name, first_name'
)->fetchAll();
$pendingByResident = [];
$pendingCases = $pdo->query(
    'SELECT id, respondent_id, incident_date, summary
     FROM blotters WHERE status = "Pending" ORDER BY incident_date DESC, id DESC'
)->fetchAll();
foreach ($pendingCases as $case) {
    if (!isset($pendingByResident[(int)$case['respondent_id']])) {
        $pendingByResident[(int)$case['respondent_id']] = [
            'id' => (int)$case['id'],
            'date' => date('M j, Y', strtotime($case['incident_date'])),
            'summary' => (string)$case['summary'],
        ];
    }
}

$selectedResidentId = filter_var($_GET['resident_id'] ?? '', FILTER_VALIDATE_INT);
$selectedResidentId = $selectedResidentId === false ? 0 : (int)$selectedResidentId;
$requestedPurpose = $_GET['purpose'] ?? '';
$purpose = is_string($requestedPurpose) ? trim($requestedPurpose) : '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $residentId = filter_var($_POST['resident_id'] ?? '', FILTER_VALIDATE_INT);
    $purposeValue = $_POST['purpose'] ?? '';
    $purpose = is_string($purposeValue) ? trim($purposeValue) : '';
    $selectedResidentId = $residentId === false ? 0 : (int)$residentId;

    $residentCheck = $pdo->prepare('SELECT first_name, middle_name, last_name, suffix FROM residents WHERE id = ?');
    $residentCheck->execute([$selectedResidentId]);
    $residentRecord = $residentCheck->fetch();
    if (!$residentRecord || $purpose === '') {
        $err = 'Choose a resident and write the purpose.';
    } elseif (strlen($purpose) > 120) {
        $err = 'Purpose must be 120 characters or fewer.';
    } else {
        $pendingCheck = $pdo->prepare('SELECT COUNT(*) FROM blotters WHERE respondent_id = ? AND status = "Pending"');
        $pendingCheck->execute([$selectedResidentId]);
        $hasPendingCase = (int)$pendingCheck->fetchColumn() > 0;
        $estimatedFee = $hasPendingCase ? PENDING_CASE_CERTIFICATE_FEE : 100;
        $pdo->prepare(
            'INSERT INTO certificates
             (resident_id, cert_type, template_id, purpose, fee, has_pending_case, status, created_by, created_at, issued_at)
             VALUES (?, ?, NULL, ?, ?, ?, "Waiting for receipt", ?, ?, NULL)'
        )->execute([
            $selectedResidentId,
            '',
            $purpose,
            $estimatedFee,
            $hasPendingCase ? 1 : 0,
            current_user()['id'],
            now(),
        ]);
        $certificateId = (int)$pdo->lastInsertId();
        audit($pdo, 'Certificate requested', ref_no($certificateId) . ' · ' . full_name($residentRecord));
        redirect('receipt.php?id=' . $certificateId);
    }
}

$residentOptions = [];
foreach ($residents as $resident) {
    $name = full_name($resident);
    $residentOptions[] = [
        'id' => (int)$resident['id'],
        'name' => $name,
        'purok' => (string)$resident['purok'],
        'birth' => date('M j, Y', strtotime($resident['birthdate'])),
        'search' => strtolower($name . ' ' . $resident['purok']),
    ];
}
$page_title = 'Issue certificate'; $page_sub = 'Daily work'; $active = 'issue';
include __DIR__ . '/includes/layout_top.php';
?>
<ol class="certificate-progress list-unstyled d-flex gap-2 mb-0 small fw-semibold">
  <li class="is-current">1 Request and blotter check</li>
  <li>2 Receipt</li>
  <li>3 Template and edit</li>
  <li>4 Print</li>
</ol>
<form method="post" class="card-box certificate-request-card" id="certificate-request-form">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="alert alert-warning py-2 small"><?= e($err) ?></div><?php endif; ?>
  <h2>Request</h2>
  <div class="mb-3">
    <label class="form-label" for="resident-search">Resident</label>
    <input class="form-control" type="search" id="resident-search" autocomplete="off" placeholder="Search resident by name or purok">
    <input type="hidden" name="resident_id" id="resident-id">
    <div class="resident-search-results" id="resident-search-results" role="listbox" aria-label="Resident search results" hidden></div>
    <div class="form-text">Not in the list? <a id="add-resident-link" href="resident_add.php?return_to_issue=1">Add the resident first.</a></div>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-12">
      <label class="form-label" for="certificate-purpose">Purpose</label>
      <input class="form-control" id="certificate-purpose" name="purpose" maxlength="120" value="<?= e($purpose) ?>" placeholder="Employment" required>
    </div>
  </div>
  <div class="certificate-blotter-check" id="certificate-blotter-check" hidden aria-live="polite"></div>
  <div class="certificate-request-actions">
    <a class="btn btn-outline-teal" href="dashboard.php">Cancel</a>
    <button class="btn btn-teal px-4" id="continue-request" type="submit">Continue</button>
  </div>
</form>
<script>
(() => {
  const residents = <?= json_encode($residentOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const pendingByResident = <?= json_encode($pendingByResident, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const presetResidentId = <?= (int)$selectedResidentId ?>;
  const search = document.getElementById('resident-search');
  const residentId = document.getElementById('resident-id');
  const results = document.getElementById('resident-search-results');
  const blotterCheck = document.getElementById('certificate-blotter-check');
  const purpose = document.getElementById('certificate-purpose');
  const form = document.getElementById('certificate-request-form');
  const addResidentLink = document.getElementById('add-resident-link');
  const continueButton = document.getElementById('continue-request');

  function updateAddResidentLink() {
    const params = new URLSearchParams({
      return_to_issue: '1',
      purpose: purpose.value
    });
    addResidentLink.href = 'resident_add.php?' + params.toString();
  }

  function updateBlotterCheck() {
    const selected = residents.find(resident => String(resident.id) === residentId.value);
    if (!selected) {
      blotterCheck.hidden = true;
      return;
    }
    const pending = pendingByResident[selected.id] || null;
    continueButton.textContent = 'Continue to receipt';
    blotterCheck.hidden = false;
    blotterCheck.className = 'certificate-blotter-check ' + (pending ? 'is-pending' : 'is-clear');
    if (pending) {
      const heading = document.createElement('strong');
      heading.textContent = 'Pending case found for ' + selected.name + '.';
      const detail = document.createElement('p');
      detail.textContent = 'Case #' + pending.id + ' · Filed ' + pending.date + ' · ' + pending.summary;
      const feeNote = document.createElement('p');
      feeNote.textContent = 'For a paid certificate, the fee is ' + <?= json_encode(peso(PENDING_CASE_CERTIFICATE_FEE)) ?> + '. Indigency remains free. Choose the fee at the receipt step.';
      blotterCheck.replaceChildren(heading, detail, feeNote);
    } else {
      const heading = document.createElement('strong');
      heading.textContent = 'No pending case for ' + selected.name + '.';
      const feeNote = document.createElement('p');
      feeNote.textContent = 'Paid certificates are ' + <?= json_encode(peso(100)) ?> + '; indigency is free. Choose the fee at the receipt step.';
      blotterCheck.replaceChildren(heading, feeNote);
    }
  }

  function selectResident(resident) {
    residentId.value = String(resident.id);
    search.value = resident.name + ' · ' + resident.purok;
    search.setCustomValidity('');
    results.replaceChildren();
    results.hidden = true;
    updateBlotterCheck();
  }

  function renderResults(clearSelection) {
    const query = search.value.trim().toLowerCase();
    if (clearSelection) residentId.value = '';
    blotterCheck.hidden = true;
    results.replaceChildren();
    const matches = residents.filter(resident => !query || resident.search.includes(query)).slice(0, 8);
    if (!matches.length) {
      const empty = document.createElement('p');
      empty.className = 'resident-search-empty';
      empty.textContent = 'No resident matches. Add the resident first.';
      results.append(empty);
      results.hidden = false;
      return;
    }
    matches.forEach(resident => {
      const option = document.createElement('button');
      option.type = 'button';
      option.className = 'resident-search-option';
      option.setAttribute('role', 'option');
      const name = document.createElement('strong');
      name.textContent = resident.name;
      const details = document.createElement('span');
      details.textContent = resident.purok + ' · Born ' + resident.birth;
      option.append(name, details);
      option.addEventListener('click', () => selectResident(resident));
      results.append(option);
    });
    results.hidden = false;
  }

  search.addEventListener('focus', () => {
    if (!residentId.value) renderResults(false);
  });
  search.addEventListener('input', () => renderResults(true));
  purpose.addEventListener('input', updateAddResidentLink);
  form.addEventListener('submit', event => {
    if (!residentId.value) {
      event.preventDefault();
      search.setCustomValidity('Choose a resident from the search results.');
      search.reportValidity();
      search.addEventListener('input', () => search.setCustomValidity(''), { once: true });
    }
  });
  document.addEventListener('click', event => {
    if (!results.contains(event.target) && event.target !== search) {
      results.hidden = true;
    }
  });

  const presetResident = residents.find(resident => resident.id === presetResidentId);
  if (presetResident) {
    selectResident(presetResident);
  }
  updateAddResidentLink();
})();
</script>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
