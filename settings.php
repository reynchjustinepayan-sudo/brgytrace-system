<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/certificate_templates.php';
require_admin();

$sulopBarangays = [
    'Balasinon', 'Buguis', 'Carre', 'Clib', 'Harada Butai', 'Katipunan', 'Kiblagon',
    'Labon', 'Laperas', 'Lapla', 'Litos', 'Luparan', 'McKinley', 'New Cebu',
    'Osmeña', 'Palili', 'Parame', 'Poblacion', 'Roxas', 'Solongvale', 'Tagolilong',
    'Tala-o', 'Talas', 'Tanwalang', 'Waterfal',
];
$municipalities = [
    'Bansalan', 'Hagonoy', 'Kiblawan', 'Magsaysay', 'Malalag',
    'Matanao', 'Padada', 'Santa Cruz', 'Sulop',
];
$barangay = [
    'name' => app_setting($pdo, 'barangay_name', BRGY_NAME),
    'municipality' => app_setting($pdo, 'barangay_municipality', BRGY_MUNI),
    'province' => app_setting($pdo, 'barangay_province', BRGY_PROVINCE),
    'punong_barangay' => app_setting($pdo, 'barangay_punong_barangay', ''),
];
$currentSeal = app_setting($pdo, 'barangay_seal', '');
$sealUrl = '';
if (preg_match('/\A[a-f0-9]{32}\.(?:png|jpg|webp)\z/i', $currentSeal)
    && is_file(__DIR__ . '/assets/uploads/barangay-seals/' . $currentSeal)) {
    $sealUrl = 'assets/uploads/barangay-seals/' . $currentSeal;
}
$maxFailedLogins = max(3, min(10, (int)app_setting($pdo, 'max_failed_logins', (string)MAX_FAILED_LOGINS)));
$lockMinutes = max(1, min(60, (int)app_setting($pdo, 'lock_minutes', (string)LOCK_MINUTES)));
$sessionTimeoutOptions = [5, 10, 15, 30, 60];
$failedAttemptOptions = [3, 5, 7, 10];
$lockDurationOptions = [1, 5, 10, 15, 30, 60];
$sessionTimeoutMinutes = (int)app_setting($pdo, 'session_timeout_minutes', '10');
if (!in_array($sessionTimeoutMinutes, $sessionTimeoutOptions, true)) { $sessionTimeoutMinutes = 10; }
if (!in_array($maxFailedLogins, $failedAttemptOptions, true)) { $maxFailedLogins = 5; }
if (!in_array($lockMinutes, $lockDurationOptions, true)) { $lockMinutes = 5; }
$backupEnabled = app_setting($pdo, 'backup_enabled', '0') === '1';
$backupFrequency = app_setting($pdo, 'backup_frequency', 'daily');
$backupTime = app_setting($pdo, 'backup_time', '02:00');
$backupDay = max(1, min(7, (int)app_setting($pdo, 'backup_day', '1')));
$lastBackup = app_setting($pdo, 'backup_last_run', '');
$certificatePrefix = app_setting($pdo, 'certificate_prefix', 'BT');
$templateName = '';
$templateCertType = array_key_first(CERT_TYPES);
$error = '';
$errorSection = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sectionValue = $_POST['section'] ?? '';
    $section = is_string($sectionValue) ? $sectionValue : '';
    $postString = static function (string $key): string {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    };
    $settingsToSave = [];
    $auditDetail = '';
    $sealTemporaryPath = null;
    $sealExtension = null;
    $newSealPath = null;
    $newCertificateTemplate = null;
    $newCertificateTemplatePath = null;
    $deleteCertificateTemplate = null;
    $deleteCertificateTemplatePath = null;
    $auditAction = 'System settings updated';

    if ($section === 'barangay') {
        $barangay = [
            'name' => $postString('barangay_name'),
            'municipality' => $postString('barangay_municipality'),
            'province' => $postString('barangay_province'),
            'punong_barangay' => $postString('barangay_punong_barangay'),
        ];
        if (!in_array($barangay['municipality'], $municipalities, true)) {
            $error = 'Choose a municipality from the list.';
        } elseif ($barangay['municipality'] !== 'Sulop') {
            $error = 'Barangay options are currently available for Sulop only. Select Sulop to choose a barangay.';
        } elseif (!in_array($barangay['name'], $sulopBarangays, true)) {
            $error = 'Choose a barangay from the Sulop list.';
        } elseif ($barangay['province'] === '') {
            $error = 'Province is required.';
        } elseif (strlen($barangay['name']) > 80 || strlen($barangay['municipality']) > 80 || strlen($barangay['province']) > 80
            || strlen($barangay['punong_barangay']) > 100) {
            $error = 'One or more barangay information fields exceed their maximum length.';
        } else {
            $upload = $_FILES['barangay_seal'] ?? null;
            if ($upload !== null) {
                if (!is_array($upload)) {
                    $error = 'The barangay seal upload is invalid.';
                } else {
                    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($uploadError !== UPLOAD_ERR_NO_FILE && $uploadError !== UPLOAD_ERR_OK) {
                        $error = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
                            ? 'The barangay seal must be no larger than 2 MB.'
                            : 'The barangay seal could not be uploaded. Please try again.';
                    } elseif ($uploadError === UPLOAD_ERR_OK) {
                        $temporaryPath = $upload['tmp_name'] ?? '';
                        $uploadSize = (int)($upload['size'] ?? 0);
                        if ($uploadSize < 1 || $uploadSize > 2 * 1024 * 1024) {
                            $error = 'The barangay seal must be no larger than 2 MB.';
                        } elseif (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
                            $error = 'The barangay seal upload is invalid.';
                        } else {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $mime = $finfo->file($temporaryPath);
                            $imageInfo = @getimagesize($temporaryPath);
                            $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
                            if (!is_string($mime) || !isset($extensions[$mime]) || !$imageInfo || ($imageInfo['mime'] ?? '') !== $mime) {
                                $error = 'Use a valid PNG, JPEG, or WebP image for the barangay seal.';
                            } elseif ($imageInfo[0] > 4000 || $imageInfo[1] > 4000 || $imageInfo[0] * $imageInfo[1] > 16000000) {
                                $error = 'The barangay seal image dimensions must not exceed 4000 by 4000 pixels.';
                            } else {
                                $sealTemporaryPath = $temporaryPath;
                                $sealExtension = $extensions[$mime];
                            }
                        }
                    }
                }
            }
            if ($error === '') {
                $settingsToSave = [
                    'barangay_name' => $barangay['name'],
                    'barangay_municipality' => $barangay['municipality'],
                    'barangay_province' => $barangay['province'],
                    'barangay_punong_barangay' => $barangay['punong_barangay'],
                    'barangay_contact' => '',
                    'barangay_email' => '',
                ];
                $auditDetail = 'Barangay information updated';
            }
        }
    } elseif ($section === 'backups') {
        $backupEnabled = ($_POST['backup_enabled'] ?? '0') === '1';
        $backupFrequency = $postString('backup_frequency');
        $backupTime = $postString('backup_time');
        $backupDay = filter_var($_POST['backup_day'] ?? '', FILTER_VALIDATE_INT);
        if (!in_array($backupFrequency, ['daily', 'weekly', 'monthly'], true)) {
            $error = 'Choose a valid backup frequency.';
        } elseif (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $backupTime)) {
            $error = 'Enter a valid backup time.';
        } elseif ($backupDay === false || $backupDay < 1 || $backupDay > 7) {
            $error = 'Choose a valid day for weekly backups.';
        } else {
            $settingsToSave = [
                'backup_enabled' => $backupEnabled ? '1' : '0',
                'backup_frequency' => $backupFrequency,
                'backup_time' => $backupTime,
                'backup_day' => (string)$backupDay,
                'backup_schedule_updated_at' => now(),
            ];
            $auditDetail = 'Local backups ' . ($backupEnabled ? 'enabled' : 'disabled') . "; {$backupFrequency} at {$backupTime}";
        }
    } elseif ($section === 'security') {
        $sessionTimeoutInput = filter_var($_POST['session_timeout_minutes'] ?? '', FILTER_VALIDATE_INT);
        $attemptsInput = filter_var($_POST['max_failed_logins'] ?? '', FILTER_VALIDATE_INT);
        $lockInput = filter_var($_POST['lock_minutes'] ?? '', FILTER_VALIDATE_INT);
        if ($sessionTimeoutInput === false || !in_array($sessionTimeoutInput, $sessionTimeoutOptions, true)) {
            $error = 'Choose a valid inactivity timeout.';
        } elseif ($attemptsInput === false || !in_array($attemptsInput, $failedAttemptOptions, true)) {
            $error = 'Choose a valid failed sign-in limit.';
        } elseif ($lockInput === false || !in_array($lockInput, $lockDurationOptions, true)) {
            $error = 'Choose a valid temporary lock duration.';
        } else {
            $sessionTimeoutMinutes = $sessionTimeoutInput;
            $maxFailedLogins = $attemptsInput;
            $lockMinutes = $lockInput;
            $settingsToSave = [
                'session_timeout_minutes' => (string)$sessionTimeoutMinutes,
                'max_failed_logins' => (string)$maxFailedLogins,
                'lock_minutes' => (string)$lockMinutes,
            ];
            $auditDetail = "Session timeout: {$sessionTimeoutMinutes} minutes; lock after {$maxFailedLogins} failed sign-ins for {$lockMinutes} minutes";
        }
    } elseif ($section === 'numbering') {
        $certificatePrefix = strtoupper($postString('certificate_prefix'));
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{0,9}$/', $certificatePrefix)) {
            $error = 'The certificate prefix must be 1–10 letters, numbers, or hyphens, and start with a letter or number.';
        } else {
            $settingsToSave = ['certificate_prefix' => $certificatePrefix];
            $auditDetail = "Certificate reference prefix changed to {$certificatePrefix}";
        }
    } elseif ($section === 'templates') {
        $templateAction = $postString('template_action');
        if ($templateAction === 'upload') {
            $templateName = $postString('template_name');
            $templateCertType = $postString('template_cert_type');
            $upload = $_FILES['template_file'] ?? null;
            if ($templateName === '' || strlen($templateName) > 100) {
                $error = 'Enter a template name of 1–100 characters.';
            } elseif (!isset(CERT_TYPES[$templateCertType])) {
                $error = 'Choose a valid certificate type for this template.';
            } elseif (!is_array($upload)) {
                $error = 'Choose a Word .docx template file.';
            } else {
                $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
                $temporaryPath = $upload['tmp_name'] ?? '';
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $error = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
                        ? 'The Word template exceeds the server upload limit.'
                        : 'Choose a Word .docx template file.';
                } elseif (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
                    $error = 'The Word template upload is invalid.';
                } elseif (!certificate_template_is_valid_docx($temporaryPath)) {
                    $error = 'Upload a valid, unencrypted Word .docx file (maximum 10 MB).';
                } else {
                    $templateDirectory = certificate_template_directory();
                    if (!is_dir($templateDirectory) && !mkdir($templateDirectory, 0700, true) && !is_dir($templateDirectory)) {
                        $error = 'Could not create the private certificate template folder.';
                    } else {
                        $templateFileName = bin2hex(random_bytes(16)) . '.docx';
                        $newCertificateTemplatePath = certificate_template_path($templateFileName);
                        if (!move_uploaded_file($temporaryPath, $newCertificateTemplatePath)) {
                            $newCertificateTemplatePath = null;
                            $error = 'Could not save the Word template. Please try again.';
                        } elseif (!chmod($newCertificateTemplatePath, 0600)) {
                            unlink($newCertificateTemplatePath);
                            $newCertificateTemplatePath = null;
                            $error = 'Could not secure the uploaded Word template.';
                        } else {
                            $newCertificateTemplate = [
                                'template_name' => $templateName,
                                'cert_type' => $templateCertType,
                                'file_name' => $templateFileName,
                            ];
                            $auditAction = 'Certificate template added';
                            $auditDetail = "{$templateName} ({$templateCertType})";
                        }
                    }
                }
            }
        } elseif ($templateAction === 'delete') {
            $templateId = filter_var($_POST['template_id'] ?? '', FILTER_VALIDATE_INT);
            if ($templateId === false || $templateId < 1) {
                $error = 'Choose a valid certificate template.';
            } else {
                $templateStmt = $pdo->prepare('SELECT id, template_name, file_name FROM certificate_templates WHERE id = ?');
                $templateStmt->execute([$templateId]);
                $templateRecord = $templateStmt->fetch();
                if (!$templateRecord) {
                    $error = 'The certificate template was not found.';
                } else {
                    $usageStmt = $pdo->prepare('SELECT COUNT(*) FROM certificates WHERE template_id = ?');
                    $usageStmt->execute([$templateId]);
                    if ((int)$usageStmt->fetchColumn() > 0) {
                        $error = 'This template is already used by a certificate and cannot be deleted.';
                    } else {
                        $deleteCertificateTemplate = $templateRecord;
                        $deleteCertificateTemplatePath = certificate_template_path((string)$templateRecord['file_name']);
                        $auditAction = 'Certificate template deleted';
                        $auditDetail = (string)$templateRecord['template_name'];
                    }
                }
            }
        } else {
            $error = 'Choose whether to upload or delete a certificate template.';
        }
    } else {
        $error = 'Choose a valid settings section.';
    }

    if ($error === '' && $sealTemporaryPath !== null && $sealExtension !== null) {
        $uploadDirectory = __DIR__ . '/assets/uploads/barangay-seals';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            $error = 'Could not create the barangay seal upload folder.';
        } else {
            $sealFilename = bin2hex(random_bytes(16)) . '.' . $sealExtension;
            $newSealPath = $uploadDirectory . DIRECTORY_SEPARATOR . $sealFilename;
            if (!move_uploaded_file($sealTemporaryPath, $newSealPath)) {
                $newSealPath = null;
                $error = 'Could not save the barangay seal. Please try again.';
            } else {
                $settingsToSave['barangay_seal'] = $sealFilename;
            }
        }
    }

    if ($error !== '') {
        $errorSection = $section;
    } else {
        try {
            $pdo->beginTransaction();
            foreach ($settingsToSave as $key => $value) {
                save_app_setting($pdo, $key, $value);
            }
            if ($newCertificateTemplate !== null) {
                $templateInsert = $pdo->prepare(
                    'INSERT INTO certificate_templates (template_name, cert_type, file_name, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?)'
                );
                $templateInsert->execute([
                    $newCertificateTemplate['template_name'],
                    $newCertificateTemplate['cert_type'],
                    $newCertificateTemplate['file_name'],
                    current_user()['id'],
                    now(),
                ]);
            }
            if ($deleteCertificateTemplate !== null) {
                $templateDelete = $pdo->prepare('DELETE FROM certificate_templates WHERE id = ?');
                $templateDelete->execute([(int)$deleteCertificateTemplate['id']]);
            }
            audit($pdo, $auditAction, $auditDetail);
            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newSealPath !== null && is_file($newSealPath)) {
                unlink($newSealPath);
            }
            if ($newCertificateTemplatePath !== null && is_file($newCertificateTemplatePath)) {
                unlink($newCertificateTemplatePath);
            }
            throw $ex;
        }
        if ($newSealPath !== null && preg_match('/\A[a-f0-9]{32}\.(?:png|jpg|webp)\z/i', $currentSeal)) {
            $oldSealPath = __DIR__ . '/assets/uploads/barangay-seals/' . $currentSeal;
            if (is_file($oldSealPath)) {
                unlink($oldSealPath);
            }
        }
        if ($deleteCertificateTemplatePath !== null && is_file($deleteCertificateTemplatePath)
            && !unlink($deleteCertificateTemplatePath)) {
            throw new RuntimeException('The template record was deleted, but its private file could not be removed.');
        }
        flash('Settings saved.');
        redirect('settings.php' . ($section === 'templates' ? '#certificate-templates' : ''));
    }
}

