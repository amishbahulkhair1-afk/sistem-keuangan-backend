<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ProgramCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class QrisImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $accountBni;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Admin Keuangan',
            'email' => 'admin@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'admin_keuangan',
        ]);

        $this->accountBni = Account::create([
            'code' => 'bank_bni',
            'name' => 'Bank BNI 1946',
            'initial_balance' => 1000000,
            'current_balance' => 1000000,
        ]);

        ProgramCategory::create([
            'code' => 'UM',
            'name' => 'Pengembangan Umum',
            'pic_name' => 'Koordinator Umum',
        ]);

        ProgramCategory::create([
            'code' => 'STHL',
            'name' => 'Sumbangan Tanpa Digit Terakhir',
            'pic_name' => 'Koordinator STHL',
        ]);
    }

    private function createSampleQrisExcel(array $rows): string
    {
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();

        // Header at row 2
        $ws->setCellValue('F2', 'bill no');
        $ws->setCellValue('G2', 'reff id');
        $ws->setCellValue('N2', 'customer name');
        $ws->setCellValue('Q2', 'amount');
        $ws->setCellValue('T2', 'trx date');

        $rowIdx = 3;
        foreach ($rows as $r) {
            $ws->setCellValue("F{$rowIdx}", $r['bill_no']);
            $ws->setCellValue("G{$rowIdx}", $r['reff_id']);
            $ws->setCellValue("N{$rowIdx}", $r['name']);
            $ws->setCellValue("Q{$rowIdx}", $r['amount']);
            $ws->setCellValue("T{$rowIdx}", $r['date']);
            $rowIdx++;
        }

        $writer = new Xlsx($ss);
        $tmp = tempnam(sys_get_temp_dir(), 'qris_') . '.xlsx';
        $writer->save($tmp);

        return $tmp;
    }

    public function test_import_qris_succeeds_with_status_success_and_updates_account_balance(): void
    {
        Sanctum::actingAs($this->user);

        $tmpFile = $this->createSampleQrisExcel([
            [
                'bill_no' => 'BILL001',
                'reff_id' => 'REFF001',
                'name' => 'Donatur Dermawan',
                'amount' => '500000',
                'date' => '2026-11-24',
            ],
            [
                'bill_no' => 'BILL002',
                'reff_id' => 'REFF002',
                'name' => 'Hamba Allah',
                'amount' => '250000',
                'date' => '2026-11-24',
            ],
        ]);

        $uploadedFile = new UploadedFile($tmpFile, 'qris_test.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/transactions/import-qris', [
            'file' => $uploadedFile,
        ]);

        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'imported_count' => 2,
            'skipped_count' => 0,
        ]);

        $this->assertStringContainsString('Berhasil mengimpor 2 transaksi QRIS', $response->json('message'));

        // Check account balance updated: 1,000,000 + 500,000 + 250,000 = 1,750,000
        $this->accountBni->refresh();
        $this->assertEquals(1750000, $this->accountBni->current_balance);

        // Check transactions exist in DB
        $this->assertDatabaseHas('transactions', [
            'qris_reff_id' => 'REFF001',
            'amount' => 500000,
        ]);
        $this->assertDatabaseHas('transactions', [
            'qris_reff_id' => 'REFF002',
            'amount' => 250000,
        ]);
    }

    public function test_import_qris_skips_duplicates_without_double_counting_balance(): void
    {
        Sanctum::actingAs($this->user);

        // First import
        $tmpFile1 = $this->createSampleQrisExcel([
            [
                'bill_no' => 'BILL001',
                'reff_id' => 'REFF001',
                'name' => 'Donatur Dermawan',
                'amount' => '500000',
                'date' => '2026-11-24',
            ],
        ]);

        $uploadedFile1 = new UploadedFile($tmpFile1, 'qris_test1.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->postJson('/api/transactions/import-qris', ['file' => $uploadedFile1])->assertStatus(200);
        if (file_exists($tmpFile1)) {
            unlink($tmpFile1);
        }

        $this->accountBni->refresh();
        $this->assertEquals(1500000, $this->accountBni->current_balance);

        // Second import with the same file
        $tmpFile2 = $this->createSampleQrisExcel([
            [
                'bill_no' => 'BILL001',
                'reff_id' => 'REFF001',
                'name' => 'Donatur Dermawan',
                'amount' => '500000',
                'date' => '2026-11-24',
            ],
        ]);

        $uploadedFile2 = new UploadedFile($tmpFile2, 'qris_test2.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $res2 = $this->postJson('/api/transactions/import-qris', ['file' => $uploadedFile2]);
        if (file_exists($tmpFile2)) {
            unlink($tmpFile2);
        }

        $res2->assertStatus(200);
        $res2->assertJson([
            'status' => 'success',
            'imported_count' => 0,
            'skipped_count' => 1,
        ]);

        // Balance should NOT increase again
        $this->accountBni->refresh();
        $this->assertEquals(1500000, $this->accountBni->current_balance);
    }

    public function test_reallocate_qris_updates_categories_based_on_current_master_pos_digits(): void
    {
        Sanctum::actingAs($this->user);

        $spm = ProgramCategory::create([
            'code' => 'SPM',
            'name' => 'Pembangunan SPM',
            'qris_unit_digit' => '23',
        ]);

        $ip = ProgramCategory::create([
            'code' => 'IP',
            'name' => 'Infaq Produktif',
            'qris_unit_digit' => '1',
        ]);

        $um = ProgramCategory::where('code', 'UM')->first();
        $sthl = ProgramCategory::where('code', 'STHL')->first();

        // Create transactions:
        // 1. Matches SPM (digit 23)
        $tx1 = Transaction::create([
            'transaction_date' => '2026-11-24',
            'type' => 'income',
            'account_id' => $this->accountBni->id,
            'category_id' => $sthl->id,
            'amount' => 500023, // digit 23 -> SPM
            'qris_reff_id' => 'REFF_SPM_01',
        ]);

        // 2. Matches IP (digit 1)
        $tx2 = Transaction::create([
            'transaction_date' => '2026-11-24',
            'type' => 'income',
            'account_id' => $this->accountBni->id,
            'category_id' => $sthl->id,
            'amount' => 100001, // digit 1 -> IP
            'qris_reff_id' => 'REFF_IP_01',
        ]);

        // 3. Non-matching digit (ends in 88, but no pos has 88 or 8) -> falls back to Pengembangan Umum (UM)
        $tx3 = Transaction::create([
            'transaction_date' => '2026-11-24',
            'type' => 'income',
            'account_id' => $this->accountBni->id,
            'category_id' => $sthl->id,
            'amount' => 50088, // ends in 88, unassigned -> UM
            'qris_reff_id' => 'REFF_UM_01',
        ]);

        $res = $this->postJson('/api/transactions/reallocate-qris');
        $res->assertStatus(200);
        $res->assertJson([
            'status' => 'success',
            'updated_count' => 3,
        ]);

        $tx1->refresh();
        $tx2->refresh();
        $tx3->refresh();

        $this->assertEquals($spm->id, $tx1->category_id);
        $this->assertEquals($ip->id, $tx2->category_id);
        $this->assertEquals($um->id, $tx3->category_id);
    }
}
