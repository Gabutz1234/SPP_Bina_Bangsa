<?php
declare(strict_types=1);

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/midtrans.php';
require_once __DIR__ . '/auth.php';
require_role('siswa');

header('Content-Type: application/json; charset=utf-8');

function cancel_transaction_response(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(
        ['ok' => $status >= 200 && $status < 300, 'message' => $message] + $extra,
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cancel_transaction_response(405, 'Method tidak diizinkan.');
}

if (($_SESSION['user']['role'] ?? '') !== 'siswa') {
    cancel_transaction_response(401, 'Sesi siswa tidak ditemukan.');
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    cancel_transaction_response(422, 'Data pembatalan tidak valid.');
}

$orderId = trim((string) ($payload['order_id'] ?? ''));
$csrf = (string) ($payload['csrf'] ?? '');
if ($orderId === '' || empty($_SESSION['csrf_tagihan']) ||
    !hash_equals($_SESSION['csrf_tagihan'], $csrf)) {
    cancel_transaction_response(419, 'Sesi pembatalan kedaluwarsa. Muat ulang halaman tagihan.');
}

if (!preg_match('/^TAGIHAN-(\d+)-\d{6}(?:-.+)?$/', $orderId, $matches)) {
    cancel_transaction_response(422, 'Order ID tidak valid.');
}

$tagihanId = (int) $matches[1];
$stmtBill = $pdo->prepare(
    'SELECT ts.id_tagihan, ts.status
     FROM tagihan_spp ts
     INNER JOIN siswa s ON s.id_siswa = ts.id_siswa
     WHERE ts.id_tagihan = ? AND s.id_user = ?
     LIMIT 1'
);
$stmtBill->execute([$tagihanId, (int) $_SESSION['user']['id_user']]);
$bill = $stmtBill->fetch();

if (!$bill) {
    cancel_transaction_response(404, 'Tagihan tidak ditemukan.');
}

if ($bill['status'] === 'LUNAS') {
    cancel_transaction_response(409, 'Tagihan sudah lunas dan tidak dapat dibatalkan.');
}

$stmtExisting = $pdo->prepare(
    'SELECT transaction_id, status_transaksi
     FROM transaksi_payment_gateway
     WHERE order_id = ?
     LIMIT 1'
);
$stmtExisting->execute([$orderId]);
$existing = $stmtExisting->fetch();
$existingStatus = $existing['status_transaksi'] ?? false;

if ($existingStatus !== false && $existingStatus !== 'pending') {
    cancel_transaction_response(409, 'Transaksi sudah tidak berstatus pending.');
}

$result = midtrans_cancel_transaction($orderId);
$httpCode = (int) ($result['code'] ?? 0);

// Midtrans menerima order_id atau transaction_id. Beberapa provider
// lebih konsisten memproses pembatalan memakai transaction_id, jadi coba
// fallback tersebut sebelum mengembalikan error ke browser.
if (($httpCode < 200 || $httpCode >= 300) &&
    !empty($existing['transaction_id']) &&
    (string) $existing['transaction_id'] !== $orderId) {
    $fallbackResult = midtrans_cancel_transaction((string) $existing['transaction_id']);
    if ((int) ($fallbackResult['code'] ?? 0) >= 200 &&
        (int) ($fallbackResult['code'] ?? 0) < 300) {
        $result = $fallbackResult;
        $httpCode = (int) $fallbackResult['code'];
    }
}

// 404 berarti order sudah tidak aktif di Midtrans dan aman dilepas dari lock lokal.
if (($httpCode < 200 || $httpCode >= 300) && $httpCode !== 404) {
    error_log('Gagal membatalkan transaksi Midtrans: ' . (string) ($result['error'] ?? '') . ' ' . (string) ($result['response'] ?? ''));
    $responseBody = json_decode((string) ($result['response'] ?? ''), true);
    $statusMessage = is_array($responseBody) ? trim((string) ($responseBody['status_message'] ?? '')) : '';
    cancel_transaction_response(
        502,
        $statusMessage !== ''
            ? 'Midtrans menolak pembatalan: ' . $statusMessage
            : 'Transaksi belum dapat dibatalkan di Midtrans.'
    );
}

$stmtUpdate = $pdo->prepare(
    'UPDATE transaksi_payment_gateway
     SET status_transaksi = "cancel"
     WHERE order_id = ? AND status_transaksi = "pending"'
);
$stmtUpdate->execute([$orderId]);

cancel_transaction_response(200, 'Transaksi dibatalkan. Silakan pilih bank lain.', [
    'order_id' => $orderId,
]);
