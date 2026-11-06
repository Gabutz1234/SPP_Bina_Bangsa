<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/midtrans.php';
require_once __DIR__ . '/../config/auth.php';
require_role('admin');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    !isset($_SESSION['user']['role']) ||
    $_SESSION['user']['role'] !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah(int|string|null $nominal): string
{
    return 'Rp ' . number_format((int) $nominal, 0, ',', '.');
}

$idTransaksi = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT t.*, COALESCE(va.nomor_va, t.virtual_account) AS display_virtual_account,
            s.nis, s.nisn, s.nama, s.no_hp,
            k.nama_kelas, j.nama_jurusan, ta.tahun_ajaran,
            ts.id_tagihan, ts.bulan, ts.tahun, ts.nominal AS nominal_tagihan,
            ts.tanggal_jatuh_tempo, ts.status AS status_tagihan,
            p.id_pembayaran, p.tanggal_pembayaran, p.nominal_pembayaran,
            p.status AS status_pembayaran
     FROM transaksi_payment_gateway t
     LEFT JOIN siswa s ON s.id_siswa = t.id_siswa
     LEFT JOIN virtual_account va ON va.id_siswa = t.id_siswa AND va.status = "aktif"
     LEFT JOIN kelas k ON k.id_kelas = s.id_kelas
     LEFT JOIN jurusan j ON j.id_jurusan = k.id_jurusan
     LEFT JOIN tahun_ajaran ta ON ta.id_tahun_ajaran = s.id_tahun_ajaran
     LEFT JOIN pembayaran p ON p.id_transaksi = t.id_transaksi
     LEFT JOIN tagihan_spp ts ON ts.id_tagihan = p.id_tagihan
     WHERE t.id_transaksi = ?
     LIMIT 1'
);
$stmt->execute([$idTransaksi]);
$transaksi = $stmt->fetch();

if (!$transaksi) {
    http_response_code(404);
    exit('Transaksi tidak ditemukan.');
}

$rawResponse = json_decode((string) $transaksi['raw_response'], true);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Transaksi - SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/transaksi.css">
</head>
<body>

<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="content">
    <div class="detail-topbar">
        <div>
            <a class="back-link" href="transaksi.php">← Kembali ke Transaksi</a>
            <h1>Detail Transaksi</h1>
            <p><?= e($transaksi['order_id']) ?></p>
        </div>
        <span class="status status-large <?= e(midtrans_status_class($transaksi['status_transaksi'])) ?>">
            <?= e(midtrans_status_label($transaksi['status_transaksi'])) ?>
        </span>
    </div>

    <div class="detail-grid">
        <section class="detail-card">
            <h2>Informasi Transaksi</h2>
            <dl>
                <dt>Order ID</dt><dd><?= e($transaksi['order_id']) ?></dd>
                <dt>Transaction ID</dt><dd><?= e($transaksi['transaction_id'] ?: '-') ?></dd>
                <dt>Status Midtrans</dt><dd><?= e($transaksi['status_transaksi']) ?></dd>
                <dt>Status Fraud</dt><dd><?= e($transaksi['fraud_status'] ?: '-') ?></dd>
                <dt>Nominal</dt><dd class="accent-value"><?= e(rupiah($transaksi['nominal'])) ?></dd>
                <dt>Tanggal Transaksi</dt><dd><?= e($transaksi['tanggal_transaksi'] ?: '-') ?></dd>
                <dt>Waktu Transaksi</dt><dd><?= e($transaksi['waktu_transaksi'] ?: '-') ?></dd>
                <dt>Diterima Sistem</dt><dd><?= e($transaksi['created_at']) ?></dd>
            </dl>
        </section>

        <section class="detail-card">
            <h2>Informasi Pembayaran</h2>
            <dl>
                <dt>Payment Type</dt><dd><?= e($transaksi['payment_type'] ?: '-') ?></dd>
                <dt>Payment Channel</dt><dd><?= e($transaksi['payment_channel'] ?: '-') ?></dd>
                <dt>Virtual Account Siswa</dt><dd class="accent-value"><?= e($transaksi['display_virtual_account'] ?: '-') ?></dd>
                <dt>Reference Number</dt><dd><?= e($transaksi['reference_number'] ?: '-') ?></dd>
                <dt>Signature</dt><dd><?= $transaksi['signature_key'] ? 'Terverifikasi' : 'Tidak tersedia' ?></dd>
                <dt>ID Pembayaran</dt><dd><?= $transaksi['id_pembayaran'] ? e($transaksi['id_pembayaran']) : 'Belum dibuat' ?></dd>
                <dt>Status Pembayaran</dt><dd><?= e($transaksi['status_pembayaran'] ?: '-') ?></dd>
            </dl>
        </section>

        <section class="detail-card">
            <h2>Data Siswa</h2>
            <dl>
                <dt>Nama</dt><dd><?= e($transaksi['nama'] ?: '-') ?></dd>
                <dt>NIS</dt><dd><?= e($transaksi['nis'] ?: '-') ?></dd>
                <dt>NISN</dt><dd><?= e($transaksi['nisn'] ?: '-') ?></dd>
                <dt>Kelas</dt><dd><?= e($transaksi['nama_kelas'] ?: '-') ?></dd>
                <dt>Jurusan</dt><dd><?= e($transaksi['nama_jurusan'] ?: '-') ?></dd>
                <dt>Tahun Ajaran</dt><dd><?= e($transaksi['tahun_ajaran'] ?: '-') ?></dd>
            </dl>
        </section>

        <section class="detail-card">
            <h2>Data Tagihan</h2>
            <dl>
                <dt>ID Tagihan</dt><dd><?= $transaksi['id_tagihan'] ? e($transaksi['id_tagihan']) : 'Belum terhubung' ?></dd>
                <dt>Periode</dt><dd><?= $transaksi['bulan'] ? e(str_pad((string) $transaksi['bulan'], 2, '0', STR_PAD_LEFT) . '/' . $transaksi['tahun']) : '-' ?></dd>
                <dt>Nominal Tagihan</dt><dd><?= $transaksi['nominal_tagihan'] ? e(rupiah($transaksi['nominal_tagihan'])) : '-' ?></dd>
                <dt>Jatuh Tempo</dt><dd><?= e($transaksi['tanggal_jatuh_tempo'] ?: '-') ?></dd>
                <dt>Status Tagihan</dt><dd><?= e($transaksi['status_tagihan'] ?: '-') ?></dd>
                <dt>Tanggal Pembayaran</dt><dd><?= e($transaksi['tanggal_pembayaran'] ?: '-') ?></dd>
            </dl>
        </section>
    </div>

    <details class="raw-response">
        <summary>Data mentah dari Midtrans</summary>
        <pre><?= e($rawResponse ? json_encode($rawResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $transaksi['raw_response']) ?></pre>
    </details>
</main>
</body>
</html>
