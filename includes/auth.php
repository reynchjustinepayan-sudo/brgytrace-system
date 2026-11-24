<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/local_backup.php';

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function now() { return date('Y-m-d H:i:s'); }
function redirect($url) { header('Location: ' . $url); exit; }
function current_user() { return $_SESSION['user'] ?? null; }
function require_login() {
    if (!current_user()) { redirect('login.php'); }
    $timeoutMinutes = (int)app_setting($GLOBALS['pdo'], 'session_timeout_minutes', '10');
    if (!in_array($timeoutMinutes, [5, 10, 15, 30, 60], true)) { $timeoutMinutes = 10; }
    $nowTimestamp = time();
    $lastActivity = $_SESSION['last_activity'] ?? $nowTimestamp;
    if (!is_numeric($lastActivity) || $nowTimestamp - (int)$lastActivity >= $timeoutMinutes * 60) {
        $expiredUser = current_user();
        unset($_SESSION['user'], $_SESSION['last_activity']);
        session_regenerate_id(true);
        $GLOBALS['pdo']->prepare('INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$expiredUser['id'], 'Signed out', 'Session expired after inactivity', now()]);
        redirect('login.php?expired=1');
    }
    $_SESSION['last_activity'] = $nowTimestamp;
}
function require_admin() { require_login(); if (current_user()['role'] !== 'admin') { http_response_code(403); exit('Admins only.'); } }
function require_secretary() { require_login(); if (current_user()['role'] !== 'secretary') { http_response_code(403); exit('Secretaries only.'); } }
function app_setting(PDO $pdo, string $key, string $default): string {
    $st = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $st->execute([$key]);
    $value = $st->fetchColumn();
    return $value === false ? $default : (string)$value;
}
function save_app_setting(PDO $pdo, string $key, string $value): void {
    $st = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $st->execute([$key, $value, now()]);
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('Invalid form token. Go back and try again.'); }
}
function audit(PDO $pdo, $action, $detail = '') {
    $uid = current_user()['id'] ?? null;
    $pdo->prepare('INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (?,?,?,?)')->execute([$uid, $action, $detail, now()]);
}
function flash($msg = null, $type = 'success') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function ref_no($id) {
    global $pdo;
    static $prefix = null;
    static $digits = null;
    if ($prefix === null || $digits === null) {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9-]/', '', app_setting($pdo, 'certificate_prefix', 'BT')));
        if ($prefix === '') { $prefix = 'BT'; }
        $digits = max(4, min(8, (int)app_setting($pdo, 'certificate_digits', '4')));
    }
    return $prefix . '-' . str_pad((string)$id, $digits, '0', STR_PAD_LEFT);
}
function full_name($r) {
    return trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'][0] . '. ' : '') . $r['last_name'] . ' ' . ($r['suffix'] ?? ''));
}
function peso($n) { return '₱' . number_format((float)$n, 2); }

/** Status pill for a certificate row (needs status, has_pending_case, fee, receipt_type, receipt_no) */
function status_pill($c) {
    if ($c['status'] === 'Issued') {
        $label = $c['receipt_no'] ? ($c['receipt_type'] === 'OR' ? 'OR ' . $c['receipt_no'] : $c['receipt_no']) : 'Free';
        return '<span class="pill pill-green">Issued · ' . e($label) . '</span>';
    }
    if (($c['cert_type'] ?? '') === '' && (!empty($c['receipt_no']) || (float)$c['fee'] === 0.0)) {
        return '<span class="pill pill-amber">Choose type and template</span>';
    }
    if ($c['has_pending_case']) { return '<span class="pill pill-red">Pending case · ₱' . number_format($c['fee']) . '</span>'; }
    return '<span class="pill pill-amber">Waiting for receipt</span>';
}

function icon($name) {
    $p = [
     'home'    => '<path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
     'doc'     => '<path d="M6 3h9l4 4v14H6z"/><path d="M9 12h7M9 16h7"/>',
     'issue'   => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M12 8v8M8 12h8"/>',
     'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
     'book'    => '<rect x="6" y="3" width="12" height="18" rx="2"/><path d="M9 8h6"/>',
     'shield'  => '<path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6z"/>',
     'help'    => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17v.1"/>',
     'account' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="10" r="3"/><path d="M6 18c1.5-2.5 4-3 6-3s4.5.5 6 3"/>',
     'users'   => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.5 2.5-5 6-5s6 1.5 6 5"/><path d="M16 5.5a3 3 0 0 1 0 5.8M18 15c2 .5 3 2 3 4"/>',
     'settings'=> '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.6 2.8-.2-.1a1.7 1.7 0 0 0-1.9.3l-.2.1h-3.2l-.1-.2a1.7 1.7 0 0 0-1.9-.3l-.2.1-2.8-1.6.1-.2a1.7 1.7 0 0 0-.3-1.9l-.1-.2v-3.2l.2-.1a1.7 1.7 0 0 0 .3-1.9l-.1-.2 1.6-2.8.2.1a1.7 1.7 0 0 0 1.9-.3l.2-.1h3.2l.1.2a1.7 1.7 0 0 0 1.9.3l.2-.1 2.8 1.6-.1.2a1.7 1.7 0 0 0 .3 1.9l.1.2v3.2z"/>',
     'audit'   => '<path d="M12 7v5l3 2"/><circle cx="12" cy="12" r="9"/>',
     'search'  => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4-4"/>',
     'plus'    => '<path d="M12 5v14M5 12h14"/>',
     'addbox'  => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M12 8v8M8 12h8"/>',
     'backup'  => '<path d="M7 18h10a4 4 0 0 0 .4-8A5.5 5.5 0 0 0 7 9a4.5 4.5 0 0 0 0 9z"/><path d="M12 16V10m-3 3 3-3 3 3"/>',
     'logout'  => '<path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
