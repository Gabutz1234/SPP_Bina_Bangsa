<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/auth.php';
$user = require_role('kepala_sekolah');
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<?php $userName = (string) ($user['nama'] ?? 'Kepala Sekolah'); $userInitial = strtoupper(substr($userName, 0, 1)); ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0,0">
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand"><div class="brand-icon"><span class="material-symbols-rounded">school</span></div><div class="brand-info"><h2>SPP Bina Bangsa</h2><p>Sistem Pembayaran Sekolah</p></div></div>
    <div class="sidebar-label">MONITORING</div>
    <nav class="sidebar-nav" aria-label="Menu Kepala Sekolah">
        <a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php"><span class="nav-icon material-symbols-rounded">dashboard</span><span class="nav-text">Dashboard</span></a>
        <a class="nav-link" href="dashboard.php#ringkasan"><span class="nav-icon material-symbols-rounded">monitoring</span><span class="nav-text">Ringkasan SPP</span></a>
        <a class="nav-link" href="dashboard.php#transaksi"><span class="nav-icon material-symbols-rounded">receipt_long</span><span class="nav-text">Transaksi Terbaru</span></a>
        <a class="nav-link" href="dashboard.php#laporan"><span class="nav-icon material-symbols-rounded">assessment</span><span class="nav-text">Laporan</span></a>
    </nav>
    <div class="sidebar-bottom"><div class="sidebar-user"><div class="user-avatar"><?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?></div><div class="user-details"><strong><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></strong><span>Kepala Sekolah</span></div></div>
        <form action="../config/logout.php" method="post" onsubmit="return confirm('Apakah kamu yakin ingin logout?');"><button class="logout-button" type="submit"><span class="nav-icon material-symbols-rounded">logout</span><span>Logout</span></button></form>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>
<script>function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');document.getElementById('sidebar-overlay').classList.toggle('show')}function closeSidebar(){document.getElementById('sidebar').classList.remove('show');document.getElementById('sidebar-overlay').classList.remove('show')}</script>
