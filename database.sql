-- BrgyTrace database (MySQL / MariaDB in XAMPP)
CREATE DATABASE IF NOT EXISTS brgytrace_legacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE brgytrace_legacy;

DROP TABLE IF EXISTS audit_log, app_settings, certificates, certificate_templates, blotters, residents, users;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(100) NOT NULL,
  role ENUM('secretary','admin') NOT NULL DEFAULT 'secretary',
  password_hash VARCHAR(255) NOT NULL,
  failed_attempts INT NOT NULL DEFAULT 0,
  failed_attempt_at DATETIME NULL,
  locked_until DATETIME NULL,
  last_login DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE app_settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE certificate_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_name VARCHAR(100) NOT NULL,
  cert_type VARCHAR(40) NOT NULL,
  file_name VARCHAR(40) NOT NULL UNIQUE,
  uploaded_by INT NULL,
  created_at DATETIME NOT NULL,
  INDEX (cert_type)
) ENGINE=InnoDB;

CREATE TABLE residents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  last_name VARCHAR(60) NOT NULL,
  first_name VARCHAR(60) NOT NULL,
  middle_name VARCHAR(60) NULL,
  suffix VARCHAR(10) NULL,
  birthdate DATE NOT NULL,
  sex ENUM('Male','Female') NOT NULL,
  civil_status ENUM('Single','Married','Widowed','Separated') NOT NULL DEFAULT 'Single',
  purok VARCHAR(40) NOT NULL,
  address VARCHAR(150) NULL,
  contact VARCHAR(30) NULL,
  created_at DATETIME NOT NULL,
  INDEX (last_name, first_name)
) ENGINE=InnoDB;

CREATE TABLE blotters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  complainant VARCHAR(120) NOT NULL,
  respondent_id INT NOT NULL,
  incident_date DATE NOT NULL,
  summary TEXT NOT NULL,
  status ENUM('Pending','Settled','Dismissed') NOT NULL DEFAULT 'Pending',
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (respondent_id) REFERENCES residents(id)
) ENGINE=InnoDB;

CREATE TABLE certificates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NOT NULL,
  cert_type VARCHAR(40) NOT NULL,
  template_id INT NULL,
  purpose VARCHAR(120) NOT NULL,
  fee DECIMAL(8,2) NOT NULL DEFAULT 0,
  has_pending_case TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('Waiting for receipt','Issued') NOT NULL DEFAULT 'Waiting for receipt',
  receipt_type ENUM('OR','Cash ticket') NULL,
  receipt_no VARCHAR(40) NULL UNIQUE,   -- a receipt number can be used only once
  receipt_date DATE NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  issued_at DATETIME NULL,
  printed_at DATETIME NULL,
  INDEX (template_id),
  FOREIGN KEY (resident_id) REFERENCES residents(id),
  CONSTRAINT fk_certificates_template_id FOREIGN KEY (template_id) REFERENCES certificate_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(255) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- Accounts:  secretary / secretary123   admin / admin123   (change after first login)
INSERT INTO users (username, full_name, role, password_hash) VALUES
('secretary','Secretary','secretary','$2y$10$sQwppRFRIe6ayqnPMBWgteArWBGeYUmnqBuy598b5JteMW2z/EqF2'),
('admin','Admin','admin','$2y$10$CTtXgYf5DMY7XIXzANX5buuYQoAQ4EWPgOo5DQ8dAC5wi10ccZRiq');

-- Sample data (delete when you start using the system)
INSERT INTO residents (last_name, first_name, middle_name, birthdate, sex, civil_status, purok, address, contact, created_at) VALUES
('Dela Cruz','Juan','Santos','1988-03-12','Male','Married','Purok 1','Purok 1, Tanwalang','0917 000 0001',NOW()),
('Reyes','Ana','Lopez','1995-07-21','Female','Single','Purok 2','Purok 2, Tanwalang','0917 000 0002',NOW()),
('Santos','Pedro','Garcia','1990-01-30','Male','Single','Purok 3','Purok 3, Tanwalang','0917 000 0003',NOW()),
('Santos','Maria','Cruz','1990-05-04','Female','Married','Purok 1','Purok 1, Tanwalang','0917 000 0004',NOW()),
('Garcia','Rosa','Mendoza','1962-11-09','Female','Widowed','Purok 4','Purok 4, Tanwalang',NULL,NOW());

INSERT INTO blotters (complainant, respondent_id, incident_date, summary, status, created_by, created_at) VALUES
('[Sample complainant]',3,CURDATE(),'[Sample summary of the case]','Pending',1,NOW());

INSERT INTO certificates (resident_id, cert_type, purpose, fee, has_pending_case, status, receipt_type, receipt_no, created_by, created_at, issued_at) VALUES
(1,'Barangay Clearance','Employment',100,0,'Waiting for receipt',NULL,NULL,1,NOW(),NULL),
(2,'Residency','School requirement',100,0,'Waiting for receipt',NULL,NULL,1,NOW(),NULL),
(3,'Barangay Clearance','Employment',200,1,'Waiting for receipt',NULL,NULL,1,NOW(),NULL),
(4,'Residency','Employment',100,0,'Issued','OR','0001246',1,NOW(),NOW()),
(5,'Certificate of Indigency','Medical assistance',0,0,'Issued',NULL,NULL,1,NOW(),NOW()),
(1,'Residency','Bank',100,0,'Issued','OR','0001201',1,DATE_SUB(NOW(),INTERVAL 1 DAY),DATE_SUB(NOW(),INTERVAL 1 DAY)),
(2,'Good Moral Character','Scholarship',100,0,'Issued','OR','0001202',1,DATE_SUB(NOW(),INTERVAL 2 DAY),DATE_SUB(NOW(),INTERVAL 2 DAY)),
(4,'Barangay Clearance','Travel',100,0,'Issued','Cash ticket','CT-0007',1,DATE_SUB(NOW(),INTERVAL 3 DAY),DATE_SUB(NOW(),INTERVAL 3 DAY));
