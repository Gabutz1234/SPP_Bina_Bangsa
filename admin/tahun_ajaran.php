<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/auth.php';
require_role('admin');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_tahun_ajaran'])) {
    $_SESSION['csrf_tahun_ajaran'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_tahun_ajaran'];
$error = '';
$success = (string) ($_GET['success'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
$aksiForm = (string) ($_GET['aksi'] ?? '');
$idEdit = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0;

$form = [
    'id_tahun_ajaran' => '',
    'tahun_ajaran' => '',
    'tanggal_mulai' => '',
    'tanggal_selesai' => '',
    'status' => 'nonaktif',
];

if ($idEdit > 0) {
    $stmt = $pdo->prepare(
        'SELECT id_tahun_ajaran, tahun_ajaran, tanggal_mulai,
                tanggal_selesai, status
         FROM tahun_ajaran
         WHERE id_tahun_ajaran = ?'
    );
    $stmt->execute([$idEdit]);
    $dataEdit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($dataEdit) {
        $form = $dataEdit;
        $aksiForm = 'edit';
    } else {
        $error = 'Data tahun ajaran tidak ditemukan.';
        $idEdit = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Token keamanan tidak valid. Muat ulang halaman.');
        }

        $aksi = (string) ($_POST['aksi'] ?? '');
        $id = filter_var($_POST['id_tahun_ajaran'] ?? null, FILTER_VALIDATE_INT) ?: 0;

        if ($aksi === 'hapus') {
            if ($id < 1) {
                throw new RuntimeException('ID tahun ajaran tidak valid.');
            }

            $stmt = $pdo->prepare(
                'SELECT status FROM tahun_ajaran WHERE id_tahun_ajaran = ?'
            );
            $stmt->execute([$id]);
            $statusTahun = $stmt->fetchColumn();

            if ($statusTahun === 'aktif') {
                throw new RuntimeException(
                    'Tahun ajaran aktif tidak dapat dihapus. Aktifkan periode lain terlebih dahulu.'
                );
            }

            $relasi = [
                'kelas' => 'SELECT COUNT(*) FROM kelas WHERE id_tahun_ajaran = ?',
                'siswa' => 'SELECT COUNT(*) FROM siswa WHERE id_tahun_ajaran = ?',
                'tarif SPP' => 'SELECT COUNT(*) FROM tarif_spp WHERE id_tahun_ajaran = ?',
                'tagihan' => 'SELECT COUNT(*) FROM tagihan_spp WHERE id_tahun_ajaran = ?',
            ];
            $terpakai = [];

            foreach ($relasi as $label => $sql) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id]);
                $jumlah = (int) $stmt->fetchColumn();
                if ($jumlah > 0) {
                    $terpakai[] = $jumlah . ' ' . $label;
                }
            }

            if ($terpakai !== []) {
                throw new RuntimeException(
                    'Tahun ajaran tidak dapat dihapus karena masih digunakan oleh '
                    . implode(', ', $terpakai) . '.'
                );
            }

            $stmt = $pdo->prepare('DELETE FROM tahun_ajaran WHERE id_tahun_ajaran = ?');
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Data tahun ajaran tidak ditemukan.');
            }

            header('Location: tahun_ajaran.php?success=' . urlencode('Tahun ajaran berhasil dihapus.'));
            exit;
        }

        if ($aksi === 'aktifkan') {
            if ($id < 1) {
                throw new RuntimeException('ID tahun ajaran tidak valid.');
            }

            $pdo->beginTransaction();
            $pdo->exec("UPDATE tahun_ajaran SET status = 'nonaktif'");
            $stmt = $pdo->prepare(
                "UPDATE tahun_ajaran SET status = 'aktif' WHERE id_tahun_ajaran = ?"
            );
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Data tahun ajaran tidak ditemukan.');
            }

            $pdo->commit();
            header('Location: tahun_ajaran.php?success=' . urlencode('Tahun ajaran aktif berhasil diperbarui.'));
            exit;
        }

        if (!in_array($aksi, ['tambah', 'edit'], true)) {
            throw new RuntimeException('Aksi tidak dikenal.');
        }

        $tahun = trim((string) ($_POST['tahun_ajaran'] ?? ''));
        $mulai = trim((string) ($_POST['tanggal_mulai'] ?? ''));
        $selesai = trim((string) ($_POST['tanggal_selesai'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'nonaktif');

        $form = [
            'id_tahun_ajaran' => (string) $id,
            'tahun_ajaran' => $tahun,
            'tanggal_mulai' => $mulai,
            'tanggal_selesai' => $selesai,
            'status' => $status,
        ];
        $aksiForm = $aksi;

        if (!preg_match('/^\d{4}\/\d{4}$/', $tahun)) {
            throw new RuntimeException('Format tahun ajaran harus seperti 2026/2027.');
        }

        [$tahunAwal, $tahunAkhir] = array_map('intval', explode('/', $tahun));
        if ($tahunAkhir !== $tahunAwal + 1) {
            throw new RuntimeException('Tahun ajaran harus berurutan, misalnya 2026/2027.');
        }

        $tanggalMulai = DateTime::createFromFormat('!Y-m-d', $mulai);
        $tanggalSelesai = DateTime::createFromFormat('!Y-m-d', $selesai);
        $validMulai = $tanggalMulai && $tanggalMulai->format('Y-m-d') === $mulai;
        $validSelesai = $tanggalSelesai && $tanggalSelesai->format('Y-m-d') === $selesai;

        if (!$validMulai || !$validSelesai || $mulai >= $selesai) {
            throw new RuntimeException('Tanggal mulai dan selesai harus valid serta berurutan.');
        }

        if (!in_array($status, ['aktif', 'nonaktif'], true)) {
            throw new RuntimeException('Status tahun ajaran tidak valid.');
        }

        if ($aksi === 'edit' && $status === 'nonaktif') {
            $stmt = $pdo->prepare(
                'SELECT status FROM tahun_ajaran WHERE id_tahun_ajaran = ?'
            );
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() === 'aktif') {
                throw new RuntimeException(
                    'Tahun ajaran aktif tidak dapat dinonaktifkan tanpa mengaktifkan periode lain.'
                );
            }
        }

        $cek = $pdo->prepare(
            'SELECT id_tahun_ajaran FROM tahun_ajaran
             WHERE tahun_ajaran = ? AND id_tahun_ajaran <> ?'
        );
        $cek->execute([$tahun, $aksi === 'edit' ? $id : 0]);
        if ($cek->fetch()) {
            throw new RuntimeException('Tahun ajaran tersebut sudah terdaftar.');
        }

        $pdo->beginTransaction();

        if ($status === 'aktif') {
            $pdo->exec("UPDATE tahun_ajaran SET status = 'nonaktif'");
        }

        if ($aksi === 'tambah') {
            $stmt = $pdo->prepare(
                'INSERT INTO tahun_ajaran
                 (tahun_ajaran, tanggal_mulai, tanggal_selesai, status)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$tahun, $mulai, $selesai, $status]);
            $pesan = 'Tahun ajaran berhasil ditambahkan.';
        } else {
            $stmt = $pdo->prepare(
                'UPDATE tahun_ajaran
                 SET tahun_ajaran = ?, tanggal_mulai = ?,
                     tanggal_selesai = ?, status = ?
                 WHERE id_tahun_ajaran = ?'
            );
            $stmt->execute([$tahun, $mulai, $selesai, $status, $id]);
            $pesan = 'Tahun ajaran berhasil diperbarui.';
        }

        $pdo->commit();
        header('Location: tahun_ajaran.php?success=' . urlencode($pesan));
        exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception instanceof PDOException && $exception->getCode() === '23000') {
            $error = 'Data tidak dapat disimpan karena masih digunakan atau sudah terdaftar.';
        } else {
            $error = $exception->getMessage();
        }
    }
}

