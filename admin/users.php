<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/virtual_account.php';
require_role('admin');

$db = db();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================
   CEK AKSES ADMIN
========================================= */

$userSession = $_SESSION['user'] ?? [];

if (($userSession['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Akses ditolak. Halaman ini hanya untuk admin.');
}

/* =========================================
   HELPER
========================================= */

if (empty($_SESSION['csrf_users'])) {
    $_SESSION['csrf_users'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_users'];
$tanggalHariIni = (new DateTimeImmutable(
    'now',
    new DateTimeZone('Asia/Jakarta')
))->format('Y-m-d');

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function validTab(string $tab): string
{
    $allowed = [
        'siswa',
        'kepala_sekolah',
        'teller',
        'admin'
    ];

    return in_array($tab, $allowed, true)
        ? $tab
        : 'siswa';
}

function redirectUsers(
    string $tab,
    string $message = '',
    string $type = 'success'
): never {
    $url = 'users.php?tab=' . urlencode($tab);

    if ($message !== '') {
        $url .= '&msg=' . urlencode($message);
        $url .= '&type=' . urlencode($type);
    }

    header('Location: ' . $url);
    exit;
}

/* =========================================
   TAB AKTIF
========================================= */

$tab = validTab((string)($_GET['tab'] ?? 'siswa'));
$error = '';

$tabs = [
    'siswa' => 'Siswa',
    'kepala_sekolah' => 'Kepala Sekolah',
    'teller' => 'Teller',
    'admin' => 'Admin'
];

/* =========================================
   PROSES FORM
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTab = validTab((string)($_POST['tab'] ?? 'siswa'));

    try {
        if (!hash_equals(
            $csrf,
            (string)($_POST['csrf'] ?? '')
        )) {
            throw new RuntimeException(
                'Token keamanan tidak valid. Muat ulang halaman.'
            );
        }

        $action = (string)($_POST['action'] ?? '');

        $idUser = filter_var(
            $_POST['id_user'] ?? null,
            FILTER_VALIDATE_INT
        );

        $idUser = $idUser ?: 0;

        /* HAPUS AKUN PERMANEN */

        if ($action === 'delete') {
            if (!$idUser) {
                throw new RuntimeException('ID pengguna tidak valid.');
            }

            if (
                $idUser ===
                (int)($userSession['id_user'] ?? 0)
            ) {
                throw new RuntimeException(
                    'Kamu tidak dapat menghapus akun sendiri.'
                );
            }

            $db->beginTransaction();

            // Jika akun adalah siswa, cari profil siswa yang terhubung.
            $idSiswa = 0;
            if ($postedTab === 'siswa') {
                $stmt = $db->prepare(
                    'SELECT id_siswa FROM siswa WHERE id_user = :id LIMIT 1'
                );
                $stmt->execute(['id' => $idUser]);
                $idSiswa = (int) ($stmt->fetchColumn() ?: 0);

                if ($idSiswa > 0) {
                    $relasi = [
                        'virtual_account' => 'SELECT COUNT(*) FROM virtual_account WHERE id_siswa = :id',
                        'tagihan' => 'SELECT COUNT(*) FROM tagihan_spp WHERE id_siswa = :id',
                        'transaksi' => 'SELECT COUNT(*) FROM transaksi_payment_gateway WHERE id_siswa = :id'
                    ];
                    $jumlahRelasi = [];

                    foreach ($relasi as $namaRelasi => $sqlRelasi) {
                        $stmt = $db->prepare($sqlRelasi);
                        $stmt->execute(['id' => $idSiswa]);
                        $jumlah = (int) $stmt->fetchColumn();
                        if ($jumlah > 0) {
                            $jumlahRelasi[] = $jumlah . ' ' . $namaRelasi;
                        }
                    }

                    if ($jumlahRelasi !== []) {
                        throw new RuntimeException(
                            'Akun tidak dapat dihapus karena profil siswa masih memiliki '
                            . implode(', ', $jumlahRelasi)
                            . '. Hapus data terkait terlebih dahulu jika memang diperlukan.'
                        );
                    }

                    // Tidak ada data keuangan, sehingga profil siswa aman dihapus.
                    $stmt = $db->prepare('DELETE FROM siswa WHERE id_siswa = :id');
                    $stmt->execute(['id' => $idSiswa]);
                }
            }

            $stmt = $db->prepare(
                "DELETE FROM users
                 WHERE id_user = :id
                   AND role = :role"
            );
            $stmt->execute([
                'id' => $idUser,
                'role' => $postedTab
            ]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException(
                    'Akun tidak ditemukan atau role akun tidak sesuai.'
                );
            }

            $db->commit();

            redirectUsers(
                $postedTab,
                $postedTab === 'siswa'
                    ? 'Akun dan data siswa berhasil dihapus permanen.'
                    : 'Akun berhasil dihapus permanen.'
            );
        }

        /* DATA AKUN */

        $nama = trim((string)($_POST['nama'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $status = (string)($_POST['status'] ?? 'aktif');

        if ($nama === '' || $username === '') {
            throw new RuntimeException(
                'Nama dan username wajib diisi.'
            );
        }

        if (
            mb_strlen($nama) > 100 ||
            mb_strlen($username) > 50
        ) {
            throw new RuntimeException(
                'Nama maksimal 100 karakter dan username maksimal 50 karakter.'
            );
        }

        if (!in_array($status, ['aktif', 'nonaktif'], true)) {
            throw new RuntimeException('Status akun tidak valid.');
        }

        if (
            $action === 'create' &&
            strlen($password) < 8
        ) {
            throw new RuntimeException(
                'Password minimal 8 karakter.'
            );
        }

        if (
            $action === 'update' &&
            $password !== '' &&
            strlen($password) < 8
        ) {
            throw new RuntimeException(
                'Password baru minimal 8 karakter.'
            );
        }

        /* DATA TAMBAHAN SISWA */

        $extra = [];

        if ($postedTab === 'siswa') {
            if ($action === 'create') {
                $stmt = $db->query(
                    'SELECT MAX(CAST(nis AS UNSIGNED)) FROM siswa'
                );
                $nisTerakhir = (int) ($stmt->fetchColumn() ?: 0);

                if ($nisTerakhir >= 99999) {
                    throw new RuntimeException(
                        'NIS sudah mencapai batas maksimum 99999.'
                    );
                }

                $nis = str_pad(
                    (string) ($nisTerakhir + 1),
                    5,
                    '0',
                    STR_PAD_LEFT
                );
            } else {
                $nis = trim((string)($_POST['nis'] ?? ''));
            }
            $nisn = trim((string)($_POST['nisn'] ?? ''));
            $jk = (string)($_POST['jenis_kelamin'] ?? '');
            $tempatLahir = trim((string)($_POST['tempat_lahir'] ?? ''));
            $tanggalLahir = trim((string)($_POST['tanggal_lahir'] ?? ''));
            $alamat = trim((string)($_POST['alamat'] ?? ''));
            $noHp = trim((string)($_POST['no_hp'] ?? ''));
            $namaOrangTua = trim((string)($_POST['nama_orang_tua'] ?? ''));

            $idKelas = filter_var(
                $_POST['id_kelas'] ?? null,
                FILTER_VALIDATE_INT
            ) ?: 0;

            $idTa = filter_var(
                $_POST['id_tahun_ajaran'] ?? null,
                FILTER_VALIDATE_INT
            ) ?: 0;

            $statusSiswa = (string)(
                $_POST['status_siswa'] ?? 'aktif'
            );

            if (!preg_match('/^\d{5}$/', $nis)) {
                throw new RuntimeException(
                    'NIS harus terdiri dari 5 digit.'
                );
            }

            if (
                $nisn !== '' &&
                !preg_match('/^\d{10}$/', $nisn)
            ) {
                throw new RuntimeException(
                    'NISN harus terdiri dari 10 digit.'
                );
            }

            if (!in_array($jk, ['L', 'P'], true)) {
                throw new RuntimeException(
                    'Pilih jenis kelamin.'
                );
            }

            if (mb_strlen($tempatLahir) > 60 || mb_strlen($alamat) > 255 ||
                mb_strlen($noHp) > 20 || mb_strlen($namaOrangTua) > 100) {
                throw new RuntimeException(
                    'Panjang data tempat lahir, alamat, nomor HP, atau nama orang tua melebihi batas.'
                );
            }

            if ($tanggalLahir !== '') {
                $tanggal = DateTime::createFromFormat('Y-m-d', $tanggalLahir);
                if (!$tanggal || $tanggal->format('Y-m-d') !== $tanggalLahir) {
                    throw new RuntimeException('Tanggal lahir tidak valid.');
                }
                if ($tanggalLahir > $tanggalHariIni) {
                    throw new RuntimeException('Tanggal lahir tidak boleh melebihi hari ini.');
                }
                $tanggalLahir = $tanggal->format('Y-m-d');
            }

            if (!$idKelas || !$idTa) {
                throw new RuntimeException(
                    'Kelas dan tahun ajaran wajib dipilih.'
                );
            }

            if (!in_array(
                $statusSiswa,
                ['aktif', 'lulus', 'keluar'],
                true
            )) {
                throw new RuntimeException(
                    'Status siswa tidak valid.'
                );
            }

            /*
             * Pastikan kelas dan tahun ajaran valid.
             * Tahun ajaran pada profil siswa mengikuti pilihan form.
             */

            $stmt = $db->prepare(
                "SELECT COUNT(*)
                 FROM kelas
                 WHERE id_kelas = :kelas
                   AND id_tahun_ajaran = :ta"
            );

            $stmt->execute([
                'kelas' => $idKelas,
                'ta' => $idTa
            ]);

            if ((int)$stmt->fetchColumn() === 0) {
                throw new RuntimeException(
                    'Kelas tidak sesuai dengan tahun ajaran yang dipilih.'
                );
            }

            $extra = [
                'nis' => $nis,
                'nisn' => $nisn !== '' ? $nisn : null,
                'jk' => $jk,
                'tempatLahir' => $tempatLahir !== '' ? $tempatLahir : null,
                'tanggalLahir' => $tanggalLahir !== '' ? $tanggalLahir : null,
                'alamat' => $alamat !== '' ? $alamat : null,
                'noHp' => $noHp !== '' ? $noHp : null,
                'namaOrangTua' => $namaOrangTua !== '' ? $namaOrangTua : null,
                'idKelas' => $idKelas,
                'idTa' => $idTa,
                'statusSiswa' => $statusSiswa
            ];
        }

        /* =================================
           TAMBAH USER
        ================================= */

        if ($action === 'create') {
            $db->beginTransaction();

            try {
                $hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $db->prepare(
                    "INSERT INTO users
                        (nama, username, password, role, status)
                     VALUES
                        (:nama, :username, :password, :role, :status)"
                );

                $stmt->execute([
                    'nama' => $nama,
                    'username' => $username,
                    'password' => $hash,
                    'role' => $postedTab,
                    'status' => $status
                ]);

                $newId = (int)$db->lastInsertId();

                if ($postedTab === 'siswa') {
                    $stmt = $db->prepare(
                        "INSERT INTO siswa (
                            nis,
                            nisn,
                            nama,
                            jenis_kelamin,
                            tempat_lahir,
                            tanggal_lahir,
                            alamat,
                            no_hp,
                            nama_orang_tua,
                            id_kelas,
                            id_tahun_ajaran,
                            id_user,
                            status
                        ) VALUES (
                            :nis,
                            :nisn,
                            :nama,
                            :jk,
                            :tempat_lahir,
                            :tanggal_lahir,
                            :alamat,
                            :no_hp,
                            :nama_orang_tua,
                            :kelas,
                            :ta,
                            :user,
                            :status
                        )"
                    );

                    $stmt->execute([
                        'nis' => $extra['nis'],
                        'nisn' => $extra['nisn'],
                        'nama' => $nama,
                        'jk' => $extra['jk'],
                        'tempat_lahir' => $extra['tempatLahir'],
                        'tanggal_lahir' => $extra['tanggalLahir'],
                        'alamat' => $extra['alamat'],
                        'no_hp' => $extra['noHp'],
                        'nama_orang_tua' => $extra['namaOrangTua'],
                        'kelas' => $extra['idKelas'],
                        'ta' => $extra['idTa'],
                        'user' => $newId,
                        'status' => $extra['statusSiswa']
                    ]);

                    $newStudentId = (int) $db->lastInsertId();
                    ensure_active_virtual_account($db, $newStudentId);

                    // Jika siklus tagihan bulan berjalan sudah dibuat untuk
                    // tahun ajaran ini, siswa baru langsung mendapat tagihan
                    // bulan berjalan dengan status BELUM_BAYAR.
                    $stmt = $db->prepare(
                        'SELECT t.id_tarif
                         FROM tarif_spp t
                         WHERE t.id_tahun_ajaran = ?
                           AND t.status = "aktif"
                           AND EXISTS (
                               SELECT 1
                               FROM tagihan_spp ts
                               WHERE ts.id_tahun_ajaran = t.id_tahun_ajaran
                                 AND ts.bulan = ?
                                 AND ts.tahun = ?
                           )
                         LIMIT 1'
                    );
                    $stmt->execute([
                        $extra['idTa'],
                        (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('n'),
                        (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y'),
                    ]);
                    $idTarifBulanIni = (int) ($stmt->fetchColumn() ?: 0);

                    if ($idTarifBulanIni > 0) {
                        $bulanIni = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('n');
                        $tahunIni = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y');
                        $stmt = $db->prepare(
                            'SELECT nominal
                             FROM tarif_spp
                             WHERE id_tarif = ?
                             LIMIT 1'
                        );
                        $stmt->execute([$idTarifBulanIni]);
                        $nominalBulanIni = (int) $stmt->fetchColumn();

                        $stmt = $db->prepare(
                            'INSERT INTO tagihan_spp
                             (id_siswa, id_tarif, id_tahun_ajaran, bulan, tahun,
                              nominal, tanggal_jatuh_tempo, status)
                             VALUES (?, ?, ?, ?, ?, ?, LAST_DAY(CONCAT(?, "-01")), "BELUM_BAYAR")
                             ON DUPLICATE KEY UPDATE id_tagihan = id_tagihan'
                        );
                        $stmt->execute([
                            $newStudentId,
                            $idTarifBulanIni,
                            $extra['idTa'],
                            $bulanIni,
                            $tahunIni,
                            $nominalBulanIni,
                            $tahunIni . '-' . str_pad((string) $bulanIni, 2, '0', STR_PAD_LEFT),
                        ]);
                    }
                }

                $db->commit();

                redirectUsers(
                    $postedTab,
                    'Data pengguna berhasil ditambahkan.'
                );
            } catch (Throwable $ex) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                throw $ex;
            }
        }

        /* =================================
           EDIT USER
        ================================= */

        if ($action === 'update') {
            if (!$idUser) {
                throw new RuntimeException(
                    'ID pengguna tidak valid.'
                );
            }

            $stmt = $db->prepare(
                "SELECT id_user
                 FROM users
                 WHERE id_user = :id
                   AND role = :role"
            );

            $stmt->execute([
                'id' => $idUser,
                'role' => $postedTab
            ]);

            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    'Pengguna tidak ditemukan.'
                );
            }

            $db->beginTransaction();

            try {
                $stmt = $db->prepare(
                    "UPDATE users
                     SET nama = :nama,
                         username = :username,
                         status = :status
                     WHERE id_user = :id
                       AND role = :role"
                );

                $stmt->execute([
                    'nama' => $nama,
                    'username' => $username,
                    'status' => $status,
                    'id' => $idUser,
                    'role' => $postedTab
                ]);

                if ($postedTab === 'siswa') {
                    $stmt = $db->prepare(
                        "UPDATE siswa
                         SET nama = :nama,
                             nis = :nis,
                             nisn = :nisn,
                             jenis_kelamin = :jk,
                              tempat_lahir = :tempat_lahir,
                              tanggal_lahir = :tanggal_lahir,
                              alamat = :alamat,
                              no_hp = :no_hp,
                              nama_orang_tua = :nama_orang_tua,
                             id_kelas = :kelas,
                             id_tahun_ajaran = :ta,
                             status = :status
                         WHERE id_user = :user"
                    );

                    $stmt->execute([
                        'nama' => $nama,
                        'nis' => $extra['nis'],
                        'nisn' => $extra['nisn'],
                        'jk' => $extra['jk'],
                        'tempat_lahir' => $extra['tempatLahir'],
                        'tanggal_lahir' => $extra['tanggalLahir'],
                        'alamat' => $extra['alamat'],
                        'no_hp' => $extra['noHp'],
                        'nama_orang_tua' => $extra['namaOrangTua'],
                        'kelas' => $extra['idKelas'],
                        'ta' => $extra['idTa'],
                        'status' => $extra['statusSiswa'],
                        'user' => $idUser
                    ]);

                    $stmt = $db->prepare(
                        "SELECT id_siswa
                         FROM siswa
                         WHERE id_user = :id"
                    );

                    $stmt->execute(['id' => $idUser]);

                    if (!$stmt->fetch()) {
                        throw new RuntimeException(
                            'Profil siswa tidak ditemukan.'
                        );
                    }
                }

                if ($password !== '') {
                    $hash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $db->prepare(
                        "UPDATE users
                         SET password = :password
                         WHERE id_user = :id
                           AND role = :role"
                    );

                    $stmt->execute([
                        'password' => $hash,
                        'id' => $idUser,
                        'role' => $postedTab
                    ]);
                }

                $db->commit();

                redirectUsers(
                    $postedTab,
                    'Data pengguna berhasil diperbarui.'
                );
            } catch (Throwable $ex) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                throw $ex;
            }
        }

        throw new RuntimeException('Aksi tidak dikenal.');

    } catch (Throwable $ex) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        if (
            $ex instanceof PDOException &&
            (string)$ex->getCode() === '23000'
        ) {
            $error =
                'Username, NIS, atau NISN sudah digunakan. '
                . 'Periksa juga data terkait.';
        } else {
            error_log($ex->__toString());

            $error = $ex instanceof RuntimeException
                ? $ex->getMessage()
                : 'Terjadi kesalahan saat memproses data.';
        }

        $tab = $postedTab;
    }
}

