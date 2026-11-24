<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$isAdmin = current_user()['role'] === 'admin';
$page_title = 'How to use BrgyTrace'; $page_sub = 'Support'; $active = 'help';
include __DIR__ . '/includes/layout_top.php';
?>
<?php if ($isAdmin): ?>
<div class="help-guide">
  <section class="help-guide-section">
    <h2>Administrator process</h2>
    <p>Set up access, confirm barangay details, and keep the system protected.</p>
    <div class="help-guide-grid">
      <article class="card-box help-step-card">
        <h3><span class="help-step-number">1</span>Review system status</h3>
        <p>Check recent sign-ins, locked accounts, resident totals, and system checks when you open the dashboard.</p>
        <a href="dashboard.php">Open the Dashboard &rarr;</a>
      </article>
      <article class="card-box help-step-card">
        <h3><span class="help-step-number">2</span>Set up barangay information</h3>
        <p>Confirm the municipality, barangay, province, Punong Barangay, and seal. Set the certificate reference prefix in Settings.</p>
        <a href="settings.php">Open Settings &rarr;</a>
      </article>
      <article class="card-box help-step-card">
        <h3><span class="help-step-number">3</span>Manage staff accounts</h3>
        <p>Create or update Administrator and Secretary accounts. Secretaries handle certificate requests. Keep at least one Administrator account and use strong initial passwords.</p>
        <a href="users.php">Manage Users &rarr;</a>
      </article>
      <article class="card-box help-step-card">
        <h3><span class="help-step-number">4</span>Secure the system and backups</h3>
        <p>Choose inactivity and sign-in lock settings. Configure local backups and check the folder and last-backup status. Backups run on the first Administrator visit after the scheduled time; monthly backups are scheduled for the first day of the month.</p>
        <a href="settings.php">Review Settings &rarr;</a>
      </article>
      <article class="card-box help-step-card">
        <h3><span class="help-step-number">5</span>Upload certificate templates</h3>
        <p>In Settings, open Certificate templates, enter a name, choose the matching certificate type, and upload a Word .docx file. You can upload as many templates as needed; each file can be up to 10 MB.</p>
        <p>Put supported placeholders in the document, such as <code>{{resident_name}}</code>, <code>{{purpose}}</code>, <code>{{issue_date}}</code>, <code>{{reference_number}}</code>, and <code>{{barangay_name}}</code>. Keep each placeholder together in one piece of text. Paragraphs, tables, basic text formatting, page breaks, and inline PNG/JPEG/GIF images are supported; advanced Word layouts may look different.</p>
        <p>A template used by a certificate cannot be removed.</p>
        <a href="settings.php#certificate-templates">Manage Certificate templates &rarr;</a>
      </article>
    </div>
  </section>

  <section class="help-guide-section">
    <h2>Records and oversight</h2>
    <p>Keep resident information accurate and review important system activity.</p>
    <div class="help-guide-grid">
      <article class="card-box help-step-card">
        <h3>Maintain resident records</h3>
        <ol>
          <li>Open Residents and search before adding a new record.</li>
          <li>If the resident is not listed, add the record and enter the required details.</li>
          <li>Review the information for accuracy before saving.</li>
        </ol>
        <a href="residents.php">Open Residents &rarr;</a>
      </article>
      <article class="card-box help-step-card">
        <h3>Review the audit log</h3>
        <p>Review sign-ins and system changes. Open <b>View details</b> on an entry to see its recorded details. Certificate requests and printing are handled from a Secretary account.</p>
        <a href="audit.php">Open Audit log &rarr;</a>
      </article>
    </div>
  </section>
</div>
<?php else: ?>
<div class="help-guide">
  <section class="help-guide-section">
    <h2>Issue a certificate</h2>
    <p>From request to print.</p>
    <div class="help-guide-grid">
      <article class="card-box help-step-card">
        <h3><span class="help-step-number">1</span>Request and blotter check</h3>
        <p>Search for the resident and select the matching record. If the resident is not listed, add the resident and return to the request. Enter the purpose. BrgyTrace checks for pending blotter cases before continuing.</p>
        <a href="issue.php">Start a certificate request &rarr;</a>
      </article>
      <article class="card-box help-step-card">
        <h3><span class="help-step-number">2</span>Record the receipt</h3>
        <p>Choose the fee at this step: paid certificates are normally <?= peso(100) ?>, a pending case requires <?= peso(PENDING_CASE_CERTIFICATE_FEE) ?>, and Certificate of Indigency is free. For a paid request, record the OR or cash-ticket number and date paid. Each receipt number can be used only once. Choose Free — no receipt for an indigency request.</p>
      </article>
      <article class="card-box help-step-card">
        <h3><span class="help-step-number">3</span>Choose the certificate and template</h3>
        <p>Select the certificate type, then choose a matching template uploaded by the Administrator or use the standard certificate. Resident details fill in automatically. Use the font and alignment tools to correct or format text; edits apply only to this certificate. Confirm the type matches the fee before continuing.</p>
      </article>
      <article class="card-box help-step-card">
        <h3><span class="help-step-number">4</span>Set print options and print</h3>
        <p>Review the final preview and choose paper size, orientation, and margins. Select the printer and number of copies in the browser print dialog. BrgyTrace records the date and time when the print dialog closes, and adds the event to the audit log. Check the printed certificate before handing it to the resident.</p>
      </article>
    </div>
  </section>

  <section class="help-guide-section">
    <h2>Records</h2>
    <p>Keep resident and incident records complete and accurate.</p>
    <div class="help-guide-grid">
      <article class="card-box help-step-card">
        <h3>Record a blotter case</h3>
        <ol>
          <li>Open Blotter and choose a new entry.</li>
          <li>Enter the complainant, respondent, incident date, and summary.</li>
          <li>Review the information and save the record.</li>
        </ol>
        <a href="blotter_add.php">Add a blotter entry &rarr;</a>
      </article>
      <article class="card-box help-step-card">
        <h3>Find or add a resident</h3>
        <ol>
          <li>Open Residents and search before adding a new record.</li>
          <li>If the resident is not listed, choose Add resident and enter the required details.</li>
          <li>Review the information for accuracy before saving.</li>
        </ol>
        <a href="residents.php">Open Residents &rarr;</a>
      </article>
    </div>
  </section>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
