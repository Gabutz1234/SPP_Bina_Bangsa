# Spesifikasi Proyek — Sistem Informasi Pembayaran SPP Berbasis Web

> Ringkasan terstruktur materi uji sertifikasi praktik demonstrasi LSP P-1 SMK Antartika 2 Sidoarjo, tahun ajaran 2026–2027. Dokumen ini menjadi acuan kebutuhan aplikasi; istilah dan aturan inti dari sumber dipertahankan.

## 1. Identitas Proyek

- **Nama:** Sistem Aplikasi Pembayaran SPP Berbasis Web
- **Jenis:** Web Application
- **Studi kasus:** SMK Bina Bangsa
- **Alamat:** Jl. Pendidikan Bangsa No. 25, Desa Sumber Makmur, Kecamatan Sukamaju, Kabupaten Sidoarjo, Jawa Timur 61256
- **Telepon:** (031) 8856731
- **Email:** info@smkbinabangsa.sch.id
- **Integrasi:** Virtual Account (VA) dan simulasi Payment Gateway.

## 2. Latar Belakang dan Tujuan

SMK Bina Bangsa membutuhkan sistem pembayaran SPP berbasis web untuk meningkatkan efektivitas, transparansi, dan kemudahan pengelolaan. Setiap siswa memiliki VA unik dan membayar melalui VA tersebut. Siswa tidak perlu datang untuk membayar tunai atau mengirim screenshot bukti transfer.

Payment Gateway memproses transaksi lalu mengirimkan status ke sistem sekolah melalui callback/webhook atau mekanisme simulasi buatan peserta. Sistem mencocokkan VA dengan siswa dan tagihan, kemudian memperbarui status pembayaran. Tidak boleh sekadar menyediakan tombol Bayar yang langsung mengubah status menjadi LUNAS.

SPP harus dibayar paling lambat pada hari terakhir setiap bulan. Jika belum ada pembayaran hingga akhir bulan, mulai tanggal 1 bulan berikutnya tagihan menjadi TUNGGAKAN.

### Sasaran kemampuan aplikasi

Aplikasi harus mampu:
1. Mengelola data siswa dan pengguna.
2. Mengelola kelas, jurusan, dan tahun ajaran.
3. Mengelola tarif SPP.
4. Mengelola VA siswa.
5. Membentuk tagihan SPP bulanan dan menampilkan jatuh tempo.
6. Menerima simulasi transaksi Payment Gateway.
7. Mengidentifikasi siswa berdasarkan VA.
8. Mencatat transaksi pembayaran.
9. Memperbarui status tagihan secara otomatis.
10. Menentukan tunggakan berdasarkan jatuh tempo.
11. Menampilkan riwayat pembayaran.
12. Menampilkan laporan pembayaran dan tunggakan.
13. Menerapkan hak akses berdasarkan role.
14. Membatasi siswa agar hanya melihat data miliknya sendiri.
15. Mendemonstrasikan integrasi Payment Gateway secara nyata atau simulatif.

## 3. Alur Bisnis Pembayaran

**Alur utama:**

`SISWA → VIRTUAL ACCOUNT → PAYMENT GATEWAY → SISTEM SEKOLAH → TAGIHAN → STATUS PEMBAYARAN`

Rincian:
1. Siswa melakukan pembayaran melalui VA.
2. Payment Gateway memproses transaksi.
3. Payment Gateway mengirim callback/webhook ke sistem sekolah.
4. Sistem menerima dan memvalidasi transaksi.
5. Sistem mencari VA, lalu mengidentifikasi siswa.
6. Sistem mencari tagihan dan mencocokkan nominal.
7. Sistem menyimpan transaksi.
8. Sistem memperbarui status tagihan menjadi LUNAS.

### Metode pembayaran wajib

Pembayaran SPP **hanya melalui Virtual Account**. Dilarang menggunakan:
- Pembayaran tunai.
- Pembayaran langsung kepada teller.
- Transfer ke rekening umum sekolah.
- Upload screenshot bukti transfer.
- Form konfirmasi pembayaran manual.

## 4. Aturan Virtual Account

- Setiap siswa wajib memiliki VA yang unik.
- **Lima digit terakhir nomor VA harus sama dengan NIS siswa.**
- Prefix VA boleh ditentukan peserta.
- Satu VA hanya terhubung ke satu siswa dan tidak boleh digunakan siswa lain.
- VA digunakan untuk identifikasi transaksi.

Contoh:
- NIS `12345` → VA `8800012345` → lima digit terakhir `12345`.

