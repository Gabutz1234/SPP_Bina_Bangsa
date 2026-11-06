<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/auth.php';
require_role('siswa');

if (($_SESSION['user']['role'] ?? '') !== 'siswa') {
    header('Location: ../index.php');
    exit;
}

function e(mixed $value): string { return htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8'); }
function rupiah(int|float $value): string { return 'Rp ' . number_format((float) $value, 0, ',', '.'); }
function namaBulan(int $bulan): string { return [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][$bulan] ?? '-'; }
function tanggalIndonesia(?string $tanggal): string { if (!$tanggal || strtotime($tanggal) === false) return '-'; $time=strtotime($tanggal); return date('d',$time).' '.namaBulan((int)date('n',$time)).' '.date('Y',$time); }
function statusLabel(string $status): string { return match($status) { 'LUNAS'=>'Lunas', 'TUNGGAKAN'=>'Tunggakan', 'BELUM_BAYAR'=>'Belum Bayar', default=>ucfirst(strtolower($status)) }; }
function statusClass(string $status): string { return match($status) { 'LUNAS'=>'status-paid', 'TUNGGAKAN'=>'status-overdue', default=>'status-pending' }; }
function transaksiLabel(string $status): string { return match($status) { 'settlement', 'capture'=>'Berhasil', 'pending'=>'Menunggu', 'cancel'=>'Dibatalkan', 'expire'=>'Kedaluwarsa', 'deny'=>'Ditolak', default=>ucfirst($status) }; }
function transaksiClass(string $status): string { return match($status) { 'settlement', 'capture'=>'history-status-success', 'pending'=>'history-status-pending', 'cancel', 'deny'=>'history-status-failed', 'expire'=>'history-status-expired', default=>'history-status-neutral' }; }

$student=null; $currentBill=null; $recentPayments=[]; $totalOverdue=0; $databaseError=null;
try {
    $stmt=$pdo->prepare('SELECT s.*, k.nama_kelas, j.nama_jurusan, ta.tahun_ajaran, va.nomor_va FROM siswa s INNER JOIN kelas k ON k.id_kelas=s.id_kelas INNER JOIN jurusan j ON j.id_jurusan=k.id_jurusan INNER JOIN tahun_ajaran ta ON ta.id_tahun_ajaran=s.id_tahun_ajaran LEFT JOIN virtual_account va ON va.id_siswa=s.id_siswa AND va.status="aktif" WHERE s.id_user=? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user']['id_user']]); $student=$stmt->fetch();
    if (!$student) throw new RuntimeException('Profil siswa belum terhubung dengan akun login.');
    $stmt=$pdo->prepare('UPDATE tagihan_spp SET status="TUNGGAKAN" WHERE id_siswa=? AND status="BELUM_BAYAR" AND tanggal_jatuh_tempo<CURDATE()'); $stmt->execute([(int)$student['id_siswa']]);
    $stmt=$pdo->prepare('SELECT ts.*, p.tanggal_pembayaran, p.nominal_pembayaran FROM tagihan_spp ts LEFT JOIN pembayaran p ON p.id_tagihan=ts.id_tagihan WHERE ts.id_siswa=? AND ts.bulan=MONTH(CURDATE()) AND ts.tahun=YEAR(CURDATE()) LIMIT 1'); $stmt->execute([(int)$student['id_siswa']]); $currentBill=$stmt->fetch()?:null;
    $stmt=$pdo->prepare('SELECT ts.bulan, ts.tahun,
                                p.tanggal_pembayaran, p.nominal_pembayaran,
                                t.payment_type, t.payment_channel,
                                t.status_transaksi, t.waktu_transaksi,
                                t.created_at, t.nominal AS nominal_transaksi
                         FROM transaksi_payment_gateway t
                         INNER JOIN tagihan_spp ts
                            ON ts.id_tagihan = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(t.order_id, "-", 2), "-", -1) AS UNSIGNED)
                         LEFT JOIN pembayaran p ON p.id_transaksi=t.id_transaksi
                         WHERE ts.id_siswa=?
                         ORDER BY COALESCE(p.tanggal_pembayaran, t.waktu_transaksi, t.created_at) DESC,
                                  t.id_transaksi DESC
                         LIMIT 5'); $stmt->execute([(int)$student['id_siswa']]); $recentPayments=$stmt->fetchAll();
    $stmt=$pdo->prepare('SELECT COALESCE(SUM(nominal),0) FROM tagihan_spp WHERE id_siswa=? AND status="TUNGGAKAN"'); $stmt->execute([(int)$student['id_siswa']]); $totalOverdue=(int)$stmt->fetchColumn();
} catch (Throwable $exception) { error_log('Dashboard siswa: '.$exception->getMessage()); $databaseError='Data dashboard belum dapat dimuat. Periksa koneksi database atau hubungan akun siswa.'; }
$userName=$student['nama']??($_SESSION['user']['nama']??'Siswa'); $currentBillStatus=$currentBill['status']??'BELUM_BAYAR';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Siswa | SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/siswa/sidebar.css"><link rel="stylesheet" href="../assets/css/siswa/dashboard.css">
</head>
<body>
<?php require_once __DIR__ . '/partials/sidebar.php'; ?>
<main class="dashboard-main">
    <header class="dashboard-header"><div><h1>Dashboard</h1><p>Ringkasan pembayaran SPP kamu</p></div><div class="student-greeting"><span>Siswa</span><strong><?=e($userName)?></strong></div></header>
    <?php if ($databaseError): ?><div class="database-error" role="alert"><?=e($databaseError)?></div><?php endif; ?>
    <section class="welcome-card"><div><p>Selamat datang,</p><h2><?=e($userName)?></h2><p>Kelola pembayaran SPP dan pantau status tagihanmu dengan mudah.</p></div><div class="welcome-meta"><span>Data siswa</span><strong>NIS <?=e($student['nis']??'-')?></strong><span><?=e($student['nama_kelas']??'-')?> · <?=e($student['nama_jurusan']??'-')?></span></div></section>
    <section class="financial-overview">
    <section class="current-bill-feature <?=e(statusClass($currentBillStatus))?>">
        <div class="current-bill-content">
            <span class="feature-label">Tagihan utama · bulan ini</span>
            <?php if ($currentBill): ?>
                <h2>SPP <?=e(namaBulan((int)$currentBill['bulan']))?> <?=e((string)$currentBill['tahun'])?></h2>
                <p>Segera selesaikan pembayaran sebelum tanggal jatuh tempo.</p>
            <?php else: ?>
                <h2>Belum ada tagihan bulan ini</h2>
                <p>Tagihan SPP akan tampil setelah dibuat oleh admin sekolah.</p>
            <?php endif; ?>
        </div>
        <?php if ($currentBill): ?>
            <div class="current-bill-amount"><span>Total tagihan</span><strong><?=rupiah((int)$currentBill['nominal'])?></strong></div>
        <?php endif; ?>
    </section>
    <article class="panel overdue-card"><div class="panel-heading"><div><h2>Total tunggakan</h2><p>Tagihan yang belum diselesaikan.</p></div><div class="summary-icon green"><span class="material-symbols-rounded">warning</span></div></div><div class="overdue-content"><strong><?=rupiah($totalOverdue)?></strong><span><?= $totalOverdue > 0 ? 'Segera selesaikan pembayaranmu.' : 'Tidak ada tunggakan saat ini.' ?></span></div></article>
    </section>
    <section class="dashboard-columns">
        <article class="panel history-panel"><div class="panel-heading"><div><h2>Riwayat transaksi</h2><p>Lima transaksi pembayaran terakhir.</p></div><a class="panel-link" href="riwayat.php">Lihat semua</a></div><div class="history-list">
            <?php if (!$recentPayments): ?><div class="empty-state">Belum ada riwayat transaksi.</div><?php else: foreach($recentPayments as $payment): ?><?php $tanggalPembayaran = $payment['tanggal_pembayaran'] ?: ($payment['waktu_transaksi'] ?: $payment['created_at']); $nominalPembayaran = $payment['nominal_pembayaran'] ?: $payment['nominal_transaksi']; $statusTransaksi = (string)($payment['status_transaksi'] ?? ''); ?><div class="history-row"><div class="month-badge"><strong><?=e(date('M',mktime(0,0,0,(int)$payment['bulan'],1)))?></strong><small><?=e((string)$payment['tahun'])?></small></div><div class="history-info"><strong>SPP <?=e(namaBulan((int)$payment['bulan']))?></strong><small><?=e(tanggalIndonesia($tanggalPembayaran))?> · <?=e($payment['payment_channel']?:$payment['payment_type']?:'Midtrans')?> · <span class="<?=e(transaksiClass($statusTransaksi))?>"><?=e(transaksiLabel($statusTransaksi))?></span></small></div><strong class="history-amount"><?=rupiah((int)$nominalPembayaran)?></strong></div><?php endforeach; endif; ?>
        </div></article>
        <aside><article class="panel account-card"><div class="panel-heading"><div><h2>Akun Virtual Account</h2><p>Gunakan VA ini untuk pembayaran SPP.</p></div></div><div class="va-box"><span>Nomor VA siswa</span><strong><?=e($student['nomor_va']??'Belum tersedia')?></strong><small>Terhubung dengan NIS <?=e($student['nis']??'-')?></small></div></article></aside>
    </section>
</main>
</body>
</html>