$sql = "
    SELECT
        ta.id_tahun_ajaran,
        ta.tahun_ajaran,
        ta.tanggal_mulai,
        ta.tanggal_selesai,
        ta.status,
        COUNT(DISTINCT k.id_kelas) AS jumlah_kelas,
        COUNT(DISTINCT s.id_siswa) AS jumlah_siswa
    FROM tahun_ajaran ta
    LEFT JOIN kelas k ON k.id_tahun_ajaran = ta.id_tahun_ajaran
    LEFT JOIN siswa s ON s.id_tahun_ajaran = ta.id_tahun_ajaran
";
$params = [];

if ($q !== '') {
    $sql .= ' WHERE ta.tahun_ajaran LIKE :q';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' GROUP BY ta.id_tahun_ajaran, ta.tahun_ajaran,
                   ta.tanggal_mulai, ta.tanggal_selesai, ta.status
          ORDER BY ta.tanggal_mulai DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daftarTahun = $stmt->fetchAll(PDO::FETCH_ASSOC);

function tanggalIndonesia(string $tanggal): string
{
    $time = strtotime($tanggal);
    return $time === false ? '-' : date('d/m/Y', $time);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Data Tahun Ajaran - SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/tahun_ajaran.css">
</head>
<body>
<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="tahun-main">
    <header class="page-heading">
        <h1>Master Data Tahun Ajaran</h1>
        <p>Kelola periode akademik yang digunakan untuk kelas, siswa, dan tagihan SPP.</p>
    </header>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="tahun-card">
        <div class="list-heading">
            <div>
                <h2>Daftar Tahun Ajaran</h2>
                <p><?= count($daftarTahun) ?> periode ditemukan.</p>
            </div>
            <a class="btn btn-primary" href="tahun_ajaran.php?aksi=tambah">+ Tambah Tahun Ajaran</a>
        </div>

        <form class="search-form" method="GET" action="tahun_ajaran.php">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari tahun ajaran...">
            <button class="btn btn-primary" type="submit">Cari</button>
            <?php if ($q !== ''): ?>
                <a class="btn btn-secondary" href="tahun_ajaran.php">Reset</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Tahun Ajaran</th>
                        <th>Mulai</th>
                        <th>Selesai</th>
                        <th>Kelas</th>
                        <th>Siswa</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$daftarTahun): ?>
                    <tr><td colspan="8" class="empty-state">Data tahun ajaran belum tersedia.</td></tr>
                <?php else: ?>
                    <?php foreach ($daftarTahun as $i => $tahun): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= e($tahun['tahun_ajaran']) ?></strong></td>
                            <td><?= e(tanggalIndonesia($tahun['tanggal_mulai'])) ?></td>
                            <td><?= e(tanggalIndonesia($tahun['tanggal_selesai'])) ?></td>
                            <td><?= (int) $tahun['jumlah_kelas'] ?></td>
                            <td><?= (int) $tahun['jumlah_siswa'] ?></td>
                            <td><span class="status <?= $tahun['status'] === 'aktif' ? 'status-active' : 'status-inactive' ?>"><?= e(ucfirst($tahun['status'])) ?></span></td>
                            <td>
                                <div class="action-buttons">
                                    <a class="btn btn-secondary btn-small" href="tahun_ajaran.php?edit=<?= (int) $tahun['id_tahun_ajaran'] ?>">Edit</a>
                                    <?php if ($tahun['status'] !== 'aktif'): ?>
                                        <form method="POST" action="tahun_ajaran.php" onsubmit="return confirm('Jadikan tahun ajaran ini sebagai periode aktif?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="aksi" value="aktifkan">
                                            <input type="hidden" name="id_tahun_ajaran" value="<?= (int) $tahun['id_tahun_ajaran'] ?>">
                                            <button class="btn btn-active btn-small" type="submit">Set Aktif</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="tahun_ajaran.php" onsubmit="return confirm('Hapus tahun ajaran ini secara permanen?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id_tahun_ajaran" value="<?= (int) $tahun['id_tahun_ajaran'] ?>">
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
    </section>
</main>

<?php if ($aksiForm === 'tambah' || $aksiForm === 'edit'): ?>
<div class="modal-shell">
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="judulTahun">
        <div class="modal-heading">
            <div>
                <h2 id="judulTahun"><?= $aksiForm === 'edit' ? 'Edit Tahun Ajaran' : 'Tambah Tahun Ajaran' ?></h2>
                <p>Gunakan format tahun berurutan, misalnya 2026/2027.</p>
            </div>
            <a class="modal-close" href="tahun_ajaran.php" aria-label="Tutup">&times;</a>
        </div>

        <form method="POST" action="tahun_ajaran.php" class="tahun-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="aksi" value="<?= e($aksiForm) ?>">
            <input type="hidden" name="id_tahun_ajaran" value="<?= e($form['id_tahun_ajaran']) ?>">

            <label>Tahun Ajaran
                <input type="text" name="tahun_ajaran" maxlength="9" placeholder="Contoh: 2026/2027" value="<?= e($form['tahun_ajaran']) ?>" required>
            </label>
            <div class="date-grid">
                <label>Tanggal Mulai
                    <input type="date" name="tanggal_mulai" value="<?= e($form['tanggal_mulai']) ?>" required>
                </label>
                <label>Tanggal Selesai
                    <input type="date" name="tanggal_selesai" value="<?= e($form['tanggal_selesai']) ?>" required>
                </label>
            </div>
            <label>Status
                <select name="status" required>
                    <option value="nonaktif" <?= $form['status'] === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                    <option value="aktif" <?= $form['status'] === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                </select>
            </label>
            <p class="form-note">Mengaktifkan periode ini akan menonaktifkan tahun ajaran aktif sebelumnya.</p>
            <div class="form-actions">
                <a class="btn btn-secondary" href="tahun_ajaran.php">Batal</a>
                <button class="btn btn-primary" type="submit">Simpan</button>
            </div>
        </form>
    </section>
</div>
<?php endif; ?>
</body>
</html>
