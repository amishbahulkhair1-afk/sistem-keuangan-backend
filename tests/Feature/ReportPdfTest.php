<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ProgramCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Admin Keuangan',
            'email' => 'admin@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'admin_keuangan',
        ]);

        Account::create([
            'code' => 'kas_tunai',
            'name' => 'Kas Tunai',
            'initial_balance' => 5000000,
            'current_balance' => 5000000,
        ]);

        ProgramCategory::create([
            'code' => 'PML2',
            'name' => 'Pembangunan Mushalla Latee II',
            'pic_name' => 'Koordinator PML2',
        ]);
    }

    public function test_unauthenticated_pdf_request_is_rejected(): void
    {
        $response = $this->get('/api/reports/pdf/neraca');
        $response->assertStatus(401);
        $this->assertStringContainsString('Akses Ditolak', $response->getContent());
    }

    public function test_authenticated_pdf_request_renders_neraca_with_kop_surat(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->get('/api/reports/pdf/neraca');
        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('PONDOK PESANTREN ANNUQAYAH', $content);
        $this->assertStringContainsString('DAERAH LATEE', $content);
        $this->assertStringContainsString('LAPORAN NERACA KEUANGAN RESMI', $content);
        $this->assertStringContainsString('TOTAL AKTIVA', $content);
    }

    public function test_authenticated_pdf_request_with_query_token(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        $response = $this->get("/api/reports/pdf/buku_bantu?token={$token}");
        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('BUKU PEMBANTU SALDO PROGRAM', $content);
        $this->assertStringContainsString('PML2', $content);
    }

    public function test_all_report_types_render_successfully(): void
    {
        Sanctum::actingAs($this->user);

        $types = ['neraca', 'buku_bantu', 'transaksi', 'donatur', 'pemetaan_program', 'barang'];

        foreach ($types as $type) {
            $response = $this->get("/api/reports/pdf/{$type}");
            $response->assertStatus(200);
        }
    }
}
