<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\DebtTransaction;
use App\Models\DonationItem;
use App\Models\Donor;
use App\Models\MaterialItem;
use App\Models\MaterialTransaction;
use App\Models\PosTransaction;
use App\Models\ProgramCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BulkActionTest extends TestCase
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

    public function test_bulk_delete_transactions_reverts_account_balances(): void
    {
        // Buat 2 transaksi pemasukan
        $tx1 = Transaction::create([
            'transaction_date' => '2026-11-20',
            'type' => 'income',
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'donor_name' => 'Donatur A',
            'amount' => 200000,
            'description' => 'Donasi 1',
        ]);
        $this->account->increment('current_balance', 200000);

        $tx2 = Transaction::create([
            'transaction_date' => '2026-11-21',
            'type' => 'income',
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'donor_name' => 'Donatur B',
            'amount' => 300000,
            'description' => 'Donasi 2',
        ]);
        $this->account->increment('current_balance', 300000);

        $this->assertEquals(1500000, $this->account->fresh()->current_balance);

        // Bulk delete tx1 dan tx2
        $response = $this->postJson('/api/transactions/bulk-delete', [
            'ids' => [$tx1->id, $tx2->id],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'deleted_count' => 2,
            ]);

        $this->assertDatabaseMissing('transactions', ['id' => $tx1->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $tx2->id]);
        $this->assertEquals(1000000, $this->account->fresh()->current_balance);
    }

    public function test_bulk_delete_pos_transactions(): void
    {
        $pt1 = PosTransaction::create([
            'transaction_date' => '2026-11-20',
            'category_id' => $this->category->id,
            'type' => 'keluar',
            'category_type' => 'operasional',
            'amount' => 50000,
            'description' => 'Beli bensin',
        ]);

        $pt2 = PosTransaction::create([
            'transaction_date' => '2026-11-21',
            'category_id' => $this->category->id,
            'type' => 'keluar',
            'category_type' => 'operasional',
            'amount' => 75000,
            'description' => 'Konsumsi tukang',
        ]);

        $response = $this->postJson('/api/pos-transactions/bulk-delete', [
            'ids' => [$pt1->id, $pt2->id],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'deleted_count' => 2,
            ]);

        $this->assertDatabaseMissing('pos_transactions', ['id' => $pt1->id]);
        $this->assertDatabaseMissing('pos_transactions', ['id' => $pt2->id]);
    }

    public function test_bulk_delete_donation_items(): void
    {
        $item1 = DonationItem::create([
            'transaction_date' => '2026-11-20',
            'donor_name' => 'H. Ahmad',
            'item_category' => 'Material',
            'item_name' => 'Semen Padang',
            'quantity' => 10,
            'unit' => 'sak',
        ]);

        $item2 = DonationItem::create([
            'transaction_date' => '2026-11-21',
            'donor_name' => 'H. Bakri',
            'item_category' => 'Material',
            'item_name' => 'Pasir',
            'quantity' => 2,
            'unit' => 'rit',
        ]);

        $response = $this->postJson('/api/donation-items/bulk-delete', [
            'ids' => [$item1->id, $item2->id],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'deleted_count' => 2,
            ]);

        $this->assertDatabaseMissing('donation_items', ['id' => $item1->id]);
        $this->assertDatabaseMissing('donation_items', ['id' => $item2->id]);
    }

    public function test_bulk_delete_debts(): void
    {
        $debt1 = DebtTransaction::create([
            'transaction_date' => '2026-11-20',
            'category_id' => $this->category->id,
            'type' => 'hutang',
            'party_name' => 'Toko Besi Abadi',
            'amount' => 500000,
            'description' => 'Hutang besi 10mm',
        ]);

        $debt2 = DebtTransaction::create([
            'transaction_date' => '2026-11-21',
            'category_id' => $this->category->id,
            'type' => 'hutang',
            'party_name' => 'UD Bangunan Jaya',
            'amount' => 750000,
            'description' => 'Hutang pasir',
        ]);

        $response = $this->postJson('/api/debts/bulk-delete', [
            'ids' => [$debt1->id, $debt2->id],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'deleted_count' => 2,
            ]);

        $this->assertDatabaseMissing('debt_transactions', ['id' => $debt1->id]);
        $this->assertDatabaseMissing('debt_transactions', ['id' => $debt2->id]);
    }
}
