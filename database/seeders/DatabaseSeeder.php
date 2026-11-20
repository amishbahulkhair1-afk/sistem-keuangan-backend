<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Account;
use App\Models\ProgramCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed Users
        $users = [
            [
                'name' => 'Administrator Keuangan',
                'email' => 'admin@pelaporan.test',
                'password' => Hash::make('admin'),
                'role' => 'admin_keuangan',
                'access_level' => 'full',
            ],
            [
                'name' => 'Pimpinan / Pengawas',
                'email' => 'pimpinan@pelaporan.test',
                'password' => Hash::make('admin'),
                'role' => 'pimpinan',
                'access_level' => 'read_only',
            ],
            [
                'name' => 'Petugas Lapangan',
                'email' => 'petugas@pelaporan.test',
                'password' => Hash::make('admin'),
                'role' => 'petugas_lapangan',
                'access_level' => 'add_only',
            ],
            [
                'name' => 'Pengurus Keuangan (Legacy)',
                'email' => 'admin@annuqayah.test',
                'password' => Hash::make('admin'),
                'role' => 'admin_keuangan',
                'access_level' => 'full',
            ],
            [
                'name' => 'Pengasuh (Legacy)',
                'email' => 'pimpinan@annuqayah.test',
                'password' => Hash::make('admin'),
                'role' => 'pimpinan',
                'access_level' => 'read_only',
            ],
        ];

        foreach ($users as $u) {
            User::firstOrCreate(['email' => $u['email']], $u);
        }

        // 2. Seed Accounts
        $accounts = [
            ['code' => 'kas_tunai', 'name' => 'Kas Tunai', 'initial_balance' => 1363000.0, 'current_balance' => 1363000.0],
            ['code' => 'bank_bni', 'name' => 'Bank BNI 1946', 'initial_balance' => 11327838.85, 'current_balance' => 11327838.85],
            ['code' => 'piutang', 'name' => 'Piutang', 'initial_balance' => 28371500.0, 'current_balance' => 28371500.0],
        ];

        foreach ($accounts as $acc) {
            Account::firstOrCreate(['code' => $acc['code']], $acc);
        }

        // 3. Seed Program Categories
        $categories = [
            ['code' => 'IP', 'name' => 'Infaq Produktif', 'qris_unit_digit' => '1', 'category_type' => 'non_pembangunan', 'pic_name' => 'Koordinator IP'],
            ['code' => 'SPM', 'name' => 'Pembangunan SPM', 'qris_unit_digit' => '2', 'category_type' => 'proyek_pembangunan', 'pic_name' => 'Koordinator SPM'],
            ['code' => 'PP', 'name' => 'Pengembangan Pembangunan Baru', 'qris_unit_digit' => '3', 'category_type' => 'proyek_pembangunan', 'pic_name' => 'Koordinator PP'],
            ['code' => 'PML2', 'name' => 'Pembangunan Mushalla Latee II', 'qris_unit_digit' => '4', 'category_type' => 'proyek_pembangunan', 'pic_name' => 'Koordinator PML2'],
            ['code' => 'SK', 'name' => 'Sanitasi dan Kebersihan', 'qris_unit_digit' => '5', 'category_type' => 'non_pembangunan', 'pic_name' => 'Koordinator SK'],
            ['code' => 'PT', 'name' => 'Pembangunan Tahfidh', 'qris_unit_digit' => '6', 'category_type' => 'proyek_pembangunan', 'pic_name' => 'Koordinator PT'],
            ['code' => 'PK', 'name' => 'Infaq Peduli Khusus', 'qris_unit_digit' => '7', 'category_type' => 'non_pembangunan', 'pic_name' => 'Koordinator PK'],
            ['code' => 'DL', 'name' => 'Dana Dalfis', 'qris_unit_digit' => '8', 'category_type' => 'non_pembangunan', 'pic_name' => 'Pengelola DL'],
            ['code' => 'PTT', 'name' => 'Pembelian Tanah Tahap VI', 'qris_unit_digit' => '9', 'category_type' => 'proyek_pembangunan', 'pic_name' => 'Koordinator PTT'],
            ['code' => 'TPT', 'name' => 'Tabungan Pembelian Tanah Tahap VI', 'qris_unit_digit' => '10', 'category_type' => 'proyek_pembangunan', 'pic_name' => 'Koordinator TPT'],
            ['code' => 'PPAB', 'name' => 'Pembangunan Pagar Asrama Baru', 'qris_unit_digit' => '11', 'category_type' => 'proyek_pembangunan', 'pic_name' => 'Koordinator PPAB'],
            ['code' => 'PTWB', 'name' => 'Pembangunan Tempat Wudu dan Bersuci', 'qris_unit_digit' => '12', 'category_type' => 'proyek_pembangunan', 'pic_name' => 'Koordinator PTWB'],
            ['code' => 'UM', 'name' => 'Pengembangan Umum', 'qris_unit_digit' => '13', 'category_type' => 'non_pembangunan', 'pic_name' => 'Koordinator UM'],
            ['code' => 'KT', 'name' => 'Konsumsi Untuk Tukang', 'qris_unit_digit' => '14', 'category_type' => 'proyek_pembangunan', 'pic_name' => 'Koordinator KT'],
            ['code' => 'STHL', 'name' => 'Sumbangan Tanpa Digit Terakhir', 'qris_unit_digit' => '0', 'category_type' => 'non_pembangunan', 'pic_name' => 'Admin Keuangan'],
        ];

        foreach ($categories as $cat) {
            ProgramCategory::firstOrCreate(['code' => $cat['code']], $cat);
        }
    }
}
