<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/virtual_account.php';
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

function tanggalIndonesia(?string $tanggal): string
{
    if (!$tanggal) {
        return '-';
    }

    $waktu = new DateTimeImmutable($tanggal);
    return $waktu->format('d/m/Y');
}

if (empty($_SESSION['csrf_tagihan_spp'])) {
    $_SESSION['csrf_tagihan_spp'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_tagihan_spp'];
$error = '';
$success = isset($_GET['success']) ? (string) $_GET['success'] : '';
$resetSuccess = isset($_GET['reset_success']) ? (string) $_GET['reset_success'] : '';
$resetError = isset($_GET['reset_error']) ? (string) $_GET['reset_error'] : '';
$bulanNama = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];

$zonaWaktu = new DateTimeZone('Asia/Jakarta');
$sekarang = new DateTimeImmutable('now', $zonaWaktu);
$bulanBerjalan = (int) $sekarang->format('n');
$tahunBerjalan = (int) $sekarang->format('Y');

// Tagihan yang melewati tanggal jatuh tempo otomatis menjadi tunggakan.
$pdo->exec(
    'UPDATE tagihan_spp
     SET status = "TUNGGAKAN"
     WHERE status = "BELUM_BAYAR"
       AND tanggal_jatuh_tempo < CURDATE()'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrf, $token)) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } elseif (($_POST['aksi'] ?? '') === 'generate') {
        try {
            $pdo->beginTransaction();

            // VA dibuat otomatis sebelum tagihan agar siswa selalu bisa membayar.
            $stmtSiswa = $pdo->query(
                'SELECT DISTINCT s.id_siswa
                 FROM siswa s
                 INNER JOIN tarif_spp t
                    ON t.id_tahun_ajaran = s.id_tahun_ajaran
                   AND t.status = "aktif"
                 WHERE s.status = "aktif"'
            );
            $jumlahVaDibuat = 0;
            while ($idSiswa = $stmtSiswa->fetchColumn()) {
                $hasilVA = ensure_active_virtual_account($pdo, (int) $idSiswa);
                $jumlahVaDibuat += $hasilVA['dibuat'] ? 1 : 0;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO tagihan_spp
                 (id_siswa, id_tarif, id_tahun_ajaran, bulan, tahun, nominal,
                  tanggal_jatuh_tempo, status)
                 SELECT s.id_siswa, t.id_tarif, s.id_tahun_ajaran, ?, ?,
                        t.nominal, LAST_DAY(CONCAT(?, "-01")), "BELUM_BAYAR"
                 FROM siswa s
                 INNER JOIN tarif_spp t
                    ON t.id_tahun_ajaran = s.id_tahun_ajaran
                   AND t.status = "aktif"
                 WHERE s.status = "aktif"
                 ON DUPLICATE KEY UPDATE id_tagihan = id_tagihan'
            );
            $stmt->execute([
                $bulanBerjalan,
                $tahunBerjalan,
                $tahunBerjalan . '-' . str_pad((string) $bulanBerjalan, 2, '0', STR_PAD_LEFT),
            ]);
            $jumlahDibuat = $stmt->rowCount();
            $pdo->commit();

            header(
                'Location: tagihan_spp.php?success=' . urlencode(
                    $jumlahDibuat > 0
                        ? $jumlahDibuat . ' tagihan baru berhasil dibuat untuk '
                            . $bulanNama[$bulanBerjalan] . ' ' . $tahunBerjalan
                            . ($jumlahVaDibuat > 0 ? ' dan ' . $jumlahVaDibuat . ' VA otomatis dibuat.' : '.')
                        : 'Tidak ada tagihan baru. Tagihan bulan berjalan mungkin sudah dibuat atau tarif aktif belum tersedia.'
                )
            );
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Generate tagihan error: ' . $exception->getMessage());
            $error = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Tagihan belum dapat dibuat. Pastikan tarif aktif sudah tersedia.';
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$bulanFilter = (int) ($_GET['bulan'] ?? 0);
$tahunFilter = (int) ($_GET['tahun'] ?? 0);
$kelasFilter = (int) ($_GET['kelas'] ?? 0);
$jurusanFilter = (int) ($_GET['jurusan'] ?? 0);

$kelasList = $pdo->query(
    'SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC'
)->fetchAll();
$jurusanList = $pdo->query(
    'SELECT id_jurusan, nama_jurusan FROM jurusan ORDER BY nama_jurusan ASC'
)->fetchAll();
$tahunList = $pdo->query(
    'SELECT DISTINCT tahun FROM tagihan_spp ORDER BY tahun DESC'
)->fetchAll(PDO::FETCH_COLUMN);

$conditions = [];
$params = [];
if ($q !== '') {
    $conditions[] = '(s.nis LIKE ? OR s.nama LIKE ? OR va.nomor_va LIKE ?)';
    $keyword = '%' . $q . '%';
    $params = [$keyword, $keyword, $keyword];
}
if (in_array($statusFilter, ['BELUM_BAYAR', 'LUNAS', 'TUNGGAKAN'], true)) {
    $conditions[] = 'ts.status = ?';
    $params[] = $statusFilter;
}
if ($bulanFilter >= 1 && $bulanFilter <= 12) {
    $conditions[] = 'ts.bulan = ?';
    $params[] = $bulanFilter;
}
if ($tahunFilter >= 2000 && $tahunFilter <= 2200) {
    $conditions[] = 'ts.tahun = ?';
    $params[] = $tahunFilter;
}
if ($kelasFilter > 0) {
    $conditions[] = 's.id_kelas = ?';
    $params[] = $kelasFilter;
}
if ($jurusanFilter > 0) {
    $conditions[] = 'k.id_jurusan = ?';
    $params[] = $jurusanFilter;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$sql = 'SELECT ts.id_tagihan, ts.bulan, ts.tahun, ts.nominal,
               ts.tanggal_jatuh_tempo, ts.status,
               s.nis, s.nama, s.status AS status_siswa,
               k.nama_kelas, j.nama_jurusan, ta.tahun_ajaran,
               va.nomor_va,
               p.id_pembayaran, p.tanggal_pembayaran,
               tr.id_transaksi
        FROM tagihan_spp ts
        INNER JOIN siswa s ON s.id_siswa = ts.id_siswa
        INNER JOIN kelas k ON k.id_kelas = s.id_kelas
        INNER JOIN jurusan j ON j.id_jurusan = k.id_jurusan
        INNER JOIN tahun_ajaran ta ON ta.id_tahun_ajaran = ts.id_tahun_ajaran
        LEFT JOIN virtual_account va
          ON va.id_siswa = s.id_siswa AND va.status = "aktif"
        LEFT JOIN pembayaran p ON p.id_tagihan = ts.id_tagihan
        LEFT JOIN transaksi_payment_gateway tr ON tr.id_transaksi = p.id_transaksi
        ' . $where . '
        ORDER BY FIELD(ts.status, "TUNGGAKAN", "BELUM_BAYAR", "LUNAS"),
                 ts.tahun DESC, ts.bulan DESC, s.nama ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daftarTagihan = $stmt->fetchAll();

$summarySql = 'SELECT COUNT(*) AS total,
                      SUM(ts.status = "BELUM_BAYAR") AS belum_bayar,
                      SUM(ts.status = "TUNGGAKAN") AS tunggakan,
                      SUM(ts.status = "LUNAS") AS lunas,
                      COALESCE(SUM(CASE WHEN ts.status = "TUNGGAKAN" THEN ts.nominal ELSE 0 END), 0) AS nominal_tunggakan
               FROM tagihan_spp ts
               INNER JOIN siswa s ON s.id_siswa = ts.id_siswa
               INNER JOIN kelas k ON k.id_kelas = s.id_kelas
               INNER JOIN jurusan j ON j.id_jurusan = k.id_jurusan
               LEFT JOIN virtual_account va ON va.id_siswa = s.id_siswa AND va.status = "aktif"
               ' . $where;
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);
$ringkasan = $summaryStmt->fetch() ?: [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tagihan SPP - SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/tagihan_spp.css">
</head>
<body>

<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="content">
    <header class="page-heading">
        <div>
            <h1>Tagihan SPP</h1>
            <p>Lacak tagihan, pembayaran, dan siswa yang masih memiliki tunggakan.</p>
        </div>
        <form method="POST" action="tagihan_spp.php"
              onsubmit="return confirm('Buat tagihan SPP untuk seluruh siswa aktif pada bulan berjalan?');">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="aksi" value="generate">
            <button class="btn btn-primary" type="submit">+ Buat Tagihan Bulan Ini</button>
        </form>
    </header>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($resetSuccess !== ''): ?>
        <div class="alert alert-success"><?= e($resetSuccess) ?></div>
    <?php endif; ?>
    <?php if ($resetError !== ''): ?>
        <div class="alert alert-error"><?= e($resetError) ?></div>
    <?php endif; ?>

    <section class="summary-grid" aria-label="Ringkasan tagihan">
        <div class="summary-card"><span>Total Tagihan</span><strong><?= (int) ($ringkasan['total'] ?? 0) ?></strong></div>
        <div class="summary-card summary-pending"><span>Belum Bayar</span><strong><?= (int) ($ringkasan['belum_bayar'] ?? 0) ?></strong></div>
        <div class="summary-card summary-danger"><span>Tunggakan</span><strong><?= (int) ($ringkasan['tunggakan'] ?? 0) ?></strong></div>
        <div class="summary-card summary-success"><span>Lunas</span><strong><?= (int) ($ringkasan['lunas'] ?? 0) ?></strong></div>
    </section>

    <section class="arrears-callout">
        <div>
            <span class="callout-label">Total nominal tunggakan</span>
            <strong><?= e(rupiah($ringkasan['nominal_tunggakan'] ?? 0)) ?></strong>
        </div>
        <p>Tagihan yang melewati tanggal jatuh tempo akan otomatis masuk status Tunggakan.</p>
    </section>

    <section class="panel">
        <div class="panel-body">
            <div class="list-heading">
                <div>
                    <h2>Daftar Tagihan</h2>
                    <p><?= count($daftarTagihan) ?> tagihan ditampilkan.</p>
                </div>
            </div>

            <form class="filter-form" method="GET" action="tagihan_spp.php">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari NIS, nama, atau nomor VA...">
                <select name="status" aria-label="Filter status">
                    <option value="">Semua Status</option>
                    <option value="TUNGGAKAN" <?= $statusFilter === 'TUNGGAKAN' ? 'selected' : '' ?>>Tunggakan</option>
                    <option value="BELUM_BAYAR" <?= $statusFilter === 'BELUM_BAYAR' ? 'selected' : '' ?>>Belum Bayar</option>
                    <option value="LUNAS" <?= $statusFilter === 'LUNAS' ? 'selected' : '' ?>>Lunas</option>
                </select>
                <select name="bulan" aria-label="Filter bulan">
                    <option value="0">Semua Bulan</option>
                    <?php foreach ($bulanNama as $nomorBulan => $namaBulan): ?>
                        <option value="<?= $nomorBulan ?>" <?= $bulanFilter === $nomorBulan ? 'selected' : '' ?>><?= e($namaBulan) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="tahun" aria-label="Filter tahun">
                    <option value="0">Semua Tahun</option>
                    <?php foreach ($tahunList as $tahun): ?>
                        <option value="<?= (int) $tahun ?>" <?= $tahunFilter === (int) $tahun ? 'selected' : '' ?>><?= (int) $tahun ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="jurusan" aria-label="Filter jurusan">
                    <option value="0">Semua Jurusan</option>
                    <?php foreach ($jurusanList as $jurusan): ?>
                        <option value="<?= (int) $jurusan['id_jurusan'] ?>" <?= $jurusanFilter === (int) $jurusan['id_jurusan'] ? 'selected' : '' ?>><?= e($jurusan['nama_jurusan']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="kelas" aria-label="Filter kelas">
                    <option value="0">Semua Kelas</option>
                    <?php foreach ($kelasList as $kelas): ?>
                        <option value="<?= (int) $kelas['id_kelas'] ?>" <?= $kelasFilter === (int) $kelas['id_kelas'] ? 'selected' : '' ?>><?= e($kelas['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary" type="submit">Cari</button>
                <?php if ($q !== '' || $statusFilter !== '' || $bulanFilter > 0 || $tahunFilter > 0 || $kelasFilter > 0 || $jurusanFilter > 0): ?>
                    <a class="btn btn-secondary" href="tagihan_spp.php">Reset</a>
                <?php endif; ?>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Siswa</th>
                            <th>Periode</th>
                            <th>Nominal</th>
                            <th>Jatuh Tempo</th>
                            <th>Status</th>
                            <th>Pembayaran</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$daftarTagihan): ?>
                        <tr><td colspan="8" class="empty-state">Belum ada data tagihan yang sesuai.</td></tr>
                    <?php else: ?>
                        <?php foreach ($daftarTagihan as $i => $tagihan): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= e($tagihan['nama']) ?></strong>
                                    <small>NIS <?= e($tagihan['nis']) ?> · <?= e($tagihan['nama_kelas']) ?></small>
                                </td>
                                <td>
                                    <strong><?= e($bulanNama[(int) $tagihan['bulan']] ?? '-') ?></strong>
                                    <small><?= e($tagihan['tahun_ajaran']) ?></small>
                                </td>
                                <td class="nominal-cell"><?= e(rupiah($tagihan['nominal'])) ?></td>
                                <td><?= e(tanggalIndonesia($tagihan['tanggal_jatuh_tempo'])) ?></td>
                                <td>
                                    <span class="status status-<?= strtolower(e($tagihan['status'])) ?>">
                                        <?= e(str_replace('_', ' ', $tagihan['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($tagihan['id_pembayaran']): ?>
                                        <span class="paid-text">Sudah dibayar</span>
                                        <small><?= e(tanggalIndonesia(substr((string) $tagihan['tanggal_pembayaran'], 0, 10))) ?></small>
                                    <?php else: ?>
                                        <span class="unpaid-text">Belum ada pembayaran</span>
                                        <?php if ($tagihan['nomor_va']): ?>
                                            <small>VA <?= e($tagihan['nomor_va']) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($tagihan['id_transaksi']): ?>
                                        <a class="btn btn-secondary btn-small" href="detail_transaksi.php?id=<?= (int) $tagihan['id_transaksi'] ?>">Transaksi</a>
                                    <?php else: ?>
                                        <span class="muted-action">Belum ada transaksi</span>
                                    <?php endif; ?>
                                    <?php if ($tagihan['status'] === 'LUNAS' || $tagihan['id_pembayaran'] || $tagihan['id_transaksi']): ?>
                                        <form class="reset-form" method="POST" action="reset_simulasi.php" onsubmit="return confirm('Reset tagihan ini untuk simulasi Sandbox? Data pembayaran dan transaksi test terkait akan dihapus.');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="id_tagihan" value="<?= (int) $tagihan['id_tagihan'] ?>">
                                            <button class="btn btn-reset btn-small" type="submit">Reset Simulasi</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>
</body>
</html>
