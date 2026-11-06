<?php
declare(strict_types=1);

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/midtrans.php';
require_once __DIR__ . '/auth.php';
require_role('siswa');

header('Content-Type: application/json; charset=utf-8');

function transaction_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function month_name(int $month): string
{
    return [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$month] ?? 'SPP';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    transaction_error(405, 'Method tidak diizinkan.');
}

if (($_SESSION['user']['role'] ?? '') !== 'siswa') {
    transaction_error(401, 'Sesi siswa tidak ditemukan.');
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);

if (!is_array($payload)) {
    transaction_error(422, 'Tagihan tidak valid.');
}

$idTagihan = filter_var($payload['id_tagihan'] ?? 0, FILTER_VALIDATE_INT);
$csrf = (string) ($payload['csrf'] ?? '');
if (!$idTagihan || empty($_SESSION['csrf_tagihan']) || !hash_equals($_SESSION['csrf_tagihan'], $csrf)) {
    transaction_error(419, 'Sesi pembayaran kedaluwarsa. Muat ulang halaman tagihan.');
}

if (midtrans_server_key() === '') {
    transaction_error(503, 'Server Key Midtrans belum tersedia di konfigurasi server.');
}

$stmt = $pdo->prepare(
    'SELECT ts.id_tagihan, ts.id_siswa, ts.bulan, ts.tahun, ts.nominal,
            ts.status, s.nama, s.nis, u.username, va.nomor_va
     FROM tagihan_spp ts
     INNER JOIN siswa s ON s.id_siswa = ts.id_siswa
     INNER JOIN users u ON u.id_user = s.id_user
     LEFT JOIN virtual_account va
       ON va.id_siswa = s.id_siswa AND va.status = "aktif"
     WHERE ts.id_tagihan = ? AND s.id_user = ?
     LIMIT 1'
);
$stmt->execute([$idTagihan, (int) $_SESSION['user']['id_user']]);
$tagihan = $stmt->fetch();

if (!$tagihan) {
    transaction_error(404, 'Tagihan tidak ditemukan.');
}

if ($tagihan['status'] === 'LUNAS') {
    transaction_error(409, 'Tagihan ini sudah lunas.');
}

$pendingStatement = $pdo->prepare(
    'SELECT tpg.order_id
     FROM transaksi_payment_gateway tpg
     WHERE tpg.status_transaksi = "pending"
       AND tpg.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
       AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(tpg.order_id, "-", 2), "-", -1) AS UNSIGNED) = ?
     ORDER BY tpg.created_at DESC, tpg.id_transaksi DESC
     LIMIT 1'
);
$pendingStatement->execute([(int) $tagihan['id_tagihan']]);
if ($pendingStatement->fetchColumn()) {
    transaction_error(409, 'Tagihan ini sudah memiliki transaksi yang masih pending. Selesaikan pembayaran pada Virtual Account tersebut terlebih dahulu.');
}

if (trim((string) ($tagihan['nomor_va'] ?? '')) === '') {
    transaction_error(422, 'Siswa belum memiliki Virtual Account aktif.');
}

$orderId = 'TAGIHAN-' . $tagihan['id_tagihan'] . '-' . date('Ym') . '-'
    . date('YmdHis') . '-' . bin2hex(random_bytes(2));
$grossAmount = (int) $tagihan['nominal'];
$email = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $tagihan['username']) . '@siswa.smkbinabangsa.sch.id';

// Midtrans memisahkan VA menjadi company code milik bank dan bagian unik.
// Gunakan VA siswa lengkap (8800 + NIS) sebagai bagian unik sehingga BRI,
// BCA dan CIMB menghasilkan: company code + VA siswa.
$vaSiswa = preg_replace('/\D+/', '', (string) $tagihan['nomor_va']);
if ($vaSiswa === '') {
    transaction_error(422, 'Virtual Account siswa tidak valid.');
}

$customVa = [
    // Batas Midtrans: BCA <= 11, BRI <= 13, CIMB <= 12,
    // VA siswa 8800 + NIS dipertahankan utuh.
    'bca_va' => $vaSiswa,
    'bri_va' => $vaSiswa,
    'cimb_va' => $vaSiswa,
    // BNI hanya menerima maksimal 8 digit; pertahankan 8 digit terakhir.
    'bni_va' => substr($vaSiswa, -8),
    // Permata mensyaratkan tepat 10 digit; tambahkan nol di depan bila perlu.
    'permata_va' => str_pad($vaSiswa, 10, '0', STR_PAD_LEFT),
];

$requestBody = [
    'transaction_details' => [
        'order_id' => $orderId,
        'gross_amount' => $grossAmount,
    ],
    // Hanya kanal Virtual Account. Company code tiap bank ditambahkan
    // Midtrans sesuai konfigurasi merchant.
    'enabled_payments' => [
        'bca_va',
        'bni_va',
        'bri_va',
        'permata_va',
        'cimb_va',
    ],
    'bca_va' => ['va_number' => $customVa['bca_va']],
    'bni_va' => ['va_number' => $customVa['bni_va']],
    'bri_va' => ['va_number' => $customVa['bri_va']],
    'permata_va' => ['va_number' => $customVa['permata_va']],
    'cimb_va' => ['va_number' => $customVa['cimb_va']],
    'expiry' => [
        'start_time' => date('Y-m-d H:i:s O'),
        'unit' => 'hour',
        'duration' => 1,
    ],
    'item_details' => [[
        'id' => 'SPP-' . $tagihan['bulan'] . '-' . $tagihan['tahun'],
        'price' => $grossAmount,
        'quantity' => 1,
        'name' => 'SPP ' . month_name((int) $tagihan['bulan']) . ' ' . $tagihan['tahun'],
    ]],
    'customer_details' => [
        'first_name' => $tagihan['nama'],
        'email' => $email,
    ],
];

$curl = curl_init(midtrans_snap_transaction_url());
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(midtrans_server_key() . ':'),
    ],
    CURLOPT_POSTFIELDS => json_encode($requestBody),
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($curl);
$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
    error_log('Gagal membuat Snap Token: ' . $curlError . ' ' . (string) $response);
    transaction_error(502, 'Midtrans belum dapat membuat transaksi. Silakan coba lagi.');
}

$result = json_decode($response, true);
if (!is_array($result) || empty($result['token'])) {
    transaction_error(502, 'Respons Midtrans tidak memiliki Snap Token.');
}

echo json_encode([
    'ok' => true,
    'token' => $result['token'],
    'order_id' => $orderId,
], JSON_UNESCAPED_UNICODE);
