-- ============================================================
-- Sistem Informasi Pembayaran SPP - SMK Bina Bangsa
-- Database untuk XAMPP / phpMyAdmin (MySQL / MariaDB)
--
-- Import di phpMyAdmin:
--   1. Buka http://localhost/phpmyadmin
--   2. Tab Import -> pilih file ini -> Go
--   (database db_spp_binabangsa dibuat otomatis oleh script)
--
-- Semua akun seed memakai password: password123
-- ============================================================

CREATE DATABASE IF NOT EXISTS `db_spp_binabangsa`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `db_spp_binabangsa`;

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. users
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `pembayaran`;
DROP TABLE IF EXISTS `transaksi_payment_gateway`;
DROP TABLE IF EXISTS `tagihan_spp`;
DROP TABLE IF EXISTS `virtual_account`;
DROP TABLE IF EXISTS `tarif_spp`;
DROP TABLE IF EXISTS `siswa`;
DROP TABLE IF EXISTS `kelas`;
DROP TABLE IF EXISTS `tahun_ajaran`;
DROP TABLE IF EXISTS `jurusan`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id_user`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama`       VARCHAR(100) NOT NULL,
  `username`   VARCHAR(50)  NOT NULL,
  `password`   VARCHAR(255) NOT NULL COMMENT 'bcrypt, hasil password_hash()',
  `role`       ENUM('admin','kepala_sekolah','teller','siswa') NOT NULL DEFAULT 'siswa',
  `status`     ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. jurusan
