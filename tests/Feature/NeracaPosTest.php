<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\DebtTransaction;
use App\Models\PosTransaction;
use App\Models\ProgramCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NeracaPosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $bankBni;
    private ProgramCategory $spm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Admin Keuangan',
            'email' => 'admin@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'admin_keuangan',
        ]);

        $this->bankBni = Account::create([
            'code' => 'bank_bni',
            'name' => 'Bank BNI',
            'initial_balance' => 10000000,
            'current_balance' => 10000000,
        ]);

        $this->spm = ProgramCategory::create([
            'code' => 'SPM',
            'name' => 'Sarana Prasarana Madrasah',
            'category_type' => 'proyek_pembangunan',
            'pic_name' => 'Ainul Fais',
        ]);
    }

    public function test_central_donation_does_not_update_neraca_dinamis_pos(): void
    {
        Sanctum::actingAs($this->user);

        // 1. Simpan donasi masuk di kas pusat untuk pos SPM
        $resTx = $this->postJson('/api/transactions', [
            'account_id' => $this->bankBni->id,
            'category_id' => $this->spm->id,
            'jenis' => 'income',
            'metode' => 'bank_transfer',
            'nominal' => 5000000,
            'keterangan' => 'Donasi QRIS SPM Pusat',
            'tanggal' => now()->toDateString(),
        ]);
        $resTx->assertStatus(201);

        // 2. Cek Neraca Konsolidasi: terupdate (Sumber Dana SPM = 5.000.000)
        $resKonsolidasi = $this->getJson('/api/reports/neraca');
        $resKonsolidasi->assertStatus(200);
        $sumberDanaItems = $resKonsolidasi->json('passiva.groups.1.items');
        $spmKonsolidasi = collect($sumberDanaItems)->firstWhere('code', 'SPM');
        $this->assertNotNull($spmKonsolidasi);
        $this->assertEquals(5000000, $spmKonsolidasi['amount']);

        // 3. Cek Neraca Dinamis Pos: TIDAK boleh terupdate oleh donasi kas pusat!
        $resPos = $this->getJson("/api/reports/neraca-pos/{$this->spm->id}");
        $resPos->assertStatus(200);
        $posData = $resPos->json();

        // Kas pos harus tetap 0 karena uang belum dicairkan ke pos
        $this->assertEquals(0, $posData['aktiva']['total_aktiva']);
        $this->assertEquals(0, $posData['passiva']['total_passiva']);
        $this->assertTrue($posData['reconciliation']['is_balanced']);
        $this->assertEquals(0, $posData['reconciliation']['selisih']);

        // Cek Sumber Dana pada neraca dinamis pos
        $sumberDanaPos = $posData['passiva']['sumber_dana'];
        $dPusat = collect($sumberDanaPos)->firstWhere('code', 'D.PUSAT');
        $dPos = collect($sumberDanaPos)->firstWhere('code', 'D.POS');
        $this->assertEquals(0, $dPusat['amount']);
        $this->assertEquals(0, $dPos['amount']);
    }

    public function test_neraca_pos_updates_on_pencairan_kas_utama_dan_pemasukan_pos_sendiri(): void
    {
        Sanctum::actingAs($this->user);

        // 1. Pencairan dana kas pusat ke pos SPM (Pengeluaran pusat dialokasikan ke SPM)
        $this->postJson('/api/transactions', [
            'account_id' => $this->bankBni->id,
            'category_id' => $this->spm->id,
            'jenis' => 'expense',
            'metode' => 'bank_transfer',
            'nominal' => 3000000,
            'keterangan' => 'Pencairan Kas Utama untuk SPM',
            'tanggal' => now()->toDateString(),
        ])->assertStatus(201);

        // 2. Pemasukan dari pos itu sendiri (donasi langsung pos)
        $this->postJson('/api/pos-transactions', [
            'category_id' => $this->spm->id,
            'type' => 'income',
            'category_tx_type' => 'donasi_langsung',
            'amount' => 1000000,
            'recipient_or_sender' => 'Hamba Allah',
            'description' => 'Sumbangan tunai diterima langsung di lokasi',
            'transaction_date' => now()->toDateString(),
        ])->assertStatus(201);

        // 3. Cek Neraca Dinamis Pos
        $resPos = $this->getJson("/api/reports/neraca-pos/{$this->spm->id}");
        $resPos->assertStatus(200);
        $posData = $resPos->json();

        // Total pemasukan pos = 3.000.000 (alokasi pusat) + 1.000.000 (donasi langsung) = 4.000.000
        $this->assertEquals(4000000, $posData['aktiva']['total_aktiva']);
        $this->assertEquals(4000000, $posData['passiva']['total_passiva']);
        $this->assertTrue($posData['reconciliation']['is_balanced']);
        $this->assertEquals(0, $posData['reconciliation']['selisih']);

        // Uraian Passiva B: Sumber Dana
        $sumberDana = $posData['passiva']['sumber_dana'];
        $dPusat = collect($sumberDana)->firstWhere('code', 'D.PUSAT');
        $dPos = collect($sumberDana)->firstWhere('code', 'D.POS');
        $this->assertEquals(3000000, $dPusat['amount']);
        $this->assertEquals(1000000, $dPos['amount']);
    }

    public function test_neraca_pos_updates_on_pos_expense_and_balances_correctly(): void
    {
        Sanctum::actingAs($this->user);

        // Dana awal masuk pos: 5.000.000 dari pencairan kas pusat
        $this->postJson('/api/transactions', [
            'account_id' => $this->bankBni->id,
            'category_id' => $this->spm->id,
            'jenis' => 'expense',
            'metode' => 'bank_transfer',
            'nominal' => 5000000,
            'keterangan' => 'Pencairan Kas Utama untuk SPM',
            'tanggal' => now()->toDateString(),
        ])->assertStatus(201);

        // Catat pengeluaran pos: Belanja Material Rp 2.000.000
        $this->postJson('/api/pos-transactions', [
            'category_id' => $this->spm->id,
            'type' => 'expense',
            'category_tx_type' => 'belanja_material',
            'amount' => 2000000,
            'description' => 'Beli Semen dan Pasir',
            'transaction_date' => now()->toDateString(),
        ])->assertStatus(201);

        // Catat pengeluaran pos: Upah Tukang Rp 1.500.000
        $this->postJson('/api/pos-transactions', [
            'category_id' => $this->spm->id,
            'type' => 'expense',
            'category_tx_type' => 'upah_tukang',
            'amount' => 1500000,
            'description' => 'Upah Mingguan Tukang',
            'transaction_date' => now()->toDateString(),
        ])->assertStatus(201);

        // Cek Neraca Dinamis Pos
        $resPos = $this->getJson("/api/reports/neraca-pos/{$this->spm->id}");
        $resPos->assertStatus(200);
        $posData = $resPos->json();

        // Sisa Kas Operasional Pos: 5.000.000 - 3.500.000 = 1.500.000
        $hartaLancar = $posData['aktiva']['harta_lancar'];
        $kasPos = collect($hartaLancar)->firstWhere('code', 'K.K');
        $this->assertEquals(1500000, $kasPos['amount']);

        // Akumulasi Realisasi Biaya: Belanja Material (2jt) + Upah Tukang (1.5jt) = 3.5jt
        $biayaOps = $posData['aktiva']['biaya_operasional'];
        $bMat = collect($biayaOps)->firstWhere('code', 'B.MAT');
        $bUpah = collect($biayaOps)->firstWhere('code', 'B.UPAH');
        $this->assertNotNull($bMat);
        $this->assertNotNull($bUpah);
        $this->assertEquals(2000000, $bMat['amount']);
        $this->assertEquals(1500000, $bUpah['amount']);

        // Total Aktiva: Kas (1.5jt) + Biaya (3.5jt) = 5.000.000
        // Total Passiva: Sumber Dana (5.000.000)
        $this->assertEquals(5000000, $posData['aktiva']['total_aktiva']);
        $this->assertEquals(5000000, $posData['passiva']['total_passiva']);
        $this->assertTrue($posData['reconciliation']['is_balanced']);
        $this->assertEquals(0, $posData['reconciliation']['selisih']);
    }

    public function test_neraca_pos_with_debt_and_receivables(): void
    {
        Sanctum::actingAs($this->user);

        // 1. Terima pinjaman dana hutang pada pos: 2.000.000
        $this->postJson('/api/pos-transactions', [
            'category_id' => $this->spm->id,
            'type' => 'income',
            'category_tx_type' => 'hutang_pinjam',
            'amount' => 2000000,
            'recipient_or_sender' => 'Toko Bangunan Berkah',
            'description' => 'Pinjaman Talangan Material',
            'transaction_date' => now()->toDateString(),
        ])->assertStatus(201);

        // 2. Cek Neraca Pos
        $resPos = $this->getJson("/api/reports/neraca-pos/{$this->spm->id}");
        $resPos->assertStatus(200);
        $posData = $resPos->json();

        // Aktiva: Kas Pos = 2.000.000
        // Passiva: Kewajiban Hutang = 2.000.000
        $this->assertEquals(2000000, $posData['aktiva']['total_aktiva']);
        $this->assertEquals(2000000, $posData['passiva']['total_passiva']);
        $this->assertTrue($posData['reconciliation']['is_balanced']);
        $this->assertEquals(0, $posData['reconciliation']['selisih']);

        // 3. Pos memberi pinjaman (piutang) ke pihak lain sebesar 500.000
        $this->postJson('/api/pos-transactions', [
            'category_id' => $this->spm->id,
            'type' => 'expense',
            'category_tx_type' => 'piutang_pinjam',
            'amount' => 500000,
            'recipient_or_sender' => 'Panitia Acara Santri',
            'description' => 'Pinjaman dana talangan kegiatan',
            'transaction_date' => now()->toDateString(),
        ])->assertStatus(201);

        $resPos2 = $this->getJson("/api/reports/neraca-pos/{$this->spm->id}");
        $resPos2->assertStatus(200);
        $posData2 = $resPos2->json();

        // Aktiva: Kas Pos (1.500.000) + Piutang (500.000) = 2.000.000
        // Passiva: Kewajiban Hutang = 2.000.000
        $this->assertEquals(2000000, $posData2['aktiva']['total_aktiva']);
        $this->assertEquals(2000000, $posData2['passiva']['total_passiva']);
        $this->assertTrue($posData2['reconciliation']['is_balanced']);
        $this->assertEquals(0, $posData2['reconciliation']['selisih']);
    }
}
