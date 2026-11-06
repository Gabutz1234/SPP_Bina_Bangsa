<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/midtrans.php';
require_once __DIR__ . '/../config/auth.php';
require_role('admin');

if (
    !isset($_SESSION['user']) ||
    ($_SESSION['user']['role'] ?? '') !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

$namaAdmin = htmlspecialchars(
    $_SESSION['user']['nama'] ?? 'Admin',
    ENT_QUOTES,
    'UTF-8'
);

function rupiah(int|float $nominal): string
{
    return 'Rp ' . number_format((float) $nominal, 0, ',', '.');
}

function aman(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
}

// Nilai default agar dashboard tetap memiliki struktur data yang konsisten.
$totalSiswa = 0;
$jumlahTagihan = 0;
$totalNominalTagihan = 0;
$jumlahPembayaran = 0;
$totalNominalPembayaran = 0;
$jumlahTunggakan = 0;
$totalNominalTunggakan = 0;
$transaksiHariIni = 0;
$transaksiTerbaru = [];
$grafikPembayaran = [];
$grafikTunggakan = [];
$periodeTagihan = (string) ($_GET['periode_tagihan'] ?? '');
$periodeTagihan = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodeTagihan)
    ? $periodeTagihan
    : '';
$periodeOptions = [];
$namaBulanLengkap = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];

