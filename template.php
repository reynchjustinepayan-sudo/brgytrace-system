<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/certificate_document.php';
require_secretary();

$id = filter_var($_GET['id'] ?? $_POST['id'] ?? '', FILTER_VALIDATE_INT);
$id = $id === false ? 0 : (int)$id;
$certificateStmt = $pdo->prepare(
    'SELECT c.*, c.id AS cid, c.purpose AS cpurpose, r.*,
            t.template_name, t.file_name AS template_file_name
     FROM certificates c
     JOIN residents r ON r.id = c.resident_id
     LEFT JOIN certificate_templates t ON t.id = c.template_id
     WHERE c.id = ?'
);
$certificateStmt->execute([$id]);
$certificate = $certificateStmt->fetch();
if (!$certificate) { http_response_code(404); exit('Request not found.'); }

$isTypeDraft = $certificate['cert_type'] === '';
$storedType = (string)$certificate['cert_type'];
if ($certificate['status'] !== 'Issued' && !$isTypeDraft && empty($certificate['receipt_no'])) {
    redirect('receipt.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $typeValue = $_POST['cert_type'] ?? '';
    $selectedType = is_string($typeValue) ? $typeValue : '';
    $templateValue = $_POST['template_id'] ?? '';
    $templateIdInput = $templateValue === '' ? null : filter_var($templateValue, FILTER_VALIDATE_INT);
    $respond = static function (int $status, array $data): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        exit;
    };

    if ($action !== 'finalize_template') {
        $respond(400, ['ok' => false, 'error' => 'Invalid template action.']);
    }
    if (!isset(CERT_TYPES[$selectedType])) {
        $respond(422, ['ok' => false, 'error' => 'Choose a valid certificate type.']);
    }
    if (!$isTypeDraft && $selectedType !== $storedType) {
        $respond(422, ['ok' => false, 'error' => 'The certificate type on this issued certificate cannot be changed.']);
    }

    $template = null;
    if ($templateIdInput !== null) {
        if ($templateIdInput === false || $templateIdInput < 1) {
            $respond(422, ['ok' => false, 'error' => 'Choose a valid template.']);
        }
        $templateStmt = $pdo->prepare('SELECT id, template_name FROM certificate_templates WHERE id = ? AND cert_type = ?');
        $templateStmt->execute([(int)$templateIdInput, $selectedType]);
        $template = $templateStmt->fetch();
        if (!$template) {
            $respond(422, ['ok' => false, 'error' => 'Choose a template that matches the certificate type.']);
        }
    }

    if ($isTypeDraft) {
        $fee = (float)$certificate['fee'];
        $receiptNumber = (string)($certificate['receipt_no'] ?? '');
        if ($selectedType === 'Certificate of Indigency') {
            if ($fee !== 0.0 || $receiptNumber !== '' || !empty($certificate['receipt_type'])) {
                $respond(422, [
                    'ok' => false,
                    'error' => 'Indigency certificates are free. Go back to the receipt step and choose Free — no receipt.',
                    'receipt_url' => 'receipt.php?id=' . $id,
                ]);
            }
        } else {
            $expectedFee = $certificate['has_pending_case'] ? PENDING_CASE_CERTIFICATE_FEE : 100;
            if ($fee !== (float)$expectedFee || $receiptNumber === '' || !in_array($certificate['receipt_type'], ['OR', 'Cash ticket'], true)) {
                $respond(422, [
                    'ok' => false,
                    'error' => 'The receipt amount does not match this request. Return to the receipt step and record the correct fee and receipt.',
                    'receipt_url' => 'receipt.php?id=' . $id,
                ]);
            }
        }

        $pdo->prepare(
            'UPDATE certificates
             SET cert_type = ?, template_id = ?, status = "Issued", issued_at = ?
             WHERE id = ?'
        )->execute([$selectedType, $template ? (int)$template['id'] : null, now(), $id]);
        audit(
            $pdo,
            'Certificate issued',
            ref_no($id) . ' · ' . $selectedType . ($template ? ' · ' . $template['template_name'] : ' · Standard template')
        );
    } else {
        $pdo->prepare('UPDATE certificates SET template_id = ? WHERE id = ?')
            ->execute([$template ? (int)$template['id'] : null, $id]);
        audit(
            $pdo,
            'Certificate template selected',
            ref_no($id) . ' · ' . ($template ? $template['template_name'] : 'Standard template')
        );
    }
    $respond(200, ['ok' => true]);
}

