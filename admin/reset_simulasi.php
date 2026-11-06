<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/midtrans.php';
require_once __DIR__ . '/../config/auth.php';
require_role('admin');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: ../index.php'); exit;
}

function kembali(string $jenis, string $pesan): never
{
    header('Location: tagihan_spp.php?reset_' . $jenis . '=' . urlencode($pesan)); exit;
}

$csrf = (string) ($_SESSION['csrf_tagihan_spp'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
    kembali('error', 'Permintaan reset simulasi tidak valid.');
}
if (midtrans_is_production()) {
    kembali('error', 'Reset simulasi hanya tersedia saat MIDTRANS_IS_PRODUCTION=false.');
}

$idTagihan = filter_var($_POST['id_tagihan'] ?? 0, FILTER_VALIDATE_INT);
if (!$idTagihan) kembali('error', 'Tagihan simulasi tidak valid.');

$stmt = $pdo->prepare('SELECT id_tagihan, status FROM tagihan_spp WHERE id_tagihan=? LIMIT 1');
$stmt->execute([$idTagihan]);
if (!$stmt->fetch()) kembali('error', 'Tagihan tidak ditemukan.');

$likeOrder = 'TAGIHAN-' . $idTagihan . '-%';
$stmt = $pdo->prepare(
    'SELECT id_transaksi, order_id, status_transaksi
     FROM transaksi_payment_gateway
     WHERE order_id LIKE ?
     ORDER BY id_transaksi ASC'
);
$stmt->execute([$likeOrder]);
$transactions = $stmt->fetchAll();

foreach ($transactions as $transaction) {
    if ($transaction['status_transaksi'] !== 'pending') continue;
    $result = midtrans_cancel_transaction((string) $transaction['order_id']);
    // 200 = berhasil dibatalkan. 404/412 berarti sudah tidak aktif atau sudah
    // berubah status di Midtrans, sehingga tetap aman untuk dibersihkan di Sandbox.
    if ($result['code'] < 200 || $result['code'] >= 300) {
        if (!in_array($result['code'], [404, 412], true)) {
            error_log('Reset simulasi gagal membatalkan ' . $transaction['order_id'] . ': ' . $result['error'] . ' ' . (string) $result['response']);
            kembali('error', 'Transaksi pending belum dapat dibatalkan di Midtrans.');
        }
    }
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('DELETE FROM pembayaran WHERE id_tagihan=?');
    $stmt->execute([$idTagihan]);
    $stmt = $pdo->prepare('DELETE FROM transaksi_payment_gateway WHERE order_id LIKE ?');
    $stmt->execute([$likeOrder]);
    $stmt = $pdo->prepare('UPDATE tagihan_spp SET status="BELUM_BAYAR" WHERE id_tagihan=?');
    $stmt->execute([$idTagihan]);
    $pdo->commit();
    kembali('success', 'Tagihan berhasil direset untuk simulasi Sandbox.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Reset simulasi error: ' . $exception->getMessage());
    kembali('error', 'Reset simulasi gagal diproses.');
}
