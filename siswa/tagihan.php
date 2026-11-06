<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/midtrans.php';
require_once __DIR__ . '/../config/auth.php';
require_role('siswa');

if (($_SESSION['user']['role'] ?? '') !== 'siswa') {
    header('Location: ../index.php');
    exit;
}

function e(mixed $value): string { return htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8'); }
function rupiah(int|float $value): string { return 'Rp ' . number_format((float) $value, 0, ',', '.'); }
function bulan(int $month): string { return [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][$month] ?? '-'; }
function tanggalIndonesia(?string $date): string { if (!$date || strtotime($date) === false) return '-'; $time=strtotime($date); return date('d',$time).' '.bulan((int)date('n',$time)).' '.date('Y',$time); }
function statusLabel(string $status): string { return match($status) { 'LUNAS'=>'Lunas', 'TUNGGAKAN'=>'Tunggakan', 'BELUM_BAYAR'=>'Belum Bayar', default=>ucfirst(strtolower($status)) }; }
function statusClass(string $status): string { return match($status) { 'LUNAS'=>'status-paid', 'TUNGGAKAN'=>'status-overdue', default=>'status-pending' }; }

$student = null;
$bills = [];
$databaseError = null;
$totalUnpaid = 0;
$totalOverdue = 0;
$unpaidCount = 0;
$filter = (string) ($_GET['status'] ?? 'semua');
$allowedFilters = ['semua', 'BELUM_BAYAR', 'TUNGGAKAN', 'LUNAS'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'semua';

try {
    $stmt=$pdo->prepare('SELECT s.id_siswa, s.nis, s.nama, k.nama_kelas, j.nama_jurusan, va.nomor_va FROM siswa s INNER JOIN kelas k ON k.id_kelas=s.id_kelas INNER JOIN jurusan j ON j.id_jurusan=k.id_jurusan LEFT JOIN virtual_account va ON va.id_siswa=s.id_siswa AND va.status="aktif" WHERE s.id_user=? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user']['id_user']]); $student=$stmt->fetch();
    if (!$student) throw new RuntimeException('Profil siswa belum terhubung dengan akun.');

    $stmt=$pdo->prepare('UPDATE tagihan_spp SET status="TUNGGAKAN" WHERE id_siswa=? AND status="BELUM_BAYAR" AND tanggal_jatuh_tempo<CURDATE()');
    $stmt->execute([(int)$student['id_siswa']]);

    $summaryStatement = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN status <> "LUNAS" THEN nominal ELSE 0 END), 0) AS total_unpaid,
            COALESCE(SUM(CASE WHEN status = "TUNGGAKAN" THEN nominal ELSE 0 END), 0) AS total_overdue,
            COALESCE(SUM(status <> "LUNAS"), 0) AS unpaid_count
         FROM tagihan_spp
         WHERE id_siswa = ?'
    );
    $summaryStatement->execute([(int)$student['id_siswa']]);
    $summary = $summaryStatement->fetch() ?: [];
    $totalUnpaid = (int) ($summary['total_unpaid'] ?? 0);
    $totalOverdue = (int) ($summary['total_overdue'] ?? 0);
    $unpaidCount = (int) ($summary['unpaid_count'] ?? 0);

    $sql='SELECT ts.*, p.tanggal_pembayaran, p.nominal_pembayaran,
                 (SELECT tpg.order_id
                  FROM transaksi_payment_gateway tpg
                  WHERE tpg.status_transaksi="pending"
                    AND tpg.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                    AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(tpg.order_id, "-", 2), "-", -1) AS UNSIGNED)=ts.id_tagihan
                  ORDER BY tpg.created_at DESC, tpg.id_transaksi DESC
                  LIMIT 1) AS pending_order_id
          FROM tagihan_spp ts
          LEFT JOIN pembayaran p ON p.id_tagihan=ts.id_tagihan
          WHERE ts.id_siswa=?';
    $params=[(int)$student['id_siswa']];
    if ($filter !== 'semua') { $sql .= ' AND ts.status=?'; $params[]=$filter; }
    $sql .= ' ORDER BY (ts.tahun=YEAR(CURDATE()) AND ts.bulan=MONTH(CURDATE())) DESC, ts.tahun DESC, ts.bulan DESC';
    $stmt=$pdo->prepare($sql); $stmt->execute($params); $bills=$stmt->fetchAll();
} catch (Throwable $exception) {
    error_log('Halaman tagihan siswa: '.$exception->getMessage());
    $databaseError='Data tagihan belum dapat dimuat. Periksa koneksi database.';
}

