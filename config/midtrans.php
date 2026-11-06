<?php
declare(strict_types=1);

// Isi melalui environment variable MIDTRANS_SERVER_KEY.
// Jangan menaruh Server Key di halaman atau JavaScript yang dikirim ke browser.
function midtrans_server_key(): string
{
    return trim((string) getenv('MIDTRANS_SERVER_KEY'));
}

function midtrans_client_key(): string
{
    return trim((string) (getenv('MIDTRANS_CLIENT_KEY') ?: getenv('MIDTRANS_ACCESS_KEY')));
}

function midtrans_snap_script_url(): string
{
    return midtrans_is_production()
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js';
}

function midtrans_snap_transaction_url(): string
{
    return midtrans_is_production()
        ? 'https://app.midtrans.com/snap/v1/transactions'
        : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
}

function midtrans_cancel_transaction(string $orderId): array
{
    $baseUrl = midtrans_is_production()
        ? 'https://api.midtrans.com/v2/'
        : 'https://api.sandbox.midtrans.com/v2/';
    $curl = curl_init($baseUrl . rawurlencode($orderId) . '/cancel');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(midtrans_server_key() . ':'),
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    return ['code' => $httpCode, 'response' => $response, 'error' => $error];
}

function midtrans_fetch_transaction_status(string $orderId): array
{
    $baseUrl = midtrans_is_production()
        ? 'https://api.midtrans.com/v2/'
        : 'https://api.sandbox.midtrans.com/v2/';
    $curl = curl_init($baseUrl . rawurlencode($orderId) . '/status');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(midtrans_server_key() . ':'),
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    return ['code' => $httpCode, 'response' => $response, 'error' => $error];
}

function midtrans_reconcile_expired_transaction(PDO $pdo, array &$transaction): void
{
    if ((string) ($transaction['status_transaksi'] ?? '') !== 'pending' ||
        midtrans_server_key() === '') {
        return;
    }

    $lastActivity = (string) ($transaction['updated_at'] ?? $transaction['created_at'] ?? '');
    if ($lastActivity !== '' && strtotime($lastActivity) !== false &&
        strtotime($lastActivity) > time() - 300) {
        return;
    }

    $orderId = trim((string) ($transaction['order_id'] ?? ''));
    if ($orderId === '') {
        return;
    }

    $result = midtrans_fetch_transaction_status($orderId);
    if (($result['code'] ?? 0) < 200 || ($result['code'] ?? 0) >= 300) {
        return;
    }

    $payload = json_decode((string) ($result['response'] ?? ''), true);
    if (!is_array($payload) || ($payload['transaction_status'] ?? '') !== 'expire') {
        return;
    }

    $rawResponse = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $transactionTime = (string) ($payload['transaction_time'] ?? '');
    $tanggalTransaksi = $transactionTime !== '' ? substr($transactionTime, 0, 10) : null;
    $stmt = $pdo->prepare(
        'UPDATE transaksi_payment_gateway
         SET transaction_id = COALESCE(NULLIF(?, ""), transaction_id),
             tanggal_transaksi = COALESCE(?, tanggal_transaksi),
             waktu_transaksi = COALESCE(?, waktu_transaksi),
             status_transaksi = "expire",
             raw_response = ?
         WHERE id_transaksi = ? AND status_transaksi = "pending"'
    );
    $stmt->execute([
        (string) ($payload['transaction_id'] ?? ''),
        $tanggalTransaksi,
        $transactionTime !== '' ? $transactionTime : null,
        $rawResponse,
        (int) ($transaction['id_transaksi'] ?? 0),
    ]);

    if ($stmt->rowCount() > 0) {
        $transaction['status_transaksi'] = 'expire';
        $transaction['transaction_id'] = (string) ($payload['transaction_id'] ?? $transaction['transaction_id'] ?? '');
        $transaction['waktu_transaksi'] = $transactionTime !== '' ? $transactionTime : ($transaction['waktu_transaksi'] ?? null);
        $transaction['tanggal_transaksi'] = $tanggalTransaksi ?: ($transaction['tanggal_transaksi'] ?? null);
    }
}

function midtrans_is_production(): bool
{
    return filter_var(getenv('MIDTRANS_IS_PRODUCTION') ?: 'false', FILTER_VALIDATE_BOOLEAN);
}

function midtrans_signature_valid(array $payload): bool
{
    $serverKey = midtrans_server_key();
    $signature = (string) ($payload['signature_key'] ?? '');
    $orderId = (string) ($payload['order_id'] ?? '');
    $statusCode = (string) ($payload['status_code'] ?? '');
    $grossAmount = (string) ($payload['gross_amount'] ?? '');

    if ($serverKey === '' || $signature === '' || $orderId === '' ||
        $statusCode === '' || $grossAmount === '') {
        return false;
    }

    $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
    return hash_equals($expected, $signature);
}

function midtrans_status_label(string $status): string
{
    return match ($status) {
        'settlement' => 'Berhasil',
        'capture' => 'Berhasil',
        'pending' => 'Menunggu Pembayaran',
        'deny' => 'Ditolak',
        'cancel' => 'Dibatalkan',
        'expire' => 'Kadaluarsa',
        default => ucfirst($status),
    };
}

function midtrans_status_class(string $status): string
{
    return match ($status) {
        'settlement', 'capture' => 'status-success',
        'pending' => 'status-pending',
        'deny', 'cancel' => 'status-danger',
        'expire' => 'status-expired',
        default => 'status-neutral',
    };
}

function midtrans_is_success(string $status, ?string $fraudStatus): bool
{
    if ($status === 'settlement') {
        return true;
    }

    return $status === 'capture' &&
        ($fraudStatus === null || strtolower($fraudStatus) === 'accept');
}
