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

function rupiah(int|string $nominal): string
{
    return 'Rp ' . number_format((int) $nominal, 0, ',', '.');
}

if (empty($_SESSION['csrf_tarif_spp'])) {
    $_SESSION['csrf_tarif_spp'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_tarif_spp'];
$error = '';
$success = isset($_GET['success']) ? (string) $_GET['success'] : '';
$aksiForm = (string) ($_GET['aksi'] ?? '');
$idEdit = (int) ($_GET['id'] ?? 0);

$form = [
    'id_tarif' => '',
    'id_tahun_ajaran' => '',
    'nominal' => '',
    'keterangan' => '',
    'status' => 'aktif',
];

if ($aksiForm === 'edit' && $idEdit > 0) {
    $stmt = $pdo->prepare(
        'SELECT id_tarif, id_tahun_ajaran, nominal, keterangan, status
         FROM tarif_spp
         WHERE id_tarif = ?'
    );
    $stmt->execute([$idEdit]);
    $tarifEdit = $stmt->fetch();

    if (!$tarifEdit) {
        $error = 'Data tarif SPP tidak ditemukan.';
        $aksiForm = '';
    } else {
        $form = [
            'id_tarif' => (string) $tarifEdit['id_tarif'],
            'id_tahun_ajaran' => (string) $tarifEdit['id_tahun_ajaran'],
            'nominal' => (string) $tarifEdit['nominal'],
            'keterangan' => (string) ($tarifEdit['keterangan'] ?? ''),
            'status' => (string) $tarifEdit['status'],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrf, $token)) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $aksi = (string) ($_POST['aksi'] ?? '');

        try {
            if ($aksi === 'simpan') {
                $idTarif = (int) ($_POST['id_tarif'] ?? 0);
                $idTahunAjaran = (int) ($_POST['id_tahun_ajaran'] ?? 0);
                $nominal = trim((string) ($_POST['nominal'] ?? ''));
                $keterangan = trim((string) ($_POST['keterangan'] ?? ''));
                $status = (string) ($_POST['status'] ?? '');

                $form = [
                    'id_tarif' => (string) $idTarif,
                    'id_tahun_ajaran' => (string) $idTahunAjaran,
                    'nominal' => $nominal,
                    'keterangan' => $keterangan,
                    'status' => $status,
                ];
                $aksiForm = $idTarif > 0 ? 'edit' : 'tambah';
                $idEdit = $idTarif;

                if ($idTahunAjaran <= 0 || !ctype_digit($nominal) || (int) $nominal <= 0) {
                    throw new RuntimeException(
                        'Tahun ajaran dan nominal SPP wajib diisi dengan benar.'
                    );
                }

                if (!in_array($status, ['aktif', 'nonaktif'], true)) {
                    throw new RuntimeException('Status tarif tidak valid.');
                }

                if (strlen($keterangan) > 100) {
                    throw new RuntimeException('Keterangan maksimal 100 karakter.');
                }

                $cekTahun = $pdo->prepare(
                    'SELECT id_tahun_ajaran FROM tahun_ajaran WHERE id_tahun_ajaran = ?'
                );
                $cekTahun->execute([$idTahunAjaran]);

                if (!$cekTahun->fetch()) {
                    throw new RuntimeException('Tahun ajaran yang dipilih tidak ditemukan.');
                }

                $cekDuplikat = $pdo->prepare(
                    'SELECT id_tarif FROM tarif_spp
                     WHERE id_tahun_ajaran = ? AND id_tarif <> ?'
                );
                $cekDuplikat->execute([$idTahunAjaran, $idTarif]);

                if ($cekDuplikat->fetch()) {
                    throw new RuntimeException(
                        'Tahun ajaran tersebut sudah memiliki tarif SPP.'
                    );
                }

                if ($idTarif > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE tarif_spp
                         SET id_tahun_ajaran = ?, nominal = ?, keterangan = ?, status = ?
                         WHERE id_tarif = ?'
                    );
                    $stmt->execute([
                        $idTahunAjaran,
                        (int) $nominal,
                        $keterangan !== '' ? $keterangan : null,
                        $status,
                        $idTarif,
                    ]);
                    $pesan = 'Tarif SPP berhasil diperbarui.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO tarif_spp
                         (id_tahun_ajaran, nominal, keterangan, status)
                         VALUES (?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $idTahunAjaran,
                        (int) $nominal,
                        $keterangan !== '' ? $keterangan : null,
                        $status,
                    ]);
                    $pesan = 'Tarif SPP berhasil ditambahkan.';
                }

                header('Location: tarif_spp.php?success=' . urlencode($pesan));
                exit;
            }

            if ($aksi === 'hapus') {
                $idTarif = (int) ($_POST['id_tarif'] ?? 0);

                if ($idTarif <= 0) {
                    throw new RuntimeException('ID tarif SPP tidak valid.');
                }

                $cekTagihan = $pdo->prepare(
                    'SELECT COUNT(*) FROM tagihan_spp WHERE id_tarif = ?'
                );
                $cekTagihan->execute([$idTarif]);
                $jumlahTagihan = (int) $cekTagihan->fetchColumn();

                if ($jumlahTagihan > 0) {
                    throw new RuntimeException(
                        'Tarif SPP tidak dapat dihapus karena sudah digunakan oleh '
                        . $jumlahTagihan . ' tagihan siswa.'
                    );
                }

                $stmt = $pdo->prepare('DELETE FROM tarif_spp WHERE id_tarif = ?');
                $stmt->execute([$idTarif]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('Data tarif SPP tidak ditemukan.');
                }

                header('Location: tarif_spp.php?success=' . urlencode('Tarif SPP berhasil dihapus.'));
                exit;
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'Tarif SPP tidak dapat diubah karena masih digunakan oleh data lain.';
            } else {
                error_log($exception->getMessage());
                $error = 'Terjadi kesalahan database.';
            }
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }
}

