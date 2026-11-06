<?php
require_once __DIR__ . '/../../config/auth.php';
require_role('admin');
$currentPage = basename($_SERVER['PHP_SELF']);

$userName = $_SESSION['user']['nama'] ?? 'Administrator';
$userInitial = strtoupper(substr($userName, 0, 1));

$masterDataPages = [
    'data_siswa.php',
    'kelas.php',
    'jurusan.php',
    'tahun_ajaran.php',
    'tarif_spp.php',
    'virtual_account.php'
];

$isMasterDataActive = in_array(
    $currentPage,
    $masterDataPages,
    true
);
?>

<!-- GOOGLE MATERIAL SYMBOLS -->
<link
    rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0,0"
>

<aside class="sidebar" id="sidebar">

    <!-- BRAND -->
    <div class="sidebar-brand">
        <div class="brand-icon">
            <span class="material-symbols-rounded">school</span>
        </div>

        <div class="brand-info">
            <h2>SPP Bina Bangsa</h2>
            <p>Sistem Pembayaran Sekolah</p>
        </div>
    </div>

    <!-- MENU LABEL -->
    <div class="sidebar-label">MENU UTAMA</div>

    <!-- NAVIGATION -->
    <nav class="sidebar-nav">

        <!-- DASHBOARD -->
        <a
            href="dashboard.php"
            class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
        >
            <span class="nav-icon material-symbols-rounded">dashboard</span>
            <span class="nav-text">Dashboard</span>
        </a>

        <!-- MASTER DATA -->
        <div class="nav-item-with-submenu">

            <button
                type="button"
                class="nav-link <?= $isMasterDataActive ? 'active' : '' ?>"
                onclick="toggleSubmenu('submenu-master-data', this)"
                aria-expanded="<?= $isMasterDataActive ? 'true' : 'false' ?>"
                aria-controls="submenu-master-data"
            >
                <span class="nav-icon material-symbols-rounded">database</span>
                <span class="nav-text">Master Data</span>

                <span class="submenu-arrow material-symbols-rounded">
                    chevron_right
                </span>
            </button>

            <!-- SUBMENU -->
            <div
                class="submenu <?= $isMasterDataActive ? 'open' : '' ?>"
                id="submenu-master-data"
            >
                <!-- KELAS -->
                <a
                    href="kelas.php"
                    class="submenu-link <?= $currentPage === 'kelas.php' ? 'active' : '' ?>"
                >
                    <span class="material-symbols-rounded submenu-icon">meeting_room</span>
                    <span>Kelas</span>
                </a>

                <!-- JURUSAN -->
                <a
                    href="jurusan.php"
                    class="submenu-link <?= $currentPage === 'jurusan.php' ? 'active' : '' ?>"
                >
                    <span class="material-symbols-rounded submenu-icon">school</span>
                    <span>Jurusan</span>
                </a>

                <!-- TAHUN AJARAN -->
                <a
                    href="tahun_ajaran.php"
                    class="submenu-link <?= $currentPage === 'tahun_ajaran.php' ? 'active' : '' ?>"
                >
                    <span class="material-symbols-rounded submenu-icon">calendar_month</span>
                    <span>Tahun Ajaran</span>
                </a>

                <!-- TARIF SPP -->
                <a
                    href="tarif_spp.php"
                    class="submenu-link <?= $currentPage === 'tarif_spp.php' ? 'active' : '' ?>"
                >
                    <span class="material-symbols-rounded submenu-icon">payments</span>
                    <span>Tarif SPP</span>
                </a>

                <!-- VIRTUAL ACCOUNT -->
                <a
                    href="virtual_account.php"
                    class="submenu-link <?= $currentPage === 'virtual_account.php' ? 'active' : '' ?>"
                >
                    <span class="material-symbols-rounded submenu-icon">account_balance</span>
                    <span>Virtual Account</span>
                </a>

            </div>
        </div>

        <!-- TRANSAKSI PAYMENT GATEWAY -->
        <a
            href="transaksi.php"
            class="nav-link <?= in_array($currentPage, ['transaksi.php', 'detail_transaksi.php'], true) ? 'active' : '' ?>"
        >
            <span class="nav-icon material-symbols-rounded">receipt_long</span>
            <span class="nav-text">Detail Transaksi</span>
        </a>

        <!-- TAGIHAN SPP -->
        <a
            href="tagihan_spp.php"
            class="nav-link <?= $currentPage === 'tagihan_spp.php' ? 'active' : '' ?>"
        >
            <span class="nav-icon material-symbols-rounded">request_quote</span>
            <span class="nav-text">Tagihan SPP</span>
        </a>

        <!-- LAPORAN -->
        <a
            href="laporan.php"
            class="nav-link <?= $currentPage === 'laporan.php' ? 'active' : '' ?>"
        >
            <span class="nav-icon material-symbols-rounded">assessment</span>
            <span class="nav-text">Laporan</span>
        </a>

        <!-- USER MANAGEMENT -->
        <a
            href="users.php"
            class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>"
        >
            <span class="nav-icon material-symbols-rounded">manage_accounts</span>
            <span class="nav-text">Manajemen Users</span>
        </a>


    </nav>

    <!-- SIDEBAR BOTTOM -->
    <div class="sidebar-bottom">

        <!-- USER PROFILE -->
        <div class="sidebar-user">

            <div class="user-avatar">
                <?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="user-details">
                <strong>
                    <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?>
                </strong>

                <span>Administrator</span>
            </div>

        </div>

        <!-- LOGOUT -->
        <form
            action="../config/logout.php"
            method="post"
            onsubmit="return confirm('Apakah kamu yakin ingin logout?');"
        >
            <button type="submit" class="logout-button">
                <span class="nav-icon material-symbols-rounded">logout</span>
                <span>Logout</span>
            </button>
        </form>

    </div>

</aside>

<!-- MOBILE OVERLAY -->
<div
    class="sidebar-overlay"
    id="sidebar-overlay"
    onclick="closeSidebar()"
></div>

<script>
    function toggleSubmenu(id, button) {
        const submenu = document.getElementById(id);
        const isOpen = submenu.classList.toggle('open');

        button.setAttribute('aria-expanded', String(isOpen));
    }

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('show');
        document.getElementById('sidebar-overlay').classList.toggle('show');
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('show');
        document.getElementById('sidebar-overlay').classList.remove('show');
    }
</script>
