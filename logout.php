<?php
require_once __DIR__ . '/includes/auth.php';
if (current_user()) { audit($pdo, 'Signed out'); }
$_SESSION = [];
session_destroy();
header('Location: login.php?out=1');