$tahunAjaranList = $pdo->query(
    "SELECT id_tahun_ajaran, tahun_ajaran, status
     FROM tahun_ajaran
     ORDER BY CASE WHEN status = 'aktif' THEN 0 ELSE 1 END, tanggal_mulai DESC"
)->fetchAll();

$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT t.id_tarif, t.id_tahun_ajaran, t.nominal, t.keterangan, t.status,
               ta.tahun_ajaran,
               ta.status AS status_tahun_ajaran,
               ta.tanggal_mulai,
               COUNT(ts.id_tagihan) AS jumlah_tagihan
        FROM tarif_spp t
        INNER JOIN tahun_ajaran ta ON ta.id_tahun_ajaran = t.id_tahun_ajaran
        LEFT JOIN tagihan_spp ts ON ts.id_tarif = t.id_tarif';
$params = [];

if ($q !== '') {
    $sql .= ' WHERE ta.tahun_ajaran LIKE ?
              OR t.keterangan LIKE ?
              OR t.status LIKE ?';
    $keyword = '%' . $q . '%';
    $params = [$keyword, $keyword, $keyword];
}

$sql .= ' GROUP BY t.id_tarif, t.id_tahun_ajaran, t.nominal, t.keterangan,
                   t.status, ta.tahun_ajaran, ta.status, ta.tanggal_mulai
          ORDER BY CASE WHEN ta.status = "aktif" THEN 0 ELSE 1 END,
                   ta.tanggal_mulai DESC, t.id_tarif DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daftarTarif = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tarif SPP - SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/tarif_spp.css">
</head>
<body>

