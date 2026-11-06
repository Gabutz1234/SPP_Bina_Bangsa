<?php
declare(strict_types=1);

/**
 * Memastikan siswa memiliki satu Virtual Account aktif.
 *
 * @return array{nomor_va: string, dibuat: bool}
 */
function ensure_active_virtual_account(
    PDO $pdo,
    int $idSiswa,
    string $prefix = '8800'
): array {
    if ($idSiswa <= 0 || !preg_match('/^\d{1,10}$/', $prefix)) {
        throw new RuntimeException('Data siswa atau prefix Virtual Account tidak valid.');
    }

    $stmt = $pdo->prepare(
        'SELECT id_siswa, nis
         FROM siswa
         WHERE id_siswa = ? AND status = "aktif"
         LIMIT 1'
    );
    $stmt->execute([$idSiswa]);
    $siswa = $stmt->fetch();

    if (!$siswa) {
        throw new RuntimeException('Siswa aktif tidak ditemukan untuk pembuatan Virtual Account.');
    }

    $nis = (string) $siswa['nis'];
    if (!preg_match('/^\d{5}$/', $nis)) {
        throw new RuntimeException('NIS siswa harus terdiri dari 5 digit untuk membuat Virtual Account.');
    }

    $nomorVA = $prefix . $nis;

    $stmt = $pdo->prepare(
        'SELECT id_va, nomor_va, status
         FROM virtual_account
         WHERE id_siswa = ?
         ORDER BY status = "aktif" DESC, id_va DESC
         LIMIT 1'
    );
    $stmt->execute([$idSiswa]);
    $va = $stmt->fetch();

    if ($va && $va['status'] === 'aktif' && $va['nomor_va'] === $nomorVA) {
        return ['nomor_va' => (string) $va['nomor_va'], 'dibuat' => false];
    }

    $stmt = $pdo->prepare(
        'SELECT id_va
         FROM virtual_account
         WHERE nomor_va = ? AND id_siswa <> ?
         LIMIT 1'
    );
    $stmt->execute([$nomorVA, $idSiswa]);
    if ($stmt->fetch()) {
        throw new RuntimeException('Nomor Virtual Account ' . $nomorVA . ' sudah digunakan siswa lain.');
    }

    if ($va) {
        $stmt = $pdo->prepare(
            'UPDATE virtual_account
             SET nomor_va = ?, prefix = ?, status = "aktif"
             WHERE id_va = ?'
        );
        $stmt->execute([$nomorVA, $prefix, (int) $va['id_va']]);

        return ['nomor_va' => $nomorVA, 'dibuat' => true];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO virtual_account (id_siswa, nomor_va, prefix, status)
         VALUES (?, ?, ?, "aktif")'
    );
    $stmt->execute([$idSiswa, $nomorVA, $prefix]);

    return ['nomor_va' => $nomorVA, 'dibuat' => true];
}