| NIS | Nama | Virtual Account |
|---|---|---|
| 12345 | Ahmad Fauzan | 8800012345 |
| 12346 | Siti Aminah | 8800012346 |
| 12347 | Budi Santoso | 8800012347 |
| 12348 | Citra Lestari | 8800012348 |

## 5. Ketentuan Payment Gateway

Untuk uji kompetensi, peserta boleh menggunakan:
- Payment Gateway sandbox.
- API sandbox.
- Mock Payment Gateway.
- Simulasi API Payment Gateway.
- Callback/webhook buatan sendiri.

Aplikasi harus memperlihatkan alur integrasi pembayaran, bukan mengubah status secara manual.

### Demonstrasi minimum kepada asesor

**Data contoh siswa**
- NIS: `12345`
- Nama: Ahmad Fauzan
- VA: `8800012345`

**Tagihan contoh**
- Periode: September 2026
- Nominal: Rp350.000
- Status awal: BELUM BAYAR

**Alur demonstrasi**
`Generate Payment → Payment Gateway → Virtual Account → Simulasi Pembayaran → Payment Berhasil → Callback/Webhook → Sistem memvalidasi → Tagihan LUNAS`

Contoh payload callback:
```json
{
  "virtual_account": "8800012345",
  "amount": 350000,
  "transaction_id": "TRX-20260918-001",
  "status": "PAID",
  "paid_at": "2026-09-25 10:15:00"
}
```

Saat callback diterima, sistem harus:
1. Menerima callback.
2. Memvalidasi transaksi/callback.
3. Mencari VA.
4. Mencari siswa.
5. Mencari tagihan.
6. Mencocokkan nominal.
7. Menyimpan transaksi.
8. Memperbarui tagihan menjadi LUNAS.

Asesor harus melihat status sebelum pembayaran (BELUM BAYAR) dan sesudah transaksi berhasil (LUNAS). Perubahan harus terjadi karena transaksi diterima sistem, bukan karena perubahan manual di database.

## 6. Aturan Status dan Jatuh Tempo

| Kondisi | Perilaku/status |
|---|---|
| Dibayar sebelum jatuh tempo | LUNAS |
| Dibayar tepat pada hari terakhir bulan | LUNAS |
| Belum dibayar sampai akhir bulan | Mulai tanggal 1 bulan berikutnya menjadi TUNGGAKAN |
| Dibayar setelah menjadi tunggakan | Status berubah TUNGGAKAN → LUNAS; tanggal pembayaran aktual tetap dicatat |

Contoh:
- SPP September 2026: Rp350.000.
- Jatuh tempo: 30 September 2026.
- Dibayar 25 September atau 30 September: LUNAS.
- Tidak dibayar sampai 30 September: pada 1 Oktober status September menjadi TUNGGAKAN.
- Jika dibayar 5 Oktober, status menjadi LUNAS, tetapi tanggal pembayaran tetap `5 Oktober 2026` agar keterlambatan dapat diketahui.

## 7. Role dan Hak Akses

Sistem memiliki empat role: **Admin, Kepala Sekolah, Teller, Siswa**.

### Admin
Mengelola sistem dan memiliki akses:
- **Dashboard:** total siswa, tagihan, pembayaran, tunggakan, transaksi, grafik pembayaran.
- **Master data:** siswa, kelas, jurusan, tahun ajaran, tarif SPP, VA.
- **Pengguna:** Admin, Kepala Sekolah, Teller, Siswa.
- **Transaksi:** transaksi Payment Gateway, transaksi VA, detail dan status transaksi.
- **Tagihan:** tagihan siswa, status tagihan, tunggakan.
- **Laporan:** pembayaran, tunggakan, rekap pembayaran.

### Kepala Sekolah
Dapat:
- Melihat dashboard, data siswa, tagihan, tunggakan, transaksi, laporan, dan grafik.
- Melakukan filter laporan.

Tidak dapat:
- Mengubah atau menghapus transaksi.
- Mengubah status pembayaran secara manual.

### Teller
Dapat:
- Melihat dashboard.
- Mencari siswa dan melihat VA.
- Melihat tagihan, status pembayaran, transaksi Payment Gateway, riwayat pembayaran, dan tunggakan.
- Mencetak laporan sesuai kewenangan.

Teller **tidak menerima pembayaran tunai** dan tidak boleh mengubah status pembayaran menjadi LUNAS secara manual.

### Siswa
Siswa hanya boleh melihat data miliknya sendiri.

Dapat melihat:
- **Profil:** NIS, NISN, nama, kelas, jurusan, tahun ajaran.
- **VA:** nomor VA miliknya.
- **Tagihan:** bulan, nominal, jatuh tempo, status.
- **Tunggakan:** bulan tunggakan, jumlah tunggakan, total nominal.
- **Riwayat pembayaran:** tanggal pembayaran, bulan pembayaran, nominal, status.

