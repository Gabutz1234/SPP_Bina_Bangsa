<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/auth.php';
require_role('siswa');

if (($_SESSION['user']['role'] ?? '') !== 'siswa') {
    header('Location: ../index.php');
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
}

$student = null;
$databaseError = null;

try {
    $statement = $pdo->prepare(
        'SELECT
            s.nis,
            s.nisn,
            s.nama,
            k.nama_kelas,
            j.nama_jurusan,
            ta.tahun_ajaran,
            va.nomor_va
         FROM siswa s
         INNER JOIN kelas k ON k.id_kelas = s.id_kelas
         INNER JOIN jurusan j ON j.id_jurusan = k.id_jurusan
         INNER JOIN tahun_ajaran ta ON ta.id_tahun_ajaran = s.id_tahun_ajaran
         LEFT JOIN virtual_account va ON va.id_siswa = s.id_siswa AND va.status = "aktif"
         WHERE s.id_user = ?
         LIMIT 1'
    );
    $statement->execute([(int) $_SESSION['user']['id_user']]);
    $student = $statement->fetch();

    if (!$student) {
        throw new RuntimeException('Profil siswa belum terhubung dengan akun.');
    }
} catch (Throwable $exception) {
    error_log('Halaman profil siswa: ' . $exception->getMessage());
    $databaseError = 'Profil siswa belum dapat dimuat. Periksa koneksi database.';
}

$studentName = $student['nama'] ?? $_SESSION['user']['nama'] ?? 'Siswa';
$studentInitial = strtoupper(substr((string) $studentName, 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Siswa | SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/siswa/sidebar.css">
    <link rel="stylesheet" href="../assets/css/siswa/profil.css">
</head>
<body>
<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main class="dashboard-main profile-main">
    <header class="dashboard-header">
        <div>
            <h1>Profil Siswa</h1>
            <p>Informasi identitas siswa yang terdaftar pada sistem.</p>
        </div>
        <?php if ($student): ?>
            <div class="profile-status"><span class="material-symbols-rounded">verified_user</span> Data terdaftar</div>
        <?php endif; ?>
    </header>

    <?php if ($databaseError): ?>
        <div class="database-error" role="alert"><?= e($databaseError) ?></div>
    <?php else: ?>
        <section class="profile-hero">
            <div class="profile-avatar" aria-hidden="true"><?= e($studentInitial) ?></div>
            <div class="profile-hero-copy">
                <span class="profile-eyebrow">Siswa aktif</span>
                <h2><?= e($student['nama']) ?></h2>
                <p>NIS <?= e($student['nis']) ?> <span aria-hidden="true">•</span> <?= e($student['nama_kelas']) ?></p>
            </div>
            <span class="material-symbols-rounded hero-mark" aria-hidden="true">school</span>
        </section>

        <section class="panel profile-panel">
            <div class="profile-heading">
                <div>
                    <span class="section-label">Data akademik</span>
                    <h2>Informasi pribadi dan sekolah</h2>
                </div>
                <span class="read-only-label"><span class="material-symbols-rounded">lock</span> Hanya dapat dilihat</span>
            </div>

            <div class="profile-fields">
                <div class="profile-field">
                    <span class="field-icon"><span class="material-symbols-rounded">badge</span></span>
                    <div><span class="field-label">NIS</span><strong><?= e($student['nis']) ?></strong></div>
                </div>
                <div class="profile-field">
                    <span class="field-icon"><span class="material-symbols-rounded">id_card</span></span>
                    <div><span class="field-label">NISN</span><strong><?= e($student['nisn']) ?></strong></div>
                </div>
                <div class="profile-field profile-field-wide">
                    <span class="field-icon"><span class="material-symbols-rounded">person</span></span>
                    <div><span class="field-label">Nama lengkap</span><strong><?= e($student['nama']) ?></strong></div>
                </div>
                <div class="profile-field">
                    <span class="field-icon"><span class="material-symbols-rounded">meeting_room</span></span>
                    <div><span class="field-label">Kelas</span><strong><?= e($student['nama_kelas']) ?></strong></div>
                </div>
                <div class="profile-field">
                    <span class="field-icon"><span class="material-symbols-rounded">account_tree</span></span>
                    <div><span class="field-label">Jurusan</span><strong><?= e($student['nama_jurusan']) ?></strong></div>
                </div>
                <div class="profile-field profile-field-wide">
                    <span class="field-icon"><span class="material-symbols-rounded">calendar_month</span></span>
                    <div><span class="field-label">Tahun ajaran</span><strong><?= e($student['tahun_ajaran']) ?></strong></div>
                </div>
                <div class="profile-field profile-field-wide">
                    <span class="field-icon va-field-icon"><span class="material-symbols-rounded">account_balance_wallet</span></span>
                    <div><span class="field-label">Nomor Virtual Account</span><strong><?= e($student['nomor_va'] ?? 'Belum tersedia') ?></strong></div>
                </div>
            </div>
        </section>

        <p class="profile-footnote"><span class="material-symbols-rounded">info</span> Jika ada data yang tidak sesuai, silakan hubungi administrator sekolah.</p>
    <?php endif; ?>
</main>
</body>
</html>
