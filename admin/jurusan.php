<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/auth.php';
require_role('admin');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Batasi akses hanya untuk admin.
if (
    !isset($_SESSION['user']['role']) ||
    $_SESSION['user']['role'] !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

$tab = $_GET['tab'] ?? 'data';
$allowedTabs = ['data', 'tambah', 'edit', 'hapus'];

if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'data';
}

$error = '';
$success = '';
$kode = '';
$nama = '';
$keterangan = '';
$idEdit = (int) ($_GET['id'] ?? 0);

// CSRF token untuk melindungi form.
if (empty($_SESSION['csrf_jurusan'])) {
    $_SESSION['csrf_jurusan'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_jurusan'];

// Ambil data untuk form edit.
if ($tab === 'edit' && $idEdit > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM jurusan WHERE id_jurusan = ?'
    );
    $stmt->execute([$idEdit]);
    $jurusanEdit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$jurusanEdit) {
        $error = 'Data jurusan tidak ditemukan.';
        $tab = 'data';
    } else {
        $kode = $jurusanEdit['kode_jurusan'];
        $nama = $jurusanEdit['nama_jurusan'];
        $keterangan = $jurusanEdit['keterangan'] ?? '';
    }
}

// Proses tambah, edit, dan hapus.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($csrf, $token)) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $aksi = $_POST['aksi'] ?? '';

        try {
            if ($aksi === 'tambah' || $aksi === 'edit') {
                $kode = strtoupper(trim($_POST['kode_jurusan'] ?? ''));
                $nama = trim($_POST['nama_jurusan'] ?? '');
                $keterangan = trim($_POST['keterangan'] ?? '');

                $id = (int) ($_POST['id_jurusan'] ?? 0);

                if ($kode === '' || $nama === '') {
                    throw new RuntimeException(
                        'Kode dan nama jurusan wajib diisi.'
                    );
                }

                if (strlen($kode) > 20 || strlen($nama) > 100 ||
                    strlen($keterangan) > 255) {
                    throw new RuntimeException(
                        'Panjang salah satu kolom melebihi batas.'
                    );
                }

                $cek = $pdo->prepare(
                    'SELECT id_jurusan FROM jurusan
                     WHERE kode_jurusan = ?
                     AND id_jurusan <> ?'
                );
                $cek->execute([$kode, $aksi === 'edit' ? $id : 0]);

                if ($cek->fetch()) {
                    throw new RuntimeException(
                        'Kode jurusan sudah digunakan.'
                    );
                }

                if ($aksi === 'tambah') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO jurusan
                        (kode_jurusan, nama_jurusan, keterangan)
                        VALUES (?, ?, ?)'
                    );
                    $stmt->execute([
                        $kode,
                        $nama,
                        $keterangan !== '' ? $keterangan : null
                    ]);

                    $pesan = 'Jurusan berhasil ditambahkan.';
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE jurusan
                         SET kode_jurusan = ?,
                             nama_jurusan = ?,
                             keterangan = ?
                         WHERE id_jurusan = ?'
                    );
                    $stmt->execute([
                        $kode,
                        $nama,
                        $keterangan !== '' ? $keterangan : null,
                        $id
                    ]);

                    if ($stmt->rowCount() === 0) {
                        $cekId = $pdo->prepare(
                            'SELECT id_jurusan FROM jurusan
                             WHERE id_jurusan = ?'
                        );
                        $cekId->execute([$id]);

                        if (!$cekId->fetch()) {
                            throw new RuntimeException(
                                'Data jurusan tidak ditemukan.'
                            );
                        }
                    }

                    $pesan = 'Jurusan berhasil diperbarui.';
                }

                header(
                    'Location: jurusan.php?success=' .
                    urlencode($pesan)
                );
                exit;
            }

            if ($aksi === 'hapus') {
                $id = (int) ($_POST['id_jurusan'] ?? 0);

                if ($id <= 0) {
                    throw new RuntimeException(
                        'ID jurusan tidak valid.'
                    );
                }

                $stmt = $pdo->prepare(
                    'DELETE FROM jurusan WHERE id_jurusan = ?'
                );
                $stmt->execute([$id]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException(
                        'Data jurusan tidak ditemukan.'
                    );
                }

                header(
                    'Location: jurusan.php?success=' .
                    urlencode('Jurusan berhasil dihapus.')
                );
                exit;
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'Jurusan tidak dapat dihapus karena masih digunakan oleh data kelas, atau kode jurusan sudah terdaftar.';
            } else {
                error_log($e->getMessage());
                $error = 'Terjadi kesalahan database.';
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    $success = (string) $_GET['success'];
}