Tidak dapat:
- Mengubah data pembayaran.
- Menghapus transaksi.
- Mengubah status pembayaran.
- Melihat data siswa lain.

## 8. Kebutuhan Dashboard

### Dashboard Siswa — contoh konten
- Sapaan dan identitas siswa (contoh: Ahmad Fauzan, NIS 12345, kelas XI RPL 1).
- Nomor VA (contoh: 8800012345).
- Tagihan bulan ini: Rp350.000 dan status BELUM BAYAR.
- Total tunggakan: Rp700.000.
- Riwayat contoh: Juli Rp350.000 LUNAS; Agustus Rp350.000 LUNAS; September Rp350.000 LUNAS; Oktober Rp350.000 TUNGGAKAN.

### Dashboard Admin — minimum
- Total siswa.
- Total tagihan.
- Total pembayaran.
- Total tunggakan.
- Transaksi hari ini.
- Grafik pembayaran.
- Grafik tunggakan.
- Transaksi terbaru.

### Dashboard Kepala Sekolah — minimum
- Jumlah siswa aktif.
- Jumlah siswa lunas.
- Jumlah siswa belum bayar.
- Jumlah siswa menunggak.
- Total penerimaan SPP.
- Total tunggakan.
- Grafik pembayaran per bulan.
- Grafik tunggakan per bulan.

### Dashboard Teller — minimum
- Transaksi hari ini.
- Nominal transaksi hari ini.
- Transaksi terbaru.
- Pencarian VA.
- Pencarian NIS.
- Status pembayaran.

## 9. Struktur Basis Data Minimum

Peserta wajib membuat basis data terstruktur. Tabel dan kolom minimum sesuai materi:

```text
users
- id_user
- nama
- username
- password
- role
- status

siswa
- id_siswa
- nis
- nama
- jenis_kelamin
- tempat_lahir
- tanggal_lahir
- alamat
- no_hp
- nama_orang_tua
- id_kelas
- id_tahun_ajaran
- id_user
- status

jurusan
- id_jurusan
- kode_jurusan
- nama_jurusan
- keterangan

kelas
- id_kelas
- nama_kelas
- tingkat
- id_jurusan
- id_tahun_ajaran

tahun_ajaran
- id_tahun_ajaran
- tahun_ajaran
- tanggal_mulai
- tanggal_selesai
- status

tarif_spp
- id_tarif
- id_tahun_ajaran
- nominal
- keterangan
- status

virtual_account
- id_va
- id_siswa
- nomor_va
- prefix
- status
- tanggal_dibuat

tagihan_spp
- id_tagihan
- id_siswa
- id_tarif
- id_tahun_ajaran
- bulan
- tahun
- nominal
- tanggal_jatuh_tempo
- status

transaksi_payment_gateway
- id_transaksi
- transaction_id
- virtual_account
- id_siswa
- tanggal_transaksi
- waktu_transaksi
- nominal
- status_transaksi
- payment_channel
- reference_number
- raw_response
- created_at
- updated_at

pembayaran
- id_pembayaran
- id_transaksi
- id_tagihan
- tanggal_pembayaran
- nominal_pembayaran
- status
```

## 10. Fitur Laporan

### Laporan Pembayaran
Filter berdasarkan:
- Tanggal, bulan, tahun.
- Tahun ajaran.
- Kelas.
- Jurusan.
- Siswa.
- Status.

### Laporan Tunggakan
Minimal menampilkan:
- NIS.
- Nama.
- Kelas.
- Bulan tunggakan.
- Jumlah tunggakan.
- Total nominal.

## 11. Fitur Pencarian

Admin dan Teller dapat mencari berdasarkan:
- NIS.
- NISN.
- Nama.
- Nomor VA.
- Transaction ID.

Contoh input VA `8800012345` menghasilkan informasi:
- NIS: 12345.
- Nama: Ahmad Fauzan.
- Kelas: XI RPL 1.
- VA: 8800012345.
- September: LUNAS.
- Oktober: TUNGGAKAN.
- November: BELUM BAYAR.

## 12. Validasi Sistem

Validasi minimum:
- Username wajib diisi.
- Password wajib diisi.
- NIS harus unik.
- NISN harus unik.
- VA harus unik.
- Lima digit terakhir VA harus sesuai NIS.
- Nominal pembayaran harus valid.
- VA harus terdaftar.
- Transaction ID harus unik.
- Tagihan harus tersedia.
- Callback harus divalidasi.
- Tagihan yang sudah LUNAS tidak boleh diproses dua kali.
- Siswa hanya dapat melihat data sendiri.
- Siswa tidak boleh mengubah status pembayaran.
- Setiap transaksi harus memiliki histori.

