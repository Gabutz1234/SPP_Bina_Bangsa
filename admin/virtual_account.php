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

if (empty($_SESSION['csrf_virtual_account'])) {
    $_SESSION['csrf_virtual_account'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_virtual_account'];
$error = '';
$success = isset($_GET['success']) ? (string) $_GET['success'] : '';
$aksiForm = (string) ($_GET['aksi'] ?? '');

$form = [
    'id_siswa' => '',
    'prefix' => '8800',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrf, $token)) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $aksi = (string) ($_POST['aksi'] ?? '');

        try {
            if ($aksi === 'simpan') {
                $idSiswa = (int) ($_POST['id_siswa'] ?? 0);
                $prefix = trim((string) ($_POST['prefix'] ?? ''));
                $form = [
                    'id_siswa' => (string) $idSiswa,
                    'prefix' => $prefix,
                ];
                $aksiForm = 'tambah';

                if ($idSiswa <= 0 || !preg_match('/^\d{1,10}$/', $prefix)) {
                    throw new RuntimeException(
                        'Siswa dan prefix VA wajib diisi dengan angka yang valid.'
                    );
                }

                $stmtSiswa = $pdo->prepare(
                    'SELECT id_siswa, nis, nama, status
                     FROM siswa
                     WHERE id_siswa = ?'
                );
                $stmtSiswa->execute([$idSiswa]);
                $siswa = $stmtSiswa->fetch();

                if (!$siswa) {
                    throw new RuntimeException('Data siswa tidak ditemukan.');
                }

                if ($siswa['status'] !== 'aktif') {
                    throw new RuntimeException(
                        'Virtual Account hanya dapat dibuat untuk siswa aktif.'
                    );
                }

                $nis = (string) $siswa['nis'];
                if (!preg_match('/^\d{5}$/', $nis)) {
                    throw new RuntimeException(
                        'NIS siswa harus terdiri dari 5 digit untuk membuat VA.'
                    );
                }

                $cekVA = $pdo->prepare(
                    'SELECT id_va FROM virtual_account WHERE id_siswa = ? LIMIT 1'
                );
                $cekVA->execute([$idSiswa]);
                if ($cekVA->fetch()) {
                    throw new RuntimeException(
                        'Siswa tersebut sudah memiliki Virtual Account.'
                    );
                }

                $nomorVA = $prefix . $nis;
                $cekNomor = $pdo->prepare(
                    'SELECT id_va FROM virtual_account WHERE nomor_va = ? LIMIT 1'
                );
                $cekNomor->execute([$nomorVA]);
                if ($cekNomor->fetch()) {
                    throw new RuntimeException('Nomor Virtual Account sudah digunakan.');
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO virtual_account
                     (id_siswa, nomor_va, prefix, status)
                     VALUES (?, ?, ?, "aktif")'
                );
                $stmt->execute([$idSiswa, $nomorVA, $prefix]);

                header('Location: virtual_account.php?success=' . urlencode('Virtual Account berhasil dibuat.'));
                exit;
            }

            if ($aksi === 'status') {
                $idVA = (int) ($_POST['id_va'] ?? 0);
                $statusBaru = (string) ($_POST['status'] ?? '');

                if ($idVA <= 0 || !in_array($statusBaru, ['aktif', 'nonaktif'], true)) {
                    throw new RuntimeException('Data status Virtual Account tidak valid.');
                }

                $stmtVA = $pdo->prepare(
                    'SELECT va.id_va, va.id_siswa, va.status,
                            COUNT(tr.id_transaksi) AS jumlah_transaksi
                     FROM virtual_account va
                     LEFT JOIN transaksi_payment_gateway tr
                       ON tr.id_siswa = va.id_siswa
                       OR tr.virtual_account = va.nomor_va
                     WHERE va.id_va = ?
                     GROUP BY va.id_va, va.id_siswa, va.status'
                );
                $stmtVA->execute([$idVA]);
                $dataVA = $stmtVA->fetch();

                if (!$dataVA) {
                    throw new RuntimeException('Virtual Account tidak ditemukan.');
                }

                if ($statusBaru === 'aktif') {
                    $cekAktif = $pdo->prepare(
                        'SELECT id_va FROM virtual_account
                         WHERE id_siswa = ? AND status = "aktif" AND id_va <> ?'
                    );
                    $cekAktif->execute([$dataVA['id_siswa'], $idVA]);
                    if ($cekAktif->fetch()) {
                        throw new RuntimeException(
                            'Siswa tersebut sudah memiliki Virtual Account aktif.'
                        );
                    }
                }

                $stmt = $pdo->prepare(
                    'UPDATE virtual_account SET status = ? WHERE id_va = ?'
                );
                $stmt->execute([$statusBaru, $idVA]);

                $pesan = $statusBaru === 'aktif'
                    ? 'Virtual Account berhasil diaktifkan.'
                    : 'Virtual Account berhasil dinonaktifkan.';
                header('Location: virtual_account.php?success=' . urlencode($pesan));
                exit;
            }

            if ($aksi === 'hapus') {
                $idVA = (int) ($_POST['id_va'] ?? 0);

                if ($idVA <= 0) {
                    throw new RuntimeException('ID Virtual Account tidak valid.');
                }

                $stmtVA = $pdo->prepare(
                    'SELECT nomor_va FROM virtual_account WHERE id_va = ?'
                );
                $stmtVA->execute([$idVA]);
                $dataVA = $stmtVA->fetch();

                if (!$dataVA) {
                    throw new RuntimeException('Virtual Account tidak ditemukan.');
                }

                $cekTransaksi = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM transaksi_payment_gateway
                     WHERE id_siswa = ? OR virtual_account = ?'
                );
                $cekTransaksi->execute([(int) $dataVA['id_siswa'], $dataVA['nomor_va']]);
                $jumlahTransaksi = (int) $cekTransaksi->fetchColumn();

                if ($jumlahTransaksi > 0) {
                    throw new RuntimeException(
                        'Virtual Account tidak dapat dihapus karena sudah memiliki '
                        . $jumlahTransaksi . ' transaksi.'
                    );
                }

                $stmt = $pdo->prepare('DELETE FROM virtual_account WHERE id_va = ?');
                $stmt->execute([$idVA]);

                header('Location: virtual_account.php?success=' . urlencode('Virtual Account berhasil dihapus.'));
                exit;
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'Virtual Account tidak dapat disimpan karena data sudah digunakan.';
            } else {
                error_log($exception->getMessage());
                $error = 'Terjadi kesalahan database.';
            }
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }
}

