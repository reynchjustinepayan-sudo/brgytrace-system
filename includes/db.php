<?php
require_once __DIR__ . '/config.php';
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS certificate_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_name VARCHAR(100) NOT NULL,
        cert_type VARCHAR(40) NOT NULL,
        file_name VARCHAR(40) NOT NULL UNIQUE,
        uploaded_by INT NULL,
        created_at DATETIME NOT NULL,
        INDEX (cert_type)
    ) ENGINE=InnoDB");
    $certificateTemplateColumn = $pdo->query("SHOW COLUMNS FROM certificates LIKE 'template_id'")->fetch();
    if (!$certificateTemplateColumn) {
        $pdo->exec('ALTER TABLE certificates ADD COLUMN template_id INT NULL AFTER cert_type, ADD INDEX (template_id)');
    }
    $templateForeignKey = $pdo->query(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'certificates'
           AND COLUMN_NAME = 'template_id'
           AND REFERENCED_TABLE_NAME = 'certificate_templates'
         LIMIT 1"
    )->fetch();
    if (!$templateForeignKey) {
        $pdo->exec(
            'ALTER TABLE certificates
             ADD CONSTRAINT fk_certificates_template_id
             FOREIGN KEY (template_id) REFERENCES certificate_templates(id) ON DELETE RESTRICT'
        );
    }
    $printedAtColumn = $pdo->query("SHOW COLUMNS FROM certificates LIKE 'printed_at'")->fetch();
    if (!$printedAtColumn) {
        $pdo->exec('ALTER TABLE certificates ADD COLUMN printed_at DATETIME NULL AFTER issued_at');
    }
    $receiptDateColumn = $pdo->query("SHOW COLUMNS FROM certificates LIKE 'receipt_date'")->fetch();
    if (!$receiptDateColumn) {
        $pdo->exec('ALTER TABLE certificates ADD COLUMN receipt_date DATE NULL AFTER receipt_no');
    }
    $certificateStatusColumn = $pdo->query("SHOW COLUMNS FROM certificates LIKE 'status'")->fetch();
    if ($certificateStatusColumn
        && strpos((string)$certificateStatusColumn['Type'], "'Waiting for certificate type'") === false) {
        $pdo->exec(
            "ALTER TABLE certificates
             MODIFY status ENUM('Waiting for receipt','Waiting for certificate type','Issued')
             NOT NULL DEFAULT 'Waiting for receipt'"
        );
    }
    $failedAttemptColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'failed_attempt_at'")->fetch();
    if (!$failedAttemptColumn) {
        $pdo->exec('ALTER TABLE users ADD COLUMN failed_attempt_at DATETIME NULL AFTER failed_attempts');
    }
} catch (PDOException $ex) {
    exit('Cannot connect to or initialize the database. Check MySQL and database permissions. (' . htmlspecialchars($ex->getMessage()) . ')');
}