/* =========================================
   DATA TABEL DAN PENCARIAN
========================================= */

$search = trim((string)($_GET['q'] ?? ''));
$filterKelas = (int) ($_GET['kelas'] ?? 0);
$filterJurusan = (int) ($_GET['jurusan'] ?? 0);
$filterStatusSiswa = (string) ($_GET['status_siswa'] ?? '');
if (!in_array($filterStatusSiswa, ['aktif', 'lulus', 'keluar'], true)) {
    $filterStatusSiswa = '';
}

$editId = filter_var(
    $_GET['edit'] ?? null,
    FILTER_VALIDATE_INT
) ?: 0;

$showForm = isset($_GET['tambah']) || $editId > 0;
$editData = null;

if ($editId > 0) {
    if ($tab === 'siswa') {
        $stmt = $db->prepare(
            "SELECT
                u.id_user,
                u.nama,
                u.username,
                u.status,
                s.nis,
                s.nisn,
                s.jenis_kelamin,
                 s.tempat_lahir,
                 s.tanggal_lahir,
                 s.alamat,
                 s.no_hp,
                 s.nama_orang_tua,
                s.id_kelas,
                s.id_tahun_ajaran,
                s.status AS status_siswa
             FROM users u
             INNER JOIN siswa s ON s.id_user = u.id_user
             WHERE u.id_user = :id
               AND u.role = 'siswa'
             LIMIT 1"
        );

        $stmt->execute(['id' => $editId]);
    } else {
        $stmt = $db->prepare(
            "SELECT id_user, nama, username, status
             FROM users
             WHERE id_user = :id
               AND role = :role
             LIMIT 1"
        );

        $stmt->execute([
            'id' => $editId,
            'role' => $tab
        ]);
    }

    $editData = $stmt->fetch();

    if (!$editData) {
        $showForm = false;
        $error = 'Data pengguna tidak ditemukan.';
    }
}

