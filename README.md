# Sistem Keuangan & Pelaporan - Backend API (Laravel 13)

Backend REST API untuk **Sistem Pelaporan Donasi & Keuangan Operasional**, dibangun menggunakan **Laravel 13** dan **Laravel Sanctum**.

Backend ini melayani integrasi penuh untuk:
1. **Web Portal (React Vite)**: Dashboard rekapitulasi, neraca saldo, import Excel QRIS, export PDF/Excel, dan master data.
2. **Mobile App (Flutter)**: Pencatatan cepat donasi masuk, belanja operasional lapangan, stok material fisik, dan tracking hutang piutang.

---

## 🚀 Fitur Utama & Endpoint API

### 1. Autentikasi & Multi-Role (Sanctum)
- `POST /api/login`: Autentikasi dengan token bearer.
- `GET /api/me`: Profil pengguna saat ini.
- `POST /api/logout`: Revoke token.
- Multi-role support:
  - **Admin**: Akses penuh ke seluruh menu, kelola user, dan pengaturan sistem.
  - **Petugas**: Entri transaksi lapangan, stok material, dan mutasi pos binaan.
  - **Pimpinan**: Akses baca laporan, neraca keuangan, dan ringkasan audit eksekutif.

### 2. Master Data & Kategori Pos
- `GET|POST /api/pos-categories`: Manajemen pos kegiatan/program donasi.
- `GET|POST /api/accounts`: Rekening kas/bank dan kode akun akuntansi.
- `GET|POST /api/donors`: Data donatur dan histori donasi.
- `GET|POST /api/users`: Manajemen pengguna dan hak akses.

### 3. Transaksi & Keuangan
- `GET|POST /api/transactions`: Jurnal transaksi umum & upload bukti struk foto.
- `POST /api/transactions/import-qris`: Import mutasi rekening QRIS otomatis (Excel/Spreadsheet).
- `GET /api/pos-transactions/summary`: Rekapitulasi kas pos operasional lapangan.

### 4. Logistik Material Fisik
- `GET|POST /api/materials`: Katalog material (semen, pasir, besi, dll.) dengan threshold stok minimal.
- `GET|POST /api/material-transactions`: Mutasi penerimaan & pemakaian material di lapangan.

### 5. Tracking Hutang & Piutang
- `GET|POST /api/debts`: Rekap dan mutasi hutang (pinjam/bayar ke toko) dan piutang.

### 6. Laporan Keuangan & Neraca
- `GET /api/reports/financial`: Rekapitulasi penerimaan, pengeluaran, dan surplus/defisit.
- `GET /api/reports/balance-sheet`: Neraca saldo otomatis berdasarkan pos akun.
- `GET /api/reports/export-pdf`: Export dokumen PDF laporan keuangan.
- `GET /api/reports/export-excel`: Export dokumen spreadsheet Excel laporan keuangan.

---

## 🛠️ Instalasi & Menjalankan

### 1. Prasyarat
- PHP >= 8.2 (dengan ekstensi `pdo_sqlite`, `mbstring`, `openssl`, `gd`)
- Composer >= 2.0

### 2. Setup Awal
```bash
# Clone repository
git clone https://github.com/amishbahulkhair1-afk/sistem-keuangan-backend.git
cd sistem-keuangan-backend

# Install dependensi PHP
composer install

# Salin file konfigurasi environment
cp .env.example .env

# Generate application key
php artisan key:generate

# Migrasi & isi database awal
php artisan migrate --seed
```

### 3. Menjalankan Server
```bash
php artisan serve --port=8000 --host=0.0.0.0
```
Server aktif dan siap diakses di `http://localhost:8000`.

---

## 🔑 Akun Demo Default

| Role | Email | Password |
|---|---|---|
| **Administrator** | `admin@pelaporan.test` | `admin` |
| **Petugas Lapangan** | `petugas@pelaporan.test` | `admin` |
| **Pimpinan** | `pimpinan@pelaporan.test` | `admin` |
| **Admin Annuqayah** | `admin@annuqayah.test` | `admin` |