-- ------------------------------------------------------------
CREATE TABLE `jurusan` (
  `id_jurusan`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode_jurusan` VARCHAR(20)  NOT NULL,
  `nama_jurusan` VARCHAR(100) NOT NULL,
  `keterangan`   VARCHAR(255) NULL,
  PRIMARY KEY (`id_jurusan`),
  UNIQUE KEY `uq_jurusan_kode` (`kode_jurusan`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. tahun_ajaran
-- ------------------------------------------------------------
CREATE TABLE `tahun_ajaran` (
  `id_tahun_ajaran` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tahun_ajaran`    VARCHAR(20) NOT NULL COMMENT 'contoh: 2026/2027',
  `tanggal_mulai`   DATE NOT NULL,
  `tanggal_selesai` DATE NOT NULL,
  `status`          ENUM('aktif','nonaktif') NOT NULL DEFAULT 'nonaktif',
  PRIMARY KEY (`id_tahun_ajaran`),
  UNIQUE KEY `uq_tahun_ajaran` (`tahun_ajaran`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. kelas
-- ------------------------------------------------------------
CREATE TABLE `kelas` (
  `id_kelas`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_kelas`      VARCHAR(50) NOT NULL COMMENT 'contoh: XI RPL 1',
  `tingkat`         ENUM('X','XI','XII') NOT NULL,
  `nomor_ruangan`   INT UNSIGNED NOT NULL COMMENT 'nomor urut ruangan, contoh: 1, 2, 3',
  `id_jurusan`      INT UNSIGNED NOT NULL,
  `id_tahun_ajaran` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id_kelas`),
  UNIQUE KEY `uq_kelas_nama` (`nama_kelas`),
  KEY `fk_kelas_jurusan` (`id_jurusan`),
  KEY `fk_kelas_ta` (`id_tahun_ajaran`),
  CONSTRAINT `fk_kelas_jurusan` FOREIGN KEY (`id_jurusan`) REFERENCES `jurusan` (`id_jurusan`),
  CONSTRAINT `fk_kelas_ta`      FOREIGN KEY (`id_tahun_ajaran`) REFERENCES `tahun_ajaran` (`id_tahun_ajaran`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. siswa
-- ------------------------------------------------------------
CREATE TABLE `siswa` (
  `id_siswa`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nis`            CHAR(5)     NOT NULL COMMENT '5 digit, menjadi 5 digit terakhir nomor VA',
  `nisn`           CHAR(10)    NULL,
  `nama`           VARCHAR(100) NOT NULL,
  `jenis_kelamin`  ENUM('L','P') NOT NULL,
  `tempat_lahir`   VARCHAR(60) NULL,
  `tanggal_lahir`  DATE        NULL,
  `alamat`         VARCHAR(255) NULL,
  `no_hp`          VARCHAR(20) NULL,
  `nama_orang_tua` VARCHAR(100) NULL,
  `id_kelas`       INT UNSIGNED NOT NULL,
  `id_tahun_ajaran` INT UNSIGNED NOT NULL,
  `id_user`        INT UNSIGNED NULL COMMENT 'akun login siswa',
  `status`         ENUM('aktif','lulus','keluar') NOT NULL DEFAULT 'aktif',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_siswa`),
  UNIQUE KEY `uq_siswa_nis` (`nis`),
  UNIQUE KEY `uq_siswa_nisn` (`nisn`),
  UNIQUE KEY `uq_siswa_user` (`id_user`),
  KEY `fk_siswa_kelas` (`id_kelas`),
  KEY `fk_siswa_ta` (`id_tahun_ajaran`),
  CONSTRAINT `fk_siswa_kelas` FOREIGN KEY (`id_kelas`) REFERENCES `kelas` (`id_kelas`),
  CONSTRAINT `fk_siswa_ta`    FOREIGN KEY (`id_tahun_ajaran`) REFERENCES `tahun_ajaran` (`id_tahun_ajaran`),
  CONSTRAINT `fk_siswa_user`  FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. tarif_spp
-- ------------------------------------------------------------
CREATE TABLE `tarif_spp` (
  `id_tarif`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tahun_ajaran` INT UNSIGNED NOT NULL,
  `nominal`         BIGINT UNSIGNED NOT NULL COMMENT 'rupiah, tanpa desimal',
  `keterangan`      VARCHAR(100) NULL,
  `status`          ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  PRIMARY KEY (`id_tarif`),
  KEY `fk_tarif_ta` (`id_tahun_ajaran`),
  CONSTRAINT `fk_tarif_ta` FOREIGN KEY (`id_tahun_ajaran`) REFERENCES `tahun_ajaran` (`id_tahun_ajaran`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. virtual_account
-- ------------------------------------------------------------
CREATE TABLE `virtual_account` (
  `id_va`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa`       INT UNSIGNED NOT NULL,
  `nomor_va`       VARCHAR(20) NOT NULL COMMENT 'contoh: 8800012345',
  `prefix`         VARCHAR(10) NOT NULL DEFAULT '8800',
  `status`         ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `tanggal_dibuat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_va`),
  UNIQUE KEY `uq_va_nomor` (`nomor_va`),
  KEY `fk_va_siswa` (`id_siswa`),
  CONSTRAINT `fk_va_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id_siswa`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8. tagihan_spp
-- ------------------------------------------------------------
CREATE TABLE `tagihan_spp` (
  `id_tagihan`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa`            INT UNSIGNED NOT NULL,
  `id_tarif`            INT UNSIGNED NOT NULL,
  `id_tahun_ajaran`     INT UNSIGNED NOT NULL,
  `bulan`               TINYINT UNSIGNED NOT NULL COMMENT '1-12',
  `tahun`               SMALLINT UNSIGNED NOT NULL COMMENT 'contoh: 2026',
  `nominal`             BIGINT UNSIGNED NOT NULL,
  `tanggal_jatuh_tempo` DATE NOT NULL COMMENT 'tanggal terakhir bulan berjalan',
  `status`              ENUM('BELUM_BAYAR','LUNAS','TUNGGAKAN') NOT NULL DEFAULT 'BELUM_BAYAR',
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_tagihan`),
  UNIQUE KEY `uq_tagihan_periode` (`id_siswa`,`tahun`,`bulan`) COMMENT 'satu tagihan per siswa per bulan',
  KEY `fk_tagihan_tarif` (`id_tarif`),
  KEY `fk_tagihan_ta` (`id_tahun_ajaran`),
  KEY `idx_tagihan_status` (`status`),
  KEY `idx_tagihan_jatuh_tempo` (`tanggal_jatuh_tempo`),
  CONSTRAINT `fk_tagihan_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id_siswa`),
  CONSTRAINT `fk_tagihan_tarif` FOREIGN KEY (`id_tarif`) REFERENCES `tarif_spp` (`id_tarif`),
  CONSTRAINT `fk_tagihan_ta`    FOREIGN KEY (`id_tahun_ajaran`) REFERENCES `tahun_ajaran` (`id_tahun_ajaran`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 9. transaksi_payment_gateway
--    Disesuaikan untuk Midtrans (Snap / Virtual Account).
--    status_transaksi mengikuti notifikasi resmi Midtrans:
--    pending, capture, settlement, deny, cancel, expire
-- ------------------------------------------------------------
CREATE TABLE `transaksi_payment_gateway` (
  `id_transaksi`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`          VARCHAR(100) NOT NULL COMMENT 'dibuat aplikasi, contoh: TAGIHAN-3-202609',
  `transaction_id`    VARCHAR(100) NULL COMMENT 'dari notifikasi Midtrans',
  `virtual_account`   VARCHAR(20)  NOT NULL,
  `id_siswa`          INT UNSIGNED NULL,
  `tanggal_transaksi` DATE NULL,
  `waktu_transaksi`   DATETIME NULL COMMENT 'transaction_time / settlement_time dari Midtrans',
  `nominal`           BIGINT UNSIGNED NOT NULL,
  `payment_type`      VARCHAR(30) NULL COMMENT 'bank_transfer, qris, e_wallet, dll',
  `payment_channel`   VARCHAR(50) NULL COMMENT 'bca, bni, bri, mandiri, dll',
  `reference_number`  VARCHAR(100) NULL COMMENT 'nomor referensi bank / fraud',
  `status_transaksi`  ENUM('pending','capture','settlement','deny','cancel','expire') NOT NULL DEFAULT 'pending',
  `fraud_status`      ENUM('accept','deny','challenge') NULL,
  `signature_key`     VARCHAR(255) NULL,
  `raw_response`      TEXT NULL COMMENT 'payload notifikasi Midtrans apa adanya',
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_transaksi`),
  UNIQUE KEY `uq_trx_order_id` (`order_id`),
  UNIQUE KEY `uq_trx_transaction_id` (`transaction_id`),
  KEY `fk_trx_siswa` (`id_siswa`),
  KEY `idx_trx_va` (`virtual_account`),
  KEY `idx_trx_status` (`status_transaksi`),
  CONSTRAINT `fk_trx_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `siswa` (`id_siswa`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 10. pembayaran
--     Dibuat saat notifikasi berstatus settlement/capture.
--     uq_pembayaran_tagihan menjamin satu tagihan tidak
--     dilunasi dua kali (pembayaran ganda).
-- ------------------------------------------------------------
CREATE TABLE `pembayaran` (
  `id_pembayaran`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_transaksi`       INT UNSIGNED NOT NULL,
  `id_tagihan`         INT UNSIGNED NOT NULL,
  `tanggal_pembayaran` DATETIME NOT NULL COMMENT 'waktu sebenarnya, tetap tersimpan walau telat',
  `nominal_pembayaran` BIGINT UNSIGNED NOT NULL,
  `status`             ENUM('LUNAS','SEBAGIAN') NOT NULL DEFAULT 'LUNAS',
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_pembayaran`),
  UNIQUE KEY `uq_pembayaran_transaksi` (`id_transaksi`),
  UNIQUE KEY `uq_pembayaran_tagihan` (`id_tagihan`) COMMENT 'mencegah pelunasan ganda',
  KEY `fk_pembayaran_tagihan` (`id_tagihan`),
  CONSTRAINT `fk_pembayaran_trx`     FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi_payment_gateway` (`id_transaksi`),
  CONSTRAINT `fk_pembayaran_tagihan` FOREIGN KEY (`id_tagihan`) REFERENCES `tagihan_spp` (`id_tagihan`)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA
-- Semua akun: password "password123"
-- ============================================================

INSERT INTO `users` (`id_user`,`nama`,`username`,`password`,`role`,`status`) VALUES
(1,'Administrator','admin','$2y$10$iwTQvMDe/jAzrKw20g42keiMNZNL79zZ8ZOQWiU/E12BW6KOA1EDi','admin','aktif'),
(2,'Kepala Sekolah','kepsek','$2y$10$AeelKv32WgEqbD02UNpkqu0FPFOdulVfp1t1d5u4cmwF2k2MeGeXm','kepala_sekolah','aktif'),
(3,'Teller','teller','$2y$10$xMiF5f3QxCCQQfVxo5TIhuaQcBu8tI6n1GAxxqf69OevLgPuKa0PW','teller','aktif'),
(4,'Ahmad Fauzan','ahmad','$2y$10$RtNVaetOumJdsti2M1MU5.Eue9ASZoAEDb9BbT55XRNv7tVzMs1Ii','siswa','aktif'),
(5,'Siti Aminah','siti','$2y$10$cSsnGWx73Sben.DFgQ72tOgY4BkYjXyO0GrFombxttUcEa.D8KJUW','siswa','aktif'),
(6,'Budi Santoso','budi','$2y$10$gDv3Eum0V48RoAyt4ovT/.i5x3pElz/ki0tbZ89HtfcEZ3LVYMJeK','siswa','aktif'),
(7,'Citra Lestari','citra','$2y$10$BKO4F8Vc2nwKAR345IcTmurPZwWD3B7izNCNJT1pLT1Yg1xGyEMom','siswa','aktif');

INSERT INTO `jurusan` (`id_jurusan`,`kode_jurusan`,`nama_jurusan`,`keterangan`) VALUES
(1,'RPL','Rekayasa Perangkat Lunak',NULL),
(2,'TKJ','Teknik Komputer dan Jaringan',NULL),
(3,'AKL','Akuntansi dan Keuangan Lembaga',NULL);

INSERT INTO `tahun_ajaran` (`id_tahun_ajaran`,`tahun_ajaran`,`tanggal_mulai`,`tanggal_selesai`,`status`) VALUES
(1,'2025/2026','2025-07-01','2026-06-30','nonaktif'),
(2,'2026/2027','2026-07-01','2027-06-30','aktif');

INSERT INTO `kelas` (`id_kelas`,`nama_kelas`,`tingkat`,`id_jurusan`,`id_tahun_ajaran`) VALUES
(1,'X RPL 1','X',1,2),
(2,'XI RPL 1','XI',1,2),
(3,'X TKJ 1','X',2,2),
(4,'XI TKJ 1','XI',2,2),
(5,'X AKL 1','X',3,2);

INSERT INTO `siswa` (`id_siswa`,`nis`,`nisn`,`nama`,`jenis_kelamin`,`tempat_lahir`,`tanggal_lahir`,`alamat`,`no_hp`,`nama_orang_tua`,`id_kelas`,`id_tahun_ajaran`,`id_user`,`status`) VALUES
(1,'12345','0091234567','Ahmad Fauzan','L','Sidoarjo','2010-05-12','Jl. Melati No. 10, Sidoarjo','081234567890','Slamet Fauzan',2,2,4,'aktif'),
(2,'12346','0091234568','Siti Aminah','P','Sidoarjo','2010-08-23','Jl. Mawar No. 5, Sidoarjo','081234567891','Agus Amin',1,2,5,'aktif'),
(3,'12347','0091234569','Budi Santoso','L','Surabaya','2010-02-17','Jl. Kenanga No. 8, Sidoarjo','081234567892','Hendra Santoso',3,2,6,'aktif'),
(4,'12348','0091234570','Citra Lestari','P','Sidoarjo','2010-11-02','Jl. Anggrek No. 21, Sidoarjo','081234567893','Dwi Lestari',5,2,7,'aktif');

INSERT INTO `tarif_spp` (`id_tarif`,`id_tahun_ajaran`,`nominal`,`keterangan`,`status`) VALUES
(1,1,300000,'SPP TA 2025/2026','nonaktif'),
(2,2,350000,'SPP TA 2026/2027','aktif');

-- Aturan VA: prefix "8800" + 5 digit NIS  =>  8800012345 dst.
INSERT INTO `virtual_account` (`id_va`,`id_siswa`,`nomor_va`,`prefix`,`status`) VALUES
(1,1,'8800012345','8800','aktif'),
(2,2,'8800012346','8800','aktif'),
(3,3,'8800012347','8800','aktif'),
(4,4,'8800012348','8800','aktif');

-- Tagihan September 2026: contoh kasus sesuai soal.
-- tanggal_jatuh_tempo = hari terakhir bulan (2026-09-30)
INSERT INTO `tagihan_spp` (`id_tagihan`,`id_siswa`,`id_tarif`,`id_tahun_ajaran`,`bulan`,`tahun`,`nominal`,`tanggal_jatuh_tempo`,`status`) VALUES
(1,1,2,2,9,2026,350000,'2026-09-30','LUNAS'),
(2,2,2,2,9,2026,350000,'2026-09-30','LUNAS'),
(3,3,2,2,9,2026,350000,'2026-09-30','TUNGGAKAN'),
(4,4,2,2,9,2026,350000,'2026-09-30','BELUM_BAYAR');

-- Contoh transaksi & pembayaran untuk Ahmad Fauzan (VA 8800012345)
INSERT INTO `transaksi_payment_gateway` (`id_transaksi`,`order_id`,`transaction_id`,`virtual_account`,`id_siswa`,`tanggal_transaksi`,`waktu_transaksi`,`nominal`,`payment_type`,`payment_channel`,`reference_number`,`status_transaksi`,`fraud_status`,`raw_response`) VALUES
(1,'TAGIHAN-1-202609','1506724182863','8800012345',1,'2026-09-25','2026-09-25 10:15:00',350000,'bank_transfer','bca','1506724182863','settlement','accept','{"va_number":"8800012345","bank":"bca","status":"settlement"}'),
(2,'TAGIHAN-2-202609','1506724182864','8800012346',2,'2026-09-28','2026-09-28 14:30:00',350000,'bank_transfer','bni','1506724182864','settlement','accept','{"va_number":"8800012346","bank":"bni","status":"settlement"}'),
(3,'TAGIHAN-3-202609','1506724182865','8800012347',3,'2026-10-05','2026-10-05 09:00:00',350000,'bank_transfer','bri','1506724182865','pending',NULL,'{"va_number":"8800012347","bank":"bri","status":"pending"}');

INSERT INTO `pembayaran` (`id_pembayaran`,`id_transaksi`,`id_tagihan`,`tanggal_pembayaran`,`nominal_pembayaran`,`status`) VALUES
(1,1,1,'2026-09-25 10:15:00',350000,'LUNAS'),
(2,2,2,'2026-09-28 14:30:00',350000,'LUNAS');