try {
    // 1. Total siswa aktif.
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM siswa
        WHERE status = 'aktif'
    ");
    $totalSiswa = (int) $stmt->fetchColumn();

    // 2. Total tagihan dan nominal seluruh tagihan.
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS jumlah,
            COALESCE(SUM(nominal), 0) AS nominal
        FROM tagihan_spp
    ");
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $jumlahTagihan = (int) $data['jumlah'];
    $totalNominalTagihan = (float) $data['nominal'];

    // 3. Total pembayaran yang sudah tercatat.
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS jumlah,
            COALESCE(SUM(nominal_pembayaran), 0) AS nominal
        FROM pembayaran
    ");
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $jumlahPembayaran = (int) $data['jumlah'];
    $totalNominalPembayaran = (float) $data['nominal'];

    // 4. Total tagihan berstatus tunggakan.
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS jumlah,
            COALESCE(SUM(nominal), 0) AS nominal
        FROM tagihan_spp
        WHERE status = 'TUNGGAKAN'
    ");
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $jumlahTunggakan = (int) $data['jumlah'];
    $totalNominalTunggakan = (float) $data['nominal'];

    // 5. Jumlah transaksi yang tanggal transaksinya hari ini.
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM transaksi_payment_gateway
        WHERE tanggal_transaksi = CURDATE()
    ");
    $transaksiHariIni = (int) $stmt->fetchColumn();

    // 6. Grafik pembayaran selama enam bulan terakhir.
    $stmt = $pdo->query("
        SELECT
            DATE_FORMAT(tanggal_pembayaran, '%Y-%m') AS periode,
            SUM(nominal_pembayaran) AS total
        FROM pembayaran
        WHERE tanggal_pembayaran >=
            DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
          AND tanggal_pembayaran < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)
        GROUP BY DATE_FORMAT(tanggal_pembayaran, '%Y-%m')
        ORDER BY periode ASC
    ");

    $hasilGrafik = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Siapkan enam bulan, termasuk bulan yang belum memiliki pembayaran.
    $labelBulan = [];
    $nilaiPembayaran = [];
    $dataPeriode = [];

    foreach ($hasilGrafik as $baris) {
        $dataPeriode[$baris['periode']] = (float) $baris['total'];
    }

    $namaBulan = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
    ];

    for ($i = 5; $i >= 0; $i--) {
        $tanggal = new DateTime('first day of this month');
        $tanggal->modify("-{$i} months");

        $periode = $tanggal->format('Y-m');
        $labelBulan[] = $namaBulan[(int) $tanggal->format('n')]
            . ' ' . $tanggal->format('Y');
        $nilaiPembayaran[] = $dataPeriode[$periode] ?? 0;
    }

    $grafikPembayaran = [
        'labels' => $labelBulan,
        'data' => $nilaiPembayaran
    ];

    // 7. Grafik jumlah tagihan berdasarkan status dan periode pilihan.
    $stmt = $pdo->query(
        'SELECT DISTINCT tahun, bulan
         FROM tagihan_spp
         ORDER BY tahun DESC, bulan DESC'
    );
    $periodeOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filterStatusSql = '';
    $filterStatusParams = [];
    if ($periodeTagihan !== '') {
        [$tahunFilter, $bulanFilter] = array_map('intval', explode('-', $periodeTagihan));
        $filterStatusSql = 'WHERE tahun = :tahun AND bulan = :bulan';
        $filterStatusParams = [
            'tahun' => $tahunFilter,
            'bulan' => $bulanFilter,
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS jumlah
         FROM tagihan_spp
         {$filterStatusSql}
         GROUP BY status"
    );
    $stmt->execute($filterStatusParams);

    $hasilStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $grafikTunggakan = [
        'labels' => ['Belum Bayar', 'Tunggakan', 'Lunas'],
        'data' => [
            (int) ($hasilStatus['BELUM_BAYAR'] ?? 0),
            (int) ($hasilStatus['TUNGGAKAN'] ?? 0),
            (int) ($hasilStatus['LUNAS'] ?? 0)
        ]
    ];

    // 8. Sepuluh transaksi terbaru.
    $stmt = $pdo->query("
        SELECT
            t.id_transaksi,
            t.order_id,
            COALESCE(va.nomor_va, t.virtual_account) AS virtual_account,
            t.nominal,
            t.payment_type,
            t.payment_channel,
            t.status_transaksi,
            t.tanggal_transaksi,
            t.waktu_transaksi,
            t.created_at,
            t.updated_at,
            s.nama AS nama_siswa
        FROM transaksi_payment_gateway t
        LEFT JOIN siswa s ON s.id_siswa = t.id_siswa
        LEFT JOIN virtual_account va ON va.id_siswa = t.id_siswa AND va.status = 'aktif'
        ORDER BY
            COALESCE(t.waktu_transaksi, t.created_at) DESC,
            t.id_transaksi DESC
        LIMIT 10
    ");

    $transaksiTerbaru = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($transaksiTerbaru as &$transaksi) {
        midtrans_reconcile_expired_transaction($pdo, $transaksi);
    }
    unset($transaksi);

} catch (PDOException $e) {
    error_log('Dashboard SPP: ' . $e->getMessage());
    $errorDatabase = true;
}

function labelStatus(string $status): string
{
    return match ($status) {
        'settlement' => 'Berhasil',
        'capture' => 'Berhasil',
        'pending' => 'Menunggu',
        'deny' => 'Ditolak',
        'cancel' => 'Dibatalkan',
        'expire' => 'Kedaluwarsa',
        default => ucfirst($status)
    };
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard Admin | SPP Bina Bangsa</title>

    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">

    <!-- Chart.js untuk membuat grafik -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="dashboard-main">
    <header class="dashboard-header">
        <div>
            <h1>Dashboard</h1>
            <p>Ringkasan pembayaran SPP SMK Bina Bangsa</p>
        </div>

        <div class="admin-greeting">
            <span>Administrator</span>
            <strong><?= $namaAdmin ?></strong>
        </div>
    </header>

    <?php if (!empty($errorDatabase)): ?>
        <div class="alert-error">
            Data dashboard gagal dimuat. Periksa koneksi database dan log PHP.
        </div>
    <?php endif; ?>

    <section class="stats-grid">
        <article class="stat-card stat-blue">
            <div class="stat-icon">S</div>
            <div>
                <p>Total Siswa Aktif</p>
                <h2><?= number_format($totalSiswa, 0, ',', '.') ?></h2>
                <small>Siswa terdaftar aktif</small>
            </div>
        </article>

        <article class="stat-card stat-purple">
            <div class="stat-icon">T</div>
            <div>
                <p>Total Tagihan</p>
                <h2><?= rupiah($totalNominalTagihan) ?></h2>
                <small><?= number_format($jumlahTagihan, 0, ',', '.') ?> tagihan</small>
            </div>
        </article>

        <article class="stat-card stat-green">
            <div class="stat-icon">P</div>
            <div>
                <p>Total Pembayaran</p>
                <h2><?= rupiah($totalNominalPembayaran) ?></h2>
                <small><?= number_format($jumlahPembayaran, 0, ',', '.') ?> pembayaran</small>
            </div>
        </article>

        <article class="stat-card stat-orange">
            <div class="stat-icon">!</div>
            <div>
                <p>Total Tunggakan</p>
                <h2><?= rupiah($totalNominalTunggakan) ?></h2>
                <small><?= number_format($jumlahTunggakan, 0, ',', '.') ?> tagihan</small>
            </div>
        </article>

        <article class="stat-card stat-teal">
            <div class="stat-icon">H</div>
            <div>
                <p>Transaksi Hari Ini</p>
                <h2><?= number_format($transaksiHariIni, 0, ',', '.') ?></h2>
                <small><?= date('d/m/Y') ?></small>
            </div>
        </article>
    </section>

    <section class="charts-grid">
        <article class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Grafik Pembayaran</h2>
                    <p>Nominal pembayaran enam bulan terakhir</p>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="grafikPembayaran"></canvas>
            </div>
        </article>

        <article class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Grafik Status Tagihan</h2>
                    <p><?= $periodeTagihan !== ''
                        ? 'Periode ' . $namaBulanLengkap[(int) substr($periodeTagihan, 5, 2)]
                            . ' ' . substr($periodeTagihan, 0, 4)
                        : 'Perbandingan jumlah tagihan berdasarkan status' ?></p>
                </div>
                <form class="chart-filter" method="get" action="dashboard.php">
                    <label for="periodeTagihan">Periode</label>
                    <select id="periodeTagihan" name="periode_tagihan" onchange="this.form.submit()">
                        <option value="">Semua bulan</option>
                        <?php foreach ($periodeOptions as $periode): ?>
                            <?php $nilaiPeriode = sprintf(
                                '%04d-%02d',
                                (int) $periode['tahun'],
                                (int) $periode['bulan']
                            ); ?>
                            <option value="<?= aman($nilaiPeriode) ?>"
                                <?= $periodeTagihan === $nilaiPeriode ? 'selected' : '' ?>
                            >
                                <?= aman($namaBulanLengkap[(int) $periode['bulan']]
                                    . ' ' . (int) $periode['tahun']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="chart-container chart-doughnut">
                <canvas id="grafikTunggakan"></canvas>
            </div>
        </article>
    </section>

    <section class="panel transactions-panel">
        <div class="panel-heading">
            <div>
                <h2>Transaksi Terbaru</h2>
                <p>Sepuluh transaksi terakhir dari payment gateway</p>
            </div>
            <a class="panel-link" href="transaksi.php">Lihat semua</a>
        </div>

        <div class="table-wrapper">
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Nama Siswa</th>
                        <th>Nomor VA</th>
                        <th>Tanggal</th>
                        <th>Metode</th>
                        <th>Nominal</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($transaksiTerbaru)): ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            Belum ada data transaksi.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transaksiTerbaru as $trx): ?>
                        <?php
                        $status = (string) $trx['status_transaksi'];
                        $statusClass = match ($status) {
                            'settlement', 'capture' => 'status-success',
                            'pending' => 'status-pending',
                            'deny', 'cancel' => 'status-failed',
                            'expire' => 'status-expired',
                            default => 'status-neutral'
                        };

                        $tanggal = $trx['waktu_transaksi']
                            ?: $trx['tanggal_transaksi']
                            ?: $trx['created_at'];
                        ?>
                        <tr>
                            <td><?= aman($trx['order_id']) ?></td>
                            <td><?= aman($trx['nama_siswa'] ?? 'Siswa tidak tersedia') ?></td>
                            <td><?= aman($trx['virtual_account']) ?></td>
                            <td>
                                <?= $tanggal
                                    ? aman(date('d/m/Y H:i', strtotime($tanggal)))
                                    : '-' ?>
                            </td>
                            <td>
                                <?= aman($trx['payment_channel'] ?: $trx['payment_type'] ?: '-') ?>
                            </td>
                            <td class="amount-cell"><?= rupiah((float) $trx['nominal']) ?></td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>">
                                    <?= aman(labelStatus($status)) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
const formatRupiah = (angka) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0
    }).format(angka);
};

const dataPembayaran = <?= json_encode(
    $grafikPembayaran,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const dataTunggakan = <?= json_encode(
    $grafikTunggakan,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

// Grafik garis pembayaran.
new Chart(document.getElementById('grafikPembayaran'), {
    type: 'line',
    data: {
        labels: dataPembayaran.labels,
        datasets: [{
            label: 'Total Pembayaran',
            data: dataPembayaran.data,
            borderColor: '#3978f6',
            backgroundColor: 'rgba(57, 120, 246, 0.12)',
            borderWidth: 3,
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointHoverRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: (context) => formatRupiah(context.parsed.y)
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: (value) => formatRupiah(value)
                },
                grid: { color: '#edf0f5' }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

// Grafik doughnut status tagihan.
new Chart(document.getElementById('grafikTunggakan'), {
    type: 'doughnut',
    data: {
        labels: dataTunggakan.labels,
        datasets: [{
            data: dataTunggakan.data,
            backgroundColor: ['#f59e0b', '#ef4444', '#16a34a'],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    padding: 18
                }
            }
        }
    }
});
</script>

</body>
</html>
