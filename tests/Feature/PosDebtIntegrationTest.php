<?php

namespace Tests\Feature;

use App\Models\DebtTransaction;
use App\Models\PosTransaction;
use App\Models\ProgramCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosDebtIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ProgramCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Admin Keuangan',
            'email' => 'admin@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'admin_keuangan',
        ]);

        $this->category = ProgramCategory::create([
            'code' => 'PML2',
            'name' => 'Pembangunan Mushalla Latee II',
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_pos_transaction_with_direct_debt_creates_debt_transaction_and_links_them(): void
    {
        $response = $this->postJson('/api/pos-transactions', [
            'category_id' => $this->category->id,
            'transaction_date' => '2026-11-24',
            'category_tx_type' => 'hutang_pinjam',
            'amount' => 5000000,
            'recipient_or_sender' => 'Toko Sumber Barokah',
            'description' => 'Pinjaman talangan material',
        ]);

        $response->assertStatus(201);
        $ptId = $response->json('id');

        $pt = PosTransaction::find($ptId);
        $this->assertNotNull($pt);
        $this->assertEquals('income', $pt->type);
        $this->assertNotNull($pt->source_debt_tx_id);

        $dt = DebtTransaction::find($pt->source_debt_tx_id);
        $this->assertNotNull($dt);
        $this->assertEquals('hutang_pinjam', $dt->type);
        $this->assertEquals(5000000, (float) $dt->amount);
        $this->assertEquals('Toko Sumber Barokah', $dt->party_name);
        $this->assertEquals($pt->id, $dt->source_pos_tx_id);
    }

    public function test_pos_expense_with_payment_status_hutang_creates_debt_transaction(): void
    {
        $response = $this->postJson('/api/pos-transactions', [
            'category_id' => $this->category->id,
            'transaction_date' => '2026-11-24',
            'category_tx_type' => 'belanja_material',
            'payment_status' => 'hutang',
            'amount' => 1500000,
            'recipient_or_sender' => 'TB Maju Lancar',
            'description' => 'Beli Semen 30 Sak tempo',
        ]);

        $response->assertStatus(201);
        $ptId = $response->json('id');

        $pt = PosTransaction::find($ptId);
        $this->assertNotNull($pt);
        $this->assertEquals('expense', $pt->type);
        $this->assertEquals('hutang', $pt->payment_status);
        $this->assertNotNull($pt->source_debt_tx_id);

        $dt = DebtTransaction::find($pt->source_debt_tx_id);
        $this->assertNotNull($dt);
        $this->assertEquals('hutang_pinjam', $dt->type);
        $this->assertEquals(1500000, (float) $dt->amount);
        $this->assertEquals('TB Maju Lancar', $dt->party_name);
        $this->assertEquals($pt->id, $dt->source_pos_tx_id);
    }

    public function test_debt_store_creates_linked_pos_transaction(): void
    {
        $response = $this->postJson('/api/debts', [
            'category_id' => $this->category->id,
            'transaction_date' => '2026-11-24',
            'type' => 'hutang_pinjam',
            'party_name' => 'Haji Ahmad',
            'amount' => 3000000,
            'description' => 'Pinjaman operasional',
        ]);

        $response->assertStatus(201);
        $dtId = $response->json('id');

        $dt = DebtTransaction::find($dtId);
        $this->assertNotNull($dt);
        $this->assertNotNull($dt->source_pos_tx_id);

        $pt = PosTransaction::find($dt->source_pos_tx_id);
        $this->assertNotNull($pt);
        $this->assertEquals('income', $pt->type);
        $this->assertEquals('hutang_pinjam', $pt->category_tx_type);
        $this->assertEquals(3000000, (float) $pt->amount);
        $this->assertEquals('Haji Ahmad', $pt->recipient_or_sender);
        $this->assertEquals($dt->id, $pt->source_debt_tx_id);
    }

    public function test_deleting_pos_transaction_deletes_linked_debt_transaction(): void
    {
        $res = $this->postJson('/api/debts', [
            'category_id' => $this->category->id,
            'transaction_date' => '2026-11-24',
            'type' => 'piutang_pinjam',
            'party_name' => 'Unit Usaha',
            'amount' => 500000,
        ]);
        $dtId = $res->json('id');
        $dt = DebtTransaction::find($dtId);
        $ptId = $dt->source_pos_tx_id;

        $delRes = $this->deleteJson("/api/pos-transactions/{$ptId}");
        $delRes->assertStatus(200);

        $this->assertNull(PosTransaction::find($ptId));
        $this->assertNull(DebtTransaction::find($dtId));
    }

    public function test_deleting_debt_transaction_deletes_linked_pos_transaction(): void
    {
        $res = $this->postJson('/api/pos-transactions', [
            'category_id' => $this->category->id,
            'transaction_date' => '2026-11-24',
            'category_tx_type' => 'hutang_bayar',
            'amount' => 1000000,
            'recipient_or_sender' => 'Pujasera',
        ]);
        $ptId = $res->json('id');
        $pt = PosTransaction::find($ptId);
        $dtId = $pt->source_debt_tx_id;

        $delRes = $this->deleteJson("/api/debts/{$dtId}");
        $delRes->assertStatus(200);

        $this->assertNull(DebtTransaction::find($dtId));
        $this->assertNull(PosTransaction::find($ptId));
    }

    public function test_bulk_delete_pos_transactions_deletes_linked_debt_transactions(): void
    {
        $res1 = $this->postJson('/api/pos-transactions', [
            'category_id' => $this->category->id,
            'transaction_date' => '2026-11-24',
            'category_tx_type' => 'hutang_pinjam',
            'amount' => 2000000,
            'recipient_or_sender' => 'Kreditur A',
        ]);
        $pt1Id = $res1->json('id');

        $res2 = $this->postJson('/api/pos-transactions', [
            'category_id' => $this->category->id,
            'transaction_date' => '2026-11-24',
            'category_tx_type' => 'belanja_material',
            'payment_status' => 'hutang',
            'amount' => 800000,
            'recipient_or_sender' => 'Kreditur B',
        ]);
        $pt2Id = $res2->json('id');

        $dt1Id = PosTransaction::find($pt1Id)->source_debt_tx_id;
        $dt2Id = PosTransaction::find($pt2Id)->source_debt_tx_id;

        $bulkRes = $this->postJson('/api/pos-transactions/bulk-delete', [
            'ids' => [$pt1Id, $pt2Id],
        ]);
        $bulkRes->assertStatus(200);

        $this->assertEmpty(PosTransaction::whereIn('id', [$pt1Id, $pt2Id])->get());
        $this->assertEmpty(DebtTransaction::whereIn('id', [$dt1Id, $dt2Id])->get());
    }

    public function test_bulk_delete_debts_deletes_linked_pos_transactions(): void
    {
        $res1 = $this->postJson('/api/debts', [
            'category_id' => $this->category->id,
            'transaction_date' => '2026-11-24',
            'type' => 'hutang_pinjam',
            'party_name' => 'Kreditur X',
            'amount' => 1200000,
        ]);
        $dt1Id = $res1->json('id');

        $res2 = $this->postJson('/api/debts', [
            'category_id' => $this->category->id,
            'transaction_date' => '2026-11-24',
            'type' => 'piutang_pinjam',
            'party_name' => 'Debitur Y',
            'amount' => 450000,
        ]);
        $dt2Id = $res2->json('id');

        $pt1Id = DebtTransaction::find($dt1Id)->source_pos_tx_id;
        $pt2Id = DebtTransaction::find($dt2Id)->source_pos_tx_id;

        $bulkRes = $this->postJson('/api/debts/bulk-delete', [
            'ids' => [$dt1Id, $dt2Id],
        ]);
        $bulkRes->assertStatus(200);

        $this->assertEmpty(DebtTransaction::whereIn('id', [$dt1Id, $dt2Id])->get());
        $this->assertEmpty(PosTransaction::whereIn('id', [$pt1Id, $pt2Id])->get());
    }
}