$siswaList = $pdo->query(
    'SELECT s.id_siswa, s.nis, s.nama, k.nama_kelas, ta.tahun_ajaran
     FROM siswa s
     INNER JOIN kelas k ON k.id_kelas = s.id_kelas
     INNER JOIN tahun_ajaran ta ON ta.id_tahun_ajaran = s.id_tahun_ajaran
     WHERE s.status = "aktif"
       AND NOT EXISTS (
           SELECT 1 FROM virtual_account va0 WHERE va0.id_siswa = s.id_siswa
       )
     ORDER BY s.nama ASC'
)->fetchAll();

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$sql = 'SELECT s.id_siswa, s.nis, s.nama, s.status AS status_siswa,
               k.nama_kelas, ta.tahun_ajaran,
               va.id_va, va.nomor_va, va.prefix, va.status AS status_va,
               va.tanggal_dibuat,
               COUNT(tr.id_transaksi) AS jumlah_transaksi
        FROM siswa s
        INNER JOIN kelas k ON k.id_kelas = s.id_kelas
        INNER JOIN tahun_ajaran ta ON ta.id_tahun_ajaran = s.id_tahun_ajaran
        LEFT JOIN virtual_account va
          ON va.id_va = (
              SELECT MAX(va2.id_va)
              FROM virtual_account va2
              WHERE va2.id_siswa = s.id_siswa
          )
        LEFT JOIN transaksi_payment_gateway tr
          ON tr.id_siswa = va.id_siswa
          OR tr.virtual_account = va.nomor_va';
$params = [];
$conditions = [];

