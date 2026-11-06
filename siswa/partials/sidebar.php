<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/auth.php';
require_role('siswa');

$currentPage = basename($_SERVER['PHP_SELF']);
$studentMenu = [
    'dashboard.php' => ['dashboard', 'Dashboard'],
    'tagihan.php' => ['request_quote', 'Tagihan'],
    'riwayat.php' => ['history', 'Riwayat Pembayaran'],
    'profil.php' => ['person', 'Profile'],
];
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0,0">

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><span class="material-symbols-rounded">school</span></div>
        <div class="brand-info">
            <h2>SPP Bina Bangsa</h2>
            <p>Sistem Pembayaran Sekolah</p>
        </div>
    </div>

    <div class="sidebar-label">MENU UTAMA</div>

    <nav class="sidebar-nav" aria-label="Menu siswa">
        <?php foreach ($studentMenu as $page => [$icon, $label]): ?>
            <a href="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>"
               class="nav-link <?= $currentPage === $page ? 'active' : '' ?>">
                <span class="nav-icon material-symbols-rounded"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="nav-text"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-bottom">
        <form action="../config/logout.php" method="post" onsubmit="return confirm('Apakah kamu yakin ingin logout?');">
            <button type="submit" class="logout-button">
                <span class="nav-icon material-symbols-rounded">logout</span>
                <span>Logout</span>
            </button>
        </form>
    </div>
</aside>

<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('show');
        document.getElementById('sidebar-overlay').classList.toggle('show');
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('show');
        document.getElementById('sidebar-overlay').classList.remove('show');
    }
</script>
