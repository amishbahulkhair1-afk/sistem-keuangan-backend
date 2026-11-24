<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CustomBalanceSheetItem;
use App\Models\ProgramCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NeracaKonsolidasiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $kasTunai;
    private Account $bankBni;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Admin Keuangan',
            'email' => 'admin@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'admin_keuangan',
        ]);

        $this->kasTunai = Account::create([
            'code' => 'kas_tunai',
            'name' => 'Kas Tunai',
            'initial_balance' => 2000000,
            'current_balance' => 2000000,
        ]);

        $this->bankBni = Account::create([
            'code' => 'bank_bni',
            'name' => 'Bank BNI 1946',
            'initial_balance' => 8000000,
            'current_balance' => 8000000,
        ]);

        Account::create([
            'code' => 'piutang',
            'name' => 'Piutang',
            'initial_balance' => 0,
            'current_balance' => 0,
        ]);

        ProgramCategory::create([
            'code' => 'SPM',
            'name' => 'Sarana Prasarana Madrasah',
            'pic_name' => 'Koordinator SPM',
        ]);
    }

    public function test_neraca_consists_of_harta_lancar_biaya_biaya_kewajiban_sumber_dana(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/reports/neraca');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertArrayHasKey('aktiva', $data);
        $this->assertArrayHasKey('passiva', $data);

        // Aktiva: Group A = Harta Lancar, Group B = Biaya-Biaya
        $aktivaGroups = $data['aktiva']['groups'];
        $this->assertCount(2, $aktivaGroups);
        $this->assertEquals('A. Harta Lancar', $aktivaGroups[0]['title']);
        $this->assertEquals('B. Biaya-Biaya Tiap Pos Program', $aktivaGroups[1]['title']);

        // Check Harta Lancar items (Kas Tunai, Kas Bank, Piutang)
        $hartaNames = array_column($aktivaGroups[0]['items'], 'name');
        $this->assertContains('Kas Tunai', $hartaNames);
        $this->assertContains('Kas Bank (Bank BNI 1946)', $hartaNames);
        $this->assertContains('Piutang ke Pihak Lain', $hartaNames);

        // Passiva: Group A = Kewajiban, Group B = Sumber Dana Tiap Pos
        $passivaGroups = $data['passiva']['groups'];
        $this->assertCount(2, $passivaGroups);
        $this->assertEquals('A. Kewajiban', $passivaGroups[0]['title']);
        $this->assertEquals('B. Sumber Dana Tiap Pos', $passivaGroups[1]['title']);

        // Check Sumber Dana SPM exists
        $sumberDanaNames = array_column($passivaGroups[1]['items'], 'name');
        $this->assertContains('Sumber Dana: Sarana Prasarana Madrasah', $sumberDanaNames);
    }

    public function test_new_pos_automatically_added_to_passiva_sumber_dana_and_aktiva_biaya(): void
    {
        Sanctum::actingAs($this->user);

        // Create a new pos
        ProgramCategory::create([
            'code' => 'ASRAMA',
            'name' => 'Pembangunan Asrama Baru',
            'pic_name' => 'Koordinator Asrama',
        ]);

        $response = $this->getJson('/api/reports/neraca');
        $response->assertStatus(200);

        $data = $response->json();

        // Must automatically appear in Passiva B: Sumber Dana Tiap Pos
        $passivaBItems = $data['passiva']['groups'][1]['items'];
        $passivaCodes = array_column($passivaBItems, 'code');
        $this->assertContains('ASRAMA', $passivaCodes);

        // Must automatically appear in Aktiva B: Biaya-Biaya Tiap Pos
        $aktivaBItems = $data['aktiva']['groups'][1]['items'];
        $aktivaCodes = array_column($aktivaBItems, 'code');
        $this->assertContains('ASRAMA', $aktivaCodes);
    }

    public function test_expense_and_income_for_pos_are_recorded_properly(): void
    {
        Sanctum::actingAs($this->user);

        $spm = ProgramCategory::where('code', 'SPM')->first();

        // 1. Income (Donasi Masuk)
        Transaction::create([
            'category_id' => $spm->id,
            'account_id' => $this->bankBni->id,
            'type' => 'income',
            'amount' => 5000000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Donasi SPM',
        ]);

        // 2. Expense (Realisasi Belanja)
        Transaction::create([
            'category_id' => $spm->id,
            'account_id' => $this->bankBni->id,
            'type' => 'expense',
            'amount' => 1500000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Beli Semen SPM',
        ]);

        $response = $this->getJson('/api/reports/neraca');
        $response->assertStatus(200);

        $data = $response->json();

        // Check Aktiva B: SPM realisasi biaya = 1,500,000
        $aktivaBItems = $data['aktiva']['groups'][1]['items'];
        $spmBiaya = collect($aktivaBItems)->firstWhere('code', 'SPM');
        $this->assertNotNull($spmBiaya);
        $this->assertEquals(1500000, $spmBiaya['amount']);

        // Check Passiva B: SPM sumber dana = 5,000,000
        $passivaBItems = $data['passiva']['groups'][1]['items'];
        $spmDana = collect($passivaBItems)->firstWhere('code', 'SPM');
        $this->assertNotNull($spmDana);
        $this->assertEquals(5000000, $spmDana['amount']);
    }

    public function test_can_create_update_and_delete_custom_neraca_items(): void
    {
        Sanctum::actingAs($this->user);

        // 1. Create manual row
        $payload = [
            'side' => 'aktiva',
            'sub_category' => 'biaya_biaya',
            'code' => 'ADM',
            'name' => 'Biaya Administrasi & Publikasi',
            'amount_prev_month' => 500000,
            'amount_current_month' => 250000,
            'amount_total' => 750000,
        ];

        $resCreate = $this->postJson('/api/reports/neraca/items', $payload);
        $resCreate->assertStatus(201);
        $itemId = $resCreate->json('id');
        $this->assertNotNull($itemId);

        $this->assertDatabaseHas('custom_balance_sheet_items', [
            'id' => $itemId,
            'category_id' => null,
            'code' => 'ADM',
            'name' => 'Biaya Administrasi & Publikasi',
            'amount_total' => 750000,
            'is_auto' => false,
        ]);

        // Verify it appears in GET /api/reports/neraca under Aktiva Group B
        $resNeraca = $this->getJson('/api/reports/neraca');
        $resNeraca->assertStatus(200);
        $aktivaB = $resNeraca->json('aktiva.groups.1.items');
        $found = collect($aktivaB)->firstWhere('id', $itemId);
        $this->assertNotNull($found);
        $this->assertEquals(750000, $found['amount']);

        // 2. Update
        $updatePayload = [
            'side' => 'aktiva',
            'sub_category' => 'biaya_biaya',
            'code' => 'ADM-REV',
            'name' => 'Biaya Administrasi & Publikasi Revisi',
            'amount_prev_month' => 500000,
            'amount_current_month' => 500000,
            'amount_total' => 1000000,
        ];

        $resUpdate = $this->putJson("/api/reports/neraca/items/{$itemId}", $updatePayload);
        $resUpdate->assertStatus(200);

        $this->assertDatabaseHas('custom_balance_sheet_items', [
            'id' => $itemId,
            'code' => 'ADM-REV',
            'amount_total' => 1000000,
        ]);

        // 3. Delete
        $resDelete = $this->deleteJson("/api/reports/neraca/items/{$itemId}");
        $resDelete->assertStatus(200);

        $this->assertDatabaseMissing('custom_balance_sheet_items', [
            'id' => $itemId,
        ]);
    }

    public function test_pdf_export_renders_detailed_groups(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->get('/api/reports/pdf/neraca');
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringContainsString('I. AKTIVA (HARTA LANCAR & BIAYA-BIAYA)', $content);
        $this->assertStringContainsString('II. PASSIVA (KEWAJIBAN & SUMBER DANA)', $content);
        $this->assertStringContainsString('A. Harta Lancar', $content);
        $this->assertStringContainsString('B. Biaya-Biaya Tiap Pos Program', $content);
        $this->assertStringContainsString('A. Kewajiban', $content);
        $this->assertStringContainsString('B. Sumber Dana Tiap Pos', $content);
        $this->assertStringContainsString('STATUS REKONSILIASI KEUANGAN:', $content);
    }
}
