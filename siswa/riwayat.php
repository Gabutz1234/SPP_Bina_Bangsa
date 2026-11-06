<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/auth.php';
require_role('siswa');

if (($_SESSION['user']['role'] ?? '') !== 'siswa') {
    header('Location: ../index.php');
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
}

function rupiah(int|float $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function bulan(int $month): string
{
    return [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$month] ?? '-';
}

function tanggalIndonesia(?string $date): string
{
    if (!$date || strtotime($date) === false) {
        return '-';
    }

    $time = strtotime($date);
    return date('d', $time) . ' ' . bulan((int) date('n', $time)) . ' ' . date('Y', $time);
}

function statusPembayaran(string $status, bool $hasPayment): array
{
    if ($hasPayment || in_array($status, ['settlement', 'capture'], true)) {
        return ['Lunas', 'status-paid', 'check_circle'];
    }

    return match ($status) {
        'pending' => ['Menunggu Pembayaran', 'status-pending', 'schedule'],
        'deny' => ['Ditolak', 'status-failed', 'cancel'],
        'cancel' => ['Dibatalkan', 'status-failed', 'cancel'],
        'expire' => ['Kedaluwarsa', 'status-failed', 'timer_off'],
        default => ['Belum Diproses', 'status-pending', 'schedule'],
    };
}

$student = null;
$payments = [];
$databaseError = null;
$filterBulan = (int) ($_GET['bulan'] ?? 0);
$filterStatus = (string) ($_GET['status'] ?? '');
if ($filterBulan < 1 || $filterBulan > 12) {
    $filterBulan = 0;
}
$allowedStatuses = ['berhasil', 'pending', 'gagal'];
if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}

try {
    $studentStatement = $pdo->prepare(
        'SELECT s.id_siswa, s.nis, s.nama, k.nama_kelas
         FROM siswa s
         INNER JOIN kelas k ON k.id_kelas = s.id_kelas
         WHERE s.id_user = ?
         LIMIT 1'
    );
    $studentStatement->execute([(int) $_SESSION['user']['id_user']]);
    $student = $studentStatement->fetch();

    if (!$student) {
        throw new RuntimeException('Profil siswa belum terhubung dengan akun.');
    }

    $paymentConditions = ['ts.id_siswa = ?'];
    $paymentParams = [(int) $student['id_siswa']];
    if ($filterBulan > 0) {
        $paymentConditions[] = 'ts.bulan = ?';
        $paymentParams[] = $filterBulan;
    }
    if ($filterStatus === 'berhasil') {
        $paymentConditions[] = '(p.id_pembayaran IS NOT NULL OR tpg.status_transaksi IN ("settlement", "capture"))';
    } elseif ($filterStatus === 'pending') {
        $paymentConditions[] = 'tpg.status_transaksi = "pending"';
    } elseif ($filterStatus === 'gagal') {
        $paymentConditions[] = 'tpg.status_transaksi IN ("deny", "cancel", "expire")';
    }

    $paymentStatement = $pdo->prepare(
        'SELECT
            tpg.id_transaksi,
            COALESCE(va.nomor_va, tpg.virtual_account) AS virtual_account,
            tpg.status_transaksi,
            tpg.payment_channel,
            tpg.waktu_transaksi,
            tpg.tanggal_transaksi,
            tpg.created_at,
            tpg.nominal AS nominal_transaksi,
            p.id_pembayaran,
            p.tanggal_pembayaran,
            p.nominal_pembayaran,
            ts.bulan,
            ts.tahun
         FROM transaksi_payment_gateway tpg
         INNER JOIN tagihan_spp ts
            ON ts.id_tagihan = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(tpg.order_id, "-", 2), "-", -1) AS UNSIGNED)
         LEFT JOIN pembayaran p ON p.id_transaksi = tpg.id_transaksi
         LEFT JOIN virtual_account va ON va.id_siswa = ts.id_siswa AND va.status = "aktif"
         WHERE ' . implode(' AND ', $paymentConditions) . '
         ORDER BY COALESCE(p.tanggal_pembayaran, tpg.waktu_transaksi, tpg.created_at) DESC, tpg.id_transaksi DESC'
    );
    $paymentStatement->execute($paymentParams);
    $payments = $paymentStatement->fetchAll();
} catch (Throwable $exception) {
    error_log('Halaman riwayat pembayaran siswa: ' . $exception->getMessage());
    $databaseError = 'Riwayat pembayaran belum dapat dimuat. Periksa koneksi database.';
}

