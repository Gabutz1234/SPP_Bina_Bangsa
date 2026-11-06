<?php
declare(strict_types=1);

require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/auth.php';

// Jika sudah login, arahkan sesuai role.
if (current_role() !== null) {
    redirect_to_role_home();
}

$error = null;
$usernameInput = '';

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameInput = trim($_POST['username'] ?? '');
    $passwordInput = (string) ($_POST['password'] ?? '');

    if ($usernameInput === '' || $passwordInput === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT id_user, nama, username, password, role, status
                 FROM users
                 WHERE username = :username
                 LIMIT 1'
            );

            $stmt->execute([
                'username' => $usernameInput
            ]);

            $user = $stmt->fetch();

            if (
                $user &&
                $user['status'] === 'aktif' &&
                password_verify($passwordInput, $user['password'])
            ) {
                session_regenerate_id(true);
                unset($user['password']);
                $_SESSION['user'] = $user;
                redirect_to_role_home();
            } else {
                $error = 'Username atau password salah, atau akun tidak aktif.';
            }
        } catch (PDOException $exception) {
            error_log('Proses login gagal: ' . $exception->getMessage());
            $error = 'Terjadi kesalahan saat memproses login.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Masuk | SPP Bina Bangsa</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="assets/css/index.css">
</head>
<body class="auth-page">

<main class="auth-shell">

    <!-- Panel pengenalan -->
    
    <section class="auth-intro">
        <h1>
            SMK Bina Bangsa
        </h1>

        <p class="intro-copy">
            Sistem Informasi Pembayaran SPP SMK Bina Bangsa
            untuk mendukung administrasi sekolah yang lebih
            tertata, praktis, dan terorganisir.
        </p>

        <div class="school-info">

            <div class="school-info-item">
                <div class="school-info-icon">⌖</div>
                <div>
                    <strong>Alamat Sekolah</strong>
                    <span>
                        Jl. Pendidikan Bangsa No. 25, Desa Sumber Makmur,
                        Kecamatan Sukamaju, Kabupaten Sidoarjo,
                        Jawa Timur 61256
                    </span>
                </div>
            </div>

            <div class="school-info-item">
                <div class="school-info-icon">☎</div>
                <div>
                    <strong>Nomor Telepon</strong>
                    <span>(031) 8856731</span>
                </div>
            </div>

            <div class="school-info-item">
                <div class="school-info-icon">✉</div>
                <div>
                    <strong>Email Sekolah</strong>
                    <a href="mailto:info@smkbinabangsa.sch.id">
                        info@smkbinabangsa.sch.id
                    </a>
                </div>
            </div>

        </div>

        <div class="intro-foot">
            <span class="dot"></span>
            Tahun ajaran 2026 / 2027
        </div>

    </section>


    <!-- Panel login -->
    <section class="auth-panel">

        <div class="auth-form-wrap">

            <p class="eyebrow">SELAMAT DATANG KEMBALI</p>

            <h2>Masuk ke akunmu</h2>

            <p class="muted">
                Gunakan akun yang sudah terdaftar untuk melanjutkan.
            </p>

            <?php if ($error !== null): ?>
                <div class="alert alert-error" role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="login-form" autocomplete="on">

                <label for="username">Username</label>

                <input
                    id="username"
                    name="username"
                    type="text"
                    placeholder="Masukkan username"
                    value="<?= e($usernameInput) ?>"
                    autocomplete="username"
                    maxlength="50"
                    autofocus
                    required
                >

                <div class="label-row">
                    <label for="password">Password</label>
                    <span class="quiet">Akun terdaftar</span>
                </div>

                <input
                    id="password"
                    name="password"
                    type="password"
                    placeholder="Masukkan password"
                    autocomplete="current-password"
                    required
                >

                <button
                    class="button button-primary button-wide"
                    type="submit"
                >
                    Masuk ke dashboard
                    <span>↗</span>
                </button>

            </form>
        </div>

        <footer class="auth-footer">
            © 2026 SMK Bina Bangsa
            <span>·</span>
            Sistem Pembayaran SPP
        </footer>

    </section>

</main>
</body>
</html>
