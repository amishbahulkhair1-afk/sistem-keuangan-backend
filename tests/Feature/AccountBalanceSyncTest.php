<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ProgramCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountBalanceSyncTest extends TestCase
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
            'initial_balance' => 0,
            'current_balance' => 0,
        ]);

        $this->bankBni = Account::create([
            'code' => 'bank_bni',
            'name' => 'Bank BNI 1946',
            'initial_balance' => 0,
            'current_balance' => 0,
        ]);
    }

    public function test_recalculate_accounts_syncs_balances_with_transactions(): void
    {
        Sanctum::actingAs($this->user);

        // Simulasi saldo tidak sinkron (misal sisa import bermasalah)
        $this->bankBni->update(['current_balance' => 20378897]);

        $resRecalculate = $this->postJson('/api/accounts/recalculate');
        $resRecalculate->assertStatus(200);

        // Karena tidak ada transaksi sama sekali, saldo harus kembali ke 0
        $this->bankBni->refresh();
        $this->assertEquals(0, $this->bankBni->current_balance);
    }

    public function test_reset_balance_zeroes_account(): void
    {
        Sanctum::actingAs($this->user);

        $this->bankBni->update(['initial_balance' => 5000000, 'current_balance' => 5000000]);

        $resReset = $this->postJson("/api/accounts/{$this->bankBni->id}/reset-balance");
        $resReset->assertStatus(200);

        $this->bankBni->refresh();
        $this->assertEquals(0, $this->bankBni->initial_balance);
        $this->assertEquals(0, $this->bankBni->current_balance);
    }

    public function test_update_account_can_set_current_balance_explicitly(): void
    {
        Sanctum::actingAs($this->user);

        $this->bankBni->update(['current_balance' => 1000000]);

        $resUpdate = $this->putJson("/api/accounts/{$this->bankBni->id}", [
            'name' => 'Bank BNI 1946 Updated',
            'initial_balance' => 0,
            'current_balance' => 0,
        ]);
        $resUpdate->assertStatus(200);

        $this->bankBni->refresh();
        $this->assertEquals(0, $this->bankBni->current_balance);
        $this->assertEquals(0, $this->bankBni->initial_balance);
    }

    public function test_deleting_last_transaction_resets_balance_to_initial(): void
    {
        Sanctum::actingAs($this->user);

        $cat = ProgramCategory::create([
            'code' => 'SPM',
            'name' => 'Sarana Prasarana',
        ]);

        $tx = Transaction::create([
            'account_id' => $this->bankBni->id,
            'category_id' => $cat->id,
            'type' => 'income',
            'amount' => 5000000,
            'transaction_date' => now()->toDateString(),
        ]);
        $this->bankBni->increment('current_balance', 5000000);

        $this->bankBni->refresh();
        $this->assertEquals(5000000, $this->bankBni->current_balance);

        // Hapus satu-satunya transaksi
        $resDelete = $this->deleteJson("/api/transactions/{$tx->id}");
        $resDelete->assertStatus(200);

        $this->bankBni->refresh();
        $this->assertEquals(0, $this->bankBni->current_balance);
    }

    public function test_bulk_deleting_last_transactions_resets_balance_to_initial(): void
    {
        Sanctum::actingAs($this->user);

        $cat = ProgramCategory::create([
            'code' => 'SPM',
            'name' => 'Sarana Prasarana',
        ]);

        $tx1 = Transaction::create([
            'account_id' => $this->bankBni->id,
            'category_id' => $cat->id,
            'type' => 'income',
            'amount' => 3000000,
            'transaction_date' => now()->toDateString(),
        ]);
        $tx2 = Transaction::create([
            'account_id' => $this->bankBni->id,
            'category_id' => $cat->id,
            'type' => 'income',
            'amount' => 2000000,
            'transaction_date' => now()->toDateString(),
        ]);
        $this->bankBni->increment('current_balance', 5000000);

        $resBulk = $this->postJson('/api/transactions/bulk-delete', [
            'ids' => [$tx1->id, $tx2->id],
        ]);
        $resBulk->assertStatus(200);

        $this->bankBni->refresh();
        $this->assertEquals(0, $this->bankBni->current_balance);
    }
}