// Pencarian.
$q = trim($_GET['q'] ?? '');

$sql = 'SELECT j.id_jurusan, j.kode_jurusan,
               j.nama_jurusan, j.keterangan,
               COUNT(k.id_kelas) AS jumlah_kelas
        FROM jurusan j
        LEFT JOIN kelas k ON k.id_jurusan = j.id_jurusan';

$params = [];

if ($q !== '') {
    $sql .= ' WHERE j.kode_jurusan LIKE ?
              OR j.nama_jurusan LIKE ?
              OR j.keterangan LIKE ?';

    $keyword = '%' . $q . '%';
    $params = [$keyword, $keyword, $keyword];
}

$sql .= ' GROUP BY j.id_jurusan, j.kode_jurusan,
          j.nama_jurusan, j.keterangan
          ORDER BY j.id_jurusan DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daftarJurusan = $stmt->fetchAll(PDO::FETCH_ASSOC);

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Jurusan - SPP Bina Bangsa</title>

    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/jurusan.css">
</head>
<body>

<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="content">
    <div class="page-heading">
        <div>
            <h1>Data Jurusan</h1>
            <p>Kelola data program keahlian SMK Bina Bangsa.</p>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-body">

        <?php if ($tab === 'tambah'): ?>
            <div class="modal-shell">
                <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="judulTambahJurusan">
                    <div class="modal-card-header">
                        <div>
                            <h2 id="judulTambahJurusan">Tambah Jurusan</h2>
                            <p>Tambahkan program keahlian baru.</p>
                        </div>
                        <a class="modal-close" href="jurusan.php" aria-label="Tutup">&times;</a>
                    </div>

        <?php endif; ?>

        <?php if ($tab === 'tambah' || $tab === 'edit'): ?>

            <?php if ($tab === 'edit'): ?>
                <h2>Edit Jurusan</h2>
            <?php endif; ?>

            <form method="POST"
                  action="jurusan.php?tab=<?= e($tab) ?><?= $tab === 'edit' ? '&id=' . $idEdit : '' ?>">

                <input type="hidden" name="csrf_token"
                       value="<?= e($csrf) ?>">
                <input type="hidden" name="aksi"
                       value="<?= e($tab) ?>">

                <?php if ($tab === 'edit'): ?>
                    <input type="hidden" name="id_jurusan"
                           value="<?= $idEdit ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="field">
                        <label for="kode_jurusan">Kode Jurusan *</label>
                        <input id="kode_jurusan"
                               name="kode_jurusan"
                               type="text"
                               maxlength="20"
                               value="<?= e($kode) ?>"
                               placeholder="Contoh: RPL"
                               required>
                    </div>

                    <div class="field">
                        <label for="nama_jurusan">Nama Jurusan *</label>
                        <input id="nama_jurusan"
                               name="nama_jurusan"
                               type="text"
                               maxlength="100"
                               value="<?= e($nama) ?>"
                               placeholder="Contoh: Rekayasa Perangkat Lunak"
                               required>
                    </div>

                    <div class="field field-full">
                        <label for="keterangan">Keterangan</label>
                        <textarea id="keterangan"
                                  name="keterangan"
                                  maxlength="255"
                                  rows="4"
                                  placeholder="Keterangan tambahan (opsional)"><?= e($keterangan) ?></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">
                        <?= $tab === 'tambah' ? 'Simpan Jurusan' : 'Simpan Perubahan' ?>
                    </button>

                    <a class="btn btn-secondary" href="jurusan.php">
                        Batal
                    </a>
                </div>
            </form>

        <?php if ($tab === 'tambah'): ?>
                </section>
            </div>
        <?php endif; ?>

        <?php elseif ($tab === 'hapus'): ?>

            <?php
            $idHapus = (int) ($_GET['id'] ?? 0);
            $stmtHapus = $pdo->prepare(
                'SELECT * FROM jurusan WHERE id_jurusan = ?'
            );
            $stmtHapus->execute([$idHapus]);
            $dataHapus = $stmtHapus->fetch(PDO::FETCH_ASSOC);
            ?>

            <h2>Hapus Jurusan</h2>

            <?php if ($dataHapus): ?>
                <div class="warning">
                    <p>Yakin ingin menghapus jurusan berikut?</p>
                    <strong><?= e($dataHapus['kode_jurusan']) ?> -
                        <?= e($dataHapus['nama_jurusan']) ?></strong>
                    <p>Jurusan yang masih digunakan oleh kelas tidak dapat dihapus.</p>
                </div>

                <form method="POST" action="jurusan.php">
                    <input type="hidden" name="csrf_token"
                           value="<?= e($csrf) ?>">
                    <input type="hidden" name="aksi" value="hapus">
                    <input type="hidden" name="id_jurusan"
                           value="<?= $idHapus ?>">

                    <div class="form-actions">
                        <button class="btn btn-danger" type="submit">
                            Ya, Hapus
                        </button>
                        <a class="btn btn-secondary" href="jurusan.php">
                            Batal
                        </a>
                    </div>
                </form>
            <?php else: ?>
                <p>Data jurusan tidak ditemukan.</p>
                <a class="btn btn-secondary" href="jurusan.php">Kembali</a>
            <?php endif; ?>

        <?php else: ?>

            <div class="list-heading">
                <div>
                    <h2>Daftar Jurusan</h2>
                    <p>Total <?= count($daftarJurusan) ?> jurusan ditemukan.</p>
                </div>

                <a class="btn btn-primary" href="jurusan.php?tab=tambah">
                    + Tambah Jurusan
                </a>
            </div>

            <form class="search-form" method="GET" action="jurusan.php">
                <input type="search" name="q"
                       value="<?= e($q) ?>"
                       placeholder="Cari kode, nama, atau keterangan...">
                <button class="btn btn-primary" type="submit">Cari</button>

                <?php if ($q !== ''): ?>
                    <a class="btn btn-secondary" href="jurusan.php">Reset</a>
                <?php endif; ?>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Kode Jurusan</th>
                            <th>Nama Jurusan</th>
                            <th>Keterangan</th>
                            <th>Jumlah Kelas</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$daftarJurusan): ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                Data jurusan tidak ditemukan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftarJurusan as $i => $j): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= e($j['kode_jurusan']) ?></strong></td>
                                <td><?= e($j['nama_jurusan']) ?></td>
                                <td><?= e($j['keterangan'] ?: '-') ?></td>
                                <td><?= (int) $j['jumlah_kelas'] ?> kelas</td>
                                <td>
                                    <div class="action-buttons">
                                        <a class="btn btn-secondary btn-small"
                                           href="jurusan.php?tab=edit&id=<?= (int) $j['id_jurusan'] ?>">
                                            Edit
                                        </a>
                                        <a class="btn btn-danger btn-small"
                                           href="jurusan.php?tab=hapus&id=<?= (int) $j['id_jurusan'] ?>">
                                            Hapus
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
        </div>
    </section>
</main>

</body>
</html>