$certificateTemplates = $pdo->query(
    'SELECT t.id, t.template_name, t.cert_type, t.file_name, t.created_at, COUNT(c.id) AS usage_count
     FROM certificate_templates t
     LEFT JOIN certificates c ON c.template_id = t.id
     GROUP BY t.id, t.template_name, t.cert_type, t.file_name, t.created_at
     ORDER BY t.cert_type, t.template_name'
)->fetchAll();
$templatePlaceholders = certificate_template_placeholders();

$page_title = 'Settings'; $page_sub = 'Admin'; $active = 'settings';
include __DIR__ . '/includes/layout_top.php';
$lastBackup = app_setting($pdo, 'backup_last_run', '');
?>
<div class="settings-grid">
  <section class="card-box settings-card">
    <h2>Barangay information</h2>
    <p class="small text-secondary">These details appear in the application and on printed certificates.</p>
    <?php if ($error && $errorSection === 'barangay'): ?><div class="alert alert-warning py-2 small"><?= e($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="barangay">
      <div class="settings-form-grid">
        <div>
          <label class="form-label" for="barangay_name">Barangay</label>
          <select class="form-select" id="barangay_name" name="barangay_name" aria-describedby="barangay-list-note" required <?= $barangay['municipality'] === 'Sulop' ? '' : 'disabled' ?>>
            <option value="">Select a barangay</option>
            <?php if ($barangay['municipality'] === 'Sulop'): ?>
              <?php foreach ($sulopBarangays as $name): ?>
                <option value="<?= e($name) ?>" <?= $barangay['name'] === $name ? 'selected' : '' ?>><?= e($name) ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
          <p class="form-text mb-0" id="barangay-list-note" <?= $barangay['municipality'] === 'Sulop' ? 'hidden' : '' ?>>Barangay options are currently available for Sulop only.</p>
        </div>
        <div>
          <label class="form-label" for="barangay_municipality">Municipality</label>
          <select class="form-select" id="barangay_municipality" name="barangay_municipality" required>
            <option value="">Select a municipality</option>
            <?php foreach ($municipalities as $municipality): ?>
              <option value="<?= e($municipality) ?>" <?= $barangay['municipality'] === $municipality ? 'selected' : '' ?>><?= e($municipality) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label class="form-label" for="barangay_province">Province</label><input class="form-control" id="barangay_province" name="barangay_province" maxlength="80" value="<?= e($barangay['province']) ?>" required></div>
        <div><label class="form-label" for="barangay_punong_barangay">Punong Barangay</label><input class="form-control" id="barangay_punong_barangay" name="barangay_punong_barangay" maxlength="100" value="<?= e($barangay['punong_barangay']) ?>"></div>
        <div class="settings-form-full">
          <label class="form-label" for="barangay_seal">Barangay seal</label>
          <input class="form-control" type="file" id="barangay_seal" name="barangay_seal" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
          <p class="form-text mb-0">Optional. PNG, JPEG, or WebP; maximum 2 MB.</p>
          <?php if ($sealUrl !== ''): ?>
            <div class="settings-seal-preview"><img src="<?= e($sealUrl) ?>" alt="Current barangay seal"><span>Current seal</span></div>
          <?php endif; ?>
        </div>
      </div>
      <button class="btn btn-teal px-4 mt-3" type="submit">Save information</button>
    </form>
  </section>

  <section class="card-box settings-card" id="backups">
    <h2>Scheduled local backups</h2>
    <p class="small text-secondary">Backups run on the first administrator visit after the selected schedule time. Monthly backups are scheduled for the first day of each month.</p>
    <?php if ($error && $errorSection === 'backups'): ?><div class="alert alert-warning py-2 small"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="backups">
      <input type="hidden" name="backup_enabled" value="0">
      <label class="settings-check mb-3"><input type="checkbox" name="backup_enabled" value="1" <?= $backupEnabled ? 'checked' : '' ?>> Enable scheduled local backups</label>
      <div class="settings-form-grid">
        <div>
          <label class="form-label" for="backup_frequency">Frequency</label>
          <select class="form-select" id="backup_frequency" name="backup_frequency">
            <option value="daily" <?= $backupFrequency === 'daily' ? 'selected' : '' ?>>Daily</option>
            <option value="weekly" <?= $backupFrequency === 'weekly' ? 'selected' : '' ?>>Weekly</option>
            <option value="monthly" <?= $backupFrequency === 'monthly' ? 'selected' : '' ?>>Monthly (first day of month)</option>
          </select>
        </div>
        <div>
          <label class="form-label" for="backup_time">Local time</label>
          <input class="form-control" type="time" id="backup_time" name="backup_time" value="<?= e($backupTime) ?>" required>
        </div>
        <div>
          <label class="form-label" for="backup_day">Weekly backup day</label>
          <select class="form-select" id="backup_day" name="backup_day">
            <?php foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $dayNumber => $dayName): ?>
              <option value="<?= $dayNumber ?>" <?= $backupDay === $dayNumber ? 'selected' : '' ?>><?= e($dayName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="settings-backup-meta">
        <div><strong>Backup folder</strong><code><?= e(local_backup_directory()) ?></code></div>
        <div><strong>Last backup</strong><span><?= $lastBackup !== '' ? e(date('M j, Y, g:i A', strtotime($lastBackup))) : 'No backup created yet' ?></span></div>
      </div>
      <button class="btn btn-teal px-4 mt-3" type="submit">Save backup schedule</button>
    </form>
  </section>

  <section class="card-box settings-card">
    <h2>Security</h2>
    <p class="small text-secondary">Set the session timeout and account lock protection.</p>
    <?php if ($error && $errorSection === 'security'): ?><div class="alert alert-warning py-2 small"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="security">
      <div class="settings-security-grid">
        <div>
          <label class="form-label" for="session_timeout_minutes">Sign out after inactivity</label>
          <select class="form-select" id="session_timeout_minutes" name="session_timeout_minutes" required>
            <?php foreach ($sessionTimeoutOptions as $minutes): ?>
              <option value="<?= $minutes ?>" <?= $sessionTimeoutMinutes === $minutes ? 'selected' : '' ?>><?= $minutes ?> minutes</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" for="max_failed_logins">Failed sign-in attempts</label>
          <select class="form-select" id="max_failed_logins" name="max_failed_logins" required>
            <?php foreach ($failedAttemptOptions as $attempts): ?>
              <option value="<?= $attempts ?>" <?= $maxFailedLogins === $attempts ? 'selected' : '' ?>>Lock after <?= $attempts ?> tries</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" for="lock_minutes">Temporary lock duration</label>
          <select class="form-select" id="lock_minutes" name="lock_minutes" required>
            <?php foreach ($lockDurationOptions as $minutes): ?>
              <option value="<?= $minutes ?>" <?= $lockMinutes === $minutes ? 'selected' : '' ?>><?= $minutes ?> <?= $minutes === 1 ? 'minute' : 'minutes' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p class="form-text mt-2">Attempts are counted per username over the selected lock window. A successful sign-in clears that username’s failed-attempt count.</p>
      <button class="btn btn-teal px-4 mt-3" type="submit">Save security settings</button>
    </form>
  </section>

  <section class="card-box settings-card">
    <h2>Certificate numbering</h2>
    <p class="small text-secondary">Set the prefix added to the automatic certificate reference number.</p>
    <?php if ($error && $errorSection === 'numbering'): ?><div class="alert alert-warning py-2 small"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="numbering">
      <div>
        <label class="form-label" for="certificate_prefix">Reference prefix</label>
        <input class="form-control" id="certificate_prefix" name="certificate_prefix" maxlength="10" pattern="[A-Za-z0-9][A-Za-z0-9\-]{0,9}" value="<?= e($certificatePrefix) ?>" required>
      </div>
      <button class="btn btn-teal px-4 mt-3" type="submit">Save numbering settings</button>
    </form>
  </section>

  <section class="card-box settings-card settings-card-wide" id="certificate-templates">
    <h2>Certificate templates</h2>
    <p class="small text-secondary">Upload as many Word templates as needed and assign each one to a certificate type. Each DOCX file can be up to 10 MB.</p>
    <?php if ($error && $errorSection === 'templates'): ?><div class="alert alert-warning py-2 small"><?= e($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="certificate-template-upload">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="templates">
      <input type="hidden" name="template_action" value="upload">
      <div class="settings-form-grid">
        <div>
          <label class="form-label" for="template_name">Template name</label>
          <input class="form-control" id="template_name" name="template_name" maxlength="100" value="<?= e($templateName) ?>" required>
        </div>
        <div>
          <label class="form-label" for="template_cert_type">Certificate type</label>
          <select class="form-select" id="template_cert_type" name="template_cert_type" required>
            <?php foreach (CERT_TYPES as $type => $fee): ?>
              <option value="<?= e($type) ?>" <?= $templateCertType === $type ? 'selected' : '' ?>><?= e($type) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="settings-form-full">
          <label class="form-label" for="template_file">Word template file</label>
          <div class="template-dropzone" id="template-dropzone">
            <p>Choose a .docx file or drag it here.</p>
            <input class="form-control" type="file" id="template_file" name="template_file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
            <span class="form-text" id="template-file-name">No file selected</span>
          </div>
        </div>
      </div>
      <p class="template-placeholder-help">
        Add placeholders in Word wherever certificate details should appear. Keep each placeholder together without applying different formatting to part of it:
        <?php foreach ($templatePlaceholders as $placeholder => $label): ?>
          <code title="<?= e($label) ?>">{{<?= e($placeholder) ?>}}</code>
        <?php endforeach; ?>
      </p>
      <p class="form-text">Paragraphs, tables, basic text formatting, page breaks, and inline PNG/JPEG/GIF images are supported. Advanced Word layouts may differ, so review the editable certificate before printing.</p>
      <button class="btn btn-teal px-4 mt-2" type="submit">Upload template</button>
    </form>

    <div class="certificate-template-list">
      <h3>Uploaded templates</h3>
      <?php if (!$certificateTemplates): ?>
        <p class="small text-secondary mb-0">No templates uploaded yet. Secretaries will use the standard certificate until a template is added.</p>
      <?php else: ?>
        <?php foreach ($certificateTemplates as $template): ?>
          <div class="certificate-template-row">
            <div class="certificate-template-info">
              <strong><?= e($template['template_name']) ?></strong>
              <span><?= e($template['cert_type']) ?> · Added <?= e(date('M j, Y', strtotime($template['created_at']))) ?></span>
            </div>
            <?php if ((int)$template['usage_count'] > 0): ?>
              <span class="small text-secondary">Used by <?= (int)$template['usage_count'] ?> certificate<?= (int)$template['usage_count'] === 1 ? '' : 's' ?></span>
            <?php else: ?>
              <form method="post" class="certificate-template-delete" onsubmit="return confirm('Remove this unused template?')">
                <?= csrf_field() ?>
                <input type="hidden" name="section" value="templates">
                <input type="hidden" name="template_action" value="delete">
                <input type="hidden" name="template_id" value="<?= (int)$template['id'] ?>">
                <button class="btn btn-sm btn-outline-teal" type="submit">Remove</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>
</div>
<script>
(() => {
  const municipalitySelect = document.getElementById('barangay_municipality');
  const barangaySelect = document.getElementById('barangay_name');
  const barangayNote = document.getElementById('barangay-list-note');
  const sulopBarangays = <?= json_encode($sulopBarangays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const savedBarangay = <?= json_encode($barangay['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

  function updateBarangayOptions() {
    const isSulop = municipalitySelect.value === 'Sulop';
    const selectedBarangay = barangaySelect.value || savedBarangay;
    barangaySelect.replaceChildren(new Option(isSulop ? 'Select a barangay' : 'Select Sulop to choose a barangay', ''));
    barangaySelect.disabled = !isSulop;
    if (isSulop) {
      sulopBarangays.forEach(name => barangaySelect.add(new Option(name, name)));
      if (sulopBarangays.includes(selectedBarangay)) {
        barangaySelect.value = selectedBarangay;
      }
    }
    barangayNote.hidden = isSulop;
  }

  municipalitySelect.addEventListener('change', updateBarangayOptions);
  updateBarangayOptions();

  const templateInput = document.getElementById('template_file');
  const templateDropzone = document.getElementById('template-dropzone');
  const templateFileName = document.getElementById('template-file-name');
  if (templateInput && templateDropzone && templateFileName) {
    const showTemplateFile = () => {
      templateFileName.textContent = templateInput.files.length ? templateInput.files[0].name : 'No file selected';
    };
    templateInput.addEventListener('change', showTemplateFile);
    templateDropzone.addEventListener('dragover', event => {
      event.preventDefault();
      templateDropzone.classList.add('is-dragging');
    });
    templateDropzone.addEventListener('dragleave', () => templateDropzone.classList.remove('is-dragging'));
    templateDropzone.addEventListener('drop', event => {
      event.preventDefault();
      templateDropzone.classList.remove('is-dragging');
      if (event.dataTransfer.files.length) {
        templateInput.files = event.dataTransfer.files;
        showTemplateFile();
      }
    });
  }
})();
</script>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
