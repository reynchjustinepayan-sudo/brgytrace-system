<?php
// ---- Database (XAMPP defaults) ----
const DB_HOST = 'localhost';
const DB_NAME = 'brgytrace_legacy';
const DB_USER = 'root';
const DB_PASS = '';

// ---- Barangay information ----
const BRGY_NAME     = 'Tanwalang';
const BRGY_MUNI     = 'Sulop';
const BRGY_PROVINCE = 'Davao del Sur';

// ---- Certificate types and their fees (PHP pesos). 0 = free ----
const CERT_TYPES = [
    'Barangay Clearance'       => 100,
    'Business Clearance'       => 100,
    'Residency'                => 100,
    'Good Moral Character'     => 100,
    'Certificate of Indigency' => 0,
    'Solo Parent'              => 100,
];
const PENDING_CASE_CERTIFICATE_FEE = 200;

// ---- Login lock ----
const MAX_FAILED_LOGINS = 5;
const LOCK_MINUTES      = 5;

date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