<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="content">
    <header class="page-heading">
        <div>
            <h1>Master Data Tarif SPP</h1>
            <p>Kelola nominal tarif pembayaran SPP berdasarkan tahun ajaran.</p>
        </div>
    </header>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-body">
            <div class="list-heading">
                <div>
                    <h2>Daftar Tarif SPP</h2>
                    <p><?= count($daftarTarif) ?> tarif ditemukan.</p>
                </div>
                <a class="btn btn-primary" href="tarif_spp.php?aksi=tambah">
                    + Tambah Tarif SPP
                </a>
            </div>

            <form class="search-form" method="GET" action="tarif_spp.php">
                <input type="search" name="q" value="<?= e($q) ?>"
                       placeholder="Cari tahun ajaran, keterangan, atau status...">
                <button class="btn btn-primary" type="submit">Cari</button>
                <?php if ($q !== ''): ?>
                    <a class="btn btn-secondary" href="tarif_spp.php">Reset</a>
                <?php endif; ?>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Tahun Ajaran</th>
                            <th>Nominal</th>
                            <th>Keterangan</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$daftarTarif): ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                Data tarif SPP belum tersedia.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftarTarif as $i => $tarif): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= e($tarif['tahun_ajaran']) ?></strong>
                                    <?php if ($tarif['jumlah_tagihan'] > 0): ?>
                                        <small class="usage-note">
                                            <?= (int) $tarif['jumlah_tagihan'] ?> tagihan
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td class="nominal-cell"><?= e(rupiah($tarif['nominal'])) ?></td>
                                <td><?= e($tarif['keterangan'] ?: '-') ?></td>
                                <td>
                                    <span class="status <?= $tarif['status'] === 'aktif' ? 'status-active' : 'status-inactive' ?>">
                                        <?= e(ucfirst($tarif['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a class="btn btn-secondary btn-small"
                                           href="tarif_spp.php?aksi=edit&amp;id=<?= (int) $tarif['id_tarif'] ?>">
                                            Edit
                                        </a>
                                        <form method="POST" action="tarif_spp.php"
                                              onsubmit="return confirm('Hapus tarif SPP untuk tahun ajaran <?= e($tarif['tahun_ajaran']) ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="aksi" value="hapus">
                                            <input type="hidden" name="id_tarif" value="<?= (int) $tarif['id_tarif'] ?>">
                                            <button class="btn btn-danger btn-small" type="submit">Hapus</button>
                                        </form>
                                    </div>
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

<?php if ($aksiForm === 'tambah' || $aksiForm === 'edit'): ?>
<div class="modal-shell">
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="judulTarif">
        <div class="modal-heading">
            <div>
                <h2 id="judulTarif">
                    <?= $aksiForm === 'edit' ? 'Edit Tarif SPP' : 'Tambah Tarif SPP' ?>
                </h2>
                <p>Tarif ini akan menjadi dasar pembentukan tagihan bulanan siswa.</p>
            </div>
            <a class="modal-close" href="tarif_spp.php" aria-label="Tutup">&times;</a>
        </div>

        <form method="POST" action="tarif_spp.php" class="tarif-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="aksi" value="simpan">
            <input type="hidden" name="id_tarif" value="<?= e($form['id_tarif']) ?>">

            <label for="id_tahun_ajaran">Tahun Ajaran <span>*</span></label>
            <select id="id_tahun_ajaran" name="id_tahun_ajaran" required>
                <option value="">Pilih tahun ajaran</option>
                <?php foreach ($tahunAjaranList as $tahun): ?>
                    <option value="<?= (int) $tahun['id_tahun_ajaran'] ?>"
                        <?= (string) $form['id_tahun_ajaran'] === (string) $tahun['id_tahun_ajaran'] ? 'selected' : '' ?>>
                        <?= e($tahun['tahun_ajaran']) ?><?= $tahun['status'] === 'aktif' ? ' — Aktif' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="nominal">Nominal SPP <span>*</span></label>
            <div class="currency-input">
                <span>Rp</span>
                <input id="nominal" name="nominal" type="number" min="1" step="1"
                       value="<?= e($form['nominal']) ?>"
                       placeholder="250000" required>
            </div>
            <p class="form-note">Masukkan angka tanpa titik atau koma, misalnya 250000.</p>

            <label for="keterangan">Keterangan</label>
            <textarea id="keterangan" name="keterangan" maxlength="100" rows="3"
                      placeholder="Contoh: Tarif reguler kelas X, XI, XII"><?= e($form['keterangan']) ?></textarea>

            <label for="status">Status <span>*</span></label>
            <select id="status" name="status" required>
                <option value="aktif" <?= $form['status'] === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                <option value="nonaktif" <?= $form['status'] === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
            </select>

            <div class="form-actions">
                <a class="btn btn-secondary" href="tarif_spp.php">Batal</a>
                <button class="btn btn-primary" type="submit">
                    <?= $aksiForm === 'edit' ? 'Simpan Perubahan' : 'Simpan Tarif' ?>
                </button>
            </div>
        </form>
    </section>
</div>
<?php endif; ?>

</body>
</html>
