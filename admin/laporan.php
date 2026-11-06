<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
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

function tanggalIndonesia(?string $tanggal): string
{
    if (!$tanggal) {
        return '-';
    }

    return (new DateTimeImmutable($tanggal))->format('d/m/Y');
}

function bulanLabel(int $bulan, array $bulanNama): string
{
    return $bulanNama[$bulan] ?? '-';
}

$bulanNama = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];

$jenis = (string) ($_GET['jenis'] ?? 'pembayaran');
if (!in_array($jenis, ['pembayaran', 'tunggakan', 'rekap'], true)) {
    $jenis = 'pembayaran';
}

$q = trim((string) ($_GET['q'] ?? ''));
$tanggalMulai = trim((string) ($_GET['tanggal_mulai'] ?? ''));
$tanggalSelesai = trim((string) ($_GET['tanggal_selesai'] ?? ''));
$tahunAjaran = (int) ($_GET['tahun_ajaran'] ?? 0);
$jurusan = (int) ($_GET['jurusan'] ?? 0);
$kelas = (int) ($_GET['kelas'] ?? 0);
$statusPembayaran = (string) ($_GET['status_pembayaran'] ?? '');

$tahunAjaranList = $pdo->query(
    'SELECT id_tahun_ajaran, tahun_ajaran
     FROM tahun_ajaran ORDER BY tanggal_mulai DESC'
)->fetchAll();
$jurusanList = $pdo->query(
    'SELECT id_jurusan, nama_jurusan FROM jurusan ORDER BY nama_jurusan ASC'
)->fetchAll();
$kelasList = $pdo->query(
    'SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC'
)->fetchAll();

$baseConditions = [];
$baseParams = [];

if ($q !== '') {
    $baseConditions[] = '(s.nis LIKE ? OR s.nama LIKE ? OR '
        . 'EXISTS (SELECT 1 FROM transaksi_payment_gateway tq
                   WHERE tq.id_siswa = s.id_siswa
                     AND (tq.order_id LIKE ? OR tq.transaction_id LIKE ?))
                  OR va.nomor_va LIKE ?)';
    $keyword = '%' . $q . '%';
    $baseParams = [$keyword, $keyword, $keyword, $keyword, $keyword];
}
if ($tahunAjaran > 0) {
    $baseConditions[] = 'ts.id_tahun_ajaran = ?';
    $baseParams[] = $tahunAjaran;
}
if ($jurusan > 0) {
    $baseConditions[] = 'k.id_jurusan = ?';
    $baseParams[] = $jurusan;
}
if ($kelas > 0) {
    $baseConditions[] = 's.id_kelas = ?';
    $baseParams[] = $kelas;
}

$data = [];
$summary = [
    'jumlah' => 0,
    'total' => 0,
    'siswa' => 0,
    'lunas' => 0,
    'belum_bayar' => 0,
    'tunggakan' => 0,
    'total_tunggakan' => 0,
];

