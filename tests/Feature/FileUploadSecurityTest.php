<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ProgramCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FileUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'admin_keuangan',
        ]);

        Account::create([
            'code' => 'kas_tunai',
            'name' => 'Kas Tunai',
            'initial_balance' => 1000000,
            'current_balance' => 1000000,
        ]);

        ProgramCategory::create([
            'code' => 'PML2',
            'name' => 'Pembangunan Mushalla Latee II',
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $response = $this->getJson('/api/transactions');
        $response->assertStatus(401);

        $response = $this->getJson('/api/categories');
        $response->assertStatus(401);
    }

    public function test_rejects_malicious_php_file_upload(): void
    {
        Sanctum::actingAs($this->user);

        $maliciousFile = UploadedFile::fake()->create('backdoor.php', 10, 'application/x-php');
        $cat = ProgramCategory::first();

        $response = $this->postJson('/api/transactions', [
            'tanggal' => '2026-11-24',
            'jenis' => 'masuk',
            'metode' => 'kas_tunai',
            'category_id' => $cat->id,
            'nominal' => 50000,
            'bukti_foto' => $maliciousFile,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['bukti_foto']);
    }

    public function test_accepts_valid_image_file_and_randomizes_filename(): void
    {
        Sanctum::actingAs($this->user);

        $validImage = UploadedFile::fake()->image('nota_asli.jpg', 600, 600);
        $cat = ProgramCategory::first();

        $response = $this->postJson('/api/transactions', [
            'tanggal' => '2026-11-24',
            'jenis' => 'masuk',
            'metode' => 'kas_tunai',
            'category_id' => $cat->id,
            'nominal' => 75000,
            'bukti_foto' => $validImage,
        ]);

        $response->assertStatus(201);

        $txId = $response->json('id');
        $this->assertDatabaseHas('transactions', ['id' => $txId]);

        // Verifikasi nama file diacak dan tidak memakai nama asli mentah
        $tx = \App\Models\Transaction::find($txId);
        $this->assertNotNull($tx->receipt_photo_path);
        $this->assertStringNotContainsString('nota_asli.jpg', $tx->receipt_photo_path);
        $this->assertStringStartsWith('uploads/bukti_', $tx->receipt_photo_path);

        $fullPath = public_path($tx->receipt_photo_path);
        $this->assertTrue(File::exists($fullPath));

        // Bersihkan file uji
        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }
}