$nisBerikutnya = '00001';

if ($tab === 'siswa' && !$editData) {
    $stmt = $db->query(
        'SELECT MAX(CAST(nis AS UNSIGNED)) FROM siswa'
    );
    $nisTerakhir = (int) ($stmt->fetchColumn() ?: 0);

    if ($nisTerakhir < 99999) {
        $nisBerikutnya = str_pad(
            (string) ($nisTerakhir + 1),
            5,
            '0',
            STR_PAD_LEFT
        );
    }
}

/* DATA PILIHAN SISWA */

$kelasRows = [];
$taRows = [];
$jurusanRows = [];

if ($tab === 'siswa') {
    $kelasRows = $db->query(
        "SELECT id_kelas, nama_kelas, id_tahun_ajaran
         FROM kelas
         ORDER BY nama_kelas"
    )->fetchAll();

    $taRows = $db->query(
        "SELECT id_tahun_ajaran, tahun_ajaran
         FROM tahun_ajaran
         ORDER BY tanggal_mulai DESC"
    )->fetchAll();

    $jurusanRows = $db->query(
        'SELECT id_jurusan, nama_jurusan FROM jurusan ORDER BY nama_jurusan'
    )->fetchAll();
}

/* QUERY DAFTAR */

if ($tab === 'siswa') {
    $sql = "
        SELECT
            u.id_user,
            u.nama,
            u.username,
            u.status AS status_akun,
            s.nis,
            s.nisn,
            s.status AS status_siswa,
            k.nama_kelas,
            j.nama_jurusan
        FROM users u
        INNER JOIN siswa s ON s.id_user = u.id_user
        LEFT JOIN kelas k ON k.id_kelas = s.id_kelas
        LEFT JOIN jurusan j ON j.id_jurusan = k.id_jurusan
        WHERE u.role = 'siswa'
    ";

    $params = [];

    if ($search !== '') {
        $sql .= "
            AND (
                u.nama LIKE :nama
                OR u.username LIKE :username
                OR s.nis LIKE :nis
                OR s.nisn LIKE :nisn
            )
        ";

        $like = '%' . $search . '%';

        $params = [
            'nama' => $like,
            'username' => $like,
            'nis' => $like,
            'nisn' => $like
        ];
    }

    if ($filterKelas > 0) {
        $sql .= ' AND s.id_kelas = :filter_kelas';
        $params['filter_kelas'] = $filterKelas;
    }
    if ($filterJurusan > 0) {
        $sql .= ' AND k.id_jurusan = :filter_jurusan';
        $params['filter_jurusan'] = $filterJurusan;
    }
    if ($filterStatusSiswa !== '') {
        $sql .= ' AND s.status = :filter_status_siswa';
        $params['filter_status_siswa'] = $filterStatusSiswa;
    }

    $sql .= ' ORDER BY u.nama ASC';

} else {
    $sql = "
        SELECT
            id_user,
            nama,
            username,
            status AS status_akun,
            created_at
        FROM users
        WHERE role = :role
    ";

    $params = ['role' => $tab];

    if ($search !== '') {
        $sql .= "
            AND (
                nama LIKE :nama
                OR username LIKE :username
            )
        ";

        $like = '%' . $search . '%';

        $params['nama'] = $like;
        $params['username'] = $like;
    }

    $sql .= ' ORDER BY nama ASC';
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$tabTitle = $tabs[$tab];
$msg = trim((string)($_GET['msg'] ?? ''));
$msgType = (string)($_GET['type'] ?? 'success');

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manajemen Users | SPP Bina Bangsa</title>

    <link rel="stylesheet" href="../assets/css/admin/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/users.css">

    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0,0">
</head>

<body>

<?php include __DIR__ . '/partials/sidebar.php'; ?>

<main class="users-main">

    <header class="users-header">
        <div>
            <h1>Manajemen Users</h1>
            <p class="page-subtitle">
                Kelola akun pengguna sistem SPP Bina Bangsa
            </p>
        </div>
    </header>

    <?php if ($msg !== ''): ?>
        <div class="notice notice-success">
            <?= e($msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice notice-error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="users-card">
        <!-- TAB ROLE -->
        <nav class="user-tabs" aria-label="Jenis pengguna">

            <?php
            $icons = [
                'siswa' => 'school',
                'kepala_sekolah' => 'account_balance',
                'teller' => 'point_of_sale',
                'admin' => 'admin_panel_settings'
            ];
            ?>

            <?php foreach ($tabs as $key => $label): ?>
                <a
                    class="user-tab <?= $tab === $key ? 'active' : '' ?>"
                    href="users.php?tab=<?= e($key) ?>"
                >
                    <span class="material-symbols-rounded">
                        <?= e($icons[$key]) ?>
                    </span>
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>

        </nav>

        <!-- SEARCH DAN TAMBAH -->

        <div class="table-toolbar">

            <form class="search-form" method="get" action="users.php">

                <input type="hidden" name="tab" value="<?= e($tab) ?>">

                <span class="material-symbols-rounded">search</span>

                <input
                    type="search"
                    name="q"
                    value="<?= e($search) ?>"
                    placeholder="<?= $tab === 'siswa'
                        ? 'Cari nama, NIS, NISN, username...'
                        : 'Cari nama atau username...' ?>"
                    aria-label="Cari pengguna"
                >

                <?php if ($tab === 'siswa'): ?>
                    <select name="kelas" aria-label="Filter kelas">
                        <option value="0">Semua kelas</option>
                        <?php foreach ($kelasRows as $kelasFilter): ?>
                            <option value="<?= (int) $kelasFilter['id_kelas'] ?>" <?= $filterKelas === (int) $kelasFilter['id_kelas'] ? 'selected' : '' ?>><?= e($kelasFilter['nama_kelas']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="jurusan" aria-label="Filter jurusan">
                        <option value="0">Semua jurusan</option>
                        <?php foreach ($jurusanRows as $jurusanFilter): ?>
                            <option value="<?= (int) $jurusanFilter['id_jurusan'] ?>" <?= $filterJurusan === (int) $jurusanFilter['id_jurusan'] ? 'selected' : '' ?>><?= e($jurusanFilter['nama_jurusan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status_siswa" aria-label="Filter status siswa">
                        <option value="">Semua status</option>
                        <option value="aktif" <?= $filterStatusSiswa === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                        <option value="lulus" <?= $filterStatusSiswa === 'lulus' ? 'selected' : '' ?>>Lulus</option>
                        <option value="keluar" <?= $filterStatusSiswa === 'keluar' ? 'selected' : '' ?>>Keluar</option>
                    </select>
                <?php endif; ?>

                <?php if ($search !== '' || $filterKelas > 0 || $filterJurusan > 0 || $filterStatusSiswa !== ''): ?>
                    <a
                        class="clear-search"
                        href="users.php?tab=<?= e($tab) ?>"
                    >Reset</a>
                <?php endif; ?>

                <button class="search-submit" type="submit">Cari</button>

            </form>

            <a
                class="btn-primary"
                href="users.php?tab=<?= e($tab) ?>&tambah=1"
            >
                <span class="material-symbols-rounded">add</span>
                Tambah <?= e($tabTitle) ?>
            </a>

        </div>

        <!-- FORM TAMBAH / EDIT -->

        <?php if ($showForm): ?>

            <?php $formIsStudentCreate = $tab === 'siswa' && !$editData; ?>
            <?php if ($formIsStudentCreate): ?>
                <div class="modal-tambah-siswa show" id="modalTambahSiswa" aria-hidden="false">
                    <div class="modal-tambah-siswa-overlay" onclick="tutupFormTambahSiswa()"></div>
            <?php endif; ?>

            <section class="user-form-panel" id="user-form">

                <div class="form-heading">
                    <div>
                        <h3>
                            <?= $editData ? 'Edit ' : 'Tambah ' ?>
                            <?= e($tabTitle) ?>
                        </h3>

                        <p>
                            Password minimal 8 karakter.
                            <?php if ($editData): ?>
                                Kosongkan password jika tidak ingin menggantinya.
                            <?php endif; ?>
                        </p>
                    </div>

                    <a
                        class="icon-button"
                        href="users.php?tab=<?= e($tab) ?>"
                        aria-label="Tutup form"
                    >
                        <span class="material-symbols-rounded">close</span>
                    </a>
                </div>

                <form
                    method="post"
                    action="users.php?tab=<?= e($tab) ?>"
                    class="user-form"
                >

                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">

                    <input
                        type="hidden"
                        name="action"
                        value="<?= $editData ? 'update' : 'create' ?>"
                    >

                    <?php if ($editData): ?>
                        <input
                            type="hidden"
                            name="id_user"
                            value="<?= (int)$editData['id_user'] ?>"
                        >
                    <?php endif; ?>

                    <label>
                        Nama lengkap
                        <input
                            name="nama"
                            maxlength="100"
                            required
                            value="<?= e($editData['nama'] ?? '') ?>"
                        >
                    </label>

                    <label>
                        Username
                        <input
                            name="username"
                            maxlength="50"
                            autocomplete="off"
                            required
                            value="<?= e($editData['username'] ?? '') ?>"
                        >
                    </label>

                    <label>
                        Password
                        <input
                            type="password"
                            name="password"
                            minlength="8"
                            <?= $editData ? '' : 'required' ?>
                            autocomplete="new-password"
                            placeholder="<?= $editData
                                ? 'Kosongkan jika tidak diubah'
                                : 'Minimal 8 karakter' ?>"
                        >
                    </label>

                    <label>
                        Status akun
                        <select name="status" required>
                            <option value="aktif"
                                <?= ($editData['status'] ?? 'aktif') === 'aktif'
                                    ? 'selected' : '' ?>>
                                Aktif
                            </option>
                            <option value="nonaktif"
                                <?= ($editData['status'] ?? '') === 'nonaktif'
                                    ? 'selected' : '' ?>>
                                Nonaktif
                            </option>
                        </select>
                    </label>

                    <?php if ($tab === 'siswa'): ?>

                        <label>
                            NIS (5 digit)
                            <input
                                name="nis"
                                inputmode="numeric"
                                pattern="[0-9]{5}"
                                minlength="5"
                                maxlength="5"
                                required
                                value="<?= e($editData['nis'] ?? $nisBerikutnya) ?>"
                                <?= !$editData ? 'readonly' : '' ?>
                            >
                        </label>

                        <label>
                            NISN (10 digit, opsional)
                            <input
                                name="nisn"
                                inputmode="numeric"
                                pattern="[0-9]{10}"
                                minlength="10"
                                maxlength="10"
                                value="<?= e($editData['nisn'] ?? '') ?>"
                            >
                        </label>

                        <label>
                            Jenis kelamin
                            <select name="jenis_kelamin" required>
                                <option value="">Pilih jenis kelamin</option>
                                <option value="L"
                                    <?= ($editData['jenis_kelamin'] ?? '') === 'L'
                                        ? 'selected' : '' ?>>
                                    Laki-laki
                                </option>
                                <option value="P"
                                    <?= ($editData['jenis_kelamin'] ?? '') === 'P'
                                        ? 'selected' : '' ?>>
                                    Perempuan
                                </option>
                            </select>
                        </label>

                        <label>
                            Tempat lahir
                            <input
                                name="tempat_lahir"
                                maxlength="60"
                                value="<?= e($editData['tempat_lahir'] ?? '') ?>"
                                placeholder="Contoh: Sidoarjo"
                            >
                        </label>

                        <label>
                            Tanggal lahir
                            <input
                                type="date"
                                name="tanggal_lahir"
                                max="<?= e($tanggalHariIni) ?>"
                                value="<?= e($editData['tanggal_lahir'] ?? '') ?>"
                            >
                        </label>

                        <label>
                            Nomor HP
                            <input
                                type="tel"
                                name="no_hp"
                                maxlength="20"
                                inputmode="tel"
                                value="<?= e($editData['no_hp'] ?? '') ?>"
                                placeholder="Contoh: 081234567890"
                            >
                        </label>

                        <label>
                            Nama orang tua
                            <input
                                name="nama_orang_tua"
                                maxlength="100"
                                value="<?= e($editData['nama_orang_tua'] ?? '') ?>"
                                placeholder="Nama orang tua atau wali"
                            >
                        </label>

                        <label class="field-wide">
                            Alamat
                            <textarea
                                name="alamat"
                                maxlength="255"
                                rows="3"
                                placeholder="Alamat lengkap siswa"
                            ><?= e($editData['alamat'] ?? '') ?></textarea>
                        </label>

                        <label>
                            Kelas
                            <select name="id_kelas" id="id_kelas" required>
                                <option value="">Pilih kelas</option>

                                <?php foreach ($kelasRows as $kelas): ?>
                                    <option
                                        value="<?= (int)$kelas['id_kelas'] ?>"
                                        data-ta="<?= (int)$kelas['id_tahun_ajaran'] ?>"
                                        <?= (string)($editData['id_kelas'] ?? '') ===
                                            (string)$kelas['id_kelas']
                                            ? 'selected' : '' ?>
                                    >
                                        <?= e($kelas['nama_kelas']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Tahun ajaran
                            <select
                                name="id_tahun_ajaran"
                                id="id_tahun_ajaran"
                                required
                            >
                                <option value="">Pilih tahun ajaran</option>

                                <?php foreach ($taRows as $ta): ?>
                                    <option
                                        value="<?= (int)$ta['id_tahun_ajaran'] ?>"
                                        <?= (string)($editData['id_tahun_ajaran'] ?? '') ===
                                            (string)$ta['id_tahun_ajaran']
                                            ? 'selected' : '' ?>
                                    >
                                        <?= e($ta['tahun_ajaran']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Status siswa
                            <select name="status_siswa" required>
                                <?php
                                $studentStatuses = [
                                    'aktif' => 'Aktif',
                                    'lulus' => 'Lulus',
                                    'keluar' => 'Keluar'
                                ];
                                ?>

                                <?php foreach ($studentStatuses as $value => $label): ?>
                                    <option
                                        value="<?= e($value) ?>"
                                        <?= ($editData['status_siswa'] ?? 'aktif') === $value
                                            ? 'selected' : '' ?>
                                    >
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                    <?php endif; ?>

                    <div class="form-actions">
                        <a
                            class="btn-secondary"
                            href="users.php?tab=<?= e($tab) ?>"
                        >
                            Batal
                        </a>

                        <button class="btn-primary" type="submit">
                            <span class="material-symbols-rounded">save</span>
                            Simpan
                        </button>
                    </div>

                </form>

            </section>

            <?php if ($formIsStudentCreate): ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <!-- TABEL DATA -->

        <div class="table-wrap">

            <table class="users-table">

                <thead>
                    <tr>
                        <?php if ($tab === 'siswa'): ?>
                            <th>ID User</th>
                            <th>Nama Lengkap</th>
                            <th>Username</th>
                            <th>NIS</th>
                            <th>Kelas</th>
                            <th>Status Siswa</th>
                        <?php else: ?>
                            <th>ID User</th>
                            <th>Nama Pengguna</th>
                            <th>Username</th>
                            <th>Dibuat</th>
                            <th>Status Akun</th>
                        <?php endif; ?>
                        <th class="actions-heading">Aksi</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!$rows): ?>

                        <tr>
                            <td colspan="<?= $tab === 'siswa' ? 7 : 6 ?>" class="empty-state">
                                <span class="material-symbols-rounded">
                                    person_search
                                </span>

                                <strong>Data tidak ditemukan</strong>

                                <span>
                                    Coba kata kunci lain atau tambahkan pengguna baru.
                                </span>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($rows as $row): ?>
<tr>
    <?php if ($tab === 'siswa'): ?>
        <td><?= (int) $row['id_user'] ?></td>

        <td>
            <strong><?= e($row['nama']) ?></strong>
        </td>

        <td><?= e($row['username']) ?></td>

        <td><?= e($row['nis']) ?></td>

        <td><?= e($row['nama_kelas'] ?: '-') ?></td>

        <td>
            <span class="status-pill <?= e($row['status_siswa']) ?>">
                <?= e(ucfirst($row['status_siswa'])) ?>
            </span>
        </td>
    <?php else: ?>
        <td><?= (int) $row['id_user'] ?></td>

        <td>
            <strong><?= e($row['nama']) ?></strong>
        </td>

        <td><?= e($row['username']) ?></td>

        <td>
            <?= e(isset($row['created_at'])
                ? date('d/m/Y', strtotime($row['created_at']))
                : '-') ?>
        </td>

        <td>
            <span class="status-pill <?= $row['status_akun'] === 'aktif' ? 'aktif' : 'nonaktif' ?>">
                <?= e(ucfirst($row['status_akun'])) ?>
            </span>
        </td>
    <?php endif; ?>

    <td>
        <div class="row-actions">
            <?php if ($tab === 'siswa'): ?>
                <button
                    type="button"
                    class="action-btn detail"
                    title="Lihat detail siswa"
                    onclick="bukaDetailSiswa(<?= (int) $row['id_user'] ?>)"
                >
                    <span class="material-symbols-rounded">visibility</span>
                </button>
            <?php endif; ?>
            <a
                class="action-btn edit"
                href="users.php?tab=<?= e($tab) ?>&edit=<?= (int) $row['id_user'] ?>"
                title="Edit"
            >
                <span class="material-symbols-rounded">edit</span>
            </a>

            <?php if ((int) $row['id_user'] !== (int) ($userSession['id_user'] ?? 0)): ?>
                <form
                    method="post"
                    action="users.php?tab=<?= e($tab) ?>"
                    onsubmit="return confirm('Hapus akun <?= e($row['nama']) ?> secara permanen? Akun tidak dapat dipulihkan. Data profil siswa dan riwayat pembayaran tetap disimpan.');"
                >
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id_user" value="<?= (int) $row['id_user'] ?>">

                    <button class="action-btn delete" type="submit" title="Hapus permanen">
                        <span class="material-symbols-rounded">delete_forever</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </td>
</tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="modal-detail" id="modalDetailSiswa" aria-hidden="true">
    <div class="modal-detail-overlay" onclick="tutupDetailSiswa()"></div>

    <section class="modal-detail-box"
             role="dialog"
             aria-modal="true"
             aria-labelledby="judulDetailSiswa">

        <div class="modal-detail-header">
            <div>
                <h2 id="judulDetailSiswa">Detail Siswa</h2>
                <p>Informasi lengkap siswa</p>
            </div>

            <button type="button"
                    class="modal-detail-close"
                    onclick="tutupDetailSiswa()"
                    aria-label="Tutup">
                &times;
            </button>
        </div>

        <div class="modal-detail-content" id="isiDetailSiswa">
            Memuat data siswa...
        </div>

        <div class="modal-detail-footer">
            <button type="button"
                    class="btn-modal-secondary"
                    onclick="tutupDetailSiswa()">
                Tutup
            </button>
        </div>
    </section>
</div>

        <div class="table-footer">
            <span>Menampilkan <?= count($rows) ?> data</span>
            <span>
                Penghapusan akun tidak menghapus profil siswa dan riwayat pembayaran.
            </span>
        </div>

    </section>

    <footer class="users-footer">
        SPP Bina Bangsa · Manajemen Pengguna
    </footer>

</main>

<script>
/*
 * Pilihan kelas akan otomatis menyesuaikan tahun ajaran.
 */
(function () {
    const kelasSelect = document.getElementById('id_kelas');
    const taSelect = document.getElementById('id_tahun_ajaran');

    if (kelasSelect && taSelect) {
        function syncTahunAjaran() {
            const selected = kelasSelect.options[kelasSelect.selectedIndex];

            if (!selected || !selected.dataset.ta) {
                return;
            }

            taSelect.value = selected.dataset.ta;
        }

        kelasSelect.addEventListener('change', syncTahunAjaran);

        if (kelasSelect.value) {
            syncTahunAjaran();
        }
    }

    
window.bukaDetailSiswa = async function bukaDetailSiswa(idUser) {
    const modal = document.getElementById('modalDetailSiswa');
    const isi = document.getElementById('isiDetailSiswa');

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-terbuka');
    isi.textContent = 'Memuat data siswa...';

    try {
        const response = await fetch(
            'detail_siswa.php?id_user=' + encodeURIComponent(idUser) + '&format=modal',
            {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }
        );

        if (!response.ok) {
            throw new Error('Data siswa gagal dimuat.');
        }

        isi.innerHTML = await response.text();
    } catch (error) {
        isi.textContent = error.message;
    }
}

window.tutupDetailSiswa = function tutupDetailSiswa() {
    const modal = document.getElementById('modalDetailSiswa');

    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-terbuka');
}

window.tutupFormTambahSiswa = function tutupFormTambahSiswa() {
    window.location.href = 'users.php?tab=siswa';
}

if (document.getElementById('modalTambahSiswa')) {
    document.body.classList.add('modal-terbuka');
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        const formModal = document.getElementById('modalTambahSiswa');
        if (formModal) {
            tutupFormTambahSiswa();
            return;
        }
        tutupDetailSiswa();
    }
});

})();
</script>

</body>
</html>