$totalPaid = array_sum(array_map(
    static fn (array $payment): int => in_array($payment['status_transaksi'], ['settlement', 'capture'], true) || $payment['id_pembayaran'] !== null
        ? (int) ($payment['nominal_pembayaran'] ?? $payment['nominal_transaksi'])
        : 0,
    $payments
));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Pembayaran | SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/siswa/sidebar.css">
    <link rel="stylesheet" href="../assets/css/siswa/riwayat.css">
    <style>
        .history-filters{display:flex;align-items:center;flex-wrap:wrap;justify-content:flex-end;gap:7px}
        .history-filters select{min-height:34px;padding:0 25px 0 9px;border:1px solid #dce3ed;border-radius:8px;background:#fff;color:var(--history-text);font:inherit;font-size:11px}
        .filter-submit,.filter-reset{display:inline-flex;align-items:center;min-height:34px;padding:0 11px;border:1px solid #dce3ed;border-radius:8px;background:#fff;color:#475569;font:600 11px inherit;text-decoration:none;cursor:pointer}
        .filter-submit{border-color:#3b82f6;background:#3b82f6;color:#fff}.filter-submit:hover{background:#2563eb}.filter-reset:hover{background:#f8fafc}
        .history-filters select:focus,.filter-submit:focus-visible,.filter-reset:focus-visible{outline:2px solid #93c5fd;outline-offset:2px}
        @media(max-width:768px){.history-filters{justify-content:flex-start;margin-top:13px}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="dashboard-main history-main">
    <header class="dashboard-header">
        <div>
            <h1>Riwayat Pembayaran</h1>
            <p>Periksa seluruh transaksi pembayaran SPP dan statusnya.</p>
        </div>
        <?php if ($student): ?>
            <div class="student-greeting">
                <span><?= e($student['nama_kelas']) ?> · NIS <?= e($student['nis']) ?></span>
                <strong><?= e($student['nama']) ?></strong>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($databaseError): ?>
        <div class="database-error" role="alert"><?= e($databaseError) ?></div>
    <?php endif; ?>

    <section class="history-overview" aria-label="Ringkasan pembayaran">
        <article class="overview-card overview-card-primary">
            <div class="overview-icon"><span class="material-symbols-rounded">task_alt</span></div>
            <div>
                <span>Transaksi pembayaran</span>
                <strong><?= count($payments) ?></strong>
                <small>tercatat di payment gateway</small>
            </div>
        </article>
        <article class="overview-card">
            <div class="overview-icon green"><span class="material-symbols-rounded">payments</span></div>
            <div>
                <span>Total sudah dibayar</span>
                <strong><?= rupiah($totalPaid) ?></strong>
                <small>seluruh periode</small>
            </div>
        </article>
    </section>

    <section class="panel history-panel">
        <div class="history-heading">
            <div>
                <span class="section-label">Catatan pembayaran</span>
                <h2><?= count($payments) ?> pembayaran ditemukan</h2>
            </div>
            <form class="history-filters" method="get" action="riwayat.php">
                <select name="bulan" aria-label="Filter bulan">
                    <option value="0">Semua bulan</option>
                    <?php for ($nomorBulan = 1; $nomorBulan <= 12; $nomorBulan++): ?>
                        <option value="<?= $nomorBulan ?>" <?= $filterBulan === $nomorBulan ? 'selected' : '' ?>><?= e(bulan($nomorBulan)) ?></option>
                    <?php endfor; ?>
                </select>
                <select name="status" aria-label="Filter status">
                    <option value="">Semua status</option>
                    <option value="berhasil" <?= $filterStatus === 'berhasil' ? 'selected' : '' ?>>Berhasil</option>
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Menunggu</option>
                    <option value="gagal" <?= $filterStatus === 'gagal' ? 'selected' : '' ?>>Gagal / Kedaluwarsa</option>
                </select>
                <button type="submit" class="filter-submit">Terapkan</button>
                <?php if ($filterBulan || $filterStatus !== ''): ?><a href="riwayat.php" class="filter-reset">Reset</a><?php endif; ?>
            </form>
        </div>

        <div class="history-table-wrap">
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Tanggal pembayaran</th>
                        <th>Bulan pembayaran</th>
                        <th>Nominal</th>
                        <th>Nomor VA transaksi</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$payments): ?>
                    <tr>
                        <td colspan="5" class="empty-history">
                            <span class="material-symbols-rounded">receipt_long</span>
                            <strong>Belum ada riwayat pembayaran</strong>
                            <span>Pembayaran yang berhasil akan muncul di halaman ini.</span>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                        $paymentDate = $payment['tanggal_pembayaran'] ?: ($payment['waktu_transaksi'] ?: ($payment['created_at'] ?? $payment['tanggal_transaksi']));
                        [$statusText, $statusClass, $statusIcon] = statusPembayaran((string) $payment['status_transaksi'], $payment['id_pembayaran'] !== null);
                        ?>
                        <tr>
                            <td>
                                <strong><?= e(tanggalIndonesia($paymentDate)) ?></strong>
                                <small><?= $paymentDate && strtotime($paymentDate) !== false ? e(date('H:i', strtotime($paymentDate))) . ' WIB' : '-' ?></small>
                            </td>
                            <td>
                                <strong>SPP <?= e(bulan((int) $payment['bulan'])) ?></strong>
                                <small>Tahun <?= e($payment['tahun']) ?></small>
                            </td>
                            <td class="payment-amount"><?= rupiah((int) ($payment['nominal_pembayaran'] ?? $payment['nominal_transaksi'])) ?></td>
                            <td>
                                <strong class="va-number"><?= e($payment['virtual_account'] ?: 'Belum tersedia') ?></strong>
                                <small><?= e($payment['payment_channel'] ?: 'Virtual Account') ?></small>
                            </td>
                            <td><span class="status-badge <?= e($statusClass) ?>"><span class="material-symbols-rounded"><?= e($statusIcon) ?></span><?= e($statusText) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="history-footnote"><span class="material-symbols-rounded">info</span> Riwayat menampilkan transaksi berhasil, menunggu, dibatalkan, dan kedaluwarsa.</p>
</main>
</body>
</html>