$clientKey = midtrans_client_key();
if (empty($_SESSION['csrf_tagihan'])) $_SESSION['csrf_tagihan'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_tagihan'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tagihan Siswa | SPP Bina Bangsa</title>
    <link rel="stylesheet" href="../assets/css/siswa/sidebar.css">
    <link rel="stylesheet" href="../assets/css/siswa/tagihan.css">
</head>
<body>
<?php require_once __DIR__ . '/partials/sidebar.php'; ?>
<main class="dashboard-main bills-main">
    <header class="dashboard-header"><div><h1>Tagihan SPP</h1><p>Lihat tagihan dan lakukan pembayaran melalui Midtrans.</p></div><div class="student-greeting"><span>Siswa</span><strong><?=e($student['nama']??$_SESSION['user']['nama']??'Siswa')?></strong></div></header>
    <?php if ($databaseError): ?><div class="database-error" role="alert"><?=e($databaseError)?></div><?php endif; ?>
    <?php if (($_GET['payment'] ?? '') === 'pending'): ?><div class="bills-alert alert-info">Pembayaran sedang menunggu konfirmasi dari Midtrans.</div><?php endif; ?>
    <?php if (($_GET['payment'] ?? '') === 'success'): ?><div class="bills-alert alert-success">Pembayaran berhasil dikirim. Status akan diperbarui setelah callback Midtrans diterima.</div><?php endif; ?>

    <section class="bill-summary-grid">
    <article class="bill-summary-card"><span class="material-symbols-rounded summary-card-icon blue">receipt_long</span><div><span>Total belum lunas</span><strong><?=rupiah($totalUnpaid)?></strong><small><?=$unpaidCount?> tagihan</small></div></article>
        <article class="bill-summary-card overdue-summary"><span class="material-symbols-rounded summary-card-icon red">warning</span><div><span>Total tunggakan</span><strong><?=rupiah($totalOverdue)?></strong><small>Perlu segera diselesaikan</small></div></article>
        <article class="bill-summary-card"><span class="material-symbols-rounded summary-card-icon green">account_balance_wallet</span><div><span>Virtual Account</span><strong class="va-summary"><?=e($student['nomor_va']??'Belum tersedia')?></strong><small>NIS <?=e($student['nis']??'-')?></small></div></article>
    </section>

    <section class="panel bills-panel">
        <div class="bills-panel-heading"><div><span class="section-label">Daftar tagihan</span><h2><?=count($bills)?> tagihan ditampilkan</h2></div><div class="filter-tabs"><?php foreach(['semua'=>'Semua','BELUM_BAYAR'=>'Belum Bayar','TUNGGAKAN'=>'Tunggakan','LUNAS'=>'Lunas'] as $value=>$label): ?><a class="filter-tab <?=$filter===$value?'active':''?>" href="tagihan.php?status=<?=e($value)?>"><?=e($label)?></a><?php endforeach; ?></div></div>
        <div class="bill-table-wrap"><table class="bill-table"><thead><tr><th>Periode</th><th>Nominal</th><th>Jatuh tempo</th><th>Status</th><th>Pembayaran</th><th>Aksi</th></tr></thead><tbody>
        <?php if (!$bills): ?><tr><td colspan="6" class="empty-bills">Belum ada tagihan yang sesuai filter.</td></tr><?php else: foreach($bills as $bill): ?><tr><td><strong>SPP <?=e(bulan((int)$bill['bulan']))?></strong><small><?=e((string)$bill['tahun'])?></small></td><td class="bill-nominal"><?=rupiah((int)$bill['nominal'])?></td><td><?=e(tanggalIndonesia($bill['tanggal_jatuh_tempo']))?></td><td><span class="status-badge <?=e(statusClass($bill['status']))?>"><?=e(statusLabel($bill['status']))?></span></td><td><?php if($bill['tanggal_pembayaran']): ?><strong><?=e(tanggalIndonesia($bill['tanggal_pembayaran']))?></strong><small><?=rupiah((int)$bill['nominal_pembayaran'])?></small><?php elseif($bill['pending_order_id']): ?><span class="not-paid">Menunggu pembayaran</span><small>Transaksi VA masih pending</small><?php else: ?><span class="not-paid">Belum ada pembayaran</span><?php endif; ?></td><td><?php if($bill['status']==='LUNAS'): ?><span class="paid-label"><span class="material-symbols-rounded">check_circle</span> Selesai</span><?php elseif($bill['pending_order_id']): ?><span class="pending-payment-label"><span class="material-symbols-rounded">schedule</span> Menunggu</span><button class="cancel-payment-button" type="button" data-order-id="<?=e((string)$bill['pending_order_id'])?>"><span class="material-symbols-rounded">swap_horiz</span> Batalkan &amp; pilih bank lain</button><?php else: ?><button class="pay-button" type="button" data-bill-id="<?= (int)$bill['id_tagihan']?>" <?= $clientKey===''?'disabled':'' ?>>Bayar sekarang <span class="material-symbols-rounded">arrow_forward</span></button><?php endif; ?></td></tr><?php endforeach; endif; ?>
        </tbody></table></div>
    </section>
    <p class="payment-footnote"><span class="material-symbols-rounded">verified_user</span> Pembayaran hanya menggunakan Virtual Account siswa melalui Midtrans. Status tagihan berubah setelah sistem menerima notifikasi pembayaran yang valid.</p>
</main>
<?php if ($clientKey !== ''): ?>
    <script src="<?= e(midtrans_snap_script_url()) ?>" data-client-key="<?= e($clientKey) ?>"></script>
    <script>
        async function cancelPendingTransaction(orderId, button, redirect) {
            if (button) {
                button.disabled = true;
                button.textContent = 'Membatalkan…';
            }

            try {
                const response = await fetch('../config/midtrans_cancel_transaction.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({order_id: orderId, csrf: '<?= e($csrfToken) ?>'})
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'Transaksi belum dapat dibatalkan.');
                }
                window.location.href = redirect
                    ? 'tagihan.php?payment=cancelled'
                    : 'tagihan.php';
            } catch (error) {
                alert(error.message);
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Batalkan & pilih bank lain';
                }
            }
        }

        document.querySelectorAll('.cancel-payment-button').forEach(function (button) {
            button.addEventListener('click', function () {
                if (confirm('Batalkan transaksi ini agar dapat memilih bank lain?')) {
                    cancelPendingTransaction(this.dataset.orderId, this, false);
                }
            });
        });

        document.querySelectorAll('.pay-button').forEach(function (button) {
            button.addEventListener('click', async function () {
                const id = this.dataset.billId;
                const original = this.innerHTML;
                button.disabled = true;
                button.innerHTML = 'Menyiapkan pembayaran…';

                let orderId = '';
                try {
                    const response = await fetch('../config/midtrans_create_transaction.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({id_tagihan: id, csrf: '<?= e($csrfToken) ?>'})
                    });
                    const data = await response.json();
                    if (!response.ok || !data.token) {
                        throw new Error(data.message || 'Transaksi tidak dapat dibuat.');
                    }
                    orderId = data.order_id || '';

                    window.snap.pay(data.token, {
                        onSuccess: function () {
                            window.location.href = 'tagihan.php?payment=success';
                        },
                        onPending: function () {
                            window.location.href = 'tagihan.php?payment=pending';
                        },
                        onError: function () {
                            alert('Pembayaran belum berhasil. Silakan coba lagi.');
                            button.disabled = false;
                            button.innerHTML = original;
                        },
                        onClose: function () {
                            if (orderId) {
                                cancelPendingTransaction(orderId, null, true);
                            } else {
                                button.disabled = false;
                                button.innerHTML = original;
                            }
                        }
                    });
                } catch (error) {
                    alert(error.message);
                    button.disabled = false;
                    button.innerHTML = original;
                }
            });
        });
    </script>
<?php endif; ?>
</body>
</html>
