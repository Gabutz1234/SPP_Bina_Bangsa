<?php
declare(strict_types=1);

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/midtrans.php';

header('Content-Type: application/json; charset=utf-8');

function notification_response(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => $status >= 200 && $status < 300, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    notification_response(405, 'Method Not Allowed');
}

if (midtrans_server_key() === '') {
    error_log('MIDTRANS_SERVER_KEY belum dikonfigurasi.');
    notification_response(500, 'Midtrans belum dikonfigurasi.');
}

$body = file_get_contents('php://input');
$payload = json_decode($body ?: '', true);

if (!is_array($payload)) {
    notification_response(400, 'Payload JSON tidak valid.');
}

if (!midtrans_signature_valid($payload)) {
    error_log('Signature Midtrans tidak valid untuk order_id: ' . (string) ($payload['order_id'] ?? '-'));
    notification_response(403, 'Signature tidak valid.');
}

$allowedStatuses = ['pending', 'capture', 'settlement', 'deny', 'cancel', 'expire'];
$status = (string) ($payload['transaction_status'] ?? '');
$orderId = trim((string) ($payload['order_id'] ?? ''));
$transactionId = trim((string) ($payload['transaction_id'] ?? ''));
$grossAmount = (int) round((float) ($payload['gross_amount'] ?? 0));

if (!in_array($status, $allowedStatuses, true) || $orderId === '' ||
    $transactionId === '' || $grossAmount <= 0) {
    notification_response(400, 'Data transaksi Midtrans tidak lengkap.');
}

$vaNumber = '';
$bank = null;
if (isset($payload['va_numbers'][0]) && is_array($payload['va_numbers'][0])) {
    $vaNumber = trim((string) ($payload['va_numbers'][0]['va_number'] ?? ''));
    $bank = isset($payload['va_numbers'][0]['bank'])
        ? (string) $payload['va_numbers'][0]['bank']
        : null;
}
$vaNumber = $vaNumber !== ''
    ? $vaNumber
    : trim((string) ($payload['virtual_account'] ?? $payload['va_number'] ?? ''));
$bank = $bank ?: (isset($payload['bank']) ? (string) $payload['bank'] : null);

// Order ID aplikasi menggunakan pola TAGIHAN-{id_tagihan}-{YYYYMM}-{attempt}.
$tagihanId = null;
if (preg_match('/^TAGIHAN-(\d+)-\d{6}(?:-.+)?$/', $orderId, $matches)) {
    $tagihanId = (int) $matches[1];
}

$studentId = null;
$tagihan = null;
$stmtTagihan = null;
if ($tagihanId !== null) {
    // Gunakan order/tagihan sebagai sumber identitas utama. VA yang dikirim
    // Midtrans memiliki company code bank dan memang bukan VA master siswa.
    $stmtTagihan = $pdo->prepare(
        'SELECT id_tagihan, id_siswa, nominal, status
         FROM tagihan_spp
         WHERE id_tagihan = ?
         LIMIT 1'
    );
    $stmtTagihan->execute([$tagihanId]);
    $tagihan = $stmtTagihan->fetch();
    if ($tagihan) {
        $studentId = (int) $tagihan['id_siswa'];
    }
}

if ($studentId === null && $vaNumber !== '') {
    $stmtStudent = $pdo->prepare(
        'SELECT id_siswa FROM virtual_account WHERE nomor_va = ? LIMIT 1'
    );
    $stmtStudent->execute([$vaNumber]);
    $studentId = $stmtStudent->fetchColumn();
    $studentId = $studentId === false ? null : (int) $studentId;
}

$stmtTagihan = null;
if ($tagihanId !== null && $studentId !== null) {
    $stmtTagihan = $pdo->prepare(
        'SELECT id_tagihan, id_siswa, nominal, status
         FROM tagihan_spp
         WHERE id_tagihan = ? AND id_siswa = ?
         LIMIT 1'
    );
    $stmtTagihan->execute([$tagihanId, $studentId]);
} elseif ($tagihanId !== null) {
    $stmtTagihan = $pdo->prepare(
        'SELECT id_tagihan, id_siswa, nominal, status
         FROM tagihan_spp
         WHERE id_tagihan = ?
         LIMIT 1'
    );
    $stmtTagihan->execute([$tagihanId]);
}

$tagihan = $stmtTagihan?->fetch();

// QRIS dan beberapa metode Snap tidak mengirim va_numbers.
// Jika VA tidak tersedia, identitas siswa tetap dapat ditentukan
// dari tagihan yang tercantum pada order_id aplikasi.
if ($tagihan && $studentId === null) {
    $studentId = (int) $tagihan['id_siswa'];
}

// Simpan VA siswa sebagai fallback agar transaksi QRIS tetap dapat
// dicari dan ditampilkan di halaman admin.
if ($studentId !== null && $vaNumber === '') {
    $stmtStudentVa = $pdo->prepare(
        'SELECT nomor_va
         FROM virtual_account
         WHERE id_siswa = ? AND status = "aktif"
         LIMIT 1'
    );
    $stmtStudentVa->execute([$studentId]);
    $studentVa = $stmtStudentVa->fetchColumn();
    if ($studentVa !== false) {
        $vaNumber = (string) $studentVa;
    }
}

