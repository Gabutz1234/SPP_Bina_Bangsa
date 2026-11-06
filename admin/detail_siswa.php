<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/auth.php';
require_role('admin');

/*
|--------------------------------------------------------------------------
| Validasi login dan hak akses
|--------------------------------------------------------------------------
*/
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    !isset($_SESSION['user']) ||
    ($_SESSION['user']['role'] ?? '') !== 'admin'
) {
    http_response_code(403);
    exit('Akses ditolak. Halaman ini hanya untuk admin.');
}

/*
|--------------------------------------------------------------------------
| Fungsi keamanan output HTML
|--------------------------------------------------------------------------
*/
function e($nilai): string
{
    return htmlspecialchars(
        (string) ($nilai ?? '-'),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Validasi ID user
|--------------------------------------------------------------------------
*/
$idUser = filter_input(INPUT_GET, 'id_user', FILTER_VALIDATE_INT);

if (!$idUser || $idUser < 1) {
    http_response_code(400);
    exit('ID user tidak valid.');
}

/*
|--------------------------------------------------------------------------
| Ambil data siswa dari database
|--------------------------------------------------------------------------
*/
try {
    $sql = "
        SELECT
            u.id_user,
            u.nama AS nama_akun,
            u.username,
            u.status AS status_akun,
            u.created_at,

            s.id_siswa,
            s.nis,
            s.nisn,
            s.nama AS nama_siswa,
            s.jenis_kelamin,
            s.tempat_lahir,
            s.tanggal_lahir,
            s.alamat,
            s.no_hp,
            s.nama_orang_tua,
            s.status AS status_siswa,

            k.nama_kelas,
            k.tingkat,

            j.nama_jurusan,

            ta.tahun_ajaran

        FROM users u

        INNER JOIN siswa s
            ON s.id_user = u.id_user

        LEFT JOIN kelas k
            ON k.id_kelas = s.id_kelas

        LEFT JOIN jurusan j
            ON j.id_jurusan = k.id_jurusan

        LEFT JOIN tahun_ajaran ta
            ON ta.id_tahun_ajaran = s.id_tahun_ajaran

        WHERE u.id_user = :id_user
          AND u.role = 'siswa'

        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id_user' => $idUser]);
    $siswa = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    http_response_code(500);
    exit('Terjadi kesalahan saat mengambil data siswa.');
}

/*
|--------------------------------------------------------------------------
| Periksa apakah data ditemukan
|--------------------------------------------------------------------------
*/
if (!$siswa) {
    http_response_code(404);
    exit('Data siswa tidak ditemukan.');
}

/*
|--------------------------------------------------------------------------
| Format data
|--------------------------------------------------------------------------
*/
$jenisKelamin = [
    'L' => 'Laki-laki',
    'P' => 'Perempuan',
];

$tanggalLahir = '-';

if (!empty($siswa['tanggal_lahir'])) {
    $timestamp = strtotime($siswa['tanggal_lahir']);

    if ($timestamp !== false) {
        $tanggalLahir = date('d-m-Y', $timestamp);
    }
}

$akunDibuat = '-';

if (!empty($siswa['created_at'])) {
    $timestampAkun = strtotime($siswa['created_at']);

    if ($timestampAkun !== false) {
        $akunDibuat = date('d-m-Y', $timestampAkun);
    }
}

/*
|--------------------------------------------------------------------------
| Fungsi untuk menampilkan satu item detail
|--------------------------------------------------------------------------
*/
function tampilkan(string $label, $nilai): void
{
    ?>
    <div class="detail-item">
        <span class="detail-label"><?= e($label) ?></span>
        <span class="detail-value"><?= e($nilai) ?></span>
    </div>
    <?php
}

/*
|--------------------------------------------------------------------------
| Mode modal: hanya mengirim HTML detail untuk pop-up
|--------------------------------------------------------------------------
*/
if (($_GET['format'] ?? '') === 'modal') {
    header('Content-Type: text/html; charset=UTF-8');
    ?>

    <div class="detail-grid">
        <?php
        tampilkan('ID User', $siswa['id_user']);
        tampilkan('ID Siswa', $siswa['id_siswa']);
        tampilkan('Nama Lengkap', $siswa['nama_siswa']);
        tampilkan('Username', $siswa['username']);
        tampilkan('NIS', $siswa['nis']);
        tampilkan('NISN', $siswa['nisn']);
        tampilkan(
            'Jenis Kelamin',
            $jenisKelamin[$siswa['jenis_kelamin']] ?? '-'
        );
        tampilkan('Tempat Lahir', $siswa['tempat_lahir']);
        tampilkan('Tanggal Lahir', $tanggalLahir);
        tampilkan('Alamat', $siswa['alamat']);
        tampilkan('Nomor HP', $siswa['no_hp']);
        tampilkan('Nama Orang Tua', $siswa['nama_orang_tua']);
        tampilkan('Kelas', $siswa['nama_kelas']);
        tampilkan('Tingkat', $siswa['tingkat']);
        tampilkan('Jurusan', $siswa['nama_jurusan']);
        tampilkan('Tahun Ajaran', $siswa['tahun_ajaran']);
        tampilkan(
            'Status Siswa',
            ucfirst((string) $siswa['status_siswa'])
        );
        tampilkan(
            'Status Akun',
            ucfirst((string) $siswa['status_akun'])
        );
        tampilkan('Akun Dibuat', $akunDibuat);
        ?>
    </div>

    <?php
    exit;
}

/*
|--------------------------------------------------------------------------
| Mode halaman biasa
|--------------------------------------------------------------------------
*/
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Detail Siswa - SPP Bina Bangsa</title>

    <link rel="stylesheet" href="../assets/css/admin/users.css">

    <style>
        .detail-page {
            max-width: 1000px;
            margin: 30px auto;
            padding: 24px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
        }

        .detail-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }

        .detail-page-header h1 {
            margin: 0 0 6px;
            font-size: 24px;
        }

        .detail-page-header p {
            margin: 0;
            color: #6b7280;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .detail-item {
            min-width: 0;
            padding: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
        }

        .detail-label {
            display: block;
            margin-bottom: 6px;
            color: #6b7280;
            font-size: 12px;
        }

        .detail-value {
            display: block;
            color: #111827;
            font-size: 14px;
            font-weight: 600;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .btn-kembali {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 7px;
            background: #f3f4f6;
            color: #111827;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-kembali:hover {
            background: #e5e7eb;
        }

        @media (max-width: 600px) {
            .detail-page {
                margin: 16px;
                padding: 16px;
            }

            .detail-page-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <main class="detail-page">
        <div class="detail-page-header">
            <div>
                <h1>Detail Siswa</h1>
                <p>Informasi lengkap siswa Bina Bangsa</p>
            </div>

            <a href="users.php" class="btn-kembali">
                Kembali
            </a>
        </div>

        <div class="detail-grid">
            <?php
            tampilkan('ID User', $siswa['id_user']);
            tampilkan('ID Siswa', $siswa['id_siswa']);
            tampilkan('Nama Lengkap', $siswa['nama_siswa']);
            tampilkan('Username', $siswa['username']);
            tampilkan('NIS', $siswa['nis']);
            tampilkan('NISN', $siswa['nisn']);
            tampilkan(
                'Jenis Kelamin',
                $jenisKelamin[$siswa['jenis_kelamin']] ?? '-'
            );
            tampilkan('Tempat Lahir', $siswa['tempat_lahir']);
            tampilkan('Tanggal Lahir', $tanggalLahir);
            tampilkan('Alamat', $siswa['alamat']);
            tampilkan('Nomor HP', $siswa['no_hp']);
            tampilkan('Nama Orang Tua', $siswa['nama_orang_tua']);
            tampilkan('Kelas', $siswa['nama_kelas']);
            tampilkan('Tingkat', $siswa['tingkat']);
            tampilkan('Jurusan', $siswa['nama_jurusan']);
            tampilkan('Tahun Ajaran', $siswa['tahun_ajaran']);
            tampilkan(
                'Status Siswa',
                ucfirst((string) $siswa['status_siswa'])
            );
            tampilkan(
                'Status Akun',
                ucfirst((string) $siswa['status_akun'])
            );
            tampilkan('Akun Dibuat', $akunDibuat);
            ?>
        </div>
    </main>

</body>
</html>
