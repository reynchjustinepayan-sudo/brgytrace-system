<?php
require_once __DIR__ . '/includes/auth.php';
require_secretary();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') !== 'record_print') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid print action.']);
        exit;
    }
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid certificate.']);
        exit;
    }
    $certificateStmt = $pdo->prepare('SELECT id FROM certificates WHERE id = ? AND status = "Issued"');
    $certificateStmt->execute([(int)$id]);
    if (!$certificateStmt->fetch()) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Issued certificate not found.']);
        exit;
    }
    $printedAt = now();
    $pdo->prepare('UPDATE certificates SET printed_at = ? WHERE id = ?')->execute([$printedAt, (int)$id]);
    audit($pdo, 'Certificate print dialog closed', ref_no((int)$id) . ' · ' . $printedAt);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'printed_at' => date('M j, Y g:i A', strtotime($printedAt))]);
    exit;
}

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($id === false || $id < 1) {
    http_response_code(404);
    exit('Certificate not found.');
}
redirect('template.php?id=' . (int)$id);
