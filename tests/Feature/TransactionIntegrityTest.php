<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MaterialItem;
use App\Models\PosTransaction;
use App\Models\ProgramCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $account;
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

        $this->account = Account::create([
            'code' => 'kas_tunai',
            'name' => 'Kas Tunai',
            'initial_balance' => 1000000,
            'current_balance' => 1000000,
        ]);

        $this->category = ProgramCategory::create([
            'code' => 'PML2',
            'name' => 'Pembangunan Mushalla Latee II',
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_income_transaction_increments_balance_atomically(): void
    {
        $response = $this->postJson('/api/transactions', [
            'tanggal' => '2026-11-24',
            'jenis' => 'masuk',
            'metode' => $this->account->id,
            'category_id' => $this->category->id,
            'nama_donatur' => 'H. Fulan',
            'nominal' => 250000,
            'keterangan' => 'Infaq Semen',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(1250000, $this->account->fresh()->current_balance);
    }

    public function test_expense_transaction_decrements_balance_and_creates_pos_tx(): void
    {
        $response = $this->postJson('/api/transactions', [
            'tanggal' => '2026-11-24',
            'jenis' => 'keluar',
            'metode' => $this->account->id,
            'category_id' => $this->category->id,
            'nominal' => 300000,
            'keterangan' => 'Alokasi Pembangunan PML2',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(700000, $this->account->fresh()->current_balance);

        $txId = $response->json('id');
        $this->assertDatabaseHas('pos_transactions', [
            'source_transaction_id' => $txId,
            'amount' => 300000,
            'category_id' => $this->category->id,
        ]);
    }

    public function test_transaction_deletion_rolls_back_balance_atomically(): void
    {
        $tx = Transaction::create([
            'transaction_date' => '2026-11-24',
            'type' => 'income',
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'amount' => 150000,
        ]);
        $this->account->increment('current_balance', 150000);
        $this->assertEquals(1150000, $this->account->fresh()->current_balance);

        $response = $this->deleteJson("/api/transactions/{$tx->id}");
        $response->assertStatus(200);

        $this->assertEquals(1000000, $this->account->fresh()->current_balance);
        $this->assertDatabaseMissing('transactions', ['id' => $tx->id]);
    }

    public function test_pos_transaction_with_material_purchase_updates_stock_atomically(): void
    {
        $material = MaterialItem::create([
            'category_id' => $this->category->id,
            'code' => 'SMN',
            'name' => 'Semen Gresik 40kg',
            'unit' => 'Sak',
            'unit_price' => 65000,
            'initial_stock' => 10,
            'current_stock' => 10,
        ]);

        $response = $this->postJson('/api/pos-transactions', [
            'category_id' => $this->category->id,
            'transaction_date' => '2026-11-24',
            'category_tx_type' => 'belanja_material',
            'amount' => 325000,
            'material_id' => $material->id,
            'material_quantity' => 5,
            'material_price' => 65000,
            'description' => 'Beli 5 sak semen',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(15, $material->fresh()->current_stock);

        $posTxId = $response->json('id');

        // Uji rollback saat pos-transaction dihapus
        $delResponse = $this->deleteJson("/api/pos-transactions/{$posTxId}");
        $delResponse->assertStatus(200);

        // Stok harus otomatis kembali ke 10
        $this->assertEquals(10, $material->fresh()->current_stock);
    }
}