if ($q !== '') {
    $conditions[] = '(s.nis LIKE ? OR s.nama LIKE ? OR va.nomor_va LIKE ?)';
    $keyword = '%' . $q . '%';
    $params = [$keyword, $keyword, $keyword];
}

if (in_array($statusFilter, ['aktif', 'nonaktif', 'belum'], true)) {
    if ($statusFilter === 'belum') {
        $conditions[] = 'va.id_va IS NULL';
    } else {
        $conditions[] = 'va.status = ?';
        $params[] = $statusFilter;
    }
}

if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= ' GROUP BY s.id_siswa, s.nis, s.nama, s.status, k.nama_kelas,
                   ta.tahun_ajaran, va.id_va, va.nomor_va, va.prefix,
                   va.status, va.tanggal_dibuat
          ORDER BY va.id_va IS NULL DESC, s.nama ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daftarVA = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Virtual Account - SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/virtual_account.css">
</head>
<body>

<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="content">
    <header class="page-heading">
        <div>
            <h1>Master Data Virtual Account</h1>
            <p>VA siswa dibuat otomatis saat siswa ditambahkan atau tagihan dibuat. Halaman ini digunakan untuk pengecekan dan perbaikan data.</p>
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
                    <h2>Daftar Virtual Account</h2>
                    <p><?= count($daftarVA) ?> data siswa ditampilkan.</p>
                </div>
                <a class="btn btn-primary" href="virtual_account.php?aksi=tambah">
                    + Buat VA Manual
                </a>
            </div>

            <form class="search-form" method="GET" action="virtual_account.php">
                <input type="search" name="q" value="<?= e($q) ?>"
                       placeholder="Cari NIS, nama siswa, atau nomor VA...">
                <select name="status" aria-label="Filter status VA">
                    <option value="">Semua Status</option>
                    <option value="aktif" <?= $statusFilter === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                    <option value="nonaktif" <?= $statusFilter === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                    <option value="belum" <?= $statusFilter === 'belum' ? 'selected' : '' ?>>Belum Dibuat</option>
                </select>
                <button class="btn btn-primary" type="submit">Cari</button>
                <?php if ($q !== '' || $statusFilter !== ''): ?>
                    <a class="btn btn-secondary" href="virtual_account.php">Reset</a>
                <?php endif; ?>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Siswa</th>
                            <th>Kelas</th>
                            <th>Tahun Ajaran</th>
                            <th>Nomor VA</th>
                            <th>Status</th>
                            <th>Transaksi</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$daftarVA): ?>
                        <tr>
                            <td colspan="8" class="empty-state">Data Virtual Account tidak ditemukan.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftarVA as $i => $data): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= e($data['nama']) ?></strong>
                                    <small>NIS <?= e($data['nis']) ?></small>
                                </td>
                                <td><?= e($data['nama_kelas']) ?></td>
                                <td><?= e($data['tahun_ajaran']) ?></td>
                                <td>
                                    <?php if ($data['nomor_va']): ?>
                                        <button type="button" class="va-copy"
                                                data-va="<?= e($data['nomor_va']) ?>"
                                                title="Salin nomor VA">
                                            <?= e($data['nomor_va']) ?>
                                            <span class="copy-icon">⧉</span>
                                        </button>
                                    <?php else: ?>
                                        <span class="not-created">Belum dibuat</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$data['id_va']): ?>
                                        <span class="status status-pending">Belum Dibuat</span>
                                    <?php else: ?>
                                        <span class="status <?= $data['status_va'] === 'aktif' ? 'status-active' : 'status-inactive' ?>">
                                            <?= e(ucfirst($data['status_va'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $data['jumlah_transaksi'] ?></td>
                                <td>
                                    <?php if (!$data['id_va']): ?>
                                        <a class="btn btn-primary btn-small"
                                           href="virtual_account.php?aksi=tambah&amp;siswa=<?= (int) $data['id_siswa'] ?>">
                                            Generate
                                        </a>
                                    <?php else: ?>
                                        <div class="action-buttons">
                                            <form method="POST" action="virtual_account.php">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="aksi" value="status">
                                                <input type="hidden" name="id_va" value="<?= (int) $data['id_va'] ?>">
                                                <input type="hidden" name="status" value="<?= $data['status_va'] === 'aktif' ? 'nonaktif' : 'aktif' ?>">
                                                <button class="btn <?= $data['status_va'] === 'aktif' ? 'btn-warning' : 'btn-active' ?> btn-small" type="submit">
                                                    <?= $data['status_va'] === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                                </button>
                                            </form>
                                            <?php if ((int) $data['jumlah_transaksi'] === 0): ?>
                                                <form method="POST" action="virtual_account.php"
                                                      onsubmit="return confirm('Hapus Virtual Account ini?');">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                    <input type="hidden" name="aksi" value="hapus">
                                                    <input type="hidden" name="id_va" value="<?= (int) $data['id_va'] ?>">
                                                    <button class="btn btn-danger btn-small" type="submit">Hapus</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
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

<?php if ($aksiForm === 'tambah'): ?>
<?php
$siswaTerpilih = (int) ($_GET['siswa'] ?? $form['id_siswa']);
?>
<div class="modal-shell">
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="judulVA">
        <div class="modal-heading">
            <div>
                <h2 id="judulVA">Generate Virtual Account</h2>
                <p>Nomor VA dibuat otomatis dari prefix dan 5 digit NIS siswa.</p>
            </div>
            <a class="modal-close" href="virtual_account.php" aria-label="Tutup">&times;</a>
        </div>

        <form method="POST" action="virtual_account.php" class="va-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="aksi" value="simpan">

            <label for="id_siswa">Siswa <span>*</span></label>
            <select id="id_siswa" name="id_siswa" required>
                <option value="">Pilih siswa</option>
                <?php foreach ($siswaList as $siswa): ?>
                    <option value="<?= (int) $siswa['id_siswa'] ?>"
                            data-nis="<?= e($siswa['nis']) ?>"
                        <?= $siswaTerpilih === (int) $siswa['id_siswa'] ? 'selected' : '' ?>>
                        <?= e($siswa['nis']) ?> — <?= e($siswa['nama']) ?> (<?= e($siswa['nama_kelas']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$siswaList): ?>
                <p class="form-note form-note-warning">Semua siswa aktif sudah memiliki Virtual Account.</p>
            <?php endif; ?>

            <label for="prefix">Prefix VA <span>*</span></label>
            <input id="prefix" name="prefix" type="text" inputmode="numeric"
                   pattern="[0-9]{1,10}" maxlength="10"
                   value="<?= e($form['prefix']) ?>" required>

            <div class="va-preview" aria-live="polite">
                <span>Nomor VA yang akan dibuat</span>
                <strong id="va-preview-value">Pilih siswa terlebih dahulu</strong>
            </div>
            <p class="form-note">Contoh: prefix 8800 + NIS 12345 menjadi 8800012345.</p>

            <div class="form-actions">
                <a class="btn btn-secondary" href="virtual_account.php">Batal</a>
                <button class="btn btn-primary" type="submit" <?= !$siswaList ? 'disabled' : '' ?>>Generate VA</button>
            </div>
        </form>
    </section>
</div>
<script>
    (function () {
        const siswa = document.getElementById('id_siswa');
        const prefix = document.getElementById('prefix');
        const preview = document.getElementById('va-preview-value');

        function updatePreview() {
            const option = siswa.options[siswa.selectedIndex];
            const nis = option ? option.dataset.nis : '';
            preview.textContent = nis ? (prefix.value || '') + nis : 'Pilih siswa terlebih dahulu';
        }

        siswa.addEventListener('change', updatePreview);
        prefix.addEventListener('input', updatePreview);
        updatePreview();
    }());
</script>
<?php endif; ?>

<script>
    document.querySelectorAll('.va-copy').forEach(function (button) {
        button.addEventListener('click', async function () {
            const value = button.dataset.va;
            try {
                await navigator.clipboard.writeText(value);
                const icon = button.querySelector('.copy-icon');
                icon.textContent = '✓';
                setTimeout(function () { icon.textContent = '⧉'; }, 1400);
            } catch (error) {
                window.prompt('Salin nomor VA berikut:', value);
            }
        });
    });
</script>
</body>
</html>