$requestedType = $_GET['cert_type'] ?? '';
$selectedType = $isTypeDraft
    ? (is_string($requestedType) && isset(CERT_TYPES[$requestedType]) ? $requestedType : '')
    : $storedType;
$requestedTemplateId = $_GET['template_id'] ?? null;
$selectedTemplateId = is_string($requestedTemplateId) && $requestedTemplateId !== ''
    ? $requestedTemplateId
    : (!$isTypeDraft && !empty($certificate['template_id']) ? (string)$certificate['template_id'] : '');
$templateOptions = [];
if ($selectedType !== '') {
    $optionStmt = $pdo->prepare('SELECT id, template_name FROM certificate_templates WHERE cert_type = ? ORDER BY template_name');
    $optionStmt->execute([$selectedType]);
    $templateOptions = $optionStmt->fetchAll();
}
$templateOptionIds = array_map(static function (array $template): string {
    return (string)$template['id'];
}, $templateOptions);
if ($selectedTemplateId !== '' && !in_array($selectedTemplateId, $templateOptionIds, true)) {
    $selectedTemplateId = '';
}

$previewHtml = '';
$previewClass = 'paper standard-certificate';
$previewError = '';
if ($selectedType !== '') {
    $previewCertificate = $certificate;
    $previewCertificate['cert_type'] = $selectedType;
    if ($selectedTemplateId !== '') {
        $templateNameStmt = $pdo->prepare('SELECT template_name, file_name FROM certificate_templates WHERE id = ? AND cert_type = ?');
        $templateNameStmt->execute([(int)$selectedTemplateId, $selectedType]);
        $selectedTemplate = $templateNameStmt->fetch();
        if ($selectedTemplate) {
            try {
                $previewHtml = certificate_uploaded_document_html($pdo, $previewCertificate, (string)$selectedTemplate['file_name']);
                $previewClass = 'paper docx-paper';
            } catch (RuntimeException $exception) {
                error_log('Certificate template preview failed: ' . $exception->getMessage());
                $previewError = 'This template could not be previewed. Ask an Admin to upload it again.';
            }
        }
    } else {
        $previewHtml = certificate_standard_document_html($pdo, $previewCertificate);
    }
}

$templateMap = [];
$allTemplateRows = $pdo->query('SELECT id, template_name, cert_type FROM certificate_templates ORDER BY template_name')->fetchAll();
foreach ($allTemplateRows as $templateRow) {
    $templateMap[$templateRow['cert_type']][] = [
        'id' => (int)$templateRow['id'],
        'name' => (string)$templateRow['template_name'],
    ];
}
$page_title = 'Template and edit'; $page_sub = 'Daily work'; $active = 'issue';
include __DIR__ . '/includes/layout_top.php';
?>
<ol class="certificate-progress list-unstyled d-flex gap-2 mb-0 small fw-semibold no-print" id="certificate-progress">
  <li class="is-done">1 Request and blotter check</li>
  <li class="is-done">2 Receipt</li>
  <li class="is-current" data-step="3">3 Template and edit</li>
  <li data-step="4">4 Print</li>
</ol>
<div class="certificate-request-summary no-print">
  <span>Request for</span><strong><?= e(full_name($certificate)) ?></strong>
  <span>·</span><span><?= e($certificate['purok']) ?></span>
  <span>·</span><span>Purpose: <?= e($certificate['cpurpose']) ?></span>
  <span>·</span><span>Fee: <?= $certificate['fee'] > 0 ? peso($certificate['fee']) : 'Free' ?></span>
