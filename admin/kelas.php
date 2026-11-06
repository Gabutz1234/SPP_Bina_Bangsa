<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/auth.php';
require_role('admin');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Pastikan hanya admin yang dapat mengakses halaman ini.
if (
    !isset($_SESSION['user']['role']) ||
    $_SESSION['user']['role'] !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

// Fungsi untuk mengamankan output HTML.
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Token CSRF.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$error = '';
$success = '';

$tingkatPilihan = ['X', 'XI', 'XII'];

// Ambil data jurusan dan tahun ajaran untuk dropdown.
$jurusanList = $pdo->query(
    "SELECT id_jurusan, kode_jurusan, nama_jurusan
     FROM jurusan
     ORDER BY kode_jurusan ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$tahunAjaranList = $pdo->query(
    "SELECT id_tahun_ajaran, tahun_ajaran, status
     FROM tahun_ajaran
     ORDER BY tanggal_mulai DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Nilai awal formulir.
$form = [
    'id_kelas' => '',
    'tingkat' => 'X',
    'id_jurusan' => '',
    'nomor_ruangan' => '',
    'id_tahun_ajaran' => '',
];

// Proses formulir.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenDikirim = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $tokenDikirim)) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $aksi = (string) ($_POST['aksi'] ?? '');

        try {
            // TAMBAH / EDIT
            if ($aksi === 'simpan') {
                $idKelas = trim((string) ($_POST['id_kelas'] ?? ''));
                $tingkat = trim((string) ($_POST['tingkat'] ?? ''));
                $idJurusan = (int) ($_POST['id_jurusan'] ?? 0);
                $nomor_ruangan = trim((string) ($_POST['nomor_ruangan'] ?? ''));
                $idTahunAjaran = (int) (
                    $_POST['id_tahun_ajaran'] ?? 0
                );

                $form = [
                    'id_kelas' => $idKelas,
                    'tingkat' => $tingkat,
                    'id_jurusan' => (string) $idJurusan,
                    'nomor_ruangan' => $nomor_ruangan,
                    'id_tahun_ajaran' => (string) $idTahunAjaran,
                ];

                if (!in_array($tingkat, $tingkatPilihan, true)) {
                    throw new RuntimeException('Tingkat kelas tidak valid.');
                }

                if (
                    $idJurusan <= 0 ||
                    $idTahunAjaran <= 0 ||
                    $nomor_ruangan === '' ||
                    strlen($nomor_ruangan) > 20
                ) {
                    throw new RuntimeException(
                        'Tingkat, jurusan, nomor_ruangan, dan tahun ajaran wajib diisi.'
                    );
                }

                // Pastikan jurusan dan tahun ajaran benar-benar tersedia.
                $cekJurusan = $pdo->prepare(
                    "SELECT kode_jurusan
                     FROM jurusan
                     WHERE id_jurusan = ?"
                );
                $cekJurusan->execute([$idJurusan]);
                $jurusan = $cekJurusan->fetch(PDO::FETCH_ASSOC);

                $cekTahun = $pdo->prepare(
                    "SELECT id_tahun_ajaran
                     FROM tahun_ajaran
                     WHERE id_tahun_ajaran = ?"
                );
                $cekTahun->execute([$idTahunAjaran]);

                if (!$jurusan || !$cekTahun->fetch()) {
                    throw new RuntimeException(
                        'Jurusan atau tahun ajaran tidak ditemukan.'
                    );
                }

                // Contoh nama yang dihasilkan: XI RPL 1.
                $namaKelas = $tingkat . ' '
                    . $jurusan['kode_jurusan'] . ' '
                    . $nomor_ruangan;

                if ($idKelas !== '') {
                    $stmt = $pdo->prepare(
                        "UPDATE kelas
                         SET nama_kelas = ?,
                             tingkat = ?,
                             id_jurusan = ?,
                             nomor_ruangan = ?,
                             id_tahun_ajaran = ?
                         WHERE id_kelas = ?"
                    );

                    $stmt->execute([
                        $namaKelas,
                        $tingkat,
                        $idJurusan,
                        $nomor_ruangan,
                        $idTahunAjaran,
                        (int) $idKelas,
                    ]);

                    $_SESSION['flash_success'] =
                        'Data kelas berhasil diperbarui.';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO kelas
                            (nama_kelas, tingkat, id_jurusan,
                             nomor_ruangan, id_tahun_ajaran)
                         VALUES (?, ?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $namaKelas,
                        $tingkat,
                        $idJurusan,
                        $nomor_ruangan,
                        $idTahunAjaran,
                    ]);

                    $_SESSION['flash_success'] =
                        'Data kelas berhasil ditambahkan.';
                }

                header('Location: kelas.php');
                exit;
            }

            // HAPUS
            if ($aksi === 'hapus') {
                $idKelas = (int) ($_POST['id_kelas'] ?? 0);

                if ($idKelas <= 0) {
                    throw new RuntimeException('ID kelas tidak valid.');
                }

                $stmt = $pdo->prepare(
                    "DELETE FROM kelas WHERE id_kelas = ?"
                );
                $stmt->execute([$idKelas]);

                $_SESSION['flash_success'] =
                    'Data kelas berhasil dihapus.';

                header('Location: kelas.php');
                exit;
            }
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $error = 'Nama kelas sudah digunakan atau kelas masih '
                    . 'dipakai oleh data siswa. Periksa kembali datanya.';
            } else {
                $error = 'Terjadi kesalahan database. Periksa konfigurasi '
                    . 'dan struktur tabel kelas.';
            }
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
}

// Pesan sukses setelah redirect.
if (isset($_SESSION['flash_success'])) {
    $success = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Ambil data yang akan diedit.
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' &&
    isset($_GET['edit'])
) {
    $idEdit = (int) $_GET['edit'];

    $stmt = $pdo->prepare(
        "SELECT id_kelas, tingkat, id_jurusan,
                nomor_ruangan, id_tahun_ajaran
         FROM kelas
         WHERE id_kelas = ?"
    );
    $stmt->execute([$idEdit]);
    $dataEdit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($dataEdit) {
        $form = $dataEdit;
    } else {
        $error = 'Data kelas tidak ditemukan.';
    }
}

// Pencarian kelas.
$keyword = trim((string) ($_GET['cari'] ?? ''));

$sql = "
    SELECT
        k.id_kelas,
        k.nama_kelas,
        k.tingkat,
        k.nomor_ruangan,
        k.id_jurusan,
        k.id_tahun_ajaran,
        j.kode_jurusan,
        j.nama_jurusan,
        ta.tahun_ajaran
    FROM kelas k
    INNER JOIN jurusan j
        ON j.id_jurusan = k.id_jurusan
    INNER JOIN tahun_ajaran ta
        ON ta.id_tahun_ajaran = k.id_tahun_ajaran
";

if ($keyword !== '') {
    $sql .= "
        WHERE k.nama_kelas LIKE :cari_kelas
           OR j.nama_jurusan LIKE :cari_jurusan
           OR ta.tahun_ajaran LIKE :cari_tahun
           OR k.nomor_ruangan LIKE :cari_ruangan
    ";
}

$sql .= " ORDER BY k.tingkat ASC, j.kode_jurusan ASC, k.nomor_ruangan ASC";

$stmt = $pdo->prepare($sql);

if ($keyword !== '') {
    $likeKeyword = '%' . $keyword . '%';
    $stmt->execute([
        'cari_kelas' => $likeKeyword,
        'cari_jurusan' => $likeKeyword,
        'cari_tahun' => $likeKeyword,
        'cari_ruangan' => $likeKeyword,
    ]);
} else {
    $stmt->execute();
}

$daftarKelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isEdit = !empty($form['id_kelas']);
$showForm = $isEdit || isset($_GET['tambah']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Kelas - SPP Bina Bangsa</title>

    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/kelas.css">
</head>
<body>

<?php require __DIR__ . '/partials/sidebar.php'; ?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Data Kelas</h1>
            <p>Kelola kelas berdasarkan tingkat, jurusan, dan nomor kelas.</p>
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

    <?php if ($showForm): ?>
    <section class="kelas-form-card <?= $isEdit ? '' : 'modal-form-card' ?>">
        <div class="section-heading">
            <div>
                <h2><?= $isEdit ? 'Edit Kelas' : 'Tambah Kelas' ?></h2>
                <p>Nama kelas dibuat otomatis dari pilihan di bawah.</p>
            </div>
            <?php if (!$isEdit): ?>
                <a href="kelas.php" class="modal-close" aria-label="Tutup">&times;</a>
            <?php endif; ?>
        </div>

        <?php if (count($jurusanList) === 0 || count($tahunAjaranList) === 0): ?>
            <div class="alert alert-error">
                <?php if (count($jurusanList) === 0): ?>
                    Data jurusan masih kosong. Tambahkan jurusan terlebih dahulu.
                <?php endif; ?>

                <?php if (count($tahunAjaranList) === 0): ?>
                    Data tahun ajaran masih kosong. Tambahkan tahun ajaran terlebih dahulu.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form method="POST" action="kelas.php" class="kelas-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="aksi" value="simpan">
                <input type="hidden" name="id_kelas" value="<?= e($form['id_kelas']) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="tingkat">Tingkat</label>
                        <select name="tingkat" id="tingkat" required>
                            <?php foreach ($tingkatPilihan as $tingkat): ?>
                                <option
                                    value="<?= e($tingkat) ?>"
                                    <?= $form['tingkat'] === $tingkat ? 'selected' : '' ?>
                                >
                                    <?= e($tingkat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="id_jurusan">Jurusan</label>
                        <select name="id_jurusan" id="id_jurusan" required>
                            <option value="">Pilih jurusan</option>
                            <?php foreach ($jurusanList as $jurusan): ?>
                                <option
                                    value="<?= e($jurusan['id_jurusan']) ?>"
                                    data-kode="<?= e($jurusan['kode_jurusan']) ?>"
                                    <?= (string) $form['id_jurusan'] ===
                                        (string) $jurusan['id_jurusan']
                                        ? 'selected' : '' ?>
                                >
                                    <?= e($jurusan['kode_jurusan']) ?>
                                    - <?= e($jurusan['nama_jurusan']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                            <label for="nomor_ruangan">Nomor Kelas</label>
                        <input
                            type="text"
                            name="nomor_ruangan"
                            id="nomor_ruangan"
                            maxlength="20"
                            placeholder="Contoh: 1"
                            value="<?= e($form['nomor_ruangan']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="id_tahun_ajaran">Tahun Ajaran</label>
                        <select
                            name="id_tahun_ajaran"
                            id="id_tahun_ajaran"
                            required
                        >
                            <option value="">Pilih tahun ajaran</option>
                            <?php foreach ($tahunAjaranList as $ta): ?>
                                <option
                                    value="<?= e($ta['id_tahun_ajaran']) ?>"
                                    <?= (string) $form['id_tahun_ajaran'] ===
                                        (string) $ta['id_tahun_ajaran']
                                        ? 'selected' : '' ?>
                                >
                                    <?= e($ta['tahun_ajaran']) ?>
                                    <?= $ta['status'] === 'aktif' ? '(Aktif)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="preview-kelas">
                    <span>Pratinjau nama kelas</span>
                    <strong id="namaKelasPreview">Pilih tingkat, jurusan, dan nomor_ruangan</strong>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">
                        <?= $isEdit ? 'Simpan Perubahan' : 'Tambah Kelas' ?>
                    </button>

                    <?php if ($isEdit): ?>
                        <a href="kelas.php" class="btn-secondary">Batal</a>
                    <?php else: ?>
                        <button type="reset" class="btn-secondary" id="btnReset">
                            Reset
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="kelas-table-card">
        <div class="section-heading">
            <div>
                <h2>Daftar Kelas</h2>
                <p>Total <?= count($daftarKelas) ?> kelas ditemukan.</p>
            </div>

            <a href="kelas.php?tambah=1" class="btn btn-primary">+ Tambah Kelas</a>
        </div>

        <form method="GET" action="kelas.php" class="search-form">
            <input
                type="search"
                name="cari"
                placeholder="Cari kelas atau jurusan..."
                value="<?= e($keyword) ?>"
            >
            <button type="submit" class="btn-primary">Cari</button>

            <?php if ($keyword !== ''): ?>
                <a href="kelas.php" class="btn-secondary">Reset</a>
            <?php endif; ?>
        </form>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Kelas</th>
                        <th>Tingkat</th>
                        <th>Jurusan</th>
                        <th>Nomor Kelas</th>
                        <th>Tahun Ajaran</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($daftarKelas) === 0): ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                Belum ada data kelas.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftarKelas as $index => $kelas): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <strong><?= e($kelas['nama_kelas']) ?></strong>
                                </td>
                                <td><?= e($kelas['tingkat']) ?></td>
                                <td>
                                    <?= e($kelas['kode_jurusan']) ?>
                                    - <?= e($kelas['nama_jurusan']) ?>
                                </td>
                                <td><?= e($kelas['nomor_ruangan']) ?></td>
                                <td><?= e($kelas['tahun_ajaran']) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a
                                            href="kelas.php?edit=<?= (int) $kelas['id_kelas'] ?>"
                                            class="btn-edit"
                                        >
                                            Edit
                                        </a>

                                        <form
                                            method="POST"
                                            action="kelas.php"
                                            onsubmit="return confirm('Yakin ingin menghapus kelas ini?');"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrfToken) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="aksi"
                                                value="hapus"
                                            >
                                            <input
                                                type="hidden"
                                                name="id_kelas"
                                                value="<?= (int) $kelas['id_kelas'] ?>"
                                            >
                                            <button type="submit" class="btn-delete">
                                                Hapus
                                            </button>
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

<script>
const tingkat = document.getElementById('tingkat');
const jurusan = document.getElementById('id_jurusan');
const nomor_ruangan = document.getElementById('nomor_ruangan');
const preview = document.getElementById('namaKelasPreview');

function perbaruiPreview() {
    if (!tingkat || !jurusan || !nomor_ruangan || !preview) return;

    const option = jurusan.options[jurusan.selectedIndex];
    const kode = option ? option.dataset.kode : '';
    const nomornomor_ruangan = nomor_ruangan.value.trim();

    if (!tingkat.value || !kode || !nomornomor_ruangan) {
        preview.textContent =
            'Pilih tingkat, jurusan, dan nomor_ruangan';
        return;
    }

    preview.textContent =
        `${tingkat.value} ${kode} ${nomornomor_ruangan}`;
}

[tingkat, jurusan, nomor_ruangan].forEach((element) => {
    if (element) {
        element.addEventListener('input', perbaruiPreview);
        element.addEventListener('change', perbaruiPreview);
    }
});

const btnReset = document.getElementById('btnReset');

if (btnReset) {
    btnReset.addEventListener('click', () => {
        setTimeout(perbaruiPreview, 0);
    });
}

perbaruiPreview();
</script>

</body>
</html>