if ($jenis === 'pembayaran') {
    $conditions = $baseConditions;
    $params = $baseParams;
    if ($tanggalMulai !== '') {
        $conditions[] = 'DATE(p.tanggal_pembayaran) >= ?';
        $params[] = $tanggalMulai;
    }
    if ($tanggalSelesai !== '') {
        $conditions[] = 'DATE(p.tanggal_pembayaran) <= ?';
        $params[] = $tanggalSelesai;
    }
    if (in_array($statusPembayaran, ['LUNAS', 'SEBAGIAN'], true)) {
        $conditions[] = 'p.status = ?';
        $params[] = $statusPembayaran;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $stmt = $pdo->prepare(
        'SELECT p.id_pembayaran, p.tanggal_pembayaran, p.nominal_pembayaran, p.status,
                t.order_id, t.transaction_id, t.payment_channel, t.payment_type,
                s.nis, s.nama, k.nama_kelas, j.nama_jurusan,
                ts.bulan, ts.tahun, ta.tahun_ajaran
         FROM pembayaran p
         INNER JOIN transaksi_payment_gateway t ON t.id_transaksi = p.id_transaksi
         INNER JOIN tagihan_spp ts ON ts.id_tagihan = p.id_tagihan
         INNER JOIN siswa s ON s.id_siswa = ts.id_siswa
         INNER JOIN kelas k ON k.id_kelas = s.id_kelas
         INNER JOIN jurusan j ON j.id_jurusan = k.id_jurusan
         INNER JOIN tahun_ajaran ta ON ta.id_tahun_ajaran = ts.id_tahun_ajaran
         LEFT JOIN virtual_account va ON va.id_siswa = s.id_siswa AND va.status = "aktif"
         ' . $where . '
         ORDER BY p.tanggal_pembayaran DESC, p.id_pembayaran DESC'
    );
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    $summaryConditions = $conditions;
    $summarySql = 'SELECT COUNT(*) AS jumlah,
                          COALESCE(SUM(p.nominal_pembayaran), 0) AS total,
                          COUNT(DISTINCT s.id_siswa) AS siswa
                   FROM pembayaran p
                   INNER JOIN transaksi_payment_gateway t ON t.id_transaksi = p.id_transaksi
                   INNER JOIN tagihan_spp ts ON ts.id_tagihan = p.id_tagihan
                   INNER JOIN siswa s ON s.id_siswa = ts.id_siswa
                   INNER JOIN kelas k ON k.id_kelas = s.id_kelas
                   LEFT JOIN virtual_account va ON va.id_siswa = s.id_siswa AND va.status = "aktif"
                   ' . ($summaryConditions ? 'WHERE ' . implode(' AND ', $summaryConditions) : '');
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($params);
    $summary = array_merge($summary, $summaryStmt->fetch() ?: []);
} elseif ($jenis === 'tunggakan') {
    $conditions = $baseConditions;
    $params = $baseParams;
    $conditions[] = 'ts.status = "TUNGGAKAN"';
    if ($tanggalMulai !== '') {
        $conditions[] = 'ts.tanggal_jatuh_tempo >= ?';
        $params[] = $tanggalMulai;
    }
    if ($tanggalSelesai !== '') {
        $conditions[] = 'ts.tanggal_jatuh_tempo <= ?';
        $params[] = $tanggalSelesai;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $stmt = $pdo->prepare(
        'SELECT ts.id_tagihan, ts.bulan, ts.tahun, ts.nominal,
                ts.tanggal_jatuh_tempo, ts.status,
                s.nis, s.nama, k.nama_kelas, j.nama_jurusan,
                ta.tahun_ajaran, va.nomor_va
         FROM tagihan_spp ts
         INNER JOIN siswa s ON s.id_siswa = ts.id_siswa
         INNER JOIN kelas k ON k.id_kelas = s.id_kelas
         INNER JOIN jurusan j ON j.id_jurusan = k.id_jurusan
         INNER JOIN tahun_ajaran ta ON ta.id_tahun_ajaran = ts.id_tahun_ajaran
         LEFT JOIN virtual_account va ON va.id_siswa = s.id_siswa AND va.status = "aktif"
         ' . $where . '
         ORDER BY ts.tanggal_jatuh_tempo ASC, s.nama ASC'
    );
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    $summaryStmt = $pdo->prepare(
        'SELECT COUNT(*) AS jumlah,
                COALESCE(SUM(ts.nominal), 0) AS total,
                COUNT(DISTINCT s.id_siswa) AS siswa
         FROM tagihan_spp ts
         INNER JOIN siswa s ON s.id_siswa = ts.id_siswa
         INNER JOIN kelas k ON k.id_kelas = s.id_kelas
         LEFT JOIN virtual_account va ON va.id_siswa = s.id_siswa AND va.status = "aktif"
         ' . $where
    );
    $summaryStmt->execute($params);
    $summary = array_merge($summary, $summaryStmt->fetch() ?: []);
} else {
    $conditions = $baseConditions;
    $params = $baseParams;
    if ($tanggalMulai !== '') {
        $conditions[] = 'ts.tanggal_jatuh_tempo >= ?';
        $params[] = $tanggalMulai;
    }
    if ($tanggalSelesai !== '') {
        $conditions[] = 'ts.tanggal_jatuh_tempo <= ?';
        $params[] = $tanggalSelesai;
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $pdo->prepare(
        'SELECT ts.tahun, ts.bulan,
                COUNT(*) AS jumlah_tagihan,
                SUM(ts.status = "LUNAS") AS lunas,
                SUM(ts.status = "BELUM_BAYAR") AS belum_bayar,
                SUM(ts.status = "TUNGGAKAN") AS tunggakan,
                COALESCE(SUM(CASE WHEN p.id_pembayaran IS NOT NULL THEN p.nominal_pembayaran ELSE 0 END), 0) AS total_penerimaan,
                COALESCE(SUM(CASE WHEN ts.status = "TUNGGAKAN" THEN ts.nominal ELSE 0 END), 0) AS total_tunggakan
         FROM tagihan_spp ts
         INNER JOIN siswa s ON s.id_siswa = ts.id_siswa
         INNER JOIN kelas k ON k.id_kelas = s.id_kelas
         INNER JOIN jurusan j ON j.id_jurusan = k.id_jurusan
         INNER JOIN tahun_ajaran ta ON ta.id_tahun_ajaran = ts.id_tahun_ajaran
         LEFT JOIN virtual_account va ON va.id_siswa = s.id_siswa AND va.status = "aktif"
         LEFT JOIN pembayaran p ON p.id_tagihan = ts.id_tagihan
         LEFT JOIN transaksi_payment_gateway t ON t.id_transaksi = p.id_transaksi
         ' . $where . '
         GROUP BY ts.tahun, ts.bulan
         ORDER BY ts.tahun DESC, ts.bulan DESC'
    );
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    foreach ($data as $row) {
        $summary['jumlah'] += (int) $row['jumlah_tagihan'];
        $summary['total'] += (int) $row['total_penerimaan'];
        $summary['lunas'] += (int) $row['lunas'];
        $summary['belum_bayar'] += (int) $row['belum_bayar'];
        $summary['tunggakan'] += (int) $row['tunggakan'];
        $summary['total_tunggakan'] += (int) $row['total_tunggakan'];
    }
}

if (($_GET['format'] ?? '') === 'csv') {
    $labelFile = $jenis === 'pembayaran' ? 'pembayaran' : ($jenis === 'tunggakan' ? 'tunggakan' : 'rekap');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-' . $labelFile . '-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');

    if ($jenis === 'pembayaran') {
        fputcsv($output, ['No', 'Tanggal Pembayaran', 'Order ID', 'Transaction ID', 'NIS', 'Nama', 'Kelas', 'Periode', 'Nominal', 'Metode', 'Status']);
        foreach ($data as $i => $row) {
            fputcsv($output, [$i + 1, $row['tanggal_pembayaran'], $row['order_id'], $row['transaction_id'], $row['nis'], $row['nama'], $row['nama_kelas'], bulanLabel((int) $row['bulan'], $bulanNama) . ' ' . $row['tahun'], $row['nominal_pembayaran'], $row['payment_channel'] ?: $row['payment_type'], $row['status']]);
        }
    } elseif ($jenis === 'tunggakan') {
        fputcsv($output, ['No', 'NIS', 'Nama', 'Kelas', 'Jurusan', 'Periode', 'Jatuh Tempo', 'Nominal', 'Virtual Account', 'Status']);
        foreach ($data as $i => $row) {
            fputcsv($output, [$i + 1, $row['nis'], $row['nama'], $row['nama_kelas'], $row['nama_jurusan'], bulanLabel((int) $row['bulan'], $bulanNama) . ' ' . $row['tahun'], $row['tanggal_jatuh_tempo'], $row['nominal'], $row['nomor_va'] ?: '-', $row['status']]);
        }
    } else {
        fputcsv($output, ['Bulan', 'Tahun', 'Jumlah Tagihan', 'Lunas', 'Belum Bayar', 'Tunggakan', 'Total Penerimaan', 'Total Tunggakan']);
        foreach ($data as $row) {
            fputcsv($output, [$bulanNama[(int) $row['bulan']] ?? '-', $row['tahun'], $row['jumlah_tagihan'], $row['lunas'], $row['belum_bayar'], $row['tunggakan'], $row['total_penerimaan'], $row['total_tunggakan']]);
        }
    }
    fclose($output);
    exit;
}

$queryForExport = $_GET;
$queryForExport['format'] = 'csv';
$urlExport = 'laporan.php?' . http_build_query($queryForExport);
$urlPrint = 'laporan.php?' . http_build_query(array_merge($_GET, ['format' => null]));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan SPP - SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/laporan.css">
</head>
<body>

<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="content">
    <header class="page-heading">
        <div>
            <h1>Laporan Pembayaran SPP</h1>
            <p>Lihat rekap pembayaran dan tunggakan siswa berdasarkan periode tertentu.</p>
        </div>
        <div class="heading-actions">
            <button class="btn btn-secondary" type="button" onclick="window.print()">Cetak</button>
            <a class="btn btn-primary" href="<?= e($urlExport) ?>">Export CSV</a>
        </div>
    </header>

    <nav class="report-tabs" aria-label="Jenis laporan">
        <a class="report-tab <?= $jenis === 'pembayaran' ? 'active' : '' ?>" href="laporan.php?jenis=pembayaran">Laporan Pembayaran</a>
        <a class="report-tab <?= $jenis === 'tunggakan' ? 'active' : '' ?>" href="laporan.php?jenis=tunggakan">Laporan Tunggakan</a>
        <a class="report-tab <?= $jenis === 'rekap' ? 'active' : '' ?>" href="laporan.php?jenis=rekap">Rekap Bulanan</a>
    </nav>

    <section class="filter-panel">
        <form class="filter-form" method="GET" action="laporan.php">
            <input type="hidden" name="jenis" value="<?= e($jenis) ?>">
            <div class="filter-field filter-wide"><label for="q">Pencarian</label><input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="NIS, nama, Order ID, atau Transaction ID..."></div>
            <div class="filter-field"><label for="tanggal_mulai">Dari tanggal</label><input id="tanggal_mulai" type="date" name="tanggal_mulai" value="<?= e($tanggalMulai) ?>"></div>
            <div class="filter-field"><label for="tanggal_selesai">Sampai tanggal</label><input id="tanggal_selesai" type="date" name="tanggal_selesai" value="<?= e($tanggalSelesai) ?>"></div>
            <div class="filter-field"><label for="tahun_ajaran">Tahun ajaran</label><select id="tahun_ajaran" name="tahun_ajaran"><option value="0">Semua periode</option><?php foreach ($tahunAjaranList as $item): ?><option value="<?= (int) $item['id_tahun_ajaran'] ?>" <?= $tahunAjaran === (int) $item['id_tahun_ajaran'] ? 'selected' : '' ?>><?= e($item['tahun_ajaran']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-field"><label for="jurusan">Jurusan</label><select id="jurusan" name="jurusan"><option value="0">Semua jurusan</option><?php foreach ($jurusanList as $item): ?><option value="<?= (int) $item['id_jurusan'] ?>" <?= $jurusan === (int) $item['id_jurusan'] ? 'selected' : '' ?>><?= e($item['nama_jurusan']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-field"><label for="kelas">Kelas</label><select id="kelas" name="kelas"><option value="0">Semua kelas</option><?php foreach ($kelasList as $item): ?><option value="<?= (int) $item['id_kelas'] ?>" <?= $kelas === (int) $item['id_kelas'] ? 'selected' : '' ?>><?= e($item['nama_kelas']) ?></option><?php endforeach; ?></select></div>
            <?php if ($jenis === 'pembayaran'): ?><div class="filter-field"><label for="status_pembayaran">Status</label><select id="status_pembayaran" name="status_pembayaran"><option value="">Semua status</option><option value="LUNAS" <?= $statusPembayaran === 'LUNAS' ? 'selected' : '' ?>>Lunas</option><option value="SEBAGIAN" <?= $statusPembayaran === 'SEBAGIAN' ? 'selected' : '' ?>>Sebagian</option></select></div><?php endif; ?>
            <div class="filter-actions"><button class="btn btn-primary" type="submit">Tampilkan</button><a class="btn btn-secondary" href="laporan.php?jenis=<?= e($jenis) ?>">Reset</a></div>
        </form>
    </section>

    <section class="summary-grid">
        <?php if ($jenis === 'pembayaran'): ?>
            <div class="summary-card"><span>Jumlah Pembayaran</span><strong><?= (int) $summary['jumlah'] ?></strong></div>
            <div class="summary-card summary-success"><span>Total Penerimaan</span><strong><?= e(rupiah($summary['total'])) ?></strong></div>
            <div class="summary-card"><span>Siswa Membayar</span><strong><?= (int) $summary['siswa'] ?></strong></div>
        <?php elseif ($jenis === 'tunggakan'): ?>
            <div class="summary-card summary-danger"><span>Jumlah Tunggakan</span><strong><?= (int) $summary['jumlah'] ?></strong></div>
            <div class="summary-card summary-danger"><span>Total Nominal</span><strong><?= e(rupiah($summary['total'])) ?></strong></div>
            <div class="summary-card"><span>Siswa Menunggak</span><strong><?= (int) $summary['siswa'] ?></strong></div>
        <?php else: ?>
            <div class="summary-card"><span>Total Tagihan</span><strong><?= (int) $summary['jumlah'] ?></strong></div>
            <div class="summary-card summary-success"><span>Total Penerimaan</span><strong><?= e(rupiah($summary['total'])) ?></strong></div>
            <div class="summary-card summary-danger"><span>Total Tunggakan</span><strong><?= e(rupiah($summary['total_tunggakan'])) ?></strong></div>
        <?php endif; ?>
    </section>

    <section class="panel report-panel">
        <div class="panel-body">
            <?php if ($jenis === 'pembayaran'): ?>
                <div class="list-heading"><div><h2>Rincian Pembayaran</h2><p><?= count($data) ?> pembayaran ditemukan.</p></div></div>
                <div class="table-wrap"><table><thead><tr><th>No.</th><th>Tanggal</th><th>Transaksi</th><th>Siswa</th><th>Periode</th><th>Nominal</th><th>Metode</th><th>Status</th></tr></thead><tbody>
                <?php if (!$data): ?><tr><td colspan="8" class="empty-state">Belum ada pembayaran yang sesuai filter.</td></tr><?php else: foreach ($data as $i => $row): ?><tr><td><?= $i + 1 ?></td><td><?= e(tanggalIndonesia($row['tanggal_pembayaran'])) ?></td><td><strong><?= e($row['order_id']) ?></strong><small><?= e($row['transaction_id']) ?></small></td><td><strong><?= e($row['nama']) ?></strong><small>NIS <?= e($row['nis']) ?> · <?= e($row['nama_kelas']) ?></small></td><td><?= e(bulanLabel((int) $row['bulan'], $bulanNama) . ' ' . $row['tahun']) ?></td><td class="nominal-cell"><?= e(rupiah($row['nominal_pembayaran'])) ?></td><td><?= e($row['payment_channel'] ?: $row['payment_type'] ?: '-') ?></td><td><span class="status status-success"><?= e($row['status']) ?></span></td></tr><?php endforeach; endif; ?></tbody></table></div>
            <?php elseif ($jenis === 'tunggakan'): ?>
                <div class="list-heading"><div><h2>Rincian Tunggakan</h2><p><?= count($data) ?> tagihan menunggak ditemukan.</p></div></div>
                <div class="table-wrap"><table><thead><tr><th>No.</th><th>Siswa</th><th>Kelas/Jurusan</th><th>Periode</th><th>Jatuh Tempo</th><th>Nominal</th><th>Virtual Account</th><th>Status</th></tr></thead><tbody>
                <?php if (!$data): ?><tr><td colspan="8" class="empty-state">Belum ada tunggakan yang sesuai filter.</td></tr><?php else: foreach ($data as $i => $row): ?><tr><td><?= $i + 1 ?></td><td><strong><?= e($row['nama']) ?></strong><small>NIS <?= e($row['nis']) ?></small></td><td><strong><?= e($row['nama_kelas']) ?></strong><small><?= e($row['nama_jurusan']) ?></small></td><td><?= e(bulanLabel((int) $row['bulan'], $bulanNama) . ' ' . $row['tahun']) ?></td><td><?= e(tanggalIndonesia($row['tanggal_jatuh_tempo'])) ?></td><td class="nominal-cell"><?= e(rupiah($row['nominal'])) ?></td><td><?= e($row['nomor_va'] ?: '-') ?></td><td><span class="status status-danger">Tunggakan</span></td></tr><?php endforeach; endif; ?></tbody></table></div>
            <?php else: ?>
                <div class="list-heading"><div><h2>Rekap Pembayaran Bulanan</h2><p><?= count($data) ?> periode ditemukan.</p></div></div>
                <div class="table-wrap"><table><thead><tr><th>No.</th><th>Periode</th><th>Total Tagihan</th><th>Lunas</th><th>Belum Bayar</th><th>Tunggakan</th><th>Penerimaan</th><th>Nominal Tunggakan</th></tr></thead><tbody>
                <?php if (!$data): ?><tr><td colspan="8" class="empty-state">Belum ada data rekap yang sesuai filter.</td></tr><?php else: foreach ($data as $i => $row): ?><tr><td><?= $i + 1 ?></td><td><strong><?= e(bulanLabel((int) $row['bulan'], $bulanNama) . ' ' . $row['tahun']) ?></strong></td><td><?= (int) $row['jumlah_tagihan'] ?></td><td><span class="metric-success"><?= (int) $row['lunas'] ?></span></td><td><?= (int) $row['belum_bayar'] ?></td><td><span class="metric-danger"><?= (int) $row['tunggakan'] ?></span></td><td class="nominal-cell"><?= e(rupiah($row['total_penerimaan'])) ?></td><td class="danger-value"><?= e(rupiah($row['total_tunggakan'])) ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>