// Yang ditampilkan dan dipakai aplikasi adalah VA master siswa (8800 + NIS).
// Nomor VA bank dari Midtrans tetap tersimpan di raw_response untuk audit.
if ($studentId !== null) {
    $stmtStudentVa = $pdo->prepare(
        'SELECT nomor_va FROM virtual_account
         WHERE id_siswa = ? AND status = "aktif" LIMIT 1'
    );
    $stmtStudentVa->execute([$studentId]);
    $studentVa = $stmtStudentVa->fetchColumn();
    if ($studentVa !== false && trim((string) $studentVa) !== '') {
        $vaNumber = (string) $studentVa;
    }
}

if ($tagihan && $grossAmount !== (int) $tagihan['nominal']) {
    notification_response(422, 'Nominal transaksi tidak sesuai dengan tagihan.');
}

$transactionTime = (string) ($payload['transaction_time'] ?? '');
$settlementTime = (string) ($payload['settlement_time'] ?? '');
$waktuTransaksi = $settlementTime !== '' ? $settlementTime : $transactionTime;
$tanggalTransaksi = $waktuTransaksi !== '' ? substr($waktuTransaksi, 0, 10) : null;
$rawResponse = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$fraudStatus = isset($payload['fraud_status']) ? (string) $payload['fraud_status'] : null;
$paymentType = isset($payload['payment_type']) ? (string) $payload['payment_type'] : null;
$referenceNumber = isset($payload['reference_number'])
    ? (string) $payload['reference_number']
    : (isset($payload['approval_code']) ? (string) $payload['approval_code'] : null);

try {
    $pdo->beginTransaction();

    $stmtExisting = $pdo->prepare(
        'SELECT id_transaksi, status_transaksi
         FROM transaksi_payment_gateway
         WHERE order_id = ?
         LIMIT 1
         FOR UPDATE'
    );
    $stmtExisting->execute([$orderId]);
    $existing = $stmtExisting->fetch();

    if ($existing) {
        $currentStatus = (string) $existing['status_transaksi'];
        $statusRank = [
            'pending' => 1,
            'deny' => 2,
            'cancel' => 2,
            'expire' => 2,
            'capture' => 3,
            'settlement' => 4,
        ];
        $keepCurrent = ($statusRank[$currentStatus] ?? 0) > ($statusRank[$status] ?? 0);

        $stmtUpdate = $pdo->prepare(
            'UPDATE transaksi_payment_gateway
             SET transaction_id = ?, virtual_account = ?, id_siswa = ?,
                 tanggal_transaksi = ?, waktu_transaksi = ?, nominal = ?,
                 payment_type = ?, payment_channel = ?, reference_number = ?,
                 status_transaksi = ?, fraud_status = ?, signature_key = ?, raw_response = ?
             WHERE id_transaksi = ?'
        );
        $stmtUpdate->execute([
            $transactionId,
            $vaNumber,
            $studentId,
            $tanggalTransaksi,
            $waktuTransaksi !== '' ? $waktuTransaksi : null,
            $grossAmount,
            $paymentType,
            $bank,
            $referenceNumber,
            $keepCurrent ? $currentStatus : $status,
            $fraudStatus,
            (string) ($payload['signature_key'] ?? ''),
            $rawResponse,
            $existing['id_transaksi'],
        ]);
        $transactionDbId = (int) $existing['id_transaksi'];
        $effectiveStatus = $keepCurrent ? $currentStatus : $status;
    } else {
        $stmtInsert = $pdo->prepare(
            'INSERT INTO transaksi_payment_gateway
             (order_id, transaction_id, virtual_account, id_siswa,
              tanggal_transaksi, waktu_transaksi, nominal, payment_type,
              payment_channel, reference_number, status_transaksi,
              fraud_status, signature_key, raw_response)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmtInsert->execute([
            $orderId,
            $transactionId,
            $vaNumber,
            $studentId,
            $tanggalTransaksi,
            $waktuTransaksi !== '' ? $waktuTransaksi : null,
            $grossAmount,
            $paymentType,
            $bank,
            $referenceNumber,
            $status,
            $fraudStatus,
            (string) ($payload['signature_key'] ?? ''),
            $rawResponse,
        ]);
        $transactionDbId = (int) $pdo->lastInsertId();
        $effectiveStatus = $status;
    }

    if ($tagihan && midtrans_is_success($effectiveStatus, $fraudStatus)) {
        $stmtPayment = $pdo->prepare(
            'SELECT id_pembayaran FROM pembayaran WHERE id_tagihan = ? LIMIT 1'
        );
        $stmtPayment->execute([$tagihan['id_tagihan']]);

        if (!$stmtPayment->fetch()) {
            $stmtPaymentInsert = $pdo->prepare(
                'INSERT INTO pembayaran
                 (id_transaksi, id_tagihan, tanggal_pembayaran, nominal_pembayaran, status)
                 VALUES (?, ?, ?, ?, "LUNAS")'
            );
            $stmtPaymentInsert->execute([
                $transactionDbId,
                $tagihan['id_tagihan'],
                $waktuTransaksi !== '' ? $waktuTransaksi : date('Y-m-d H:i:s'),
                $grossAmount,
            ]);
        }

        $stmtUpdateBill = $pdo->prepare(
            'UPDATE tagihan_spp SET status = "LUNAS" WHERE id_tagihan = ?'
        );
        $stmtUpdateBill->execute([$tagihan['id_tagihan']]);
    }

    $pdo->commit();
    notification_response(200, 'Notifikasi Midtrans berhasil diproses.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Midtrans notification error: ' . $exception->getMessage());
    notification_response(500, 'Notifikasi belum dapat diproses.');
}