</div>
<section class="certificate-stage" id="template-stage">
  <div class="card-box certificate-template-controls">
    <div class="certificate-template-fields">
      <?php if ($isTypeDraft): ?>
        <div>
          <label class="form-label" for="certificate-type-choice">Certificate type</label>
          <select class="form-select" id="certificate-type-choice" name="cert_type" form="template-choice-form" required>
            <option value="">Choose a certificate type</option>
            <?php foreach (CERT_TYPES as $type => $typeFee): ?>
              <option value="<?= e($type) ?>" <?= $selectedType === $type ? 'selected' : '' ?>><?= e($type) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <div>
          <span class="form-label d-block">Certificate type</span>
          <div class="form-control certificate-fixed-value"><?= e($selectedType) ?></div>
        </div>
      <?php endif; ?>
      <div>
        <label class="form-label" for="template-choice">Template (from Settings)</label>
        <select class="form-select" id="template-choice" name="template_id" form="template-choice-form" <?= $selectedType === '' ? 'disabled' : '' ?>>
          <option value="">Standard <?= $selectedType !== '' ? e($selectedType) : 'certificate' ?> (default)</option>
          <?php foreach ($templateOptions as $template): ?>
            <option value="<?= (int)$template['id'] ?>" <?= $selectedTemplateId === (string)$template['id'] ? 'selected' : '' ?>><?= e($template['template_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="certificate-template-load">
        <form method="get" id="template-choice-form">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-outline-teal" type="submit" <?= $selectedType === '' ? 'disabled' : '' ?>>Load preview</button>
        </form>
      </div>
    </div>
    <p class="form-text mb-0">Resident and certificate details fill in automatically. Changes you make here apply only to this certificate.</p>
    <?php if ($isTypeDraft && $selectedType !== '' && $certificate['fee'] == 0 && $selectedType !== 'Certificate of Indigency'): ?>
      <div class="alert alert-warning small mt-3 mb-0">A free receipt choice only matches Certificate of Indigency. Return to the receipt step to record a paid certificate before continuing.</div>
    <?php elseif ($isTypeDraft && $selectedType === 'Certificate of Indigency' && $certificate['fee'] > 0): ?>
      <div class="alert alert-warning small mt-3 mb-0">Indigency is free. The paid receipt cannot be used for this certificate; return to the receipt step to correct it.</div>
    <?php elseif ($isTypeDraft && $certificate['fee'] > 0 && $certificate['has_pending_case'] && (float)$certificate['fee'] !== (float)PENDING_CASE_CERTIFICATE_FEE): ?>
      <div class="alert alert-warning small mt-3 mb-0">A paid certificate with a pending case must use the <?= peso(PENDING_CASE_CERTIFICATE_FEE) ?> fee. Return to the receipt step to correct it.</div>
    <?php elseif ($isTypeDraft && $certificate['fee'] > 0 && !$certificate['has_pending_case'] && (float)$certificate['fee'] !== 100.0): ?>
      <div class="alert alert-warning small mt-3 mb-0">A paid certificate without a pending case must use the <?= peso(100) ?> fee. Return to the receipt step to correct it.</div>
    <?php endif; ?>
    <?php if ($previewError): ?><div class="alert alert-warning small mt-3 mb-0"><?= e($previewError) ?></div><?php endif; ?>
  </div>
  <?php if ($previewHtml !== ''): ?>
    <div class="certificate-editor-card card-box">
      <div class="certificate-editor-toolbar no-print" id="certificate-editor-toolbar" role="toolbar" aria-label="Certificate text formatting">
        <label class="visually-hidden" for="editor-font-family">Font family</label>
        <select class="form-select" id="editor-font-family">
          <option value="Times New Roman">Times New Roman</option>
          <option value="Arial">Arial</option>
          <option value="Calibri">Calibri</option>
          <option value="Georgia">Georgia</option>
        </select>
        <label class="visually-hidden" for="editor-font-size">Font size</label>
        <select class="form-select" id="editor-font-size">
          <option value="2">10 pt</option><option value="3" selected>12 pt</option>
          <option value="4">14 pt</option><option value="5">18 pt</option>
          <option value="6">24 pt</option><option value="7">32 pt</option>
        </select>
        <button type="button" class="btn btn-outline-teal" data-command="bold" aria-label="Bold"><b>B</b></button>
        <button type="button" class="btn btn-outline-teal" data-command="italic" aria-label="Italic"><i>I</i></button>
        <button type="button" class="btn btn-outline-teal" data-command="underline" aria-label="Underline"><u>U</u></button>
        <span class="certificate-toolbar-divider"></span>
        <button type="button" class="btn btn-outline-teal" data-command="justifyLeft" aria-label="Align left">Left</button>
        <button type="button" class="btn btn-outline-teal" data-command="justifyCenter" aria-label="Align center">Center</button>
        <button type="button" class="btn btn-outline-teal" data-command="justifyRight" aria-label="Align right">Right</button>
        <button type="button" class="btn btn-outline-teal" data-command="justifyFull" aria-label="Justify">Justify</button>
      </div>
      <div class="certificate-edit-preview <?= e($previewClass) ?>" id="certificate-editor" contenteditable="true" spellcheck="true" role="textbox" aria-label="Editable certificate preview">
        <?= $previewHtml ?>
      </div>
      <div class="certificate-editor-actions no-print">
        <a class="btn btn-outline-teal" href="receipt.php?id=<?= $id ?>">Back to receipt</a>
        <button class="btn btn-teal px-4" type="button" id="continue-to-print" <?= $previewError ? 'disabled' : '' ?>>Continue to print</button>
      </div>
    </div>
  <?php endif; ?>
</section>
<section class="certificate-stage certificate-print-stage" id="print-stage" hidden>
  <div class="certificate-print-settings card-box no-print">
    <h2>Print</h2>
    <button class="btn btn-teal certificate-print-button" id="print-certificate" type="button">Print certificate</button>
    <div class="mb-3">
      <label class="form-label" for="paper-size">Paper size</label>
      <select class="form-select" id="paper-size"><option>A4</option><option>Letter</option><option>Legal</option></select>
    </div>
    <div class="mb-3">
      <label class="form-label" for="paper-orientation">Orientation</label>
      <select class="form-select" id="paper-orientation"><option value="portrait">Portrait</option><option value="landscape">Landscape</option></select>
    </div>
    <div class="mb-3">
      <label class="form-label" for="paper-margins">Margins</label>
      <select class="form-select" id="paper-margins"><option value="0.5in">Normal</option><option value="0.25in">Narrow</option><option value="0in">None</option></select>
    </div>
    <p class="form-text">Choose the printer and number of copies in your browser’s print dialog.</p>
    <p class="small mb-0" id="last-printed"><?= !empty($certificate['printed_at']) ? 'Last print dialog closed: ' . e(date('M j, Y g:i A', strtotime($certificate['printed_at']))) : 'Not printed yet.' ?></p>
    <div class="d-flex gap-2 mt-3">
      <button class="btn btn-outline-teal" type="button" id="back-to-template">Back to edit</button>
    </div>
  </div>
  <div class="certificate-print-preview-wrap">
    <div class="certificate-print-preview" id="certificate-print-preview"></div>
  </div>
</section>
<form id="template-finalize-form" class="d-none">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="action" value="finalize_template">
  <input type="hidden" name="cert_type" id="final-cert-type" value="<?= e($selectedType) ?>">
  <input type="hidden" name="template_id" id="final-template-id" value="<?= e($selectedTemplateId) ?>">
</form>
<form id="print-log-form" class="d-none">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="action" value="record_print">
</form>
<style id="certificate-page-settings">@page { size: A4 portrait; margin: 0.5in; }</style>
<script>
(() => {
  const certificateId = <?= $id ?>;
  const templatesByType = <?= json_encode($templateMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const typeSelect = document.getElementById('certificate-type-choice');
  const templateSelect = document.getElementById('template-choice');
  const templateForm = document.getElementById('template-choice-form');
  const editor = document.getElementById('certificate-editor');
  const finalizeButton = document.getElementById('continue-to-print');
  const printStage = document.getElementById('print-stage');
  const templateStage = document.getElementById('template-stage');
  const printPreview = document.getElementById('certificate-print-preview');
  const finalizeForm = document.getElementById('template-finalize-form');
  const printForm = document.getElementById('print-log-form');
  const printPageSettings = document.getElementById('certificate-page-settings');
  const progress = document.getElementById('certificate-progress');
  let printWasRequested = false;
  let previousTypeValue = typeSelect ? typeSelect.value : '';
  let previousTemplateValue = templateSelect ? templateSelect.value : '';

  function updateTemplateOptions() {
    if (!typeSelect || !templateSelect) return;
    const typeTemplates = templatesByType[typeSelect.value] || [];
    const previousValue = templateSelect.value;
    templateSelect.replaceChildren(new Option(
      typeSelect.value ? 'Standard ' + typeSelect.value + ' (default)' : 'Choose a certificate type first',
      ''
    ));
    typeTemplates.forEach(template => templateSelect.add(new Option(template.name, String(template.id))));
    templateSelect.disabled = !typeSelect.value;
    if (typeTemplates.some(template => String(template.id) === previousValue)) {
      templateSelect.value = previousValue;
    }
    document.getElementById('final-cert-type').value = typeSelect.value;
    document.getElementById('final-template-id').value = templateSelect.value;
  }

  if (typeSelect) {
    typeSelect.addEventListener('change', () => {
      if (editor && editor.dataset.edited === 'true'
        && !confirm('Changing the certificate type will discard your current edits. Continue?')) {
        typeSelect.value = previousTypeValue;
        return;
      }
      previousTypeValue = typeSelect.value;
      updateTemplateOptions();
      editor?.setAttribute('data-edited', 'false');
    });
  }
  if (templateSelect) {
    templateSelect.addEventListener('change', () => {
      if (editor && editor.dataset.edited === 'true'
        && !confirm('Loading a different template will discard your current edits. Continue?')) {
        templateSelect.value = previousTemplateValue;
        return;
      }
      previousTemplateValue = templateSelect.value;
      document.getElementById('final-template-id').value = templateSelect.value;
      editor?.setAttribute('data-edited', 'false');
    });
  }
  if (templateForm) {
    templateForm.addEventListener('submit', event => {
      if (editor && editor.dataset.edited === 'true'
        && !confirm('Loading a template will discard your current edits. Continue?')) {
        event.preventDefault();
      }
    });
  }

  const toolbar = document.getElementById('certificate-editor-toolbar');
  if (toolbar && editor) {
    let savedRange = null;
    const rememberSelection = () => {
      const selection = window.getSelection();
      if (selection && selection.rangeCount && editor.contains(selection.anchorNode)) {
        savedRange = selection.getRangeAt(0).cloneRange();
      }
    };
    const restoreSelection = () => {
      editor.focus();
      if (savedRange && editor.contains(savedRange.commonAncestorContainer)) {
        const selection = window.getSelection();
        if (selection) {
          selection.removeAllRanges();
          selection.addRange(savedRange);
        }
      }
    };
    toolbar.addEventListener('mousedown', event => {
      if (event.target.closest('button')) event.preventDefault();
    });
    toolbar.querySelectorAll('[data-command]').forEach(button => {
      button.addEventListener('click', () => {
        restoreSelection();
        document.execCommand(button.dataset.command, false, null);
        editor.dataset.edited = 'true';
        rememberSelection();
      });
    });
    const fontFamily = document.getElementById('editor-font-family');
    fontFamily.addEventListener('mousedown', rememberSelection);
    fontFamily.addEventListener('change', event => {
      restoreSelection();
      document.execCommand('fontName', false, event.target.value);
      editor.querySelectorAll('font[face]').forEach(font => {
        const span = document.createElement('span');
        span.style.fontFamily = font.getAttribute('face');
        while (font.firstChild) span.append(font.firstChild);
        font.replaceWith(span);
      });
      editor.dataset.edited = 'true';
      rememberSelection();
    });
    const fontSize = document.getElementById('editor-font-size');
    fontSize.addEventListener('mousedown', rememberSelection);
    fontSize.addEventListener('change', event => {
      restoreSelection();
      document.execCommand('fontSize', false, event.target.value);
      const points = { '2': 10, '3': 12, '4': 14, '5': 18, '6': 24, '7': 32 };
      editor.querySelectorAll('font[size]').forEach(font => {
        const span = document.createElement('span');
        span.style.fontSize = (points[font.getAttribute('size')] || 12) + 'pt';
        while (font.firstChild) span.append(font.firstChild);
        font.replaceWith(span);
      });
      editor.dataset.edited = 'true';
      rememberSelection();
    });
    editor.addEventListener('input', () => {
      editor.dataset.edited = 'true';
      rememberSelection();
    });
    editor.addEventListener('mouseup', rememberSelection);
    editor.addEventListener('keyup', rememberSelection);
    editor.addEventListener('paste', event => {
      const clipboard = event.clipboardData;
      if (!clipboard) return;
      event.preventDefault();
      document.execCommand('insertText', false, clipboard.getData('text/plain'));
    });
  }

  function updatePageRule() {
    const size = document.getElementById('paper-size').value;
    const orientation = document.getElementById('paper-orientation').value;
    const margin = document.getElementById('paper-margins').value;
    printPageSettings.textContent = '@page { size: ' + size + ' ' + orientation + '; margin: ' + margin + '; }';
  }
  ['paper-size', 'paper-orientation', 'paper-margins'].forEach(id => {
    document.getElementById(id).addEventListener('change', updatePageRule);
  });

  if (finalizeButton) {
    finalizeButton.addEventListener('click', async () => {
      if (!editor || !document.getElementById('final-cert-type').value) {
        alert('Choose a certificate type and load a preview first.');
        return;
      }
      const selectedType = document.getElementById('final-cert-type').value;
      const selectedTemplateId = document.getElementById('final-template-id').value;
      if (selectedTemplateId && !(templatesByType[selectedType] || []).some(template => String(template.id) === selectedTemplateId)) {
        alert('Choose a template that matches the certificate type.');
        return;
      }
      finalizeButton.disabled = true;
      finalizeButton.textContent = 'Preparing print page…';
      try {
        const response = await fetch('template.php?id=' + certificateId, {
          method: 'POST',
          body: new URLSearchParams(new FormData(finalizeForm)),
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          const error = document.createElement('div');
          error.className = 'alert alert-warning small mt-3';
          error.setAttribute('role', 'alert');
          error.textContent = result.error || 'Could not prepare the certificate.';
          if (result.receipt_url) {
            const link = document.createElement('a');
            link.href = result.receipt_url;
            link.textContent = ' Go back to the receipt step.';
            error.append(link);
          }
          document.querySelector('.certificate-editor-actions').before(error);
          return;
        }
        const printDocument = editor.cloneNode(true);
        printDocument.removeAttribute('id');
        printDocument.removeAttribute('contenteditable');
        printDocument.removeAttribute('spellcheck');
        printDocument.classList.add('certificate-print-document');
        printPreview.replaceChildren(printDocument);
        templateStage.hidden = true;
        printStage.hidden = false;
        progress.querySelector('[data-step="3"]').classList.remove('is-current');
        progress.querySelector('[data-step="3"]').classList.add('is-done');
        progress.querySelector('[data-step="4"]').classList.add('is-current');
        document.querySelector('.page-head h1').textContent = 'Print certificate';
        document.title = 'Print certificate · BrgyTrace';
        updatePageRule();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } catch (error) {
        alert('Could not prepare the print page. Check your connection and try again.');
      } finally {
        finalizeButton.disabled = false;
        finalizeButton.textContent = 'Continue to print';
      }
    });
  }

  document.getElementById('back-to-template').addEventListener('click', () => {
    printStage.hidden = true;
    templateStage.hidden = false;
    progress.querySelector('[data-step="3"]').classList.remove('is-done');
    progress.querySelector('[data-step="3"]').classList.add('is-current');
    progress.querySelector('[data-step="4"]').classList.remove('is-current');
    document.querySelector('.page-head h1').textContent = 'Template and edit';
    document.title = 'Template and edit · BrgyTrace';
  });
  document.getElementById('print-certificate').addEventListener('click', () => {
    updatePageRule();
    printWasRequested = true;
    window.print();
  });
  window.addEventListener('afterprint', async () => {
    if (!printWasRequested) return;
    printWasRequested = false;
    try {
      const response = await fetch('print.php?id=' + certificateId, {
        method: 'POST',
        body: new URLSearchParams(new FormData(printForm)),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const result = await response.json();
      if (response.ok && result.ok) {
        document.getElementById('last-printed').textContent = 'Last print dialog closed: ' + result.printed_at;
      } else {
        document.getElementById('last-printed').textContent = 'The print time could not be recorded. Please contact an Administrator.';
      }
    } catch (error) {
      document.getElementById('last-printed').textContent = 'The print time could not be recorded. Please contact an Administrator.';
    }
  });
})();
</script>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
