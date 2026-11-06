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

function rupiah(int|string $nominal): string
{
    return 'Rp ' . number_format((int) $nominal, 0, ',', '.');
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$jenis = (string) ($_GET['jenis'] ?? '');
$statusOptions = [
    'pending' => 'Menunggu Pembayaran',
    'berhasil' => 'Berhasil',
    'deny' => 'Ditolak',
    'cancel' => 'Dibatalkan',
    'expire' => 'Kedaluwarsa',
];
$bankOptions = [
    'bca' => 'BCA', 'bni' => 'BNI', 'bri' => 'BRI',
    'permata' => 'Permata', 'cimb' => 'CIMB', 'danamon' => 'Danamon',
];

$conditions = [];
$params = [];

if ($q !== '') {
    $conditions[] = '(t.order_id LIKE ? OR t.transaction_id LIKE ? OR '
        . 't.virtual_account LIKE ? OR s.nama LIKE ? OR s.nis LIKE ?)';
    $keyword = '%' . $q . '%';
    $params = [$keyword, $keyword, $keyword, $keyword, $keyword];
}

if (array_key_exists($status, $statusOptions)) {
    if ($status === 'berhasil') {
        $conditions[] = 't.status_transaksi IN ("capture", "settlement")';
    } else {
        $conditions[] = 't.status_transaksi = ?';
        $params[] = $status;
    }
}

if (array_key_exists($jenis, $bankOptions)) {
    $conditions[] = 'LOWER(t.payment_channel) = ?';
    $params[] = $jenis;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
$countSql = 'SELECT COUNT(*)
             FROM transaksi_payment_gateway t
             LEFT JOIN siswa s ON s.id_siswa = t.id_siswa
             LEFT JOIN virtual_account va ON va.id_siswa = t.id_siswa AND va.status = "aktif"
             ' . $where;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT t.id_transaksi, t.order_id, t.transaction_id,
               COALESCE(va.nomor_va, t.virtual_account) AS virtual_account,
               t.nominal, t.payment_type, t.payment_channel, t.status_transaksi,
               t.fraud_status, t.waktu_transaksi, t.created_at, t.updated_at,
               s.nis, s.nama,
               p.id_pembayaran,
               ts.bulan, ts.tahun
        FROM transaksi_payment_gateway t
        LEFT JOIN siswa s ON s.id_siswa = t.id_siswa
        LEFT JOIN virtual_account va ON va.id_siswa = t.id_siswa AND va.status = "aktif"
        LEFT JOIN pembayaran p ON p.id_transaksi = t.id_transaksi
        LEFT JOIN tagihan_spp ts ON ts.id_tagihan = p.id_tagihan
        ' . $where . '
        ORDER BY t.created_at DESC, t.id_transaksi DESC
        LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daftarTransaksi = $stmt->fetchAll();
foreach ($daftarTransaksi as &$transaksi) {
    midtrans_reconcile_expired_transaction($pdo, $transaksi);
}
unset($transaksi);

$ringkasan = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        SUM(status_transaksi = "pending") AS pending,
        SUM(status_transaksi IN ("capture", "settlement")) AS berhasil,
        SUM(status_transaksi IN ("deny", "cancel", "expire")) AS gagal
     FROM transaksi_payment_gateway'
)->fetch();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaksi Payment Gateway - SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/transaksi.css">
</head>
<body>

<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="content">
    <header class="page-heading">
        <div>
            <h1>Transaksi Payment Gateway</h1>
            <p>Pantau transaksi Virtual Account dan status pembayaran dari Midtrans.</p>
        </div>
    </header>

    <section class="summary-grid" aria-label="Ringkasan transaksi">
        <div class="summary-card"><span>Total Transaksi</span><strong><?= (int) ($ringkasan['total'] ?? 0) ?></strong></div>
        <div class="summary-card summary-pending"><span>Menunggu Pembayaran</span><strong><?= (int) ($ringkasan['pending'] ?? 0) ?></strong></div>
        <div class="summary-card summary-success"><span>Berhasil</span><strong><?= (int) ($ringkasan['berhasil'] ?? 0) ?></strong></div>
        <div class="summary-card summary-danger"><span>Gagal / Kadaluarsa</span><strong><?= (int) ($ringkasan['gagal'] ?? 0) ?></strong></div>
    </section>

    <section class="panel">
        <div class="panel-body">
            <div class="list-heading">
                <div>
                    <h2>Daftar Transaksi</h2>
                    <p><?= $totalRows ?> transaksi ditemukan · halaman <?= $page ?> dari <?= $totalPages ?>.</p>
                </div>
            </div>

            <form class="search-form" method="GET" action="transaksi.php">
                <input type="search" name="q" value="<?= e($q) ?>"
                       placeholder="Cari Order ID, Transaction ID, VA, NIS, atau nama...">
                <select name="jenis" aria-label="Filter jenis transaksi">
                    <option value="">Semua Bank</option>
                    <?php foreach ($bankOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $jenis === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" aria-label="Filter status transaksi">
                    <option value="">Semua Status</option>
                    <?php foreach ($statusOptions as $option => $label): ?>
                        <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary" type="submit">Cari</button>
                <?php if ($q !== '' || $status !== '' || $jenis !== ''): ?>
                    <a class="btn btn-secondary" href="transaksi.php">Reset</a>
                <?php endif; ?>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Transaksi</th>
                            <th>Siswa</th>
                            <th>Virtual Account</th>
                            <th>Nominal</th>
                            <th>Metode</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$daftarTransaksi): ?>
                        <tr><td colspan="8" class="empty-state">Belum ada transaksi yang sesuai.</td></tr>
                    <?php else: ?>
                        <?php foreach ($daftarTransaksi as $i => $trx): ?>
                            <tr>
                                <td><?= $offset + $i + 1 ?></td>
                                <td>
                                    <strong><?= e($trx['order_id']) ?></strong>
                                    <small><?= e($trx['transaction_id'] ?: 'Transaction ID belum tersedia') ?></small>
                                </td>
                                <td>
                                    <strong><?= e($trx['nama'] ?: 'Siswa tidak ditemukan') ?></strong>
                                    <small><?= $trx['nis'] ? 'NIS ' . e($trx['nis']) : '-' ?></small>
                                </td>
                                <td><?= e($trx['virtual_account'] ?: '-') ?></td>
                                <td class="nominal-cell"><?= e(rupiah($trx['nominal'])) ?></td>
                                <td>
                                    <?= e($trx['payment_channel'] ?: $trx['payment_type'] ?: '-') ?>
                                    <?php if ((int) $trx['id_pembayaran'] > 0): ?>
                                        <small class="paid-note">Tagihan terbayar</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status <?= e(midtrans_status_class($trx['status_transaksi'])) ?>">
                                        <?= e(midtrans_status_label($trx['status_transaksi'])) ?>
                                    </span>
                                    <small class="raw-status"><?= e($trx['status_transaksi']) ?></small>
                                </td>
                                <td>
                                    <a class="btn btn-secondary btn-small"
                                       href="detail_transaksi.php?id=<?= (int) $trx['id_transaksi'] ?>">Detail</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Halaman transaksi">
                    <?php $paginationQuery = $_GET; ?>
                    <?php if ($page > 1): ?>
                        <?php $paginationQuery['page'] = $page - 1; ?>
                        <a class="pagination-button" href="transaksi.php?<?= e(http_build_query($paginationQuery)) ?>">‹ Sebelumnya</a>
                    <?php endif; ?>
                    <span class="pagination-current">Halaman <?= $page ?> / <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <?php $paginationQuery['page'] = $page + 1; ?>
                        <a class="pagination-button" href="transaksi.php?<?= e(http_build_query($paginationQuery)) ?>">Berikutnya ›</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>