## 13. Pengujian Fungsional Minimum

### Login
- Login valid.
- Username salah.
- Password salah.
- Field kosong.

### Data
- Tambah, edit, hapus, cari data.
- Validasi data.

### Virtual Account
- VA valid.
- VA duplikat.
- VA tidak terdaftar.
- Kesesuaian lima digit terakhir dengan NIS.

### Payment Gateway
- Pembayaran berhasil.
- Pembayaran gagal.
- Callback diterima.
- Callback tidak valid.
- Duplicate callback.
- Transaction ID duplikat.

### Tagihan
- Belum bayar.
- Lunas.
- Tunggakan.
- Pembayaran setelah tunggakan.

### Hak akses
- Admin.
- Kepala Sekolah.
- Teller.
- Siswa.

## 14. Ketentuan Khusus Wajib

1. Pembayaran SPP hanya melalui VA.
2. Setiap siswa memiliki VA masing-masing.
3. Lima digit terakhir VA harus sama dengan NIS.
4. Siswa tidak perlu mengirim screenshot bukti pembayaran.
5. Status LUNAS ditentukan berdasarkan transaksi pembayaran yang diterima sistem.
6. Payment Gateway wajib didemonstrasikan.
7. Peserta harus menunjukkan simulasi transaksi Payment Gateway.
8. Peserta harus menunjukkan callback/webhook atau mekanisme penerimaan transaksi setara.
9. Sistem mencocokkan transaksi dengan VA siswa.
10. Sistem mencocokkan transaksi dengan tagihan SPP.
11. SPP wajib dibayar paling lambat hari terakhir setiap bulan.
12. Jika belum dibayar sampai akhir bulan, tagihan menjadi TUNGGAKAN pada tanggal 1 bulan berikutnya.
13. Pembayaran setelah jatuh tempo tetap dapat melunasi tunggakan.
14. Tanggal pembayaran sebenarnya harus tetap disimpan.
15. Siswa hanya dapat melihat datanya sendiri.
16. Siswa tidak dapat mengubah status pembayaran.
17. Teller tidak menerima pembayaran tunai.
18. Status tidak boleh diubah menjadi LUNAS secara manual untuk melewati Payment Gateway.
19. Sistem harus mencegah pembayaran ganda.
20. Semua transaksi harus dapat ditelusuri melalui nomor transaksi/referensi.

## 15. Alur Akhir dan Hasil yang Diharapkan

Hasil akhir adalah aplikasi Sistem Informasi Pembayaran SPP berbasis web untuk SMK Bina Bangsa menggunakan Virtual Account dan terintegrasi dengan Payment Gateway.

**Alur pembayaran berhasil:**

`SISWA → VA → PAYMENT GATEWAY → TRANSAKSI BERHASIL → CALLBACK/WEBHOOK → SISTEM SEKOLAH → VALIDASI TRANSAKSI → CARI TAGIHAN → UPDATE PEMBAYARAN → LUNAS`

**Alur menjadi tunggakan:**

`TAGIHAN SPP → BELUM ADA TRANSAKSI → AKHIR BULAN TERLEWATI → TANGGAL 1 BULAN BERIKUTNYA → TUNGGAKAN`

Jika siswa kemudian membayar:

`SISWA MEMBAYAR → PAYMENT GATEWAY → CALLBACK → SISTEM MENERIMA DAN MEMVALIDASI TRANSAKSI → TAGIHAN LUNAS`

## 16. Instruksi Implementasi untuk AI

Gunakan dokumen ini sebagai sumber kebutuhan utama saat membantu mengembangkan proyek.

- Pertahankan empat role dan batas aksesnya.
- Jangan mengizinkan pembayaran tunai atau konfirmasi screenshot/manual.
- Jangan mengubah tagihan menjadi LUNAS hanya karena tombol Bayar ditekan; status harus berdasarkan transaksi/callback yang diterima dan divalidasi.
- Pastikan VA unik dan lima digit terakhirnya sama dengan NIS.
- Pastikan callback dan Transaction ID divalidasi serta transaksi duplikat dicegah.
- Simpan tanggal pembayaran aktual, termasuk pembayaran yang dilakukan setelah jatuh tempo.
- Terapkan isolasi data siswa berdasarkan akun yang sedang login.
- Jangan mengasumsikan detail teknis yang tidak ditentukan dokumen; minta klarifikasi bila keputusan implementasi memengaruhi aturan bisnis.
